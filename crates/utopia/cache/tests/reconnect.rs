//! Retries after a lost connection (the `testCacheReconnect` tests of the
//! Redis and Memcached suites), against scripted servers that drop the
//! connection a set number of times before answering.

#![cfg(feature = "redis")]

use std::sync::Arc;
use std::sync::atomic::{AtomicUsize, Ordering};

use php_std::types::Value;
use utopia_async::io::{AsyncReadExt, AsyncWriteExt};
use utopia_async::net::TcpListener;
use utopia_cache::resp::{self, Reply};
use utopia_cache::{Adapter, Error, Redis};
#[cfg(feature = "memcached")]
use utopia_cache::{Memcached, MemcachedClient};

/// Starts a server that hangs up on the first `drops` commands and then
/// answers each with `answer(command)`.
async fn server(drops: usize, answer: fn(&[u8]) -> Vec<u8>, framed: bool) -> (u16, Arc<AtomicUsize>) {
    let listener = TcpListener::bind("127.0.0.1:0").await.expect("bind");
    let port = listener.local_addr().expect("addr").port();
    let dropped = Arc::new(AtomicUsize::new(0));
    let counter = dropped.clone();
    utopia_async::spawn(async move {
        loop {
            let Ok((mut socket, _)) = listener.accept().await else { return };
            let counter = counter.clone();
            utopia_async::spawn(async move {
                let mut buffer = Vec::new();
                let mut chunk = [0u8; 4096];
                loop {
                    match socket.read(&mut chunk).await {
                        Ok(0) | Err(_) => return,
                        Ok(n) => buffer.extend_from_slice(&chunk[..n]),
                    }
                    loop {
                        let command = if framed {
                            let mut offset = 0;
                            match resp::parse(&buffer, &mut offset) {
                                Ok(Some(Reply::Array(args))) => {
                                    buffer.drain(..offset);
                                    args.iter().filter_map(Reply::as_bytes).collect::<Vec<_>>().join(&b' ')
                                }
                                _ => break,
                            }
                        } else {
                            let Some(end) = buffer.windows(2).position(|w| w == b"\r\n") else { break };
                            let line = buffer[..end].to_vec();
                            buffer.drain(..end + 2);
                            line
                        };
                        let handshake = command.starts_with(b"CLIENT") || command.starts_with(b"HELLO");
                        if !handshake && counter.load(Ordering::SeqCst) < drops {
                            counter.fetch_add(1, Ordering::SeqCst);
                            return;
                        }
                        if socket.write_all(&answer(&command)).await.is_err() {
                            return;
                        }
                    }
                }
            });
        }
    });
    (port, dropped)
}

fn redis_answer(command: &[u8]) -> Vec<u8> {
    if command.starts_with(b"HGET") {
        let stored = br#"{"time":4102444800,"data":"reconnect"}"#;
        [format!("${}\r\n", stored.len()).into_bytes(), stored.to_vec(), b"\r\n".to_vec()].concat()
    } else if command.starts_with(b"CLIENT") || command.starts_with(b"HELLO") {
        b"-ERR unknown command\r\n".to_vec()
    } else {
        b"+OK\r\n".to_vec()
    }
}

async fn redis(port: u16, retries: i64) -> Redis {
    let client = redis::Client::open(format!("redis://127.0.0.1:{port}")).expect("client");
    let config = redis::aio::ConnectionManagerConfig::new().set_number_of_retries(0);
    let connection = redis::aio::ConnectionManager::new_with_config(client, config).await.expect("connect");
    Redis::new(connection).with_max_retries(retries).with_retry_delay(10)
}

#[utopia_async::test]
async fn redis_retries_a_lost_connection() {
    let (port, dropped) = server(2, redis_answer, true).await;
    let cache = redis(port, 3).await;
    assert_eq!(cache.load("k", 60, "k").await.unwrap(), Some(Value::from("reconnect")));
    assert_eq!(dropped.load(Ordering::SeqCst), 2);
    assert_eq!(cache.retry().max_retries(), 3);
}

#[utopia_async::test]
async fn redis_without_retries_raises_the_lost_connection() {
    let (port, _) = server(1, redis_answer, true).await;
    let cache = redis(port, 0).await;
    let error = cache.load("k", 60, "k").await.unwrap_err();
    assert!(matches!(error, Error::Redis(_)), "{error:?}");
    assert_eq!(error.php_class(), "RedisException");
}

#[cfg(feature = "memcached")]
fn memcached_answer(command: &[u8]) -> Vec<u8> {
    if command.starts_with(b"get ") {
        let stored = br#"a:2:{s:4:"time";i:4102444800;s:4:"data";s:9:"reconnect";}"#;
        [format!("VALUE k 4 {}\r\n", stored.len()).into_bytes(), stored.to_vec(), b"\r\nEND\r\n".to_vec()].concat()
    } else {
        b"STORED\r\n".to_vec()
    }
}

#[cfg(feature = "memcached")]
#[utopia_async::test]
async fn memcached_retries_a_lost_connection() {
    let (port, dropped) = server(2, memcached_answer, false).await;
    let cache = Memcached::new(MemcachedClient::new("127.0.0.1", port)).with_max_retries(3).with_retry_delay(10);
    assert_eq!(cache.load("k", 60, "").await.unwrap(), Some(Value::from("reconnect")));
    assert_eq!(dropped.load(Ordering::SeqCst), 2);
}

#[cfg(feature = "memcached")]
#[utopia_async::test]
async fn memcached_gives_up_after_its_retries() {
    let (port, _) = server(5, memcached_answer, false).await;
    let cache = Memcached::new(MemcachedClient::new("127.0.0.1", port)).with_max_retries(1).with_retry_delay(10);
    let error = cache.load("k", 60, "").await.unwrap_err();
    assert_eq!(error.php_class(), "MemcachedException");
    assert!(error.to_string().starts_with("Memcached connection failed after 2 attempts."), "{error}");
}
