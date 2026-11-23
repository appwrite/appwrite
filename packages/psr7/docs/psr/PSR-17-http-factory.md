HTTP Factories
==============

This document describes a common standard for factories that create [PSR-7][psr7]
compliant HTTP objects.

PSR-7 did not include a recommendation on how to create HTTP objects, which leads
to difficulties when needing to create new HTTP objects within components that are
not tied to a specific implementation of PSR-7.

The interfaces outlined in this document describe methods by which PSR-7 objects
can be instantiated.

The key words "MUST", "MUST NOT", "REQUIRED", "SHALL", "SHALL NOT", "SHOULD",
"SHOULD NOT", "RECOMMENDED", "MAY", and "OPTIONAL" in this document are to be
interpreted as described in [RFC 2119][rfc2119].

[psr7]: https://www.php-fig.org/psr/psr-7/
[rfc2119]: https://tools.ietf.org/html/rfc2119

## 1. Specification

An HTTP factory is a method by which a new HTTP object, as defined by PSR-7,
is created. HTTP factories MUST implement these interfaces for each object type
that is provided by the package.

## 2. Interfaces

The following interfaces MAY be implemented together within a single class or
in separate classes.

### 2.1 RequestFactoryInterface

Has the ability to create client requests.

```php
namespace Psr\Http\Message;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

interface RequestFactoryInterface
{
    /**
     * Create a new request.
     *
     * @param string $method The HTTP method associated with the request.
     * @param UriInterface|string $uri The URI associated with the request. 
     */
    public function createRequest(string $method, $uri): RequestInterface;
}
```

### 2.2 ResponseFactoryInterface

Has the ability to create responses.

```php
namespace Psr\Http\Message;

use Psr\Http\Message\ResponseInterface;

interface ResponseFactoryInterface
{
    /**
     * Create a new response.
     *
     * @param int $code The HTTP status code. Defaults to 200.
     * @param string $reasonPhrase The reason phrase to associate with the status code
     *     in the generated response. If none is provided, implementations MAY use
     *     the defaults as suggested in the HTTP specification.
     */
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface;
}
```

### 2.3 ServerRequestFactoryInterface

Has the ability to create server requests.

```php
namespace Psr\Http\Message;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

interface ServerRequestFactoryInterface
{
    /**
     * Create a new server request.
     *
     * Note that server parameters are taken precisely as given - no parsing/processing
     * of the given values is performed. In particular, no attempt is made to
     * determine the HTTP method or URI, which must be provided explicitly.
     *
     * @param string $method The HTTP method associated with the request.
     * @param UriInterface|string $uri The URI associated with the request. 
     * @param array $serverParams An array of Server API (SAPI) parameters with
     *     which to seed the generated request instance.
     */
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface;
}
```

### 2.4 StreamFactoryInterface

Has the ability to create streams for requests and responses.

```php
namespace Psr\Http\Message;

use Psr\Http\Message\StreamInterface;

interface StreamFactoryInterface
{
    /**
     * Create a new stream from a string.
     *
     * The stream SHOULD be created with a temporary resource.
     *
     * @param string $content String content with which to populate the stream.
     */
    public function createStream(string $content = ''): StreamInterface;

    /**
     * Create a stream from an existing file.
     *
     * The file MUST be opened using the given mode, which may be any mode
     * supported by the `fopen` function.
     *
     * The `$filename` MAY be any string supported by `fopen()`.
     *
     * @param string $filename The filename or stream URI to use as basis of stream.
     * @param string $mode The mode with which to open the underlying filename/stream.
     *
     * @throws \RuntimeException If the file cannot be opened.
     * @throws \InvalidArgumentException If the mode is invalid.
     */
    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface;

    /**
     * Create a new stream from an existing resource.
     *
     * The stream MUST be readable and may be writable.
     *
     * @param resource $resource The PHP resource to use as the basis for the stream.
     */
    public function createStreamFromResource($resource): StreamInterface;
}
```

Implementations of this interface SHOULD use a temporary stream when creating
resources from strings. The RECOMMENDED method for doing so is:

```php
$resource = fopen('php://temp', 'r+');
```

### 2.5 UploadedFileFactoryInterface

Has the ability to create streams for uploaded files.

```php
namespace Psr\Http\Message;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

interface UploadedFileFactoryInterface
{
    /**
     * Create a new uploaded file.
     *
     * If a size is not provided it will be determined by checking the size of
     * the stream.
     *
     * @link http://php.net/manual/features.file-upload.post-method.php
     * @link http://php.net/manual/features.file-upload.errors.php
     *
     * @param StreamInterface $stream The underlying stream representing the
     *     uploaded file content.
     * @param int $size The size of the file in bytes.
     * @param int $error The PHP file upload error.
     * @param string $clientFilename The filename as provided by the client, if any.
     * @param string $clientMediaType The media type as provided by the client, if any.
     *
     * @throws \InvalidArgumentException If the file resource is not readable.
     */
    public function createUploadedFile(
        StreamInterface $stream,
        ?int $size = null,
        int $error = \UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null
    ): UploadedFileInterface;
}
```

Since [psr/http-factory version 1.1](https://packagist.org/packages/psr/http-factory#1.1.0),
the above interface has been updated to add explicit nullable types.

### 2.6 UriFactoryInterface

Has the ability to create URIs for client and server requests.

```php
namespace Psr\Http\Message;

use Psr\Http\Message\UriInterface;

interface UriFactoryInterface
{
    /**
     * Create a new URI.
     *
     * @param string $uri The URI to parse.
     *
     * @throws \InvalidArgumentException If the given URI cannot be parsed.
     */
    public function createUri(string $uri = '') : UriInterface;
}
```
