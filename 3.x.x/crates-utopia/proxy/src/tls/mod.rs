//! TLS configuration + protocol-specific detection. Mirrors `src/Server/TCP/TLS.php`.

pub mod context;

use serde_json::json;
use utopia_validators::{Text, Validator};

pub use context::TlsContext;

/// PostgreSQL SSLRequest message (8 bytes): Int32(8) length + Int32(80877103) code.
pub const PG_SSL_REQUEST: [u8; 8] = [0x00, 0x00, 0x00, 0x08, 0x04, 0xd2, 0x16, 0x2f];

/// PostgreSQL SSLResponse: server willing to accept SSL.
pub const PG_SSL_RESPONSE_OK: u8 = b'S';

/// PostgreSQL SSLResponse: server unwilling to accept SSL.
pub const PG_SSL_RESPONSE_REJECT: u8 = b'N';

/// MySQL CLIENT_SSL capability flag (0x00000800).
pub const MYSQL_CLIENT_SSL_FLAG: u32 = 0x0000_0800;

/// Default cipher suites - strong, modern, broadly compatible.
pub const DEFAULT_CIPHERS: &str = concat!(
    "ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:",
    "ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:",
    "ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:",
    "DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384"
);

/// Numeric minimum protocol value matching PHP `TLS::MIN_TLS_VERSION = 32` (TLS 1.2).
pub const MIN_TLS_VERSION: u16 = 32;

/// TLS configuration for TCP proxy server. Readonly-style - fields are public and the
/// struct itself is constructed once and cloned.
#[derive(Debug, Clone)]
pub struct Tls {
    pub certificate: String,
    pub key: String,
    pub ca: String,
    pub require_client_cert: bool,
    pub ciphers: String,
    pub min_protocol: u16,
}

impl Tls {
    pub fn new(certificate: impl Into<String>, key: impl Into<String>) -> Self {
        Self {
            certificate: certificate.into(),
            key: key.into(),
            ca: String::new(),
            require_client_cert: false,
            ciphers: DEFAULT_CIPHERS.to_string(),
            min_protocol: MIN_TLS_VERSION,
        }
    }

    pub fn with_ca(mut self, ca: impl Into<String>) -> Self {
        self.ca = ca.into();
        self
    }

    pub fn with_client_cert_required(mut self, required: bool) -> Self {
        self.require_client_cert = required;
        self
    }

    pub fn with_ciphers(mut self, ciphers: impl Into<String>) -> Self {
        self.ciphers = ciphers.into();
        self
    }

    pub fn with_min_protocol(mut self, min_protocol: u16) -> Self {
        self.min_protocol = min_protocol;
        self
    }

    /// Validate that configured certificate files exist and are readable.
    pub fn validate(&self) -> Result<(), String> {
        let path = Text::new(4096);
        if !path.is_valid(&json!(self.certificate)) {
            return Err(format!(
                "TLS certificate path is invalid: {}",
                path.description()
            ));
        }
        if self.certificate.is_empty() {
            return Err("TLS certificate path is empty".to_string());
        }
        if !std::path::Path::new(&self.certificate).is_file() {
            return Err(format!(
                "TLS certificate file not readable: {}",
                self.certificate
            ));
        }
        if self.key.is_empty() {
            return Err("TLS key path is empty".to_string());
        }
        if !path.is_valid(&json!(self.key)) {
            return Err(format!("TLS key path is invalid: {}", path.description()));
        }
        if !std::path::Path::new(&self.key).is_file() {
            return Err(format!("TLS private key file not readable: {}", self.key));
        }
        if self.require_client_cert && self.ca.is_empty() {
            return Err(
                "CA certificate path is required when client certificate verification is enabled"
                    .to_string(),
            );
        }
        if !self.ca.is_empty() && !std::path::Path::new(&self.ca).is_file() {
            return Err(format!("TLS CA certificate file not readable: {}", self.ca));
        }
        Ok(())
    }

    /// True when client certificate verification is required and a CA is configured.
    pub fn is_mutual(&self) -> bool {
        self.require_client_cert && !self.ca.is_empty()
    }

    /// Detect PostgreSQL SSLRequest: exactly 8 bytes equal to `PG_SSL_REQUEST`.
    pub fn is_postgresql_ssl_request(data: &[u8]) -> bool {
        data.len() == 8 && data == PG_SSL_REQUEST
    }

    /// Detect MySQL SSL handshake request. Checks:
    /// - packet length ≥ 36
    /// - sequence ID (byte 3) == 1
    /// - capability flags (LE u16 at offset 4) include CLIENT_SSL (0x0800).
    pub fn is_mysql_ssl_request(data: &[u8]) -> bool {
        if data.len() < 36 {
            return false;
        }
        if data[3] != 1 {
            return false;
        }
        let cap_low = u16::from(data[4]) | (u16::from(data[5]) << 8);
        (u32::from(cap_low) & MYSQL_CLIENT_SSL_FLAG) != 0
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn detects_postgresql_ssl_request() {
        assert!(Tls::is_postgresql_ssl_request(&PG_SSL_REQUEST));
        assert!(!Tls::is_postgresql_ssl_request(&[0u8; 8]));
        assert!(!Tls::is_postgresql_ssl_request(&[0u8; 7]));
    }

    #[test]
    fn detects_mysql_ssl_request() {
        let mut buf = vec![0u8; 36];
        buf[3] = 1;
        buf[4] = 0x00;
        buf[5] = 0x08; // CLIENT_SSL bit in the high byte of low word
        assert!(Tls::is_mysql_ssl_request(&buf));

        buf[3] = 0;
        assert!(!Tls::is_mysql_ssl_request(&buf));
    }
}
