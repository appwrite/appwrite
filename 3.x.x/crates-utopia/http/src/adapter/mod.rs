//! Server adapters (memory + Hyper).

mod hyper;
mod memory;

pub use hyper::HyperServer;
pub use memory::MemoryAdapter;

use crate::error::Result;
use crate::request::Request;
use crate::response::Response;
use std::future::Future;
use std::pin::Pin;
use utopia_di::Container;

pub type BoxedFuture<'a, T> = Pin<Box<dyn Future<Output = T> + Send + 'a>>;

/// Per-request handler registered via [`Adapter::on_request`].
pub type RequestHandler = Box<dyn Fn(Request, Response) -> BoxedFuture<'static, ()> + Send + Sync>;

/// Adapter contract used by [`crate::Http`].
pub trait Adapter: Send + Sync + 'static {
    /// Shared application resources container for this server instance.
    fn resources(&self) -> &Container;

    /// Request-scoped child container (falls through to [`Self::resources`]).
    ///
    /// Per-request bindings (`request`, `response`, `error`) must go here so concurrent
    /// requests do not mutate the shared application container.
    fn context(&self) -> Container {
        Container::child(self.resources())
    }

    /// Optional listen address (Hyper).
    fn address(&self) -> Option<&str> {
        None
    }

    /// Register the framework request callback.
    fn on_request(&self, handler: RequestHandler) -> BoxedFuture<'_, ()>;

    /// Start serving (blocks for Hyper; drains queue for Memory).
    fn start(&self) -> BoxedFuture<'_, Result<()>>;
}
