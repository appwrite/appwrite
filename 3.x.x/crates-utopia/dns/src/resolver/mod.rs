pub mod cloudflare;
pub mod google;
pub mod memory;
pub mod proxy;

pub use cloudflare::Cloudflare;
pub use google::Google;
pub use memory::Memory;
pub use proxy::Proxy;

use crate::error::Result;
use crate::query::Query;
use crate::Message;

/// PHP `Utopia\DNS\Resolver`.
pub trait Resolver: Send + Sync {
    /// Returns a DNS response for the given query.
    fn resolve(&self, query: &Query) -> Result<Message>;
}
