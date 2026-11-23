# Streaming

## Responses

`stream()` delivers the response body to a sink callback chunk-by-chunk as
it arrives, so large downloads, Server-Sent Events, and LLM token streams are
consumed with bounded memory — the whole body is never held at once. It returns a
response carrying the status and headers; the body is empty because the body was
handed to the sink. Both adapters support it.

```php
<?php

$response = $client->stream($request, function (string $chunk): void {
    echo $chunk;
});

echo $response->getStatusCode();
```

The sink runs as each chunk arrives, which means it also applies backpressure: the
transfer does not read ahead while the sink is still working. To stop early, throw
from the sink.

```php
<?php

// Parse a line-delimited (NDJSON / SSE) stream as it streams in.
$buffer = '';

$client->stream($request, function (string $chunk) use (&$buffer): void {
    $buffer .= $chunk;

    while (($newline = strpos($buffer, "\n")) !== false) {
        $line = substr($buffer, 0, $newline);
        $buffer = substr($buffer, $newline + 1);
        // handle $line
    }
});
```

Notes:

- Use `sendRequest()` for normal requests — it buffers the body and returns a
  fully decodable response (`->json()`, `->form()`, `->multipart()`).
- `stream()` returns only once the stream ends. For an unbounded stream
  (e.g. SSE), set the transport timeout to no-limit (`CURLOPT_TIMEOUT_MS => 0` on
  cURL, `timeout => -1` on Swoole) and stop by throwing from the sink.
- The Swoole adapter must run inside a coroutine, like `sendRequest()`.

## Requests

Attach a file-backed body and the cURL adapter uploads it chunk-by-chunk through a
read callback, so memory stays bounded no matter how large the file is — it is
never read into a string. `createStreamFromFile()` opens the file lazily, so even
building the request costs nothing.

```php
<?php

$request = $requestFactory
    ->createRequest(Method::POST, 'https://example.com/upload')
    ->withHeader(Header::CONTENT_TYPE, ContentType::OCTET_STREAM)
    ->withBody($streamFactory->createStreamFromFile('/path/to/large.bin'));

$client->sendRequest($request);
```

The body's `getSize()` sets `Content-Length` when known; an unsized stream (e.g. a
pipe) is sent with chunked transfer encoding. Seekable bodies are rewound before
each attempt, so streamed uploads remain safe to retry.

### Multipart file uploads

`Part::file()` references a file by path and reads it lazily, so multipart uploads
stay bounded too — on **both** adapters:

```php
<?php

use Utopia\Psr7\Request\Multipart\Part;

$request = $requestFactory->multipart(Method::POST, 'https://example.com/upload', [
    'name' => 'Ada',
    'file' => Part::file('file', '/path/to/large.bin', 'large.bin', ContentType::OCTET_STREAM),
]);

$client->sendRequest($request);
```

The cURL adapter streams the serialised multipart body straight from disk through
its read callback. The Swoole adapter sends each file with its native `addFile()`,
which streams from disk with zero-copy `sendfile()`.

Notes:

- A raw (non-multipart) request body streams bounded on the cURL adapter; the
  Swoole coroutine client has no raw-body streaming, so it buffers raw bodies —
  use a multipart `Part::file()` for bounded uploads on Swoole.
- Swoole's native upload can't represent custom per-part headers, repeated field
  names, or empty files; a multipart body using any of those is buffered instead
  (it is still sent correctly, just not streamed).
