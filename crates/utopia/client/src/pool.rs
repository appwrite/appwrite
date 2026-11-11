//! Sharing a bounded set of clients between concurrent callers.

use utopia_pools::{Adapter as Store, Resource};

use crate::adapter::{Adapter, Sink};
use crate::{Error, Request, Response};

/// A client that borrows a pooled adapter for the duration of each request
/// and returns it once the request completes, so concurrent callers share a
/// bounded set of connections. An adapter whose request failed is discarded
/// (its connection may be broken) and replaced on demand.
///
/// PHP: `Utopia\Client\Pool` over a `Utopia\Pools\Pool` of clients.
///
/// ```no_run
/// use utopia_client::{Http, Pool, Request};
/// use utopia_pools::{Channel, Pool as Connections};
///
/// # async fn run() -> Result<(), utopia_client::Error> {
/// let connections = Connections::new(Channel::new(), "api", 8, || async {
///     Ok::<_, utopia_client::Error>(Http::new())
/// }, 5.0).map_err(utopia_client::Error::from)?;
/// let pool = Pool::new(connections);
/// let response = pool.send(Request::new("GET", "https://example.com/")?).await?;
/// # Ok(())
/// # }
/// ```
///
/// ```php
/// use Utopia\Client\Adapter\Curl\Client as Curl;
/// use Utopia\Client\Pool;
/// use Utopia\Pools\Adapter\Stack;
/// use Utopia\Pools\Pool as Connections;
/// use Utopia\Psr7\Request;
/// use Utopia\Psr7\Uri;
///
/// $pool = new Pool(new Connections(new Stack(), 'api', 8, fn () => new Curl(), 5.0));
/// $request = new Request('GET', Uri::parse('https://example.com/'));
/// // $pool->sendRequest($request) borrows a client, sends, and gives it back
/// ```
#[derive(Debug, Clone)]
pub struct Pool<A, S> {
    connections: utopia_pools::Pool<A, S>,
}

impl<A: Adapter + Resource, S: Store<A>> Pool<A, S> {
    /// Requests borrow adapters from `connections`.
    ///
    /// PHP: `new Pool($connections)`.
    pub fn new(connections: utopia_pools::Pool<A, S>) -> Pool<A, S> {
        Pool { connections }
    }

    /// The pool requests borrow from.
    pub fn connections(&self) -> &utopia_pools::Pool<A, S> {
        &self.connections
    }

    /// Sends a request on a borrowed adapter.
    ///
    /// PHP: `sendRequest($request)`.
    pub async fn send(&self, request: Request) -> Result<Response, Error> {
        self.connections.with(async |adapter: &A| adapter.send(request).await).await
    }

    /// Streams a request on a borrowed adapter.
    ///
    /// PHP: `stream($request, $sink)`.
    pub async fn stream<K: Sink>(&self, request: Request, sink: &mut K) -> Result<Response, Error> {
        self.connections.with(async |adapter: &A| adapter.stream(request, sink).await).await
    }
}

impl From<utopia_pools::Error> for Error {
    /// A pool failure: no adapter became free in time, or one could not be
    /// created.
    fn from(error: utopia_pools::Error) -> Error {
        match error {
            utopia_pools::Error::InvalidArgument(message) => Error::InvalidArgument(message),
            other => Error::Pool(other.to_string()),
        }
    }
}

impl Resource for crate::Http {}
