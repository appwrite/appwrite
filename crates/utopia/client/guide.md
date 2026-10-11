# Getting started

`utopia-client` sends HTTP/1.1 requests over TCP or TLS on tokio. A `Client` wraps a transport (`Http`) with request defaults; every `with_*` returns a configured copy, so a client can be shared and specialised freely. Responses with a 4xx or 5xx status are returned, not raised: only a failure to get a response is an `Error`.

## Send a request

Build a `Request`, send it, and read the response's status, headers and body.

```rust,no_run
use utopia_client::{Client, Http, Request};

# async fn run() -> Result<(), utopia_client::Error> {
let client = Client::new(Http::new());
let request = Request::new("POST", "https://api.example.com/users")?
    .header("Content-Type", "application/json")?
    .body(r#"{"name":"Ada"}"#);

let response = client.send(request).await?;
println!("{} {}", response.status(), response.headers().line("content-type"));
println!("{}", response.text());
# Ok(())
# }
```

```php
use Utopia\Client\Adapter\Curl\Client as Curl;
use Utopia\Client\Client;
use Utopia\Psr7\Request;
use Utopia\Psr7\Stream;
use Utopia\Psr7\Uri;

$client = new Client(new Curl());
$request = (new Request('POST', Uri::parse('https://api.example.com/users')))
    ->withHeader('Content-Type', 'application/json')
    ->withBody((new Stream\Factory())->createStream('{"name":"Ada"}'));

echo $request->getMethod(), ' ', $request->getUri(), "\n";
// $response = $client->sendRequest($request);
// echo $response->getStatusCode(), ' ', $response->getBody(), "\n";
```

## Configure defaults

A base URI resolves relative requests, default headers fill in what a request does not set, and timeouts are in seconds (0 is no limit).

```rust,no_run
use utopia_client::{Client, Http, Request};

# async fn run() -> Result<(), utopia_client::Error> {
let client = Client::new(Http::new())
    .with_base_uri("https://api.example.com/v1")?
    .with_headers([("Accept", vec!["application/json"])])
    .with_bearer_auth("token")
    .with_timeout(10.0)?
    .with_connect_timeout(2.0)?;

// GET https://api.example.com/v1/users?page=2
let users = client.send(Request::new("GET", "users?page=2")?).await?;
# Ok(())
# }
```

```php
use Utopia\Client\Adapter\Curl\Client as Curl;
use Utopia\Client\Client;
use Utopia\Psr7\Request;
use Utopia\Psr7\Uri;

$client = (new Client(new Curl()))
    ->withBaseUri('https://api.example.com/v1')
    ->withHeaders(['Accept' => 'application/json'])
    ->withBearerAuth('token')
    ->withTimeout(10.0)
    ->withConnectTimeout(2.0);

// $client->sendRequest(new Request('GET', Uri::parse('users?page=2')))
// sends GET https://api.example.com/v1/users?page=2
```

## Fetch a URL a user chose

`PublicInternet` refuses private, loopback, link-local and reserved addresses. The transport checks the address each connection actually reached, on every redirect hop, so DNS cannot answer differently between a check and the connection.

```rust,no_run
use utopia_client::{Client, Error, Http, PublicInternet, Request};

# async fn run() -> Result<(), utopia_client::Error> {
let client = Client::new(Http::new())
    .with_destinations(PublicInternet::default())
    .with_follow_redirects(true, 5)?;

match client.send(Request::new("GET", "http://169.254.169.254/latest/meta-data")?).await {
    Err(Error::Destination(message)) => println!("refused: {message}"),
    other => println!("{other:?}"),
}
# Ok(())
# }
```

```php
use Utopia\Client\Adapter\Curl\Client as Curl;
use Utopia\Client\Client;
use Utopia\Client\Destinations\PublicInternet;
use Utopia\Client\Exception\DestinationException;
use Utopia\Psr7\Request;
use Utopia\Psr7\Uri;

$client = (new Client(new Curl(), new PublicInternet()))->withFollowRedirects(true, 5);

$request = new Request('GET', Uri::parse('http://169.254.169.254/latest/meta-data'));
// $client->sendRequest($request) throws DestinationException for the link-local address
```

## Stream a large response

`stream` passes each body chunk to a sink as it arrives, so memory stays bounded; the returned response carries the status and headers.

```rust,no_run
use utopia_client::{Client, Http, Request};

# async fn run() -> Result<(), utopia_client::Error> {
let client = Client::new(Http::new());
let mut size = 0usize;
let mut count = |chunk: &[u8]| size += chunk.len();
let response = client.stream(Request::new("GET", "https://example.com/large.bin")?, &mut count).await?;
println!("{} bytes, status {}", size, response.status());
# Ok(())
# }
```

```php
use Utopia\Client\Adapter\Curl\Client as Curl;
use Utopia\Client\Client;
use Utopia\Psr7\Request;
use Utopia\Psr7\Uri;

$client = new Client(new Curl());
$size = 0;
$sink = function (string $chunk) use (&$size) {
    $size += strlen($chunk);
};
// $response = $client->stream(new Request('GET', Uri::parse('https://example.com/large.bin')), $sink);
// $response->getBody() is empty: the bytes went to $sink
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
# Ok::<(), utopia_client::Error>(())
```

```php
use Utopia\Client\Decorator\Retry\Backoff;
use Utopia\Client\Exception\ConnectionException;
use Utopia\Psr7\Request;
use Utopia\Psr7\Uri;

$backoff = new Backoff(3, 0.1, 10.0, 2.0, fn (): float => 1.0);
$request = new Request('GET', Uri::parse('https://example.com/'));
$reset = new ConnectionException($request, 'Connection reset');

var_dump($backoff->delay($request, 1, null, $reset)); // float(0.1)
var_dump($backoff->delay($request, 2, null, $reset)); // float(0.2)
var_dump($backoff->delay($request, 3, null, $reset)); // NULL
```
