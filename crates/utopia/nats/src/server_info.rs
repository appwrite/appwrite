//! What the server announces in its `INFO` line.

use serde_json::Value;

/// The server's `INFO`: identity, limits and capabilities.
///
/// PHP: `Utopia\NATS\ServerInfo` (public readonly properties, same names in
/// snake case).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ServerInfo {
    /// `server_id`.
    pub server_id: String,
    /// `server_name`.
    pub server_name: String,
    /// `version`.
    pub version: String,
    /// `proto`.
    pub proto: i64,
    /// `host`.
    pub host: String,
    /// `port`.
    pub port: i64,
    /// `headers`: whether `HPUB`/`HMSG` are supported.
    pub headers_supported: bool,
    /// `auth_required`.
    pub auth_required: bool,
    /// `tls_required`.
    pub tls_required: bool,
    /// `tls_available`.
    pub tls_available: bool,
    /// `max_payload` (1 MiB when absent).
    pub max_payload: i64,
    /// `connect_urls`: other cluster members.
    pub connect_urls: Vec<String>,
    /// `nonce`, signed by NKey authentication.
    pub nonce: Option<String>,
    /// `jetstream`.
    pub jetstream: bool,
    /// `client_id`.
    pub client_id: Option<i64>,
    /// `client_ip`.
    pub client_ip: Option<String>,
}

impl ServerInfo {
    /// Reads the fields of a decoded `INFO` object; missing (or `null`)
    /// fields take their defaults.
    ///
    /// PHP: `ServerInfo::fromArray()`.
    pub fn from_value(data: &Value) -> ServerInfo {
        let field = |key: &str| data.get(key).filter(|v| !v.is_null());
        let string = |key: &str| field(key).and_then(Value::as_str).unwrap_or_default().to_owned();
        let int = |key: &str, default: i64| field(key).and_then(Value::as_i64).unwrap_or(default);
        let flag = |key: &str| field(key).and_then(Value::as_bool).unwrap_or(false);
        ServerInfo {
            server_id: string("server_id"),
            server_name: string("server_name"),
            version: string("version"),
            proto: int("proto", 0),
            host: string("host"),
            port: int("port", 0),
            headers_supported: flag("headers"),
            auth_required: flag("auth_required"),
            tls_required: flag("tls_required"),
            tls_available: flag("tls_available"),
            max_payload: int("max_payload", 1_048_576),
            connect_urls: field("connect_urls")
                .and_then(Value::as_array)
                .map(|urls| urls.iter().filter_map(Value::as_str).map(str::to_owned).collect())
                .unwrap_or_default(),
            nonce: field("nonce").and_then(Value::as_str).map(str::to_owned),
            jetstream: flag("jetstream"),
            client_id: field("client_id").and_then(Value::as_i64),
            client_ip: field("client_ip").and_then(Value::as_str).map(str::to_owned),
        }
    }
}
