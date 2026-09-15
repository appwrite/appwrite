# utopia-client

PSR-18-shaped HTTP client for Utopia. Rust port of [utopia-php/client](https://github.com/utopia-php/client) (`packages/client`, PHP SHA `fc54711d09f0`).

cURL and Swoole coroutine transports in PHP; this crate maps them to **reqwest (blocking)** and **Tokio + reqwest (async)**. Messages use the workspace `http` 1.x crate (no separate PSR-7 crate).

## Install

```toml
utopia-client = { path = "../utopia-client" } # workspace
```

## Quick start

```rust
use bytes::Bytes;
use http::Request;
use utopia_client::adapter::curl::Client as CurlAdapter;
use utopia_client::{Client, StreamingClient};

let client = Client::new(CurlAdapter::new())
    .with_base_uri("https://api.example.com/v1")?
    .with_bearer_auth("token");

let request = Request::builder()
    .method("GET")
    .uri("users")
    .body(Bytes::new())?;

let response = client.send_request(request)?;
println!("{}", response.status());
```

HTTP/1.1 is used by default and redirects are not followed. HTTP `4xx`/`5xx` are returned, not thrown.

## Transports

| PHP | Rust | Notes |
|-----|------|--------|
| `Utopia\Client\Adapter\Curl\Client` | `adapter::curl::Client` | reqwest blocking. Native `CURLOPT_*` keys become [`CurlOptions`]. |
| `Utopia\Client\Adapter\SwooleCoroutine\Client` | `adapter::swoole_coroutine::Client` | **Deviation:** Tokio + reqwest instead of `ext-swoole`. `send_request` must run on a Tokio runtime or it returns `AdapterPrecondition` (`"Swoole coroutine HTTP requests must run inside a coroutine."`). Enable feature `tokio` / `swoole` as documentation aliases; both adapters compile by default. |

Connection reuse is **off** by default (`pool_max_idle_per_host(0)`). `with_connection_reuse(true)` keeps a reqwest client so sockets can be reused.

Client certificates with a passphrase are not supported under rustls - documented limitation vs PHP `CURLOPT_KEYPASSWD`.

Path-rootless relative URIs (`users?active=1`) cannot be stored in `http::Uri`. Attach `RelativeUri` on the request extensions (the test helper does this automatically) and `Client` joins them like PHP.

## API Reference

### `Client<A: Adapter>` - PHP `Utopia\Client`

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new(adapter: A) -> Self` | Wrap an adapter. |
| `with_timeout` | `fn with_timeout(&self, seconds: f64) -> Result<Self, Error>` | PHP `withTimeout`. Invalid values are `Error::value()` (PHP `ValueError`). |
| `with_connect_timeout` | `fn with_connect_timeout(&self, seconds: f64) -> Result<Self, Error>` | PHP `withConnectTimeout`. |
| `with_ssl_verification` | `fn with_ssl_verification(&self, enabled: bool) -> Self` | PHP `withSslVerification`. |
| `with_custom_ca` | `fn with_custom_ca(&self, path: impl Into<String>) -> Self` | PHP `withCustomCA`. |
| `with_certificate` | `fn with_certificate(&self, cert, key, passphrase: Option<String>) -> Self` | PHP `withCertificate`. |
| `with_min_tls_version` | `fn with_min_tls_version(&self, version: Tls) -> Self` | PHP `withMinTlsVersion`. |
| `with_connection_reuse` | `fn with_connection_reuse(&self, enabled: bool) -> Self` | PHP `withConnectionReuse`. |
| `with_headers` | `fn with_headers(&self, headers) -> Self` | Default headers; does not override request headers. |
| `with_base_uri` | `fn with_base_uri(&self, uri: impl AsRef<str>) -> Result<Self, Error>` | Absolute URI only (`"Base URI must be absolute."`). |
| `with_basic_auth` | `fn with_basic_auth(&self, username, password) -> Self` | `Authorization: Basic …`. |
| `with_bearer_auth` | `fn with_bearer_auth(&self, token) -> Self` | `Authorization: Bearer …`. |
| `with_trace_propagation` | `fn with_trace_propagation(&self, enabled: bool) -> Self` | W3C `traceparent` from `utopia_span::Span::traceparent`. Off by default; never overwrites an inbound header. |
| `send_request` | via [`StreamingClient`] | PHP `sendRequest`. |
| `stream` | via [`StreamingClient`] | PHP `stream`; response body is empty. |

`RelativeUri` - Rust-only: path-rootless target (`users?x=1`) on `http::Extensions`.

### `Adapter` / `StreamingClient`

| Method | Description |
|--------|-------------|
| `send_request` | PSR-18 `sendRequest`. |
| `stream` | PHP `StreamingClientInterface::stream`. |
| `with_*` | Immutable clones; same names as `Client`. |

### `Decorator<A>` / `Retry<A, S>` / `Backoff`

PHP `Utopia\Client\Decorator`, `Decorator\Retry`, `Retry\Backoff`, `Retry\Strategy`.

`Backoff` retries idempotent methods (`GET`, `HEAD`, `PUT`, `DELETE`, `OPTIONS`, `TRACE`) on network errors and 429/502/503/504, with exponential backoff and full jitter. Numeric `Retry-After` is honoured and capped to `max_delay`. Streaming retries only if no bytes reached the sink.

### `Pool<T>`

PHP `Utopia\Client\Pool`. Borrows from `utopia_pools::Pool` for each request. The pools API is async (`use_resource`); `Pool::send_request` / `stream` block on it.

`T` must implement [`StreamingClient`] + `utopia_pools::Recover` + `Send + 'static`.

### `Tls`

PHP `Utopia\Client\Tls`: `V1_0`, `V1_1`, `V1_2`, `V1_3`.

### `ResponseBuilder`

PHP `Utopia\Client\Response\Builder`. Builds `http::Response<Bytes>`. **Deviation:** the `http` crate does not store custom reason phrases.

### Exceptions - PHP `Utopia\Client\Exception\*`

All live on [`Error`] with [`ErrorKind`]:

| Kind | PSR-18 role |
|------|-------------|
| `Network`, `Dns`, `Timeout`, `Protocol`, `Proxy`, `Connection`, `Tls` | `is_network()` |
| `Request`, `AdapterInitialization`, `AdapterPrecondition`, `InvalidResponse`, `InvalidUri` | `is_request_exception()` |
| `Value` | PHP `ValueError` (timeouts) |
| `InvalidArgument` | PHP `InvalidArgumentException` |

`get_request()` returns the failed request when present. Type aliases (`TimeoutException`, …) match PHP class names.

## Tests

```bash
cargo test -p utopia-client
```

Ports PHPUnit (`ClientTest`, `Decorator`, `Retry`, `Backoff`, `Pool`, `Exception`, `Timeout`, `AdapterContract`). HTTP uses localhost + utopia-test-wiremock (WireMock) - no live network.

Swoole contract tests run on Tokio (`adapter_swoole.rs`); default tests do not require PHP Swoole.

## Benchmarks

```bash
cargo bench --manifest-path crates-utopia/client/Cargo.toml
```

PHP twin: `benchmarks/client/`.

## License

MIT - see [LICENSE](LICENSE).
