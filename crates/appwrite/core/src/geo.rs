//! Geo lookups through the `appwrite-geo` service (`Appwrite\Geo\Geo`).

use std::collections::HashMap;
use std::net::IpAddr;
use std::sync::Mutex;
use std::time::Duration;

use bytes::Bytes;
use http_body_util::{BodyExt, Empty};
use hyper_util::client::legacy::Client;
use hyper_util::client::legacy::connect::HttpConnector;
use hyper_util::rt::TokioExecutor;
use serde_json::Value;

const CACHE_SIZE: usize = 10_000;

/// Country lookups with an in-process cache.
pub struct Geo {
    endpoint: Option<String>,
    secret: String,
    client: Client<HttpConnector, Empty<Bytes>>,
    cache: Mutex<HashMap<String, String>>,
}

impl Geo {
    pub fn new(endpoint: Option<String>, secret: Option<String>) -> Self {
        let mut connector = HttpConnector::new();
        connector.set_connect_timeout(Some(Duration::from_secs(3)));
        connector.set_nodelay(true);
        let client = Client::builder(TokioExecutor::new()).pool_max_idle_per_host(64).build(connector);
        let enabled = endpoint.is_some() && secret.is_some();
        Self {
            endpoint: if enabled { endpoint.map(|e| e.trim_end_matches('/').to_owned()) } else { None },
            secret: secret.unwrap_or_default(),
            client,
            cache: Mutex::new(HashMap::new()),
        }
    }

    /// Upper-case ISO country code, or `--` when unknown.
    pub async fn country_code(&self, ip: &str) -> String {
        let ip = if ip.parse::<IpAddr>().is_ok() { ip } else { "0.0.0.0" };
        if let Some(code) = self.cache.lock().ok().and_then(|c| c.get(ip).cloned()) {
            return code;
        }
        let Some(endpoint) = &self.endpoint else {
            return "--".to_owned();
        };
        let lookup = async {
            let request = http::Request::get(format!("{endpoint}/ips/{ip}"))
                .header("authorization", format!("Bearer {}", self.secret))
                .body(Empty::<Bytes>::new())
                .ok()?;
            let response = self.client.request(request).await.ok()?;
            if response.status() != 200 {
                return None;
            }
            let body = response.into_body().collect().await.ok()?.to_bytes();
            let json: Value = serde_json::from_slice(&body).ok()?;
            Some(json.get("countryCode").and_then(Value::as_str).unwrap_or("--").to_uppercase())
        };
        match tokio::time::timeout(Duration::from_secs(3), lookup).await {
            Ok(Some(code)) => {
                if let Ok(mut cache) = self.cache.lock() {
                    if cache.len() >= CACHE_SIZE {
                        cache.clear();
                    }
                    cache.insert(ip.to_owned(), code.clone());
                }
                code
            }
            _ => "--".to_owned(),
        }
    }
}
