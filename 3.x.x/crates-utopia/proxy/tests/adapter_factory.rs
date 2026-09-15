//! Ports `tests/Unit/AdapterFactoryTest.php`.

use std::sync::Arc;

use utopia_proxy::server::tcp::TcpConfig;
use utopia_proxy::{Fixed, Resolver, TcpAdapter};

#[test]
fn default_adapter_factory_is_none() {
    let config = TcpConfig::new(vec![5432]);
    assert!(config.adapter_factory.is_none());
}

#[test]
fn adapter_factory_accepts_closure() {
    let config = TcpConfig::new(vec![5432])
        .with_adapter_factory(|port, resolver| TcpAdapter::new(port, Some(resolver)));
    assert!(config.adapter_factory.is_some());
}

#[test]
fn adapter_factory_closure_is_invokable() {
    let config = TcpConfig::new(vec![5432])
        .with_adapter_factory(|port, resolver| TcpAdapter::new(port, Some(resolver)));
    let factory = config.adapter_factory.as_ref().unwrap();
    let resolver: Arc<dyn Resolver> = Arc::new(Fixed::new("host:5432"));
    let adapter = factory(5432, resolver);
    assert_eq!(adapter.port(), 5432);
}

#[test]
fn other_config_values_preserved_with_factory() {
    let config = TcpConfig::new(vec![5432])
        .with_host("127.0.0.1")
        .with_workers(8)
        .with_adapter_factory(|port, resolver| TcpAdapter::new(port, Some(resolver)));

    assert_eq!(config.host, "127.0.0.1");
    assert_eq!(config.ports, vec![5432]);
    assert_eq!(config.workers, 8);
    assert!(config.adapter_factory.is_some());
}

#[test]
fn none_adapter_factory_preserves_defaults() {
    let config = TcpConfig::new(vec![5432]);
    assert!(config.adapter_factory.is_none());
    assert_eq!(config.host, "0.0.0.0");
    assert_eq!(config.ports, vec![5432]);
}
