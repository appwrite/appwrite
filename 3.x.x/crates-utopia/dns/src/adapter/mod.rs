pub mod native;
pub mod swoole;

use std::net::SocketAddr;
use std::sync::Arc;

use async_trait::async_trait;

use crate::error::Result;
use crate::protocol::Protocol;

/// Packet callback. PHP `callable(string $buffer, string $ip, int $port, Protocol $protocol): string`.
pub type PacketHandler = Arc<dyn Fn(&[u8], &str, u16, Protocol) -> Vec<u8> + Send + Sync>;

/// Worker-start callback. PHP `callable(int $workerId): void`.
pub type WorkerStartHandler = Arc<dyn Fn(i64) + Send + Sync>;

/// PHP `Utopia\DNS\Adapter`.
#[async_trait]
pub trait Adapter: Send + Sync {
    fn on_worker_start(&self, callback: WorkerStartHandler);
    fn on_packet(&self, callback: PacketHandler);
    /// Blocking start. PHP `Adapter::start`.
    fn start(&self) -> Result<()>;
    /// Async start for tests (Tokio).
    async fn start_async(&self) -> Result<()>;
    /// Stop a running `start_async` loop.
    fn stop(&self) {}
    fn udp_addr(&self) -> Option<SocketAddr> {
        None
    }
    fn tcp_addr(&self) -> Option<SocketAddr> {
        None
    }
    fn http_addr(&self) -> Option<SocketAddr> {
        None
    }
}
