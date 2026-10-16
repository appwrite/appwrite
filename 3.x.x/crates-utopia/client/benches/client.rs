use std::time::Instant;

use bytes::Bytes;
use http::Request;
use utopia_client::{Adapter, Backoff, Client, Retry, Strategy, StreamingClient};

#[derive(Clone, Debug)]
struct NoopAdapter;

impl StreamingClient for NoopAdapter {
    fn send_request(
        &self,
        _request: Request<Bytes>,
    ) -> Result<http::Response<Bytes>, utopia_client::Error> {
        Ok(http::Response::builder()
            .status(200)
            .body(Bytes::new())
            .unwrap())
    }

    fn stream(
        &self,
        request: Request<Bytes>,
        _sink: &mut dyn FnMut(&[u8]),
    ) -> Result<http::Response<Bytes>, utopia_client::Error> {
        self.send_request(request)
    }
}

impl Adapter for NoopAdapter {
    fn with_timeout(&self, _seconds: f64) -> Result<Self, utopia_client::Error> {
        Ok(self.clone())
    }
    fn with_connect_timeout(&self, _seconds: f64) -> Result<Self, utopia_client::Error> {
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
    fn with_min_tls_version(&self, _version: utopia_client::Tls) -> Self {
        self.clone()
    }
    fn with_connection_reuse(&self, _enabled: bool) -> Self {
        self.clone()
    }
}

fn bench(name: &str, iters: u64, mut f: impl FnMut()) {
    for _ in 0..iters.min(1_000) {
        f();
    }
    let start = Instant::now();
    for _ in 0..iters {
        f();
    }
    let elapsed = start.elapsed();
    println!(
        "{name}: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}

fn main() {
    let client = Client::new(NoopAdapter)
        .with_base_uri("https://api.example.com/v1")
        .unwrap()
        .with_headers([("Accept", "application/json")])
        .with_bearer_auth("token");
    let request = Request::builder()
        .method("GET")
        .uri("users")
        .body(Bytes::new())
        .unwrap();
    bench("client_send", 50_000, || {
        std::hint::black_box(client.send_request(request.clone()).unwrap());
    });

    let retry = Retry::new(NoopAdapter);
    let absolute = Request::builder()
        .method("GET")
        .uri("https://example.com/users")
        .body(Bytes::new())
        .unwrap();
    bench("retry_send", 50_000, || {
        std::hint::black_box(retry.send_request(absolute.clone()).unwrap());
    });

    let strategy = Backoff::new().with_randomizer(|| 1.0);
    let error = utopia_client::Error::network(absolute.clone(), "reset", 0);
    bench("backoff_delay", 200_000, || {
        std::hint::black_box(strategy.delay(&absolute, 1, None, Some(&error)));
    });
}
