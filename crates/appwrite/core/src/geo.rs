//! Geo lookups through the `appwrite-geo` service (`Appwrite\Geo\Geo`).

use std::collections::HashMap;
use std::net::IpAddr;
use std::sync::{Arc, Mutex};
use std::time::Duration;

use bytes::Bytes;
use http_body_util::{BodyExt, Empty};
use hyper_util::client::legacy::Client;
use hyper_util::client::legacy::connect::HttpConnector;
use hyper_util::rt::TokioExecutor;
use serde_json::Value;

const CACHE_SIZE: usize = 10_000;

/// What `Geo::get()` knows about an IP (`Appwrite\Locale\GeoRecord`), as
/// `Geo::build()` shapes the service's record.
#[derive(Debug, Clone, Default)]
pub struct GeoRecord {
    /// Upper case, or `--` when unknown.
    pub country_code: String,
    /// Upper case, or `--` when unknown.
    pub continent_code: String,
    pub latitude: Option<f64>,
    pub longitude: Option<f64>,
    pub time_zone: Option<String>,
    pub weather_code: Option<String>,
    pub postal_code: Option<String>,
    pub autonomous_system_number: Option<String>,
    pub autonomous_system_organization: Option<String>,
    pub connection_type: Option<String>,
    pub connection_usage_type: Option<String>,
    pub connection_organization: Option<String>,
    pub isp: Option<String>,
}

impl GeoRecord {
    fn unknown() -> Self {
        Self { country_code: "--".into(), continent_code: "--".into(), ..Self::default() }
    }

    /// `Geo::build($ip, $record)`.
    fn build(record: &Value) -> Self {
        let text = |key: &str| match record.get(key) {
            Some(Value::String(s)) => Some(s.clone()),
            Some(Value::Number(n)) => Some(n.to_string()),
            _ => None,
        };
        let float = |key: &str| match record.get(key) {
            Some(Value::Number(n)) => n.as_f64(),
            Some(Value::String(s)) => s.parse().ok(),
            _ => None,
        };
        let upper = |key: &str| text(key).unwrap_or_else(|| "--".into()).to_uppercase();
        Self {
            country_code: upper("countryCode"),
            continent_code: upper("continentCode"),
            latitude: float("latitude"),
            longitude: float("longitude"),
            time_zone: text("timeZone"),
            weather_code: text("weatherCode"),
            postal_code: text("postalCode"),
            autonomous_system_number: text("autonomousSystemNumber"),
            autonomous_system_organization: text("autonomousSystemOrganization"),
            connection_type: text("connection"),
            connection_usage_type: text("user").or_else(|| text("type")),
            connection_organization: text("organization"),
            isp: text("isp"),
        }
    }
}

/// Lookups with an in-process cache.
pub struct Geo {
    endpoint: Option<String>,
    secret: String,
    client: Client<HttpConnector, Empty<Bytes>>,
    cache: Mutex<HashMap<String, Arc<GeoRecord>>>,
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
        self.get(ip).await.country_code.clone()
    }

    /// `Geo::get($ip)`.
    pub async fn get(&self, ip: &str) -> Arc<GeoRecord> {
        let ip = if ip.parse::<IpAddr>().is_ok() { ip } else { "0.0.0.0" };
        if let Some(record) = self.cache.lock().ok().and_then(|c| c.get(ip).cloned()) {
            return record;
        }
        let Some(endpoint) = &self.endpoint else {
            return Arc::new(GeoRecord::unknown());
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
            match &json {
                Value::Object(m) if !m.is_empty() => Some(GeoRecord::build(&json)),
                _ => None,
            }
        };
        match tokio::time::timeout(Duration::from_secs(3), lookup).await {
            Ok(Some(record)) => {
                let record = Arc::new(record);
                if let Ok(mut cache) = self.cache.lock() {
                    if cache.len() >= CACHE_SIZE {
                        cache.clear();
                    }
                    cache.insert(ip.to_owned(), record.clone());
                }
                record
            }
            _ => Arc::new(GeoRecord::unknown()),
        }
    }
}
