An HTTP client needs to build requests and read responses; a server needs to describe the request it received. This library gives both sides one vocabulary: a `Request`, a `Response` and a `ServerRequest`, each with case-insensitive headers and a body stream, plus a `Uri` and factories that build typed requests. This guide covers the common tasks.

## Build a request

`request::Factory` parses the URI, upper-cases the method and sets `Host`. Messages are values: each `with_*` call returns the changed message.

```
use utopia_psr7::{Message, StreamRef, request::Factory};

let request = Factory::default()
    .create_request("post", "https://example.com/users?active=1")?
    .with_header("Accept", ["application/json", "text/plain"])?
    .with_body(StreamRef::from_bytes(b"body"));

assert_eq!(request.method(), "POST");
assert_eq!(request.request_target(), "/users?active=1");
assert_eq!(request.header_line("accept"), "application/json, text/plain");
assert_eq!(request.header_line("Host"), "example.com");
assert_eq!(request.body().to_bytes(), b"body");
# Ok::<(), utopia_psr7::Error>(())
```

```php
use Utopia\Psr7\Request;
use Utopia\Psr7\Stream;

$request = (new Request\Factory())
    ->createRequest('post', 'https://example.com/users?active=1')
    ->withHeader('Accept', ['application/json', 'text/plain'])
    ->withBody(new Stream('body'));

echo $request->getMethod(), "\n";              // POST
echo $request->getRequestTarget(), "\n";       // /users?active=1
echo $request->getHeaderLine('accept'), "\n";  // application/json, text/plain
echo $request->getHeaderLine('Host'), "\n";    // example.com
echo $request->getBody(), "\n";                // body
```

## Send JSON or a form

The typed builders encode the payload and set `Content-Type` (and `Accept` for JSON) unless the headers you pass set them.

```
use serde_json::json;
use utopia_psr7::{Message, request::Factory};

let factory = Factory::default();

let request = factory.json("POST", "https://example.com/users", &json!({"name": "Ada"}), &[])?;
assert_eq!(request.header_line("Content-Type"), "application/json");
assert_eq!(request.body().to_bytes(), br#"{"name":"Ada"}"#);

let query = factory.query("GET", "https://example.com/users?active=1", &json!({
    "page": 2,
    "search": "Ada Lovelace",
}), &[])?;
assert_eq!(query.uri().to_string(), "https://example.com/users?active=1&page=2&search=Ada%20Lovelace");
# Ok::<(), utopia_psr7::Error>(())
```

```php
use Utopia\Psr7\Request;

$factory = new Request\Factory();

$json = $factory->json('POST', 'https://example.com/users', ['name' => 'Ada']);
echo $json->getHeaderLine('Content-Type'), "\n"; // application/json
echo $json->getBody(), "\n";                     // {"name":"Ada"}

$query = $factory->query('GET', 'https://example.com/users?active=1', ['page' => 2, 'search' => 'Ada Lovelace']);
echo $query->getUri(), "\n"; // https://example.com/users?active=1&page=2&search=Ada%20Lovelace
```

## Upload files with multipart

A multipart body is serialised lazily: files on disk are read in chunks while the request is sent.

```
use utopia_psr7::{Message, multipart::Part, request::Factory};

let request = Factory::default().multipart("POST", "https://example.com/upload", vec![
    Part::field("name", b"Ada", vec![]),
    Part::body("avatar", b"image-bytes", Some("ada.png"), Some("image/png"), vec![]),
], &[])?;

assert!(request.header_line("Content-Type").starts_with("multipart/form-data; boundary=utopia-"));
let body = String::from_utf8(request.body().to_bytes()).unwrap();
assert!(body.contains("Content-Disposition: form-data; name=\"avatar\"; filename=\"ada.png\""));
# Ok::<(), utopia_psr7::Error>(())
```

```php
use Utopia\Psr7\Request;
use Utopia\Psr7\Request\Multipart\Part;

$request = (new Request\Factory())->multipart('POST', 'https://example.com/upload', [
    'name' => 'Ada',
    'avatar' => Part::body('avatar', 'image-bytes', 'ada.png', 'image/png'),
]);

var_dump(str_starts_with($request->getHeaderLine('Content-Type'), 'multipart/form-data; boundary=utopia-')); // bool(true)
var_dump(str_contains((string) $request->getBody(), 'name="avatar"; filename="ada.png"'));                // bool(true)
```

## Read a response

`Response` decodes its body as JSON, text, a form, XML or multipart parts.

```
use utopia_psr7::{Message, Response, StreamRef};

let response = Response::new(200, "")?
    .with_header("Content-Type", "multipart/form-data; boundary=abc123")?
    .with_body(StreamRef::from_bytes(
        b"--abc123\r\nContent-Disposition: form-data; name=\"name\"\r\n\r\nAda\r\n--abc123--\r\n",
    ));

let parts = response.multipart()?;
assert_eq!(parts.len(), 1);
assert_eq!(parts[0].name().as_deref(), Some(&b"name"[..]));
assert_eq!(parts[0].body(), b"Ada");
# Ok::<(), utopia_psr7::Error>(())
```

```php
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

$response = (new Response(200))
    ->withHeader('Content-Type', 'multipart/form-data; boundary=abc123')
    ->withBody(new Stream("--abc123\r\nContent-Disposition: form-data; name=\"name\"\r\n\r\nAda\r\n--abc123--\r\n"));

$parts = $response->multipart();
echo count($parts), "\n";         // 1
echo $parts[0]->name(), "\n";     // name
echo $parts[0]->body(), "\n";     // Ada
```

## Work with URIs

`Uri::parse` normalises the scheme and host and percent-encodes what needs it; default ports are left out.

```
use utopia_psr7::Uri;

let uri = Uri::parse("HTTPS://Example.com:443/a path?x=1")?;
assert_eq!(uri.to_string(), "https://example.com/a%20path?x=1");
assert_eq!(uri.with_port(Some(8443))?.authority(), "example.com:8443");
# Ok::<(), utopia_psr7::Error>(())
```

```php
use Utopia\Psr7\Uri;

$uri = Uri::parse('HTTPS://Example.com:443/a path?x=1');
echo $uri, "\n";                                // https://example.com/a%20path?x=1
echo $uri->withPort(8443)->getAuthority(), "\n"; // example.com:8443
```
