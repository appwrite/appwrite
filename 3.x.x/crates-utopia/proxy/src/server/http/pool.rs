//! Per-endpoint HTTP connection pool. Mirrors the `$pools` channel-of-clients
//! pattern in `src/Server/HTTP/Swoole/Handler.php` (one bounded pool per
//! `host:port`, acquire with timeout, push back on drop if healthy).

use std::collections::VecDeque;
use std::sync::Arc;
use std::time::Duration;

use bytes::Bytes;
use dashmap::DashMap;
use http_body_util::Full;
use hyper::body::Incoming;
use hyper::client::conn::http1::{handshake, SendRequest};
use hyper::Request;
use hyper_util::rt::TokioIo;
use parking_lot::Mutex;
use tokio::net::TcpStream;
use tokio::sync::Semaphore;
use tokio::time::timeout;

use crate::error::ProxyError;

/// Body type used by pooled clients. We collect incoming bodies into bytes
/// before forwarding, so a single `Full<Bytes>` type covers both GET/HEAD
/// (empty) and methods with payloads.
pub type ClientBody = Full<Bytes>;

/// A pooled backend HTTP/1.1 client. Wraps hyper's `SendRequest` plus metadata
/// required to decide whether the connection is worth returning to the pool.
pub struct Client {
    sender: SendRequest<ClientBody>,
    healthy: bool,
}

impl std::fmt::Debug for Client {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Client")
            .field("healthy", &self.healthy)
            .finish_non_exhaustive()
    }
}

impl Client {
    pub fn is_healthy(&self) -> bool {
        self.healthy && self.sender.is_ready()
    }

    pub async fn send(
        &mut self,
        request: Request<ClientBody>,
    ) -> Result<hyper::Response<Incoming>, hyper::Error> {
        match self.sender.send_request(request).await {
            Ok(response) => Ok(response),
            Err(error) => {
                self.healthy = false;
                Err(error)
            }
        }
    }
}

/// A bounded pool of clients for a single `host:port`. The semaphore caps the
/// total live client count; the deque holds idle clients available for reuse.
pub struct Pool {
    endpoint: String,
    capacity: Arc<Semaphore>,
    idle: Mutex<VecDeque<Client>>,
}

impl std::fmt::Debug for Pool {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Pool")
            .field("endpoint", &self.endpoint)
            .finish_non_exhaustive()
    }
}

impl Pool {
    pub fn new(endpoint: impl Into<String>, size: usize) -> Self {
        Self {
            endpoint: endpoint.into(),
            capacity: Arc::new(Semaphore::new(size)),
            idle: Mutex::new(VecDeque::with_capacity(size)),
        }
    }

    pub fn endpoint(&self) -> &str {
        &self.endpoint
    }

    /// Acquire a client - reuses an idle one if available, otherwise dials a
    /// fresh TCP connection up to `connect_timeout`. The pool-slot acquire
    /// itself is bounded by `pool_timeout`.
    pub async fn acquire(
        self: &Arc<Self>,
        pool_timeout: Duration,
        connect_timeout: Duration,
    ) -> Result<PooledClient, ProxyError> {
        let permit = match timeout(pool_timeout, self.capacity.clone().acquire_owned()).await {
            Ok(Ok(permit)) => Some(permit),
            Ok(Err(_)) => return Err(ProxyError::Other("pool closed".to_string())),
            Err(_) => None,
        };

        if permit.is_some() {
            if let Some(client) = self.idle.lock().pop_front() {
                if client.is_healthy() {
                    return Ok(PooledClient {
                        pool: Some(Arc::clone(self)),
                        permit,
                        client: Some(client),
                    });
                }
            }
        }

        let client = connect(&self.endpoint, connect_timeout).await?;

        Ok(PooledClient {
            pool: permit.as_ref().map(|_| Arc::clone(self)),
            permit,
            client: Some(client),
        })
    }

    fn release(&self, client: Client) {
        if client.is_healthy() {
            self.idle.lock().push_back(client);
        }
    }

    /// Returns the current number of idle clients. Test helper.
    pub fn idle_len(&self) -> usize {
        self.idle.lock().len()
    }
}

/// RAII wrapper returned by `Pool::acquire`. On drop, healthy clients are
/// pushed back into the pool; broken ones are discarded and the permit freed.
pub struct PooledClient {
    pool: Option<Arc<Pool>>,
    permit: Option<tokio::sync::OwnedSemaphorePermit>,
    client: Option<Client>,
}

impl std::fmt::Debug for PooledClient {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("PooledClient").finish_non_exhaustive()
    }
}

impl PooledClient {
    pub fn client_mut(&mut self) -> &mut Client {
        self.client
            .as_mut()
            .expect("PooledClient accessed after drop")
    }

    /// Mark the underlying client as unhealthy so it won't be recycled.
    pub fn invalidate(&mut self) {
        if let Some(client) = self.client.as_mut() {
            client.healthy = false;
        }
    }
}

impl Drop for PooledClient {
    fn drop(&mut self) {
        if let (Some(pool), Some(client)) = (self.pool.take(), self.client.take()) {
            pool.release(client);
        }
        drop(self.permit.take());
    }
}

/// Top-level per-endpoint pool registry used by the HTTP handler. Lazily
/// creates a pool on first access for an endpoint.
#[derive(Default, Debug)]
pub struct Pools {
    map: DashMap<String, Arc<Pool>>,
}

impl Pools {
    pub fn new() -> Self {
        Self {
            map: DashMap::new(),
        }
    }

    pub fn get_or_create(&self, endpoint: &str, size: usize) -> Arc<Pool> {
        if let Some(existing) = self.map.get(endpoint) {
            return Arc::clone(existing.value());
        }
        Arc::clone(
            self.map
                .entry(endpoint.to_string())
                .or_insert_with(|| Arc::new(Pool::new(endpoint.to_string(), size)))
                .value(),
        )
    }
}

async fn connect(endpoint: &str, connect_timeout: Duration) -> Result<Client, ProxyError> {
    let stream = match timeout(connect_timeout, TcpStream::connect(endpoint)).await {
        Ok(Ok(stream)) => stream,
        Ok(Err(error)) => return Err(ProxyError::BackendConnect(error.to_string())),
        Err(_) => {
            return Err(ProxyError::BackendConnect(format!(
                "connect to {endpoint} timed out after {connect_timeout:?}"
            )))
        }
    };
    stream
        .set_nodelay(true)
        .map_err(|error| ProxyError::BackendConnect(error.to_string()))?;

    let io = TokioIo::new(stream);
    let (sender, connection) = handshake::<_, ClientBody>(io)
        .await
        .map_err(|error| ProxyError::BackendConnect(error.to_string()))?;

    tokio::spawn(async move {
        if let Err(error) = connection.await {
            tracing::debug!(?error, "backend connection closed");
        }
    });

    Ok(Client {
        sender,
        healthy: true,
    })
}
