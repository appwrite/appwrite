//! WireMock container client for Utopia tests.
//!
//! Requires the compose/CI `wiremock` service (`WIREMOCK_URL`, default
//! `http://127.0.0.1:8089`).

use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::thread::{self, JoinHandle};
use std::time::{Duration, Instant};

use once_cell::sync::OnceCell;
use parking_lot::Mutex;
use serde_json::Value;
use tokio::sync::{OwnedSemaphorePermit, Semaphore};
use url::Url;

use crate::respond::{serve_respond, RecordedRequest, Respond};

static GLOBAL: OnceCell<GlobalWireMock> = OnceCell::new();

struct GlobalWireMock {
    base_url: String,
    gate: Arc<Semaphore>,
}

struct Backend {
    _join: JoinHandle<()>,
    stop: Arc<AtomicBool>,
    _respond: Arc<dyn Respond>,
}

/// Exclusive access to the shared WireMock container for one test.
pub struct MockServer {
    base_url: String,
    client: reqwest::Client,
    _permit: OwnedSemaphorePermit,
    backends: Mutex<Vec<Backend>>,
}

impl std::fmt::Debug for MockServer {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("MockServer")
            .field("base_url", &self.base_url)
            .finish_non_exhaustive()
    }
}

impl MockServer {
    /// Connect to the WireMock container at `WIREMOCK_URL`.
    pub async fn start() -> Self {
        let global = tokio::task::spawn_blocking(|| GLOBAL.get_or_init(bootstrap))
            .await
            .expect("WireMock bootstrap task");
        let permit = Arc::clone(&global.gate)
            .acquire_owned()
            .await
            .expect("wiremock semaphore");
        let client = reqwest::Client::builder()
            .timeout(Duration::from_secs(30))
            .build()
            .expect("wiremock http client");
        let server = Self {
            base_url: global.base_url.clone(),
            client,
            _permit: permit,
            backends: Mutex::new(Vec::new()),
        };
        server.reset().await;
        server
    }

    #[must_use]
    pub fn uri(&self) -> String {
        self.base_url.trim_end_matches('/').to_string()
    }

    pub async fn reset(&self) {
        let _ = self
            .client
            .post(format!("{}/__admin/reset", self.uri()))
            .send()
            .await;
    }

    pub async fn received_requests(&self) -> Option<Vec<RecordedRequest>> {
        let response = self
            .client
            .get(format!("{}/__admin/requests", self.uri()))
            .send()
            .await
            .ok()?;
        let body: Value = response.json().await.ok()?;
        let requests = body.get("requests")?.as_array()?.clone();
        Some(
            requests
                .into_iter()
                .rev()
                .filter_map(|entry| {
                    let req = entry.get("request")?;
                    RecordedRequest::from_wiremock(req)
                })
                .collect(),
        )
    }

    pub(crate) async fn post_mapping(&self, mapping: Value) {
        let response = self
            .client
            .post(format!("{}/__admin/mappings", self.uri()))
            .json(&mapping)
            .send()
            .await
            .expect("wiremock mapping POST");
        assert!(
            response.status().is_success(),
            "wiremock mapping failed: {} {}",
            response.status(),
            response.text().await.unwrap_or_default()
        );
    }

    pub(crate) async fn mount_respond_with(&self, request: Value, respond: Arc<dyn Respond>) {
        let (backend_url, join, stop) = serve_respond(Arc::clone(&respond));
        let proxy_url = rewrite_callback_url(&backend_url);
        let mut mapping = serde_json::Map::new();
        mapping.insert("priority".into(), serde_json::json!(1));
        mapping.insert("request".into(), request);
        mapping.insert(
            "response".into(),
            serde_json::json!({ "proxyBaseUrl": proxy_url }),
        );
        self.post_mapping(Value::Object(mapping)).await;
        self.backends.lock().push(Backend {
            _join: join,
            stop,
            _respond: respond,
        });
    }
}

impl Drop for MockServer {
    fn drop(&mut self) {
        for backend in self.backends.lock().drain(..) {
            backend.stop.store(true, Ordering::SeqCst);
            let _ = backend;
        }
    }
}

fn bootstrap() -> GlobalWireMock {
    let base = std::env::var("WIREMOCK_URL").unwrap_or_else(|_| "http://127.0.0.1:8089".into());
    let base = base.trim_end_matches('/').to_string();
    let deadline = Instant::now() + Duration::from_secs(30);
    while Instant::now() < deadline {
        if reachable(&base) {
            return GlobalWireMock {
                base_url: base,
                gate: Arc::new(Semaphore::new(1)),
            };
        }
        thread::sleep(Duration::from_millis(100));
    }
    panic!(
        "WireMock is not reachable at {base}. Start it with:\n  docker compose -f docker-compose.test.yml up -d wiremock"
    );
}

fn reachable(base: &str) -> bool {
    let Ok(url) = Url::parse(&format!("{base}/__admin/mappings")) else {
        return false;
    };
    let host = url.host_str().unwrap_or("127.0.0.1");
    let port = url.port_or_known_default().unwrap_or(80);
    let Ok(addrs) = std::net::ToSocketAddrs::to_socket_addrs(&(host, port)) else {
        return false;
    };
    for addr in addrs {
        if let Ok(mut stream) =
            std::net::TcpStream::connect_timeout(&addr, Duration::from_millis(200))
        {
            use std::io::{Read, Write};
            let _ = stream.set_read_timeout(Some(Duration::from_millis(400)));
            let _ = stream.set_write_timeout(Some(Duration::from_millis(400)));
            let request = format!(
                "GET /__admin/mappings HTTP/1.1\r\nHost: {host}\r\nConnection: close\r\n\r\n"
            );
            if stream.write_all(request.as_bytes()).is_err() {
                continue;
            }
            let mut buf = [0u8; 128];
            if stream
                .read(&mut buf)
                .ok()
                .is_some_and(|n| std::str::from_utf8(&buf[..n]).is_ok_and(|s| s.contains("200")))
            {
                return true;
            }
        }
    }
    false
}

fn rewrite_callback_url(backend_url: &str) -> String {
    // WireMock-in-Docker cannot reach the test process via 127.0.0.1.
    let Some(host) = std::env::var("WIREMOCK_CALLBACK_HOST").ok() else {
        return backend_url.to_string();
    };
    let Ok(mut url) = Url::parse(backend_url) else {
        return backend_url.to_string();
    };
    let _ = url.set_host(Some(&host));
    url.to_string()
}
