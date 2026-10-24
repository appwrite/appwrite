use std::future::Future;
use std::sync::Arc;

use crate::response::Body;
use crate::{Request, Response};

/// What the server runs for each request: turns a request into a response.
pub trait Handler: Send + Sync + 'static {
    /// The response to `request`.
    fn handle(&self, request: Request) -> impl Future<Output = Response> + Send;

    /// The hyper response for `request`. By default the handler's response,
    /// buffered; [`App`](crate::App) streams.
    fn respond(self: &Arc<Self>, request: Request) -> impl Future<Output = http::Response<Body>> + Send {
        let this = self.clone();
        async move { this.handle(request).await.into_http() }
    }
}
