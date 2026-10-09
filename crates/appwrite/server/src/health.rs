//! `GET /v1/health/version` and the container healthcheck probe.

use std::io::{Read, Write};
use std::net::TcpStream;
use std::time::Duration;

use appwrite_core::Result;
use appwrite_core::action;
use appwrite_core::platform::{Context, Module, Route};
use utopia_http::{Method, Response};

pub struct Health;

async fn version(ctx: &mut Context) -> Result<Response> {
    Ok(ctx.ok(&serde_json::json!({ "version": appwrite_core::VERSION })))
}

impl Module for Health {
    fn name(&self) -> &'static str {
        "health"
    }

    fn routes(&self) -> Vec<Route> {
        vec![Route {
            method: Method::GET,
            path: "/v1/health/version",
            name: "getVersion",
            namespace: "health",
            sdk_method: "getVersion",
            scopes: &["public"],
            event: None,
            audit: None,
            groups: &["api", "health"],
            action: action!(version),
        }]
    }
}

/// Blocking probe used by `appwrite-rust health` (no curl needed in the image).
pub fn probe() -> i32 {
    let port = utopia_system::env_int("_APP_RUST_PORT", 8080);
    let Ok(mut stream) = TcpStream::connect(("127.0.0.1", port as u16)) else { return 1 };
    let _ = stream.set_read_timeout(Some(Duration::from_secs(3)));
    let request = "GET /v1/health/version HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
    if stream.write_all(request.as_bytes()).is_err() {
        return 1;
    }
    let mut buf = [0u8; 64];
    match stream.read(&mut buf) {
        Ok(n) if n >= 12 && &buf[9..12] == b"200" => 0,
        _ => 1,
    }
}
