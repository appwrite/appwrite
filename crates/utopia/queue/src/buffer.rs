//! Coalescing of requests waiting for the same transport (`Internal\Buffer`).

use std::sync::{Arc, Mutex};

use tokio::sync::oneshot;

use crate::connection::BoxFuture;
use crate::error::{Error, Result};

/// Sends a batch of requests; one result per request, in order.
pub(crate) type Send<Req, Res> =
    Arc<dyn Fn(Vec<Req>) -> BoxFuture<'static, Result<Vec<Result<Res>>>> + Sync + std::marker::Send>;

type Waiting<Req, Res> = Vec<(Req, oneshot::Sender<Result<Res>>)>;

/// Requests already waiting when a batch leaves go out together in the next
/// one, without a timer: one dispatcher task drains them in batches of at
/// most 1000 while anything is pending.
pub(crate) struct Buffer<Req, Res> {
    send: Send<Req, Res>,
    state: Arc<Mutex<(Waiting<Req, Res>, bool)>>,
}

impl<Req: std::marker::Send + 'static, Res: std::marker::Send + 'static> Buffer<Req, Res> {
    pub(crate) fn new(send: Send<Req, Res>) -> Self {
        Self { send, state: Arc::new(Mutex::new((Vec::new(), false))) }
    }

    /// Queues `request` and waits for its result.
    pub(crate) async fn request(&self, request: Req) -> Result<Res> {
        let (tx, rx) = oneshot::channel();
        let start = {
            let mut state = self.state.lock().unwrap_or_else(|e| e.into_inner());
            state.0.push((request, tx));
            !std::mem::replace(&mut state.1, true)
        };
        if start {
            let state = self.state.clone();
            let send = self.send.clone();
            tokio::spawn(async move {
                loop {
                    let batch: Waiting<Req, Res> = {
                        let mut s = state.lock().unwrap_or_else(|e| e.into_inner());
                        if s.0.is_empty() {
                            s.1 = false;
                            return;
                        }
                        let n = s.0.len().min(1000);
                        s.0.drain(..n).collect()
                    };
                    dispatch(&send, batch).await;
                }
            });
        }
        rx.await.unwrap_or_else(|_| Err(Error::Runtime("Queue confirmation wait was interrupted".into())))
    }
}

/// Earlier confirmations stand when the transport fails later: only
/// unresolved requests get the failure.
async fn dispatch<Req, Res>(send: &Send<Req, Res>, batch: Waiting<Req, Res>) {
    let (requests, replies): (Vec<Req>, Vec<_>) = batch.into_iter().unzip();
    match send(requests).await {
        Ok(results) => {
            let mut results = results.into_iter();
            for reply in replies {
                let result = results.next().unwrap_or_else(|| Err(Error::Runtime("Missing transport result".into())));
                let _ = reply.send(result);
            }
        }
        Err(e) => {
            for reply in replies {
                let _ = reply.send(Err(e.clone()));
            }
        }
    }
}
