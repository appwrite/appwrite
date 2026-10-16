//! Ports `tests/Unit/AdapterMetadataTest.php`.

mod common;

use std::sync::Arc;

use common::MockResolver;
use utopia_proxy::adapter::Adapter;
use utopia_proxy::{Protocol, Resolver, TcpAdapter};

#[test]
fn http_adapter_metadata() {
    let resolver: Arc<dyn Resolver> = Arc::new(MockResolver::new());
    let adapter = Adapter::new(Some(resolver), Protocol::Http);
    assert_eq!(adapter.protocol(), Protocol::Http);
}

#[test]
fn smtp_adapter_metadata() {
    let resolver: Arc<dyn Resolver> = Arc::new(MockResolver::new());
    let adapter = Adapter::new(Some(resolver), Protocol::Smtp);
    assert_eq!(adapter.protocol(), Protocol::Smtp);
}

#[test]
fn tcp_adapter_metadata() {
    let resolver: Arc<dyn Resolver> = Arc::new(MockResolver::new());
    let adapter = TcpAdapter::new(5432, Some(resolver));
    assert_eq!(adapter.protocol(), Protocol::PostgreSQL);
    assert_eq!(adapter.port(), 5432);
}
