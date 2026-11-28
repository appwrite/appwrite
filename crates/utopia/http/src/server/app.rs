use std::future::Future;
use std::sync::Arc;

use bytes::Bytes;
use http_body_util::BodyExt;

use crate::http::Http;
use crate::response::{Aborted, Body, Stream, head_response};
use crate::server::Handler;
use crate::{Request, Response};

/// Serves an [`Http`] application: each request runs [`Http::run`] in a
/// fresh context.
pub struct App(
    /// The application.
    pub Arc<Http>,
);

impl Handler for App {
    /// Runs the request through the application in a fresh context; a
    /// failure gives an empty 500.
    fn handle(&self, request: Request) -> impl Future<Output = Response> + Send {
        let http = self.0.clone();
        async move {
            let context = http.context();
            let mut response = Response::new();
            if let Err(error) = http.run(&request, &mut response, &context).await {
                tracing::error!(%error, class = error.php_class(), "request failed");
                let mut failed = Response::new();
                failed.set_status(http::StatusCode::INTERNAL_SERVER_ERROR);
                failed.force_status();
                return failed;
            }
            response
        }
    }

    /// Runs the request in its own task and answers as soon as the head is
    /// sent: chunks written with [`Response::chunk`] reach the client as
    /// they are written.
    fn respond(self: &Arc<Self>, request: Request) -> impl Future<Output = http::Response<Body>> + Send {
        let http = self.0.clone();
        async move {
            let (head_tx, head_rx) = utopia_async::sync::oneshot::channel();
            let (body_tx, mut body_rx) = utopia_async::sync::mpsc::unbounded_channel::<Result<Bytes, Aborted>>();
            utopia_async::spawn(async move {
                let context = http.context();
                let mut response = Response::new();
                response.attach(Stream { head: Some(head_tx), body: Some(body_tx) });
                if let Err(error) = http.run(&request, &mut response, &context).await {
                    tracing::error!(%error, class = error.php_class(), "request failed");
                    if !response.wire().ended && response.wire().status.is_none() {
                        response.set_status(http::StatusCode::INTERNAL_SERVER_ERROR);
                        response.force_status();
                    }
                }
                response.finish();
            });
            match head_rx.await {
                Ok(head) if head.body.is_some() => {
                    let body = http_body_util::Full::new(head.body.unwrap_or_default())
                        .map_err(|never| match never {})
                        .boxed();
                    head_response(head.status, &head.headers, &head.cookies, body)
                }
                Ok(head) => {
                    let frames = futures_util::stream::unfold(body_rx, |mut rx| async move {
                        let chunk = rx.recv().await?;
                        if chunk.is_err() {
                            // Pending once, so hyper flushes the chunks already written
                            // before the error closes the connection.
                            utopia_async::task::yield_now().await;
                        }
                        Some((chunk.map(hyper::body::Frame::data), rx))
                    });
                    let body = http_body_util::BodyExt::boxed(http_body_util::StreamBody::new(frames));
                    head_response(head.status, &head.headers, &head.cookies, body)
                }
                Err(_) => {
                    body_rx.close();
                    let mut failed = Response::new();
                    failed.set_status(http::StatusCode::INTERNAL_SERVER_ERROR);
                    failed.force_status();
                    failed.into_http()
                }
            }
        }
    }
}
