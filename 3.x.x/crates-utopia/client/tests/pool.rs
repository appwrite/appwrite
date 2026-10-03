//! PHP `tests/Client/PoolTest.php`.

mod support;

use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;

use bytes::Bytes;
use http::{Request, Response, StatusCode};
use support::request;
use utopia_client::{Pool, StreamingClient};
use utopia_pools::{Recover, Stack};

#[derive(Clone, Debug)]
struct FakeClient {
    status: u16,
}

impl StreamingClient for FakeClient {
    fn send_request(
        &self,
        _request: Request<Bytes>,
    ) -> Result<Response<Bytes>, utopia_client::Error> {
        Ok(Response::builder()
            .status(StatusCode::from_u16(self.status).unwrap())
            .body(Bytes::new())
            .unwrap())
    }

    fn stream(
        &self,
        request: Request<Bytes>,
        sink: &mut dyn FnMut(&[u8]),
    ) -> Result<Response<Bytes>, utopia_client::Error> {
        sink(b"chunk");
        self.send_request(request)
    }
}

impl Recover for FakeClient {}

fn connections(
    init: impl Fn() -> FakeClient + Send + Sync + 'static,
    size: usize,
) -> utopia_pools::Pool<FakeClient> {
    utopia_pools::Pool::new(Stack::new(), "test", size, init, 0.0).unwrap()
}

#[test]
fn it_borrows_a_connection_to_send_a_request() {
    let pool = Pool::new(connections(|| FakeClient { status: 200 }, 4));
    assert_eq!(
        pool.send_request(request("GET", "https://example.com"))
            .unwrap()
            .status(),
        200
    );
}

#[test]
fn it_borrows_a_connection_to_stream_a_request() {
    let pool = Pool::new(connections(|| FakeClient { status: 200 }, 4));
    let mut received = Vec::new();
    let response = pool
        .stream(request("GET", "https://example.com"), &mut |chunk| {
            received.extend_from_slice(chunk);
        })
        .unwrap();
    assert_eq!(received, b"chunk");
    assert_eq!(response.status(), 200);
}

#[test]
fn it_reclaims_the_connection_so_it_can_be_reused() {
    let created = Arc::new(AtomicUsize::new(0));
    let created_clone = Arc::clone(&created);
    let pool = Pool::new(connections(
        move || {
            created_clone.fetch_add(1, Ordering::SeqCst);
            FakeClient { status: 200 }
        },
        1,
    ));
    pool.send_request(request("GET", "https://example.com"))
        .unwrap();
    pool.send_request(request("GET", "https://example.com"))
        .unwrap();
    assert_eq!(created.load(Ordering::SeqCst), 1);
}
