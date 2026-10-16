//! PHP `Utopia\Client\Pool`.

use bytes::Bytes;
use http::{Request, Response};
use utopia_pools::{Pool as Connections, PoolError, Recover};

use crate::{Error, StreamingClient};

/// Block on a pool future from sync `send_request` / `stream`.
fn block_on<T>(future: impl std::future::Future<Output = T>) -> T {
    match tokio::runtime::Handle::try_current() {
        Ok(handle) => tokio::task::block_in_place(|| handle.block_on(future)),
        Err(_) => tokio::runtime::Builder::new_current_thread()
            .enable_all()
            .build()
            .expect("tokio runtime")
            .block_on(future),
    }
}

/// PHP `Utopia\Client\Pool`.
#[derive(Clone, Debug)]
pub struct Pool<T> {
    connections: Connections<T>,
}

impl<T: StreamingClient + Recover + Send + 'static> Pool<T> {
    /// PHP `new Pool(Pool $connections)`.
    #[must_use]
    pub fn new(connections: Connections<T>) -> Self {
        Self { connections }
    }

    pub fn send_request(&self, request: Request<Bytes>) -> Result<Response<Bytes>, Error> {
        self.with_client(request, |client, request| client.send_request(request))
    }

    pub fn stream(
        &self,
        request: Request<Bytes>,
        sink: &mut dyn FnMut(&[u8]),
    ) -> Result<Response<Bytes>, Error> {
        let mut result = None;
        let pool_result = block_on(self.connections.use_resource(|client| {
            match client.stream(request.clone(), sink) {
                Ok(response) => {
                    result = Some(Ok(response));
                    Ok(())
                }
                Err(error) => {
                    result = Some(Err(error));
                    Err(PoolError::callback("request failed"))
                }
            }
        }));
        match result {
            Some(outcome) => {
                let _ = pool_result;
                outcome
            }
            None => Err(pool_result.err().map_or_else(
                || Error::invalid_argument("pool did not return a connection"),
                Error::from,
            )),
        }
    }

    fn with_client(
        &self,
        request: Request<Bytes>,
        call: impl FnOnce(&T, Request<Bytes>) -> Result<Response<Bytes>, Error>,
    ) -> Result<Response<Bytes>, Error> {
        let mut result = None;
        let pool_result =
            block_on(
                self.connections
                    .use_resource(|client| match call(client, request.clone()) {
                        Ok(response) => {
                            result = Some(Ok(response));
                            Ok(())
                        }
                        Err(error) => {
                            result = Some(Err(error));
                            Err(PoolError::callback("request failed"))
                        }
                    }),
            );
        match result {
            Some(outcome) => {
                let _ = pool_result;
                outcome
            }
            None => Err(pool_result.err().map_or_else(
                || Error::invalid_argument("pool did not return a connection"),
                Error::from,
            )),
        }
    }
}

impl<T: StreamingClient + Recover + Send + 'static> StreamingClient for Pool<T> {
    fn send_request(&self, request: Request<Bytes>) -> Result<Response<Bytes>, Error> {
        Pool::send_request(self, request)
    }

    fn stream(
        &self,
        request: Request<Bytes>,
        sink: &mut dyn FnMut(&[u8]),
    ) -> Result<Response<Bytes>, Error> {
        Pool::stream(self, request, sink)
    }
}
