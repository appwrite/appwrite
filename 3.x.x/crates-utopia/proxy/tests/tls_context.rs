//! Ports `tests/Unit/TLSTest.php` + `TLSContextTest.php`.

use std::fs;
use std::io::Write;

use tempfile_minimal as tmp;
use utopia_proxy::tls::{
    Tls, TlsContext, DEFAULT_CIPHERS, MIN_TLS_VERSION, MYSQL_CLIENT_SSL_FLAG, PG_SSL_REQUEST,
    PG_SSL_RESPONSE_OK, PG_SSL_RESPONSE_REJECT,
};

// Avoid bringing in a new dev-dep for temp files: implement a tiny shim.
mod tempfile_minimal {
    use std::path::PathBuf;
    pub struct NamedTemp(pub PathBuf);
    impl NamedTemp {
        pub fn new(prefix: &str) -> Self {
            let mut p = std::env::temp_dir();
            let nanos = std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_nanos();
            p.push(format!("{prefix}_{nanos}_{}", std::process::id()));
            Self(p)
        }
        pub fn path(&self) -> &std::path::Path {
            &self.0
        }
    }
    impl Drop for NamedTemp {
        fn drop(&mut self) {
            let _ = std::fs::remove_file(&self.0);
        }
    }
}

fn touch(prefix: &str) -> tmp::NamedTemp {
    let t = tmp::NamedTemp::new(prefix);
    let mut f = fs::File::create(t.path()).unwrap();
    f.write_all(b"-- test --\n").unwrap();
    t
}

#[test]
fn constructor_sets_required_paths() {
    let tls = Tls::new("/certs/server.crt", "/certs/server.key");
    assert_eq!(tls.certificate, "/certs/server.crt");
    assert_eq!(tls.key, "/certs/server.key");
}

#[test]
fn constructor_default_values() {
    let tls = Tls::new("/certs/server.crt", "/certs/server.key");
    assert_eq!(tls.ca, "");
    assert!(!tls.require_client_cert);
    assert_eq!(tls.ciphers, DEFAULT_CIPHERS);
    assert_eq!(tls.min_protocol, MIN_TLS_VERSION);
}

#[test]
fn constructor_custom_values() {
    let tls = Tls::new("/certs/server.crt", "/certs/server.key")
        .with_ca("/certs/ca.crt")
        .with_client_cert_required(true)
        .with_ciphers("ECDHE-RSA-AES128-GCM-SHA256")
        .with_min_protocol(64);

    assert_eq!(tls.ca, "/certs/ca.crt");
    assert!(tls.require_client_cert);
    assert_eq!(tls.ciphers, "ECDHE-RSA-AES128-GCM-SHA256");
    assert_eq!(tls.min_protocol, 64);
}

#[test]
fn pg_ssl_request_constant() {
    assert_eq!(PG_SSL_REQUEST.len(), 8);
    assert_eq!(
        PG_SSL_REQUEST,
        [0x00, 0x00, 0x00, 0x08, 0x04, 0xd2, 0x16, 0x2f]
    );
}

#[test]
fn pg_ssl_response_constants() {
    assert_eq!(PG_SSL_RESPONSE_OK, b'S');
    assert_eq!(PG_SSL_RESPONSE_REJECT, b'N');
}

#[test]
fn mysql_ssl_flag_constant() {
    assert_eq!(MYSQL_CLIENT_SSL_FLAG, 0x0000_0800);
}

#[test]
fn default_ciphers_contains_modern_suites() {
    assert!(DEFAULT_CIPHERS.contains("ECDHE-ECDSA-AES128-GCM-SHA256"));
    assert!(DEFAULT_CIPHERS.contains("ECDHE-RSA-AES256-GCM-SHA384"));
    assert!(DEFAULT_CIPHERS.contains("CHACHA20-POLY1305"));
}

#[test]
fn validate_passes_with_readable_files() {
    let cert = touch("cert_");
    let key = touch("key_");
    let tls = Tls::new(
        cert.path().to_string_lossy().to_string(),
        key.path().to_string_lossy().to_string(),
    );
    tls.validate().unwrap();
}

#[test]
fn validate_errors_for_unreadable_cert() {
    let tls = Tls::new("/nonexistent/cert.crt", "/tmp/key.key");
    let err = tls.validate().unwrap_err();
    assert!(err.contains("TLS certificate file not readable"));
}

#[test]
fn validate_errors_for_unreadable_key() {
    let cert = touch("cert_");
    let tls = Tls::new(
        cert.path().to_string_lossy().to_string(),
        "/nonexistent/key.key",
    );
    let err = tls.validate().unwrap_err();
    assert!(err.contains("TLS private key file not readable"));
}

#[test]
fn validate_errors_when_client_cert_required_but_no_ca_path() {
    let cert = touch("cert_");
    let key = touch("key_");
    let tls = Tls::new(
        cert.path().to_string_lossy().to_string(),
        key.path().to_string_lossy().to_string(),
    )
    .with_client_cert_required(true);
    let err = tls.validate().unwrap_err();
    assert!(err.contains("CA certificate path is required"));
}

#[test]
fn validate_errors_for_unreadable_ca_file() {
    let cert = touch("cert_");
    let key = touch("key_");
    let tls = Tls::new(
        cert.path().to_string_lossy().to_string(),
        key.path().to_string_lossy().to_string(),
    )
    .with_ca("/nonexistent/ca.crt");
    let err = tls.validate().unwrap_err();
    assert!(err.contains("TLS CA certificate file not readable"));
}

#[test]
fn validate_passes_with_all_readable_files() {
    let cert = touch("cert_");
    let key = touch("key_");
    let ca = touch("ca_");
    let tls = Tls::new(
        cert.path().to_string_lossy().to_string(),
        key.path().to_string_lossy().to_string(),
    )
    .with_ca(ca.path().to_string_lossy().to_string())
    .with_client_cert_required(true);
    tls.validate().unwrap();
}

#[test]
fn validate_ca_path_optional_without_client_cert() {
    let cert = touch("cert_");
    let key = touch("key_");
    let tls = Tls::new(
        cert.path().to_string_lossy().to_string(),
        key.path().to_string_lossy().to_string(),
    );
    tls.validate().unwrap();
}

#[test]
fn is_mutual_returns_true_when_both_conditions_met() {
    let tls = Tls::new("/certs/server.crt", "/certs/server.key")
        .with_ca("/certs/ca.crt")
        .with_client_cert_required(true);
    assert!(tls.is_mutual());
}

#[test]
fn is_mutual_returns_false_when_client_cert_not_required() {
    let tls = Tls::new("/certs/server.crt", "/certs/server.key").with_ca("/certs/ca.crt");
    assert!(!tls.is_mutual());
}

#[test]
fn is_mutual_returns_false_when_ca_path_empty() {
    let tls = Tls::new("/certs/server.crt", "/certs/server.key").with_client_cert_required(true);
    assert!(!tls.is_mutual());
}

#[test]
fn is_mutual_returns_false_with_defaults() {
    let tls = Tls::new("/certs/server.crt", "/certs/server.key");
    assert!(!tls.is_mutual());
}

#[test]
fn is_postgresql_ssl_request_with_valid_data() {
    assert!(Tls::is_postgresql_ssl_request(&PG_SSL_REQUEST));
}

#[test]
fn is_postgresql_ssl_request_with_too_short_data() {
    assert!(!Tls::is_postgresql_ssl_request(&[
        0x00, 0x00, 0x00, 0x08, 0x04, 0xd2, 0x16
    ]));
}

#[test]
fn is_postgresql_ssl_request_with_too_long_data() {
    let mut buf = PG_SSL_REQUEST.to_vec();
    buf.push(0);
    assert!(!Tls::is_postgresql_ssl_request(&buf));
}

#[test]
fn is_postgresql_ssl_request_with_empty_data() {
    assert!(!Tls::is_postgresql_ssl_request(b""));
}

#[test]
fn is_postgresql_ssl_request_with_wrong_bytes() {
    assert!(!Tls::is_postgresql_ssl_request(&[
        0x00, 0x00, 0x00, 0x08, 0x00, 0x00, 0x00, 0x00
    ]));
}

#[test]
fn is_postgresql_ssl_request_with_regular_startup_message() {
    let startup = [0x00, 0x00, 0x00, 0x08, 0x00, 0x03, 0x00, 0x00];
    assert!(!Tls::is_postgresql_ssl_request(&startup));
}

#[test]
fn is_mysql_ssl_request_with_valid_data() {
    let mut buf = vec![0u8; 36];
    buf[3] = 0x01;
    buf[4] = 0x00;
    buf[5] = 0x08;
    assert!(Tls::is_mysql_ssl_request(&buf));
}

#[test]
fn is_mysql_ssl_request_with_too_short_data() {
    assert!(!Tls::is_mysql_ssl_request(&[0u8; 35]));
}

#[test]
fn is_mysql_ssl_request_with_empty_data() {
    assert!(!Tls::is_mysql_ssl_request(b""));
}

#[test]
fn is_mysql_ssl_request_with_wrong_sequence_id() {
    let mut buf = vec![0u8; 36];
    buf[3] = 0x02;
    buf[4] = 0x00;
    buf[5] = 0x08;
    assert!(!Tls::is_mysql_ssl_request(&buf));
}

#[test]
fn is_mysql_ssl_request_without_ssl_flag() {
    let mut buf = vec![0u8; 36];
    buf[3] = 0x01;
    assert!(!Tls::is_mysql_ssl_request(&buf));
}

#[test]
fn is_mysql_ssl_request_with_ssl_flag_and_other_flags() {
    let mut buf = vec![0u8; 36];
    buf[3] = 0x01;
    buf[4] = 0xFF;
    buf[5] = 0x0F;
    assert!(Tls::is_mysql_ssl_request(&buf));
}

#[test]
fn is_mysql_ssl_request_with_sequence_id_zero() {
    let mut buf = vec![0u8; 36];
    buf[3] = 0x00;
    buf[4] = 0x00;
    buf[5] = 0x08;
    assert!(!Tls::is_mysql_ssl_request(&buf));
}

#[test]
fn is_mysql_ssl_request_with_exactly_36_bytes() {
    let mut buf = vec![0u8; 36];
    buf[3] = 0x01;
    buf[4] = 0x00;
    buf[5] = 0x08;
    assert!(Tls::is_mysql_ssl_request(&buf));
}

#[test]
fn is_mysql_ssl_request_with_larger_packet() {
    let mut buf = vec![0u8; 100];
    buf[3] = 0x01;
    buf[4] = 0x00;
    buf[5] = 0x08;
    assert!(Tls::is_mysql_ssl_request(&buf));
}

#[test]
fn tls_context_preserves_original_tls() {
    let tls = Tls::new("/certs/server.crt", "/certs/server.key");
    let ctx = TlsContext::new(tls.clone());
    assert_eq!(ctx.tls().certificate, tls.certificate);
    assert_eq!(ctx.tls().key, tls.key);
}
