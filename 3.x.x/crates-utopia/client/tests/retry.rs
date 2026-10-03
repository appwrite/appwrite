//! PHP `tests/Client/Decorator/RetryTest.php`.

mod support;

use std::sync::{Arc, Mutex};

use bytes::Bytes;
use http::{Request, Response, StatusCode};
use support::request;
use utopia_client::{Adapter, Backoff, Error, Retry, StreamingClient, Tls};

type Outcome = Arc<dyn Fn(&mut dyn FnMut(&[u8])) -> Result<Response<Bytes>, Error> + Send + Sync>;

#[derive(Clone)]
struct QueueAdapter {
    outcomes: Vec<Outcome>,
    calls: Arc<Mutex<usize>>,
}

impl QueueAdapter {
    fn new(outcomes: Vec<Outcome>) -> Self {
        Self {
            outcomes,
            calls: Arc::new(Mutex::new(0)),
        }
    }

    fn calls(&self) -> usize {
        *self.calls.lock().unwrap()
    }

    fn next(&self, sink: &mut dyn FnMut(&[u8])) -> Result<Response<Bytes>, Error> {
        let mut calls = self.calls.lock().unwrap();
        let index = *calls;
        *calls += 1;
        drop(calls);
        self.outcomes.get(index).expect("No more queued outcomes.")(sink)
    }
}

impl StreamingClient for QueueAdapter {
    fn send_request(&self, _request: Request<Bytes>) -> Result<Response<Bytes>, Error> {
        self.next(&mut |_| {})
    }

    fn stream(
        &self,
        _request: Request<Bytes>,
        sink: &mut dyn FnMut(&[u8]),
    ) -> Result<Response<Bytes>, Error> {
        self.next(sink)
    }
}

impl Adapter for QueueAdapter {
    fn with_timeout(&self, _seconds: f64) -> Result<Self, Error> {
        Ok(self.clone())
    }
    fn with_connect_timeout(&self, _seconds: f64) -> Result<Self, Error> {
        Ok(self.clone())
    }
    fn with_ssl_verification(&self, _enabled: bool) -> Self {
        self.clone()
    }
    fn with_custom_ca(&self, _path: impl Into<String>) -> Self {
        self.clone()
    }
    fn with_certificate(
        &self,
        _cert_path: impl Into<String>,
        _key_path: impl Into<String>,
        _passphrase: Option<String>,
    ) -> Self {
        self.clone()
    }
    fn with_min_tls_version(&self, _version: Tls) -> Self {
        self.clone()
    }
    fn with_connection_reuse(&self, _enabled: bool) -> Self {
        self.clone()
    }
}

fn ok(status: u16) -> Outcome {
    Arc::new(move |_| {
        Ok(Response::builder()
            .status(StatusCode::from_u16(status).unwrap())
            .body(Bytes::new())
            .unwrap())
    })
}

fn err_network(message: &'static str) -> Outcome {
    Arc::new(move |_| {
        Err(Error::network(
            request("GET", "https://example.com/resource"),
            message,
            0,
        ))
    })
}

fn retry(inner: QueueAdapter, delays: Arc<Mutex<Vec<f64>>>) -> Retry<QueueAdapter, Backoff> {
    Retry::with_strategy(inner, Backoff::new().with_randomizer(|| 1.0)).with_sleep(move |seconds| {
        delays.lock().unwrap().push(seconds);
    })
}

#[test]
fn it_retries_transient_failures_until_success() {
    let inner = QueueAdapter::new(vec![err_network("reset"), err_network("reset"), ok(200)]);
    let delays = Arc::new(Mutex::new(Vec::new()));
    let response = retry(inner.clone(), Arc::clone(&delays))
        .send_request(request("GET", "https://example.com/resource"))
        .unwrap();
    assert_eq!(response.status(), 200);
    assert_eq!(inner.calls(), 3);
    assert_eq!(*delays.lock().unwrap(), vec![0.1, 0.2]);
}

#[test]
fn it_stops_and_rethrows_after_exhausting_attempts() {
    let inner = QueueAdapter::new(vec![
        err_network("reset"),
        err_network("reset"),
        err_network("reset"),
    ]);
    let delays = Arc::new(Mutex::new(Vec::new()));
    let error = retry(inner.clone(), Arc::clone(&delays))
        .send_request(request("GET", "https://example.com/resource"))
        .unwrap_err();
    assert!(error.is_network());
    assert_eq!(inner.calls(), 3);
    assert_eq!(*delays.lock().unwrap(), vec![0.1, 0.2]);
}

#[test]
fn it_does_not_retry_request_exceptions() {
    let req = request("GET", "https://example.com/resource");
    let inner = QueueAdapter::new(vec![Arc::new({
        let req = req.clone();
        move |_| Err(Error::invalid_uri(req.clone(), "bad"))
    })]);
    let delays = Arc::new(Mutex::new(Vec::new()));
    let error = retry(inner.clone(), Arc::clone(&delays))
        .send_request(req)
        .unwrap_err();
    assert!(error.is_request_exception());
    assert_eq!(inner.calls(), 1);
    assert!(delays.lock().unwrap().is_empty());
}

#[test]
fn it_does_not_retry_non_idempotent_methods() {
    let inner = QueueAdapter::new(vec![err_network("reset")]);
    let delays = Arc::new(Mutex::new(Vec::new()));
    let error = retry(inner.clone(), Arc::clone(&delays))
        .send_request(request("POST", "https://example.com/resource"))
        .unwrap_err();
    assert!(error.is_network());
    assert_eq!(inner.calls(), 1);
}

#[test]
fn it_retries_overloaded_status_responses() {
    let inner = QueueAdapter::new(vec![ok(503), ok(200)]);
    let delays = Arc::new(Mutex::new(Vec::new()));
    let response = retry(inner.clone(), Arc::clone(&delays))
        .send_request(request("GET", "https://example.com/resource"))
        .unwrap();
    assert_eq!(response.status(), 200);
    assert_eq!(inner.calls(), 2);
    assert_eq!(*delays.lock().unwrap(), vec![0.1]);
}

#[test]
fn it_retries_streams_only_when_no_bytes_were_delivered() {
    let inner = QueueAdapter::new(vec![
        err_network("reset"),
        Arc::new(|sink| {
            sink(b"hello");
            Ok(Response::builder().status(200).body(Bytes::new()).unwrap())
        }),
    ]);
    let delays = Arc::new(Mutex::new(Vec::new()));
    let mut received = Vec::new();
    let response = retry(inner.clone(), delays)
        .stream(
            request("GET", "https://example.com/resource"),
            &mut |chunk| received.extend_from_slice(chunk),
        )
        .unwrap();
    assert_eq!(response.status(), 200);
    assert_eq!(received, b"hello");
    assert_eq!(inner.calls(), 2);
}

#[test]
fn it_does_not_retry_streams_after_bytes_were_delivered() {
    let inner = QueueAdapter::new(vec![Arc::new(|sink| {
        sink(b"partial");
        Err(Error::network(
            request("GET", "https://example.com/resource"),
            "reset",
            0,
        ))
    })]);
    let delays = Arc::new(Mutex::new(Vec::new()));
    let mut received = Vec::new();
    let error = retry(inner.clone(), delays)
        .stream(
            request("GET", "https://example.com/resource"),
            &mut |chunk| received.extend_from_slice(chunk),
        )
        .unwrap_err();
    assert!(error.is_network());
    assert_eq!(received, b"partial");
    assert_eq!(inner.calls(), 1);
}

#[test]
fn it_forwards_configuration_to_the_inner_adapter() {
    let retry = Retry::new(QueueAdapter::new(vec![]));
    let configured = retry
        .with_timeout(5.0)
        .unwrap()
        .with_connect_timeout(1.0)
        .unwrap()
        .with_ssl_verification(false)
        .with_custom_ca("/etc/ssl/ca.pem")
        .with_certificate("/etc/ssl/client.pem", "/etc/ssl/client.key", None)
        .with_min_tls_version(Tls::V1_2);
    let _ = configured;
}
