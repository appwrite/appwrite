# Utopia Client

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/client`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/client) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

An HTTP/1.1 client with immutable configuration, destination checks on every connection, redirects, retries with backoff, streaming and pooling.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-client = { git = "https://github.com/utopia-rs/client" }
```

# Getting started

`utopia-client` sends HTTP/1.1 requests over TCP or TLS on tokio. A `Client` wraps a transport (`Http`) with request defaults; every `with_*` returns a configured copy, so a client can be shared and specialised freely. Responses with a 4xx or 5xx status are returned, not raised: only a failure to get a response is an `Error`.

## Send a request

Build a `Request`, send it, and read the response's status, headers and body.

```rust
use utopia_client::{Client, Http, Request};

let client = Client::new(Http::new());
let request = Request::new("POST", "https://api.example.com/users")?
    .header("Content-Type", "application/json")?
    .body(r#"{"name":"Ada"}"#);

let response = client.send(request).await?;
println!("{} {}", response.status(), response.headers().line("content-type"));
println!("{}", response.text());
```

## Configure defaults

A base URI resolves relative requests, default headers fill in what a request does not set, and timeouts are in seconds (0 is no limit).

```rust
use utopia_client::{Client, Http, Request};

let client = Client::new(Http::new())
    .with_base_uri("https://api.example.com/v1")?
    .with_headers([("Accept", vec!["application/json"])])
    .with_bearer_auth("token")
    .with_timeout(10.0)?
    .with_connect_timeout(2.0)?;

// GET https://api.example.com/v1/users?page=2
let users = client.send(Request::new("GET", "users?page=2")?).await?;
```

## Fetch a URL a user chose

`PublicInternet` refuses private, loopback, link-local and reserved addresses. The transport checks the address each connection actually reached, on every redirect hop, so DNS cannot answer differently between a check and the connection.

```rust
use utopia_client::{Client, Error, Http, PublicInternet, Request};

let client = Client::new(Http::new())
    .with_destinations(PublicInternet::default())
    .with_follow_redirects(true, 5)?;

match client.send(Request::new("GET", "http://169.254.169.254/latest/meta-data")?).await {
    Err(Error::Destination(message)) => println!("refused: {message}"),
    other => println!("{other:?}"),
}
```

## Stream a large response

`stream` passes each body chunk to a sink as it arrives, so memory stays bounded; the returned response carries the status and headers.

```rust
use utopia_client::{Client, Http, Request};

let client = Client::new(Http::new());
let mut size = 0usize;
let mut count = |chunk: &[u8]| size += chunk.len();
let response = client.stream(Request::new("GET", "https://example.com/large.bin")?, &mut count).await?;
println!("{} bytes, status {}", size, response.status());
```

## Retry transient failures

`Retry` decorates a transport. The default `Backoff` retries idempotent methods on network failures and 429, 502, 503 and 504 responses, with exponential backoff and full jitter, honouring a numeric `Retry-After`.

```rust
use utopia_client::{Backoff, Error, Outcome, Request, Strategy};

let backoff = Backoff::new(3, 0.1, 10.0, 2.0).with_randomizer(|| 1.0);
let request = Request::new("GET", "https://example.com/")?;
let reset = Error::Connection("Connection reset".into());

assert_eq!(backoff.delay(&request, 1, Outcome::Error(&reset)), Some(0.1));
assert_eq!(backoff.delay(&request, 2, Outcome::Error(&reset)), Some(0.2));
assert_eq!(backoff.delay(&request, 3, Outcome::Error(&reset)), None);
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
