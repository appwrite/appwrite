//! The multiplexed connection's deadlines against a scripted server: what a
//! caller's expired deadline may do to the connection everyone shares
//! (MultiplexingDeadlineTest), and that replies stay paired with callers.
//! The server can stall (keep the socket, answer nothing until resumed),
//! which is what `docker pause` does to Redis in the PHP suite.

#![cfg(feature = "redis")]

use std::collections::HashMap;
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, AtomicUsize, Ordering};
use std::time::Duration;

use php_std::types::Value;
use utopia_async::io::{AsyncReadExt, AsyncWriteExt};
use utopia_async::net::TcpListener;
use utopia_async::sync::Notify;
use utopia_cache::resp::{self, Reply};
use utopia_cache::{Adapter, Error, Multiplexing, MultiplexingOptions};

/// A tiny Redis: HGET, HSET, PING; counts connections; can stall.
struct Server {
    port: u16,
    connections: Arc<AtomicUsize>,
    stalled: Arc<AtomicBool>,
    resumed: Arc<Notify>,
}

impl Server {
    async fn start() -> Server {
        let listener = TcpListener::bind("127.0.0.1:0").await.expect("bind");
        let port = listener.local_addr().expect("addr").port();
        let connections = Arc::new(AtomicUsize::new(0));
        let stalled = Arc::new(AtomicBool::new(false));
        let resumed = Arc::new(Notify::new());
        let data: Arc<std::sync::Mutex<HashMap<Vec<u8>, Vec<u8>>>> = Arc::default();
        let (c, s, r) = (connections.clone(), stalled.clone(), resumed.clone());
        utopia_async::spawn(async move {
            loop {
                let Ok((mut socket, _)) = listener.accept().await else { return };
                c.fetch_add(1, Ordering::SeqCst);
                let (s, r, data) = (s.clone(), r.clone(), data.clone());
                utopia_async::spawn(async move {
                    let mut buffer = Vec::new();
                    let mut chunk = [0u8; 4096];
                    loop {
                        let mut offset = 0;
                        while let Ok(Some(Reply::Array(args))) = resp::parse(&buffer, &mut offset) {
                            buffer.drain(..offset);
                            offset = 0;
                            while s.load(Ordering::SeqCst) {
                                r.notified().await;
                            }
                            let arg = |i: usize| args.get(i).and_then(Reply::as_bytes).unwrap_or_default().to_vec();
                            let reply: Vec<u8> = match arg(0).to_ascii_uppercase().as_slice() {
                                b"PING" => b"+PONG\r\n".to_vec(),
                                b"HSET" => {
                                    data.lock().unwrap().insert([arg(1), arg(2)].concat(), arg(3));
                                    b":1\r\n".to_vec()
                                }
                                b"HGET" => match data.lock().unwrap().get(&[arg(1), arg(2)].concat()) {
                                    Some(v) => {
                                        [format!("${}\r\n", v.len()).into_bytes(), v.clone(), b"\r\n".to_vec()].concat()
                                    }
                                    None => b"$-1\r\n".to_vec(),
                                },
                                _ => b"-ERR unknown command\r\n".to_vec(),
                            };
                            if socket.write_all(&reply).await.is_err() {
                                return;
                            }
                        }
                        match socket.read(&mut chunk).await {
                            Ok(0) | Err(_) => return,
                            Ok(n) => buffer.extend_from_slice(&chunk[..n]),
                        }
                    }
                });
            }
        });
        Server { port, connections, stalled, resumed }
    }

    fn pause(&self) {
        self.stalled.store(true, Ordering::SeqCst);
    }

    fn resume(&self) {
        self.stalled.store(false, Ordering::SeqCst);
        self.resumed.notify_waiters();
    }

    async fn adapter(&self, read_timeout: f64, liveness_timeout: f64) -> Multiplexing {
        let mut options = MultiplexingOptions::new("127.0.0.1", self.port);
        options.read_timeout = read_timeout;
        options.liveness_timeout = liveness_timeout;
        Multiplexing::connect(options).await.expect("connect")
    }
}

fn value(s: &str) -> Value {
    Value::from(s)
}

#[utopia_async::test]
async fn deadline_throws_timeout_rather_than_connection_failure() {
    let server = Server::start().await;
    let cache = server.adapter(0.05, 5.0).await;
    cache.save("deadline", &value("value"), "deadline", 0).await.unwrap();

    server.pause();
    let error = cache.load("deadline", 60, "deadline").await.unwrap_err();
    server.resume();

    assert!(matches!(error, Error::Timeout(_)), "{error:?}");
    assert!(error.to_string().contains("Timed out waiting for Redis response"));
}

#[utopia_async::test]
async fn abandoned_reply_is_not_handed_to_the_next_caller() {
    let server = Server::start().await;
    let cache = server.adapter(0.05, 5.0).await;
    cache.save("first", &value("first-value"), "first", 0).await.unwrap();
    cache.save("second", &value("second-value"), "second", 0).await.unwrap();

    server.pause();
    assert!(matches!(cache.load("first", 60, "first").await, Err(Error::Timeout(_))));
    server.resume();
    utopia_async::sleep(Duration::from_millis(300)).await;

    assert_eq!(cache.load("second", 60, "second").await.unwrap(), Some(value("second-value")));
    assert_eq!(cache.load("first", 60, "first").await.unwrap(), Some(value("first-value")));
}

#[utopia_async::test]
async fn a_stall_costs_the_waiting_callers_and_no_reconnect() {
    let server = Server::start().await;
    let cache = Arc::new(server.adapter(0.05, 5.0).await);
    cache.save("survives", &value("survives-value"), "survives", 0).await.unwrap();
    let before = server.connections.load(Ordering::SeqCst);

    server.pause();
    let mut callers = Vec::new();
    for _ in 0..8 {
        let cache = cache.clone();
        callers.push(utopia_async::spawn(async move { cache.load("survives", 60, "survives").await }));
    }
    let mut timeouts = 0;
    for caller in callers {
        if matches!(caller.await.unwrap(), Err(Error::Timeout(_))) {
            timeouts += 1;
        }
    }
    server.resume();
    utopia_async::sleep(Duration::from_millis(300)).await;

    assert_eq!(timeouts, 8, "every caller waiting on the stalled server expires");
    assert_eq!(cache.load("survives", 60, "survives").await.unwrap(), Some(value("survives-value")));
    assert!(cache.ping().await.unwrap());
    assert_eq!(server.connections.load(Ordering::SeqCst), before, "a stall must not rebuild the connection");
}

#[utopia_async::test]
async fn silence_beyond_the_liveness_timeout_tears_the_connection_down() {
    let server = Server::start().await;
    let cache = server.adapter(0.05, 0.3).await;
    cache.save("liveness", &value("liveness-value"), "liveness", 0).await.unwrap();

    server.pause();
    let mut torn = None;
    let deadline = std::time::Instant::now() + Duration::from_secs(3);
    while std::time::Instant::now() < deadline {
        match cache.load("liveness", 60, "liveness").await {
            Err(Error::Timeout(_)) => continue,
            Err(e @ Error::Idle(_)) => {
                torn = Some(e);
                break;
            }
            other => panic!("unexpected {other:?}"),
        }
    }
    server.resume();

    let torn = torn.expect("a silent connection is declared dead");
    assert!(torn.to_string().contains("idle for"), "{torn}");
    utopia_async::sleep(Duration::from_millis(300)).await;
    assert_eq!(cache.load("liveness", 60, "liveness").await.unwrap(), Some(value("liveness-value")));
    assert_eq!(server.connections.load(Ordering::SeqCst), 2, "the next caller reconnects");
}

#[utopia_async::test]
async fn idle_gap_is_not_mistaken_for_a_dead_connection() {
    let server = Server::start().await;
    let cache = server.adapter(0.1, 0.3).await;
    cache.save("gap", &value("value"), "gap", 0).await.unwrap();
    utopia_async::sleep(Duration::from_millis(500)).await;

    server.pause();
    let error = cache.load("gap", 60, "gap").await.unwrap_err();
    server.resume();

    assert!(matches!(error, Error::Timeout(_)), "idle time with nothing outstanding is not counted: {error:?}");
    assert_eq!(cache.load("gap", 60, "gap").await.unwrap(), Some(value("value")));
}

#[utopia_async::test]
async fn invalid_options_are_rejected() {
    let mut options = MultiplexingOptions::new("127.0.0.1", 1);
    options.read_timeout = 1.0;
    options.liveness_timeout = 0.5;
    let error = Multiplexing::connect(options).await.err().expect("rejected");
    assert_eq!(error.to_string(), "livenessTimeout must be greater than or equal to readTimeout");
    assert_eq!(error.php_class(), "InvalidArgumentException");
}

#[utopia_async::test]
async fn a_closed_connection_is_rebuilt_by_the_next_call() {
    let server = Server::start().await;
    let cache = server.adapter(0.25, 5.0).await;
    cache.save("k", &value("v"), "k", 0).await.unwrap();
    cache.disconnect();
    assert_eq!(cache.load("k", 60, "k").await.unwrap(), Some(value("v")));
    assert_eq!(server.connections.load(Ordering::SeqCst), 2);
}

#[utopia_async::test]
async fn pending_depth_goes_up_and_down_with_each_command() {
    use utopia_cache::telemetry::{Kind, MemoryTelemetry, Number};

    let server = Server::start().await;
    let cache = server.adapter(0.25, 5.0).await;
    let telemetry = MemoryTelemetry::new();
    cache.set_telemetry(Arc::new(telemetry.clone()));
    assert!(cache.ping().await.unwrap());

    let points = telemetry.points(Kind::UpDownCounter, "cache.redis_multiplexing.pending.depth").expect("recorded");
    let values: Vec<Number> = points.iter().map(|p| p.value).collect();
    assert_eq!(values, vec![Number::Int(1), Number::Int(-1)]);
}
