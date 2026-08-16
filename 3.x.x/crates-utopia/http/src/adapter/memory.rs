use super::{Adapter, BoxedFuture, RequestHandler};
use crate::error::Result;
use crate::request::Request;
use crate::response::Response;
use parking_lot::Mutex;
use std::fmt;
use std::sync::Arc;
use utopia_di::Container;

/// In-memory adapter for unit tests and offline `run()` loops.
pub struct MemoryAdapter {
    resources: Container,
    queue: Mutex<Vec<(Request, Response)>>,
    handler: Mutex<Option<Arc<RequestHandler>>>,
}

impl fmt::Debug for MemoryAdapter {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("MemoryAdapter")
            .field("resources", &self.resources)
            .finish_non_exhaustive()
    }
}

impl MemoryAdapter {
    pub fn new(resources: Container) -> Self {
        Self {
            resources,
            queue: Mutex::new(Vec::new()),
            handler: Mutex::new(None),
        }
    }

    /// Enqueue a request/response pair processed by `start()` / drained manually.
    pub fn push(&self, request: Request, response: Response) {
        self.queue.lock().push((request, response));
    }

    pub fn push_simple(&self, method: &str, uri: &str) -> Response {
        let response = Response::new();
        self.push(Request::new(method, uri), response.clone());
        response
    }
}

impl Adapter for MemoryAdapter {
    fn resources(&self) -> &Container {
        &self.resources
    }

    fn on_request(&self, handler: RequestHandler) -> BoxedFuture<'_, ()> {
        *self.handler.lock() = Some(Arc::new(handler));
        Box::pin(async {})
    }

    fn start(&self) -> BoxedFuture<'_, Result<()>> {
        Box::pin(async move {
            let handler = self
                .handler
                .lock()
                .clone()
                .ok_or_else(|| crate::error::HttpError::Other("no request handler".into()))?;
            loop {
                let next = self.queue.lock().pop();
                let Some((req, res)) = next else {
                    break;
                };
                handler(req, res).await;
            }
            Ok(())
        })
    }
}
