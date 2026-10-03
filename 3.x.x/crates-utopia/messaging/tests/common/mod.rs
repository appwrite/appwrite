//! Mailpit SMTP target for messaging tests.
//!
//! Requires the compose/CI `mailpit` service (`MAIL_CATCHER_HOST` /
//! `MAIL_CATCHER_PORT`, default `127.0.0.1:11025`).

use std::net::TcpStream;
use std::net::ToSocketAddrs;
use std::time::{Duration, Instant};

/// Compose Mailpit host/port. Panics if the catcher is not reachable.
pub fn smtp_target() -> (String, u16) {
    let host = std::env::var("MAIL_CATCHER_HOST").unwrap_or_else(|_| "127.0.0.1".into());
    let port: u16 = std::env::var("MAIL_CATCHER_PORT")
        .ok()
        .and_then(|value| value.parse().ok())
        .unwrap_or(11025);
    let deadline = Instant::now() + Duration::from_secs(30);
    while Instant::now() < deadline {
        if (host.as_str(), port)
            .to_socket_addrs()
            .ok()
            .into_iter()
            .flatten()
            .find_map(|addr| TcpStream::connect_timeout(&addr, Duration::from_millis(200)).ok())
            .is_some()
        {
            return (host, port);
        }
        std::thread::sleep(Duration::from_millis(100));
    }
    panic!(
        "Mailpit is not reachable at {host}:{port}. Start it with:\n  docker compose -f docker-compose.test.yml up -d mailpit"
    );
}
