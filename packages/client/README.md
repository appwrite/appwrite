# Utopia Client

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/client`](https://github.com/utopia-php/monorepo/tree/main/packages/client) — please open issues and pull requests there.

A small PSR-18 HTTP client for PHP 8.4+. cURL and Swoole coroutine transports, using `utopia-php/psr7` for PSR-7 messages and request/response helpers.

## Install

```bash
composer require utopia-php/client
```

Use `ext-curl` for the cURL adapter and `ext-swoole` for the Swoole coroutine adapter.

## Quick start

```php
<?php

use Utopia\Client;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request;

require __DIR__ . '/vendor/autoload.php';

$client = new Client(new CurlAdapter());
$requestFactory = new Request\Factory();

$request = $requestFactory->json(Method::POST, 'https://example.com/users', [
    'name' => 'Ada',
]);

$response = $client->sendRequest($request);

echo $response->getStatusCode();
echo $response->json()['name'];
```

`Utopia\Client` implements `Psr\Http\Client\ClientInterface`, so it works anywhere a PSR-18 client is expected. HTTP/1.1 is used by default and redirects are not followed unless you call `withFollowRedirects()`, so you receive exactly the response the server returned.

The concrete `Utopia\Psr7` messages and factories are provided by the `utopia-php/psr7` dependency.

## Configure the client

Defaults are immutable — each `with*()` returns a configured clone, and a default never overrides a header already set on the request.

```php
<?php

use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;

$client = $client
    ->withBaseUri('https://api.example.com/v1')   // relative request URIs resolve against this
    ->withHeaders([
        Header::ACCEPT => ContentType::JSON,
        Header::USER_AGENT => 'Acme API Client',
    ])
    ->withBearerAuth('token');                     // or ->withBasicAuth('user', 'pass')
```

## Build requests

```php
<?php

use Utopia\Psr7\Method;
use Utopia\Psr7\Request;
use Utopia\Psr7\Request\Multipart\Part;

$requestFactory = new Request\Factory();

$json = $requestFactory->json(Method::POST, 'https://api.example.com/users', [
    'name' => 'Ada',
]);

$form = $requestFactory->form(Method::POST, 'https://api.example.com/sessions', [
    'email' => 'ada@example.com',
    'password' => 'secret',
]);

$query = $requestFactory->query(Method::GET, 'https://api.example.com/users', [
    'page' => 2,
    'search' => 'Ada Lovelace',
]);

$upload = $requestFactory->multipart(Method::POST, 'https://api.example.com/uploads', [
    'name' => 'Ada',
    'avatar' => Part::file('avatar', '/tmp/avatar.png', 'avatar.png', 'image/png'),
]);

$xml = $requestFactory->xml(Method::POST, 'https://api.example.com/users', '<user><name>Ada</name></user>');

$text = $requestFactory->text(Method::POST, 'https://api.example.com/notes', 'Hello, Ada');
```

Pass a 4th `$headers` array to any factory method to override defaults, such as a custom `Content-Type` or `Accept`.

## Decode responses

```php
<?php

$type = $response->contentType(); // media type without parameters, e.g. "application/json"
$data = $response->json();
$xml = $response->xml();
$text = $response->text();
$form = $response->form();
$parts = $response->multipart();

foreach ($parts as $part) {
    $name = $part->name();
    $filename = $part->filename();
    $contentType = $part->contentType();
    $body = $part->body();
}
```

HTTP `4xx` and `5xx` responses are returned, not thrown, as required by PSR-18.

## More

- [Streaming](docs/streaming.md) — consume large downloads (SSE, LLM token streams) and upload large files, both with bounded memory.
- [Retries](docs/retries.md) — the `Retry` decorator and its configurable best-practice backoff strategy.
- [Pooling](docs/pooling.md) — share a bounded set of reused connections across concurrent callers with `Pool`.
- [Configuration](docs/configuration.md) — timeouts, TLS, connection reuse, native cURL options, and the Swoole coroutine adapter.
- [Error handling](docs/error-handling.md) — the PSR-18 exception hierarchy the adapters throw.
- [Development](docs/development.md) — running the test suite and checks.
