//! TLS context - builds a rustls `ServerConfig` from a `Tls`. Mirrors
//! `src/Server/TCP/TLSContext.php` (the `toSwooleConfig()` equivalent).

use std::fs::File;
use std::io::BufReader;
use std::sync::Arc;

use rustls::server::{NoClientAuth, WebPkiClientVerifier};
use rustls::{RootCertStore, ServerConfig};
use rustls_pki_types::{CertificateDer, PrivateKeyDer};

use super::Tls;

/// Build a rustls `ServerConfig` from a `Tls`.
#[derive(Debug)]
pub struct TlsContext {
    tls: Tls,
}

impl TlsContext {
    pub fn new(tls: Tls) -> Self {
        Self { tls }
    }

    pub fn tls(&self) -> &Tls {
        &self.tls
    }

    /// Build a `rustls::ServerConfig` ready for a tokio-rustls acceptor. When
    /// `require_client_cert` is set, uses `WebPkiClientVerifier` with the CA store
    /// for mTLS enforcement.
    pub fn rustls_server_config(&self) -> Result<Arc<ServerConfig>, String> {
        let certs = load_certs(&self.tls.certificate)?;
        let key = load_private_key(&self.tls.key)?;

        let builder = ServerConfig::builder();
        let builder = if self.tls.require_client_cert && !self.tls.ca.is_empty() {
            let mut roots = RootCertStore::empty();
            let ca_certs = load_certs(&self.tls.ca)?;
            for cert in ca_certs {
                roots
                    .add(cert)
                    .map_err(|e| format!("failed to add CA cert: {e}"))?;
            }
            let verifier = WebPkiClientVerifier::builder(Arc::new(roots))
                .build()
                .map_err(|e| format!("failed to build client verifier: {e}"))?;
            builder.with_client_cert_verifier(verifier)
        } else {
            builder.with_client_cert_verifier(Arc::new(NoClientAuth))
        };

        let config = builder
            .with_single_cert(certs, key)
            .map_err(|e| format!("failed to install server cert: {e}"))?;

        Ok(Arc::new(config))
    }
}

fn load_certs(path: &str) -> Result<Vec<CertificateDer<'static>>, String> {
    let file = File::open(path).map_err(|e| format!("open {path}: {e}"))?;
    let mut reader = BufReader::new(file);
    rustls_pemfile::certs(&mut reader)
        .collect::<Result<Vec<_>, _>>()
        .map_err(|e| format!("parse certs from {path}: {e}"))
}

fn load_private_key(path: &str) -> Result<PrivateKeyDer<'static>, String> {
    let file = File::open(path).map_err(|e| format!("open {path}: {e}"))?;
    let mut reader = BufReader::new(file);
    rustls_pemfile::private_key(&mut reader)
        .map_err(|e| format!("parse key from {path}: {e}"))?
        .ok_or_else(|| format!("no private key found in {path}"))
}
