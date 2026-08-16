//! Typed SMTP server configuration. Mirrors `src/Server/SMTP/Config.php`.

use std::thread;
use std::time::Duration;

/// SMTP proxy server configuration. Readonly, field-for-field equivalent to the
/// PHP `Config` class with identical defaults.
#[derive(Debug, Clone)]
pub struct SmtpConfig {
    pub host: String,
    pub port: u16,
    pub workers: usize,
    pub max_connections: usize,
    pub max_coroutine: usize,
    pub socket_buffer_size: usize,
    pub buffer_output_size: usize,
    pub enable_coroutine: bool,
    pub max_wait_time: Duration,
    pub timeout: Duration,
    pub connect_timeout: Duration,
    pub skip_validation: bool,
    pub cache_ttl: u64,
}

impl SmtpConfig {
    /// Default SMTP listen port.
    pub const DEFAULT_PORT: u16 = 25;
}

impl Default for SmtpConfig {
    fn default() -> Self {
        let cpus = thread::available_parallelism()
            .map(|n| n.get())
            .unwrap_or(1);
        Self {
            host: String::from("0.0.0.0"),
            port: Self::DEFAULT_PORT,
            workers: cpus * 2,
            max_connections: 50_000,
            max_coroutine: 50_000,
            socket_buffer_size: 2 * 1024 * 1024,
            buffer_output_size: 2 * 1024 * 1024,
            enable_coroutine: true,
            max_wait_time: Duration::from_secs(60),
            timeout: Duration::from_secs(30),
            connect_timeout: Duration::from_secs(5),
            skip_validation: false,
            cache_ttl: 60,
        }
    }
}
