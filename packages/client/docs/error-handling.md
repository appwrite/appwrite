# Error Handling

A request has two possible outcomes: it returns a response, or it throws. An HTTP
`4xx` or `5xx` is a **response**, not an exception — as required by PSR-18, those
are returned so you inspect `getStatusCode()` yourself. Exceptions are reserved for
failures where no usable response was produced.

## Two kinds of failure

Every exception implements `Psr\Http\Client\ClientExceptionInterface` and falls into
one of two branches. The branch tells you whether retrying could help:

- **`NetworkExceptionInterface`** — the transport failed and the request never
  completed (DNS, connect, timeout, reset, TLS). These are often transient, so
  retrying an *idempotent* request with backoff is reasonable.
- **`RequestExceptionInterface`** — the request or the response is fundamentally
  invalid (non-absolute URI, missing extension, malformed response). Retrying the
  same request will not help.

Catch the interfaces, not the concrete classes, when all you need is that decision:

```php
<?php

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;

for ($attempt = 1; ; $attempt++) {
    try {
        $response = $client->sendRequest($request);
        break;
    } catch (NetworkExceptionInterface $error) {
        if ($attempt >= 3) {
            throw $error;
        }
        // Transport failure — back off and retry. Only do this for idempotent
        // requests; a non-idempotent request (e.g. POST) may have been received.
    } catch (RequestExceptionInterface $error) {
        // The request or response is invalid — surface it; retrying is pointless.
        throw $error;
    }
}
```

## Hierarchy

```
ClientExceptionInterface
├── NetworkExceptionInterface — transport failed; the request did not complete
│   └── NetworkException
│       ├── DnsException         host could not be resolved
│       ├── TimeoutException     connect or transfer timed out
│       ├── ProtocolException    HTTP framing / HTTP-2/3 transport error
│       ├── ProxyException       proxy or SOCKS handshake failed
│       └── ConnectionException  refused, reset, unreachable, or broken
│           └── TlsException     TLS handshake or certificate failure
└── RequestExceptionInterface — the request or response is invalid; retrying will not help
    └── RequestException
        ├── InvalidUriException             URI is not absolute or uses an unsupported scheme
        ├── AdapterPreconditionException    runtime precondition unmet (extension missing, not in a coroutine)
        ├── AdapterInitializationException  the transport could not be initialized
        └── InvalidResponseException        the server's response was malformed
```

`TlsException` is a `ConnectionException`, which is a `NetworkException`. **Order
matters** when catching concrete classes: list the most specific first, or a broader
`catch` will shadow it.

```php
<?php

use Utopia\Client\Exception\ConnectionException;
use Utopia\Client\Exception\DnsException;
use Utopia\Client\Exception\InvalidResponseException;
use Utopia\Client\Exception\InvalidUriException;
use Utopia\Client\Exception\ProtocolException;
use Utopia\Client\Exception\ProxyException;
use Utopia\Client\Exception\TimeoutException;
use Utopia\Client\Exception\TlsException;

try {
    $response = $client->sendRequest($request);
} catch (TimeoutException $error) {
    // Connect or transfer timed out.
} catch (DnsException $error) {
    // Host could not be resolved.
} catch (TlsException $error) {        // before ConnectionException — it is a subclass
    // TLS handshake or certificate failure.
} catch (ProxyException $error) {
    // Proxy or SOCKS handshake failed.
} catch (ProtocolException $error) {
    // HTTP framing / HTTP-2/3 transport error.
} catch (ConnectionException $error) {
    // Connection refused, reset, unreachable, or broken.
} catch (InvalidUriException $error) {
    // The request URI is not absolute or uses an unsupported scheme.
} catch (InvalidResponseException $error) {
    // The server's response was malformed.
}
```

## What an exception carries

Both branches expose the request that failed via `getRequest()`, alongside the
standard SPL accessors:

```php
<?php

use Psr\Http\Client\NetworkExceptionInterface;

try {
    $response = $client->sendRequest($request);
} catch (NetworkExceptionInterface $error) {
    $error->getRequest();   // the RequestInterface that failed
    $error->getMessage();   // human-readable description
    $error->getCode();      // native cURL errno / Swoole error code
    $error->getPrevious();  // underlying throwable, if any
}
```
