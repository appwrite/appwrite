//! Port of `tests/e2e/DNS/HttpTest.php` using an in-process Tokio DNS-over-HTTPS adapter.

mod common;

use std::io::{Read, Write};
use std::net::{SocketAddr, TcpStream};
use std::time::Duration;

use common::start_http;
use utopia_dns::message::{Message, Question, Record};

fn http_exchange(
    addr: SocketAddr,
    method: &str,
    path: &str,
    body: &[u8],
    content_type: &str,
) -> Option<Vec<u8>> {
    let mut stream = TcpStream::connect_timeout(&addr, Duration::from_secs(5)).ok()?;
    stream.set_read_timeout(Some(Duration::from_secs(5))).ok()?;
    stream
        .set_write_timeout(Some(Duration::from_secs(5)))
        .ok()?;
    use std::fmt::Write as _;
    let mut req = format!("{method} {path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n");
    if !body.is_empty() {
        let _ = write!(
            req,
            "Content-Type: {content_type}\r\nContent-Length: {}\r\n",
            body.len()
        );
    }
    req.push_str("\r\n");
    stream.write_all(req.as_bytes()).ok()?;
    if !body.is_empty() {
        stream.write_all(body).ok()?;
    }
    let mut buf = Vec::new();
    stream.read_to_end(&mut buf).ok()?;
    let text = String::from_utf8_lossy(&buf);
    let header_end = text.find("\r\n\r\n")?;
    let status_line = text.lines().next().unwrap_or("");
    if !status_line.contains(" 200 ") {
        return None;
    }
    Some(buf[header_end + 4..].to_vec())
}

fn b64url(data: &[u8]) -> String {
    const T: &[u8] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_";
    let mut out = String::new();
    let mut i = 0;
    while i < data.len() {
        let b0 = data[i];
        let b1 = data.get(i + 1).copied();
        let b2 = data.get(i + 2).copied();
        out.push(char::from(T[usize::from(b0 >> 2)]));
        match (b1, b2) {
            (None, _) => {
                out.push(char::from(T[usize::from((b0 & 3) << 4)]));
            }
            (Some(b1), None) => {
                out.push(char::from(T[usize::from(((b0 & 3) << 4) | (b1 >> 4))]));
                out.push(char::from(T[usize::from((b1 & 15) << 2)]));
            }
            (Some(b1), Some(b2)) => {
                out.push(char::from(T[usize::from(((b0 & 3) << 4) | (b1 >> 4))]));
                out.push(char::from(T[usize::from(((b1 & 15) << 2) | (b2 >> 6))]));
                out.push(char::from(T[usize::from(b2 & 63)]));
            }
        }
        i += 3;
    }
    out
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn post_query() {
    let server = start_http().await;
    let addr = server.http.unwrap();
    let query = Message::query(Question::new("dev.appwrite.io", Record::TYPE_A), None, true)
        .unwrap()
        .encode(None)
        .unwrap();
    let body = http_exchange(addr, "POST", "/", &query, "application/dns-message").unwrap();
    let message = Message::decode(&body).unwrap();
    assert_eq!(message.answers.len(), 1);
    assert_eq!(message.answers[0].name, "dev.appwrite.io");
    assert_eq!(message.answers[0].type_code, Record::TYPE_A);
    assert_eq!(message.answers[0].rdata, "180.12.3.24");
    server.stop();
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn get_query() {
    let server = start_http().await;
    let addr = server.http.unwrap();
    let query = Message::query(
        Question::new("dev.appwrite.io", Record::TYPE_TXT),
        None,
        true,
    )
    .unwrap()
    .encode(None)
    .unwrap();
    let encoded = b64url(&query);
    let path = format!("/?dns={encoded}");
    let body = http_exchange(addr, "GET", &path, b"", "application/dns-message").unwrap();
    let message = Message::decode(&body).unwrap();
    assert_eq!(message.answers.len(), 1);
    assert_eq!(message.answers[0].type_code, Record::TYPE_TXT);
    assert_eq!(message.answers[0].rdata, "awesome-secret-key");
    server.stop();
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn large_response_is_not_truncated() {
    let server = start_http().await;
    let addr = server.http.unwrap();
    let query = Message::query(
        Question::new("large.localhost", Record::TYPE_TXT),
        None,
        true,
    )
    .unwrap()
    .encode(None)
    .unwrap();
    let body = http_exchange(addr, "POST", "/", &query, "application/dns-message").unwrap();
    let message = Message::decode(&body).unwrap();
    assert!(!message.header.truncated);
    assert_eq!(message.answers.len(), 8);
    server.stop();
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn invalid_requests() {
    let server = start_http().await;
    let addr = server.http.unwrap();
    assert!(http_exchange(addr, "GET", "/?dns=!!!", b"", "application/dns-message").is_none());
    assert!(http_exchange(addr, "GET", "/", b"", "application/dns-message").is_none());
    assert!(http_exchange(addr, "POST", "/", b"raw", "text/plain").is_none());
    assert!(http_exchange(addr, "DELETE", "/", b"", "application/dns-message").is_none());
    server.stop();
}
