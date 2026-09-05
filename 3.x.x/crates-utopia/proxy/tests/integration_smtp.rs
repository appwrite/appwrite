//! Integration tests for the SMTP proxy server.
//!
//! Spins a `SmtpServer` on an ephemeral port pointed at a local "echo-ish" SMTP
//! backend. The backend speaks just enough SMTP to drive the proxy state machine
//! (greeting, per-command 250 acks, 354 for DATA, 250 after the dot).

use std::io;
use std::sync::Arc;
use std::time::Duration;

use tokio::io::{AsyncBufReadExt, AsyncReadExt, AsyncWriteExt, BufReader};
use tokio::net::{TcpListener, TcpStream};
use tokio::time::timeout;

use utopia_proxy::resolver::fixed::Fixed;
use utopia_proxy::server::smtp::{SmtpConfig, SmtpServer};

const STEP_TIMEOUT: Duration = Duration::from_secs(5);

/// Bind to port 0 on loopback and return (listener, "host:port" string).
async fn bind_ephemeral() -> io::Result<(TcpListener, String)> {
    let listener = TcpListener::bind("127.0.0.1:0").await?;
    let addr = listener.local_addr()?;
    Ok((listener, format!("{}:{}", addr.ip(), addr.port())))
}

/// Spawn a minimal SMTP backend that responds to every command with a canned
/// 250 reply, answers DATA with 354, and acknowledges the dot terminator with 250.
fn spawn_backend(listener: TcpListener) {
    tokio::spawn(async move {
        loop {
            let Ok((stream, _)) = listener.accept().await else {
                return;
            };
            tokio::spawn(async move {
                let (read_half, mut write_half) = stream.into_split();
                let mut reader = BufReader::new(read_half);

                if write_half
                    .write_all(b"220 backend ESMTP ready\r\n")
                    .await
                    .is_err()
                {
                    return;
                }

                let mut in_data = false;
                let mut line = Vec::new();

                loop {
                    line.clear();
                    let read = match reader.read_until(b'\n', &mut line).await {
                        Ok(0) | Err(_) => return,
                        Ok(n) => n,
                    };
                    let _ = read;

                    if in_data {
                        let trimmed: &[u8] = {
                            let mut s = 0;
                            let mut e = line.len();
                            while s < e && line[s].is_ascii_whitespace() {
                                s += 1;
                            }
                            while e > s && line[e - 1].is_ascii_whitespace() {
                                e -= 1;
                            }
                            &line[s..e]
                        };
                        if trimmed == b"." {
                            in_data = false;
                            if write_half.write_all(b"250 Ok: queued\r\n").await.is_err() {
                                return;
                            }
                        }
                        continue;
                    }

                    let upper: String = line
                        .iter()
                        .take(4)
                        .map(|b| b.to_ascii_uppercase() as char)
                        .collect();
                    match upper.as_str() {
                        "EHLO" => {
                            if write_half
                                .write_all(b"250-backend\r\n250 OK\r\n")
                                .await
                                .is_err()
                            {
                                return;
                            }
                        }
                        "DATA" => {
                            if write_half
                                .write_all(b"354 Start mail input; end with <CRLF>.<CRLF>\r\n")
                                .await
                                .is_err()
                            {
                                return;
                            }
                            in_data = true;
                        }
                        "QUIT" => {
                            let _ = write_half.write_all(b"221 Bye\r\n").await;
                            return;
                        }
                        _ => {
                            if write_half.write_all(b"250 OK\r\n").await.is_err() {
                                return;
                            }
                        }
                    }
                }
            });
        }
    });
}

/// Read a single CRLF-terminated line from the client connection.
async fn read_response(
    reader: &mut BufReader<tokio::net::tcp::OwnedReadHalf>,
) -> io::Result<String> {
    let mut line = Vec::new();
    let n = timeout(STEP_TIMEOUT, reader.read_until(b'\n', &mut line))
        .await
        .map_err(|_| io::Error::new(io::ErrorKind::TimedOut, "read_response timeout"))??;
    if n == 0 {
        return Err(io::Error::new(
            io::ErrorKind::UnexpectedEof,
            "connection closed",
        ));
    }
    Ok(String::from_utf8_lossy(&line).to_string())
}

/// Read bytes from the client socket until the response ends (no more pending data
/// within a short idle window). Useful for multi-line EHLO replies.
async fn read_chunk(stream: &mut TcpStream) -> io::Result<String> {
    let mut buffer = vec![0u8; 4096];
    let n = timeout(STEP_TIMEOUT, stream.read(&mut buffer))
        .await
        .map_err(|_| io::Error::new(io::ErrorKind::TimedOut, "read_chunk timeout"))??;
    if n == 0 {
        return Err(io::Error::new(
            io::ErrorKind::UnexpectedEof,
            "connection closed",
        ));
    }
    Ok(String::from_utf8_lossy(&buffer[..n]).to_string())
}

fn smtp_config(port: u16) -> SmtpConfig {
    SmtpConfig {
        host: "127.0.0.1".to_string(),
        port,
        skip_validation: true,
        cache_ttl: 0,
        connect_timeout: Duration::from_secs(2),
        timeout: Duration::from_secs(5),
        ..SmtpConfig::default()
    }
}

/// Spawn an `SmtpServer` on 127.0.0.1:0 and return the concrete listen port.
async fn spawn_server(backend_endpoint: String) -> io::Result<u16> {
    let probe = TcpListener::bind("127.0.0.1:0").await?;
    let port = probe.local_addr()?.port();
    drop(probe);

    let resolver = Arc::new(Fixed::new(backend_endpoint));
    let server = SmtpServer::new(resolver, smtp_config(port));

    tokio::spawn(async move {
        let _ = server.start().await;
    });

    // Give the listener a moment to bind.
    for _ in 0..50 {
        if TcpStream::connect(("127.0.0.1", port)).await.is_ok() {
            return Ok(port);
        }
        tokio::time::sleep(Duration::from_millis(20)).await;
    }
    Err(io::Error::other("SMTP server did not start"))
}

#[tokio::test]
async fn full_smtp_session_flows_end_to_end() {
    let (backend_listener, backend_endpoint) = bind_ephemeral().await.unwrap();
    spawn_backend(backend_listener);

    let port = spawn_server(backend_endpoint).await.unwrap();

    let mut client = TcpStream::connect(("127.0.0.1", port)).await.unwrap();

    // Greeting
    let greeting = read_chunk(&mut client).await.unwrap();
    assert!(
        greeting.starts_with("220 "),
        "expected 220 greeting, got: {greeting}"
    );

    // EHLO
    client.write_all(b"EHLO test\r\n").await.unwrap();
    let ehlo_reply = read_chunk(&mut client).await.unwrap();
    assert!(
        ehlo_reply.contains("250"),
        "expected 250 in EHLO reply, got: {ehlo_reply}"
    );

    // MAIL FROM
    client.write_all(b"MAIL FROM:<a@b.com>\r\n").await.unwrap();
    let mail_reply = read_chunk(&mut client).await.unwrap();
    assert!(
        mail_reply.starts_with("250"),
        "expected 250 after MAIL, got: {mail_reply}"
    );

    // RCPT TO
    client.write_all(b"RCPT TO:<c@d.com>\r\n").await.unwrap();
    let rcpt_reply = read_chunk(&mut client).await.unwrap();
    assert!(
        rcpt_reply.starts_with("250"),
        "expected 250 after RCPT, got: {rcpt_reply}"
    );

    // DATA
    client.write_all(b"DATA\r\n").await.unwrap();
    let data_reply = read_chunk(&mut client).await.unwrap();
    assert!(
        data_reply.starts_with("354"),
        "expected 354 after DATA, got: {data_reply}"
    );

    // Body + terminator
    client
        .write_all(b"Subject: Test\r\nHello World\r\n.\r\n")
        .await
        .unwrap();
    let body_reply = read_chunk(&mut client).await.unwrap();
    assert!(
        body_reply.starts_with("250"),
        "expected 250 after dot terminator, got: {body_reply}"
    );

    // QUIT
    client.write_all(b"QUIT\r\n").await.unwrap();
    let quit_reply = read_chunk(&mut client).await.unwrap();
    assert!(
        quit_reply.starts_with("221") || quit_reply.starts_with("250"),
        "expected QUIT reply, got: {quit_reply}"
    );
}

#[tokio::test]
async fn unknown_command_returns_500() {
    let (backend_listener, backend_endpoint) = bind_ephemeral().await.unwrap();
    spawn_backend(backend_listener);

    let port = spawn_server(backend_endpoint).await.unwrap();

    let client = TcpStream::connect(("127.0.0.1", port)).await.unwrap();
    let (read_half, mut write_half) = client.into_split();
    let mut reader = BufReader::new(read_half);

    // Consume greeting.
    let greeting = read_response(&mut reader).await.unwrap();
    assert!(greeting.starts_with("220 "));

    // Bogus command - must get 500 without touching the backend.
    write_half.write_all(b"FOOBAR hello\r\n").await.unwrap();
    let reply = read_response(&mut reader).await.unwrap();
    assert!(
        reply.starts_with("500"),
        "expected 500 for unknown command, got: {reply}"
    );
}
