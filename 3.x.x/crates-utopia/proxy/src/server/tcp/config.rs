//! TCP server configuration. Mirrors `src/Server/TCP/Config.php` field-for-field.

use std::path::PathBuf;
use std::sync::Arc;
use std::time::Duration;

use crate::resolver::Resolver;
use crate::tls::Tls;

use super::server::AdapterFactory;

/// Typed configuration for the TCP proxy server.
///
/// Fields mirror `src/Server/TCP/Config.php`. Defaults are computed against
/// the host CPU count where the PHP version uses `swoole_cpu_num()`.
pub struct TcpConfig {
    pub ports: Vec<u16>,
    pub host: String,
    pub workers: usize,
    pub max_connections: u32,
    pub max_coroutine: u32,
    pub socket_buffer_size: u32,
    pub buffer_output_size: u32,
    pub reactor_num: usize,
    pub enable_reuse_port: bool,
    pub backlog: i32,
    pub package_max_length: u32,
    pub tcp_keepidle: u32,
    pub tcp_keepinterval: u32,
    pub tcp_keepcount: u32,
    pub tcp_user_timeout_ms: u32,
    pub tcp_quickack: bool,
    pub enable_coroutine: bool,
    pub max_wait_time: u32,
    pub log_connections: bool,
    pub receive_buffer_size: usize,
    pub gc_interval_ms: u32,
    pub dns_cache_ttl: u64,
    pub tcp_notsent_lowat: u32,
    pub sockmap_enabled: bool,
    pub sockmap_bpf_object: PathBuf,
    pub timeout: Duration,
    pub connect_timeout: Duration,
    pub skip_validation: bool,
    pub cache_ttl: u64,
    pub tls: Option<Arc<Tls>>,
    pub adapter_factory: Option<AdapterFactory>,
}

impl std::fmt::Debug for TcpConfig {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("TcpConfig")
            .field("ports", &self.ports)
            .field("host", &self.host)
            .field("workers", &self.workers)
            .finish_non_exhaustive()
    }
}

impl TcpConfig {
    /// Build a config with PHP-equivalent defaults. `ports` is required; every
    /// other knob is optional via fluent `with_*` setters.
    pub fn new(ports: Vec<u16>) -> Self {
        let cpus = num_cpus();
        let max_connections: u32 = 200_000;
        Self {
            ports,
            host: "0.0.0.0".to_string(),
            workers: cpus,
            max_connections,
            max_coroutine: max_connections.saturating_mul(2),
            // Kernel per-socket buffer bounds (SO_RCVBUF / SO_SNDBUF). Swoole
            // uses tight buffers so high-fan-in setups can hold many idle
            // connections; we do the same. 32 KB is plenty for database and
            // HTTP proxy traffic where the proxy is just forwarding bytes.
            socket_buffer_size: 32 * 1024,
            buffer_output_size: 32 * 1024,
            reactor_num: cpus,
            enable_reuse_port: true,
            backlog: 65_535,
            package_max_length: 32 * 1024 * 1024,
            tcp_keepidle: 30,
            tcp_keepinterval: 10,
            tcp_keepcount: 3,
            tcp_user_timeout_ms: 10_000,
            tcp_quickack: true,
            enable_coroutine: true,
            max_wait_time: 60,
            log_connections: false,
            // Userspace scratch buffer for the first-packet read and the
            // bidirectional copy. Kept tight so each connection's task is cheap;
            // the kernel handles queuing via SO_{SND,RCV}BUF.
            receive_buffer_size: 8_192,
            gc_interval_ms: 5_000,
            dns_cache_ttl: 60,
            tcp_notsent_lowat: 16_384,
            sockmap_enabled: false,
            sockmap_bpf_object: PathBuf::new(),
            timeout: Duration::from_secs(30),
            connect_timeout: Duration::from_secs(5),
            skip_validation: false,
            cache_ttl: 0,
            tls: None,
            adapter_factory: None,
        }
    }

    pub fn with_host(mut self, host: impl Into<String>) -> Self {
        self.host = host.into();
        self
    }

    pub fn with_workers(mut self, workers: usize) -> Self {
        self.workers = workers;
        self
    }

    pub fn with_reactor_num(mut self, reactor_num: usize) -> Self {
        self.reactor_num = reactor_num;
        self
    }

    pub fn with_max_connections(mut self, max: u32) -> Self {
        self.max_connections = max;
        self.max_coroutine = max.saturating_mul(2);
        self
    }

    pub fn with_tls(mut self, tls: Arc<Tls>) -> Self {
        self.tls = Some(tls);
        self
    }

    pub fn with_skip_validation(mut self, skip: bool) -> Self {
        self.skip_validation = skip;
        self
    }

    pub fn with_cache_ttl(mut self, ttl_seconds: u64) -> Self {
        self.cache_ttl = ttl_seconds;
        self
    }

    pub fn with_timeout(mut self, timeout: Duration) -> Self {
        self.timeout = timeout;
        self
    }

    pub fn with_connect_timeout(mut self, timeout: Duration) -> Self {
        self.connect_timeout = timeout;
        self
    }

    pub fn with_sockmap(mut self, bpf_object: impl Into<PathBuf>) -> Self {
        self.sockmap_enabled = true;
        self.sockmap_bpf_object = bpf_object.into();
        self
    }

    pub fn with_adapter_factory<F>(mut self, factory: F) -> Self
    where
        F: Fn(u16, Arc<dyn Resolver>) -> crate::adapter::tcp::TcpAdapter + Send + Sync + 'static,
    {
        self.adapter_factory = Some(Box::new(factory));
        self
    }

    /// True when a `Tls` is attached.
    pub fn is_tls_enabled(&self) -> bool {
        self.tls.is_some()
    }
}

fn num_cpus() -> usize {
    std::thread::available_parallelism()
        .map(|n| n.get())
        .unwrap_or(1)
}
