use std::sync::Arc;
use std::time::Duration;

use crate::server::Settings;
use crate::trusted_headers::TrustedHeaders;

/// Server tuning for [`serve`](crate::serve).
#[derive(Debug, Clone)]
pub struct ServerOptions {
    /// Maximum accepted request body size in bytes.
    pub max_body: usize,
    /// Keep-alive connections.
    pub keep_alive: bool,
    /// Header read timeout (slowloris protection).
    pub header_read_timeout: Duration,
    /// Time allowed for in-flight connections after a shutdown signal (`max_wait_time`).
    pub shutdown_grace: Duration,
    /// Requests in flight before new ones get a 503 (`max_concurrency`).
    pub max_concurrency: usize,
    /// Which forwarded headers requests believe.
    pub trusted: Arc<TrustedHeaders>,
}

impl Default for ServerOptions {
    /// 32 MiB bodies, keep-alive, 30 seconds to read headers and to drain on
    /// shutdown, 1000 requests in flight, `x-forwarded-proto` trusted.
    fn default() -> Self {
        Self {
            max_body: 32 * 1024 * 1024,
            keep_alive: true,
            header_read_timeout: Duration::from_secs(30),
            shutdown_grace: Duration::from_secs(30),
            max_concurrency: 1_000,
            trusted: TrustedHeaders::shared_default(),
        }
    }
}

impl From<&Settings> for ServerOptions {
    /// The default options with the drain deadline and the concurrency cap
    /// of `settings`.
    fn from(settings: &Settings) -> Self {
        Self {
            shutdown_grace: Duration::from_secs(settings.max_wait_time),
            max_concurrency: settings.max_concurrency,
            ..Self::default()
        }
    }
}
