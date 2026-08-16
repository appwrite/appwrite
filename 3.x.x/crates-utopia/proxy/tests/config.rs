//! Ports `tests/Unit/ConfigTest.php`.

use std::sync::Arc;
use std::time::Duration;

use utopia_proxy::server::tcp::TcpConfig;
use utopia_proxy::tls::Tls;

#[test]
fn default_host() {
    let config = TcpConfig::new(vec![5432]);
    assert_eq!(config.host, "0.0.0.0");
}

#[test]
fn ports_are_stored() {
    let config = TcpConfig::new(vec![5432, 3306]);
    assert_eq!(config.ports, vec![5432, 3306]);
}

#[test]
fn default_workers_matches_cpu_count() {
    let config = TcpConfig::new(vec![5432]);
    let cpus = std::thread::available_parallelism()
        .map(|n| n.get())
        .unwrap_or(1);
    assert_eq!(config.workers, cpus);
}

#[test]
fn default_max_connections() {
    let config = TcpConfig::new(vec![5432]);
    assert_eq!(config.max_connections, 200_000);
}

#[test]
fn default_max_coroutine_is_double_max_connections() {
    let config = TcpConfig::new(vec![5432]);
    assert_eq!(config.max_coroutine, 400_000);
}

#[test]
fn default_buffer_sizes_are_right_sized() {
    let config = TcpConfig::new(vec![5432]);
    // Kernel per-socket bounds deliberately tight so high-fan-in idle
    // connections don't balloon RSS - Swoole parity choice.
    assert_eq!(config.socket_buffer_size, 32 * 1024);
    assert_eq!(config.buffer_output_size, 32 * 1024);
}

#[test]
fn default_reactor_num_matches_cpu_count() {
    let config = TcpConfig::new(vec![5432]);
    let cpus = std::thread::available_parallelism()
        .map(|n| n.get())
        .unwrap_or(1);
    assert_eq!(config.reactor_num, cpus);
}

#[test]
fn default_enable_reuse_port() {
    let config = TcpConfig::new(vec![5432]);
    assert!(config.enable_reuse_port);
}

#[test]
fn default_backlog() {
    let config = TcpConfig::new(vec![5432]);
    assert_eq!(config.backlog, 65535);
}

#[test]
fn default_package_max_length() {
    let config = TcpConfig::new(vec![5432]);
    assert_eq!(config.package_max_length, 32 * 1024 * 1024);
}

#[test]
fn default_tcp_keepalive_settings() {
    let config = TcpConfig::new(vec![5432]);
    assert_eq!(config.tcp_keepidle, 30);
    assert_eq!(config.tcp_keepinterval, 10);
    assert_eq!(config.tcp_keepcount, 3);
}

#[test]
fn default_tcp_user_timeout_ms() {
    let config = TcpConfig::new(vec![5432]);
    assert_eq!(config.tcp_user_timeout_ms, 10_000);
}

#[test]
fn default_tcp_quickack() {
    let config = TcpConfig::new(vec![5432]);
    assert!(config.tcp_quickack);
}

#[test]
fn default_gc_interval_ms() {
    let config = TcpConfig::new(vec![5432]);
    assert_eq!(config.gc_interval_ms, 5_000);
}

#[test]
fn default_dns_cache_ttl() {
    let config = TcpConfig::new(vec![5432]);
    assert_eq!(config.dns_cache_ttl, 60);
}

#[test]
fn default_tcp_notsent_lowat() {
    let config = TcpConfig::new(vec![5432]);
    assert_eq!(config.tcp_notsent_lowat, 16_384);
}

#[test]
fn default_enable_coroutine() {
    let config = TcpConfig::new(vec![5432]);
    assert!(config.enable_coroutine);
}

#[test]
fn default_max_wait_time() {
    let config = TcpConfig::new(vec![5432]);
    assert_eq!(config.max_wait_time, 60);
}

#[test]
fn default_log_connections() {
    let config = TcpConfig::new(vec![5432]);
    assert!(!config.log_connections);
}

#[test]
fn default_receive_buffer_size() {
    let config = TcpConfig::new(vec![5432]);
    // Small userspace scratch - kernel handles real buffering via SO_RCVBUF.
    assert_eq!(config.receive_buffer_size, 8_192);
}

#[test]
fn default_connect_timeout() {
    let config = TcpConfig::new(vec![5432]);
    assert_eq!(config.connect_timeout, Duration::from_secs(5));
}

#[test]
fn default_skip_validation() {
    let config = TcpConfig::new(vec![5432]);
    assert!(!config.skip_validation);
}

#[test]
fn default_tls_is_none() {
    let config = TcpConfig::new(vec![5432]);
    assert!(config.tls.is_none());
}

#[test]
fn custom_reactor_num() {
    let config = TcpConfig::new(vec![5432]).with_reactor_num(4);
    assert_eq!(config.reactor_num, 4);
}

#[test]
fn custom_host() {
    let config = TcpConfig::new(vec![5432]).with_host("127.0.0.1");
    assert_eq!(config.host, "127.0.0.1");
}

#[test]
fn custom_workers() {
    let config = TcpConfig::new(vec![5432]).with_workers(4);
    assert_eq!(config.workers, 4);
}

#[test]
fn custom_connect_timeout() {
    let config = TcpConfig::new(vec![5432]).with_connect_timeout(Duration::from_millis(10_500));
    assert_eq!(config.connect_timeout, Duration::from_millis(10_500));
}

#[test]
fn custom_skip_validation() {
    let config = TcpConfig::new(vec![5432]).with_skip_validation(true);
    assert!(config.skip_validation);
}

#[test]
fn is_tls_enabled_false_by_default() {
    let config = TcpConfig::new(vec![5432]);
    assert!(!config.is_tls_enabled());
}

#[test]
fn is_tls_enabled_true_when_configured() {
    let tls = Arc::new(Tls::new("/certs/server.crt", "/certs/server.key"));
    let config = TcpConfig::new(vec![5432]).with_tls(tls);
    assert!(config.is_tls_enabled());
}
