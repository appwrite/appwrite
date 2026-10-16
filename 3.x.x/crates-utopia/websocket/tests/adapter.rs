//! In-process port of `tests/e2e/AdapterTest.php` (no Docker / live Swoole).

use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::thread;
use std::time::Duration;

use utopia_websocket::prelude::*;

fn wait_port(handle: &TokioAdapter) -> u16 {
    for _ in 0..200 {
        let port = handle.get_native().port;
        if port != 0 {
            return port;
        }
        thread::sleep(Duration::from_millis(10));
    }
    panic!("server did not bind a port");
}

fn spawn_echo_server() -> (TokioAdapter, thread::JoinHandle<()>, u16) {
    let mut adapter = Swoole::new("127.0.0.1", 0);
    adapter.set_worker_number(1);
    let handle = adapter.clone();
    let send_handle = adapter.clone();
    let mut server = Server::new(adapter);
    let started = Arc::new(AtomicBool::new(false));
    let started_flag = Arc::clone(&started);
    server.on_start(Box::new(move || {
        started_flag.store(true, Ordering::SeqCst);
    }));
    server.on_message(Box::new(move |connection, message| {
        match message.as_str() {
            "ping" => {
                let _ = send_handle.send(&[connection], "pong");
            }
            "pong" => {
                let _ = send_handle.send(&[connection], "ping");
            }
            "broadcast" => {
                let connections = send_handle.get_connections();
                let _ = send_handle.send(&connections, "broadcast");
            }
            "disconnect" => {
                let _ = send_handle.send(&[connection], "disconnect");
                let _ = send_handle.close(connection, 1000);
            }
            _ => {}
        }
    }));

    let join = thread::spawn(move || {
        server.start();
    });

    for _ in 0..200 {
        if started.load(Ordering::SeqCst) {
            break;
        }
        thread::sleep(Duration::from_millis(10));
    }
    let port = wait_port(&handle);
    (handle, join, port)
}

fn client_for(port: u16) -> Client {
    Client::new(format!("ws://127.0.0.1:{port}"), HashMap::default(), 5.0).unwrap()
}

/// In-process equivalent of `AdapterTest::testServer` for Swoole/Workerman.
#[test]
fn test_tokio_adapter_echo_and_broadcast() {
    let (handle, join, port) = spawn_echo_server();

    let mut client = client_for(port);
    client.connect().unwrap();
    client.send("ping").unwrap();
    assert_eq!(client.receive().unwrap().as_deref(), Some("pong"));
    assert!(client.is_connected());

    let mut client_a = client_for(port);
    client_a.connect().unwrap();
    let mut client_b = client_for(port);
    client_b.connect().unwrap();

    client_a.send("ping").unwrap();
    assert_eq!(client_a.receive().unwrap().as_deref(), Some("pong"));
    client_b.send("pong").unwrap();
    assert_eq!(client_b.receive().unwrap().as_deref(), Some("ping"));

    client_a.send("broadcast").unwrap();
    assert_eq!(client.receive().unwrap().as_deref(), Some("broadcast"));
    assert_eq!(client_a.receive().unwrap().as_deref(), Some("broadcast"));
    assert_eq!(client_b.receive().unwrap().as_deref(), Some("broadcast"));

    client_a.close();
    client_b.close();

    client.send("disconnect").unwrap();
    assert_eq!(client.receive().unwrap().as_deref(), Some("disconnect"));

    let mut stopper = handle.clone();
    stopper.shutdown().ok();
    let _ = join.join();
}

/// PHP Docker/Swoole e2e (`tests/e2e/AdapterTest.php`) - requires live servers.
#[test]
#[ignore = "requires PHP Swoole/Workerman servers from tests/servers"]
fn test_php_swoole_workerman_e2e() {}
