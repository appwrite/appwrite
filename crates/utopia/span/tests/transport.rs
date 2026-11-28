//! The default Sentry transport, against a local HTTP server: inside a
//! runtime it sends on that runtime, outside one on a thread of its own.
#![cfg(feature = "http")]

use std::io::{BufRead, BufReader, Read, Write};
use std::net::TcpListener;
use std::sync::mpsc;
use std::thread;
use std::time::Duration;

use utopia_span::exporter::{HttpTransport, Request, Transport};

/// Accepts one request, answers 200, and hands back what arrived.
fn server() -> (String, mpsc::Receiver<(String, Vec<u8>)>) {
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let url = format!("http://{}/api/1/envelope/", listener.local_addr().unwrap());
    let (sent, received) = mpsc::channel();
    thread::spawn(move || {
        let (stream, _) = listener.accept().unwrap();
        let mut reader = BufReader::new(stream.try_clone().unwrap());
        let mut head = String::new();
        let mut length = 0;
        loop {
            let mut line = String::new();
            reader.read_line(&mut line).unwrap();
            if let Some(value) = line.to_ascii_lowercase().strip_prefix("content-length:") {
                length = value.trim().parse().unwrap();
            }
            if line == "\r\n" {
                break;
            }
            head.push_str(&line);
        }
        let mut body = vec![0; length];
        reader.read_exact(&mut body).unwrap();
        (&stream).write_all(b"HTTP/1.1 200 OK\r\ncontent-length: 0\r\nconnection: close\r\n\r\n").unwrap();
        sent.send((head, body)).unwrap();
    });
    (url, received)
}

fn request(url: String) -> Request {
    Request {
        method: "POST",
        url,
        headers: vec![("X-Sentry-Auth", "Sentry sentry_key=public".into())],
        body: b"{\"event_id\":\"1\"}\n".to_vec(),
    }
}

fn arrives(received: mpsc::Receiver<(String, Vec<u8>)>) {
    let (head, body) = received.recv_timeout(Duration::from_secs(5)).expect("the envelope never arrived");
    assert!(head.starts_with("POST /api/1/envelope/ HTTP/1.1\r\n"), "{head}");
    assert!(head.to_ascii_lowercase().contains("x-sentry-auth: sentry sentry_key=public\r\n"), "{head}");
    assert_eq!(body, b"{\"event_id\":\"1\"}\n");
}

#[test]
fn sends_outside_a_runtime() {
    let (url, received) = server();
    HttpTransport::new().send(request(url));
    arrives(received);
}

#[test]
fn sends_on_the_current_runtime() {
    let (url, received) = server();
    let runtime = utopia_async::Builder::current_thread().build().unwrap();
    runtime.block_on(async {
        HttpTransport::new().send(request(url));
        // The send is spawned on this runtime, so keep it running until the server has the envelope.
        utopia_async::spawn_blocking(move || arrives(received)).await.unwrap();
    });
}
