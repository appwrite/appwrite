//! Local HTTP/1.1 test server porting `tests/server.php` plus raw/TCP helpers.
#![allow(dead_code)]

use std::io::{Read, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::atomic::{AtomicBool, AtomicUsize, Ordering};
use std::sync::Arc;
use std::thread::{self, JoinHandle};
use std::time::Duration;

use flate2::write::GzEncoder;
use flate2::Compression;
use sha2::{Digest, Sha256};

pub struct TestServer {
    port: u16,
    shutdown: Arc<AtomicBool>,
    handle: Option<JoinHandle<()>>,
}

impl TestServer {
    pub fn serve() -> Self {
        let listener = TcpListener::bind("127.0.0.1:0").expect("bind");
        listener.set_nonblocking(true).ok();
        let port = listener.local_addr().unwrap().port();
        let shutdown = Arc::new(AtomicBool::new(false));
        let flag = Arc::clone(&shutdown);
        let handle = thread::spawn(move || loop {
            if flag.load(Ordering::Relaxed) {
                break;
            }
            match listener.accept() {
                Ok((stream, _)) => {
                    thread::spawn(move || {
                        let _ = stream.set_read_timeout(Some(Duration::from_secs(5)));
                        let _ = stream.set_write_timeout(Some(Duration::from_secs(5)));
                        let _ = handle_connection(stream);
                    });
                }
                Err(error) if error.kind() == std::io::ErrorKind::WouldBlock => {
                    thread::sleep(Duration::from_millis(5));
                }
                Err(_) => break,
            }
        });
        Self {
            port,
            shutdown,
            handle: Some(handle),
        }
    }

    pub fn port(&self) -> u16 {
        self.port
    }

    pub fn url(&self, path: &str) -> String {
        format!("http://127.0.0.1:{}{path}", self.port)
    }
}

impl Drop for TestServer {
    fn drop(&mut self) {
        self.shutdown.store(true, Ordering::Relaxed);
        if let Some(handle) = self.handle.take() {
            let _ = handle.join();
        }
    }
}

struct Parsed {
    method: String,
    target: String,
    path: String,
    query: String,
    headers: Vec<(String, String)>,
    body: Vec<u8>,
}

fn handle_connection(mut stream: TcpStream) -> std::io::Result<()> {
    loop {
        let parsed = match read_request(&mut stream) {
            Ok(parsed) => parsed,
            Err(_) => return Ok(()),
        };
        let close = header(&parsed, "connection").eq_ignore_ascii_case("close");
        let response = route(&parsed);
        stream.write_all(&response)?;
        stream.flush()?;
        if close {
            break;
        }
    }
    Ok(())
}

fn read_request(stream: &mut TcpStream) -> std::io::Result<Parsed> {
    let mut buf = Vec::new();
    let mut tmp = [0u8; 4096];
    loop {
        let read = stream.read(&mut tmp)?;
        if read == 0 {
            break;
        }
        buf.extend_from_slice(&tmp[..read]);
        if let Some(header_end) = find_header_end(&buf) {
            let header_block = &buf[..header_end];
            let rest = buf[header_end..].to_vec();
            let text = String::from_utf8_lossy(header_block);
            let mut lines = text.split("\r\n");
            let request_line = lines.next().unwrap_or("");
            let mut parts = request_line.split(' ');
            let method = parts.next().unwrap_or("GET").to_owned();
            let target = parts.next().unwrap_or("/").to_owned();
            let mut headers = Vec::new();
            for line in lines {
                if line.is_empty() {
                    continue;
                }
                if let Some((name, value)) = line.split_once(':') {
                    headers.push((name.trim().to_owned(), value.trim().to_owned()));
                }
            }
            let content_length = headers
                .iter()
                .find(|(name, _)| name.eq_ignore_ascii_case("content-length"))
                .and_then(|(_, value)| value.parse::<usize>().ok())
                .unwrap_or(0);
            let mut body = rest;
            while body.len() < content_length {
                let read = stream.read(&mut tmp)?;
                if read == 0 {
                    break;
                }
                body.extend_from_slice(&tmp[..read]);
            }
            body.truncate(content_length);
            let (path, query) = match target.split_once('?') {
                Some((path, query)) => (path.to_owned(), query.to_owned()),
                None => (target.clone(), String::new()),
            };
            return Ok(Parsed {
                method,
                target,
                path,
                query,
                headers,
                body,
            });
        }
        if buf.len() > 1024 * 1024 {
            break;
        }
    }
    Err(std::io::Error::new(
        std::io::ErrorKind::UnexpectedEof,
        "incomplete request",
    ))
}

fn find_header_end(buf: &[u8]) -> Option<usize> {
    buf.windows(4)
        .position(|window| window == b"\r\n\r\n")
        .map(|i| i + 4)
}

fn header(parsed: &Parsed, name: &str) -> String {
    parsed
        .headers
        .iter()
        .filter(|(key, _)| key.eq_ignore_ascii_case(name))
        .map(|(_, value)| value.as_str())
        .collect::<Vec<_>>()
        .join(", ")
}

fn route(parsed: &Parsed) -> Vec<u8> {
    let path = parsed.path.as_str();
    match path {
        "/not-found" => text(404, "missing"),
        "/server-error" => text(500, "failed"),
        "/redirect" => {
            let mut out = status(302, "Found");
            add_header(&mut out, "Location", "/final");
            add_header(&mut out, "Content-Type", "text/plain;charset=UTF-8");
            finish(&mut out, b"redirect");
            out
        }
        "/headers" => {
            let mut out = status(204, "No Content");
            add_header(&mut out, "X-Trace", "one");
            add_header(&mut out, "X-Trace", "two");
            add_header(&mut out, "X-Mixed-Case", "Value");
            finish(&mut out, b"");
            out
        }
        "/binary" => {
            let mut out = status(200, "OK");
            add_header(&mut out, "Content-Type", "application/octet-stream");
            finish(&mut out, b"\x00\x01hello\xff");
            out
        }
        "/request-headers" => {
            let host = header(parsed, "host");
            let trace = header(parsed, "x-trace");
            text(200, &format!("{host}:{trace}"))
        }
        "/request-target" | "/space%20name" => text(200, &parsed.target),
        "/" if parsed.query.contains("ping=1") => text(200, &parsed.target),
        "/method" => {
            let mut out = status(200, "OK");
            add_header(&mut out, "Content-Type", "text/plain;charset=UTF-8");
            add_header(&mut out, "X-Request-Method", &parsed.method);
            finish(&mut out, parsed.method.as_bytes());
            out
        }
        "/body-info" => {
            let hash = hex::encode(Sha256::digest(&parsed.body));
            text(200, &format!("{}:{hash}", parsed.body.len()))
        }
        "/selected-headers" => {
            let comma = header(parsed, "x-comma");
            let zero = header(parsed, "x-zero");
            let mixed = header(parsed, "x-mixed-request");
            text(200, &format!("{comma}:{zero}:{mixed}"))
        }
        "/large-response" => text(200, &"abcd".repeat(65_536)),
        "/stream" => {
            let mut body = Vec::new();
            for i in 0..5 {
                body.extend_from_slice(format!("chunk{i}\n").as_bytes());
            }
            text(200, std::str::from_utf8(&body).unwrap())
        }
        "/slow" => {
            thread::sleep(Duration::from_secs(1));
            text(200, "slow")
        }
        "/gzip" => gzip_route(parsed),
        "/multipart" => multipart_route(parsed),
        "/stream-large" => {
            let chunk = vec![b'a'; 65_536];
            let mut body = Vec::with_capacity(chunk.len() * 128);
            for _ in 0..128 {
                body.extend_from_slice(&chunk);
            }
            let mut out = status(200, "OK");
            add_header(&mut out, "Content-Type", "application/octet-stream");
            finish(&mut out, &body);
            out
        }
        _ => {
            let custom = header(parsed, "x-custom");
            let body = String::from_utf8_lossy(&parsed.body);
            let mut out = status(202, "Accepted");
            add_header(&mut out, "Content-Type", "text/plain;charset=UTF-8");
            finish(
                &mut out,
                format!("{}:{}:{custom}:{body}", parsed.method, path).as_bytes(),
            );
            out
        }
    }
}

fn multipart_route(parsed: &Parsed) -> Vec<u8> {
    let content_type = header(parsed, "content-type");
    let boundary = content_type.split("boundary=").nth(1).map_or("", str::trim);
    let mut name = String::new();
    let mut file = Vec::new();
    if !boundary.is_empty() {
        let marker = format!("--{boundary}");
        let body = String::from_utf8_lossy(&parsed.body);
        for part in body.split(&marker) {
            let part = part.trim_start_matches("\r\n");
            if part.is_empty() || part.starts_with("--") {
                continue;
            }
            let (headers, content) = part.split_once("\r\n\r\n").unwrap_or((part, ""));
            let content = content
                .trim_end_matches("--")
                .trim_end_matches("\r\n")
                .as_bytes();
            if headers.contains("name=\"name\"") && !headers.contains("filename=") {
                String::from_utf8_lossy(content)
                    .trim()
                    .clone_into(&mut name);
            }
            if headers.contains("filename=") {
                file = content.to_vec();
                if file.ends_with(b"\r\n") {
                    file.truncate(file.len() - 2);
                }
            }
        }
    }
    let hash = hex::encode(Sha256::digest(&file));
    text(200, &format!("{}:{}:{hash}", name, file.len()))
}

fn gzip_route(parsed: &Parsed) -> Vec<u8> {
    let accept = header(parsed, "accept-encoding");
    let repeat = query_param(&parsed.query, "repeat")
        .and_then(|value| value.parse::<usize>().ok())
        .unwrap_or(64)
        .max(1);
    let binary = query_param(&parsed.query, "type") == Some("binary");
    let compressible = query_param(&parsed.query, "compress") != Some("0");
    let payload = if binary {
        let unit: Vec<u8> = (0..=255).collect();
        unit.repeat(repeat)
    } else {
        "utopia ".repeat(repeat).into_bytes()
    };
    let mut out = status(200, "OK");
    add_header(
        &mut out,
        "Content-Type",
        if binary {
            "application/octet-stream"
        } else {
            "text/plain;charset=UTF-8"
        },
    );
    add_header(&mut out, "X-Accept-Encoding", &accept);
    if compressible && accept.contains("gzip") {
        add_header(&mut out, "Content-Encoding", "gzip");
        let mut encoder = GzEncoder::new(Vec::new(), Compression::default());
        encoder.write_all(&payload).unwrap();
        let gz = encoder.finish().unwrap();
        finish(&mut out, &gz);
        out
    } else {
        add_header(&mut out, "Content-Length", &payload.len().to_string());
        finish(&mut out, &payload);
        out
    }
}

fn query_param<'a>(query: &'a str, name: &str) -> Option<&'a str> {
    query.split('&').find_map(|pair| {
        let (key, value) = pair.split_once('=')?;
        (key == name).then_some(value)
    })
}

fn status(code: u16, reason: &str) -> Vec<u8> {
    format!("HTTP/1.1 {code} {reason}\r\n").into_bytes()
}

fn add_header(out: &mut Vec<u8>, name: &str, value: &str) {
    out.extend_from_slice(format!("{name}: {value}\r\n").as_bytes());
}

fn finish(out: &mut Vec<u8>, body: &[u8]) {
    if !String::from_utf8_lossy(out)
        .to_ascii_lowercase()
        .contains("content-length")
        && !body.is_empty()
    {
        add_header(out, "Content-Length", &body.len().to_string());
    }
    out.extend_from_slice(b"\r\n");
    out.extend_from_slice(body);
}

fn text(code: u16, body: &str) -> Vec<u8> {
    let mut out = status(code, "OK");
    add_header(&mut out, "Content-Type", "text/plain;charset=UTF-8");
    finish(&mut out, body.as_bytes());
    out
}

pub fn raw(response: &[u8], test: impl FnOnce(u16)) {
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let port = listener.local_addr().unwrap().port();
    let response = response.to_vec();
    let handle = thread::spawn(move || {
        if let Ok((mut stream, _)) = listener.accept() {
            let _ = stream.set_read_timeout(Some(Duration::from_secs(2)));
            let mut buf = [0u8; 8192];
            let _ = stream.read(&mut buf);
            let _ = stream.write_all(&response);
        }
    });
    test(port);
    let _ = handle.join();
}

/// Write plaintext immediately so a TLS client fails the handshake (rustls
/// waits for `ServerHello` if the peer only reads, which surfaces as Timeout).
pub fn plaintext_on_connect(test: impl FnOnce(u16)) {
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let port = listener.local_addr().unwrap().port();
    let handle = thread::spawn(move || {
        if let Ok((mut stream, _)) = listener.accept() {
            let _ = stream.write_all(b"HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n");
            let _ = stream.flush();
            thread::sleep(Duration::from_millis(50));
        }
    });
    test(port);
    let _ = handle.join();
}

pub fn unbound(test: impl FnOnce(u16)) {
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let port = listener.local_addr().unwrap().port();
    drop(listener);
    test(port);
}

pub fn drops_first_keep_alive(test: impl FnOnce(u16)) -> usize {
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let port = listener.local_addr().unwrap().port();
    let connections = Arc::new(AtomicUsize::new(0));
    let count = Arc::clone(&connections);
    let shutdown = Arc::new(AtomicBool::new(false));
    let flag = Arc::clone(&shutdown);
    listener.set_nonblocking(true).ok();
    let handle = thread::spawn(move || {
        while !flag.load(Ordering::Relaxed) {
            match listener.accept() {
                Ok((mut stream, _)) => {
                    let n = count.fetch_add(1, Ordering::SeqCst) + 1;
                    let _ = stream.set_read_timeout(Some(Duration::from_secs(2)));
                    loop {
                        let mut buf = [0u8; 4096];
                        match stream.read(&mut buf) {
                            Ok(0) | Err(_) => break,
                            Ok(_) => {
                                let body = b"ok";
                                let _ = stream.write_all(
                                    format!(
                                        "HTTP/1.1 200 OK\r\nContent-Length: {}\r\n\r\n",
                                        body.len()
                                    )
                                    .as_bytes(),
                                );
                                let _ = stream.write_all(body);
                                if n == 1 {
                                    break;
                                }
                            }
                        }
                    }
                }
                Err(error) if error.kind() == std::io::ErrorKind::WouldBlock => {
                    thread::sleep(Duration::from_millis(5));
                }
                Err(_) => break,
            }
        }
    });
    test(port);
    shutdown.store(true, Ordering::Relaxed);
    let _ = handle.join();
    connections.load(Ordering::SeqCst)
}
