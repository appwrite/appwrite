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
    /// Bytes of request bodies held in memory at once, server-wide: a body
    /// that would take the total past it gets a 503.
    pub max_body_memory: usize,
    /// Time allowed to read an accepted request body: a client that sends
    /// it slower has its connection closed.
    pub body_read_timeout: Duration,
    /// Bytes read from a rejected body (too large, or the server is busy)
    /// so the answer reaches the client before the connection closes.
    pub drain_limit: usize,
    /// Time allowed to read a rejected body, up to [`Self::drain_limit`].
    pub drain_timeout: Duration,
    /// Which forwarded headers requests believe.
    pub trusted: Arc<TrustedHeaders>,
}

impl Default for ServerOptions {
    /// 32 MiB bodies with 1 GiB of them in memory at once and two minutes to
    /// read one, keep-alive, 30 seconds to read headers and to drain on
    /// shutdown, 1000 requests in flight, rejected bodies read for up to
    /// 64 MiB or 10 seconds, `x-forwarded-proto` trusted.
    fn default() -> Self {
        Self {
            max_body: 32 * 1024 * 1024,
            keep_alive: true,
            header_read_timeout: Duration::from_secs(30),
            shutdown_grace: Duration::from_secs(30),
            max_concurrency: 1_000,
            max_body_memory: 1024 * 1024 * 1024,
            body_read_timeout: Duration::from_secs(120),
            drain_limit: 64 * 1024 * 1024,
            drain_timeout: Duration::from_secs(10),
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
