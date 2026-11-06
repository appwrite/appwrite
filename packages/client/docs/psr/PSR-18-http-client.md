HTTP Client
===========

This document describes a common interface for sending HTTP requests and receiving HTTP responses.

The key words "MUST", "MUST NOT", "REQUIRED", "SHALL", "SHALL NOT", "SHOULD",
"SHOULD NOT", "RECOMMENDED", "MAY", and "OPTIONAL" in this document are to be
interpreted as described in [RFC 2119](http://tools.ietf.org/html/rfc2119).

## Goal

The goal of this PSR is to allow developers to create libraries decoupled from HTTP client
implementations. This will make libraries more reusable as it reduces the number of
dependencies and lowers the likelihood of version conflicts.

A second goal is that HTTP clients can be replaced as per the
[Liskov substitution principle][Liskov]. This means that all clients MUST behave in the
same way when sending a request.

## Definitions

* **Client** - A Client is a library that implements this specification for the purposes of
sending PSR-7-compatible HTTP Request messages and returning a PSR-7-compatible HTTP Response message to a Calling library.
* **Calling Library** - A Calling Library is any code that makes use of a Client.  It does not implement
this specification's interfaces but consumes an object that implements them (a Client).

## Client

A Client is an object implementing `ClientInterface`.

A Client MAY:

* Choose to send an altered HTTP request from the one it was provided. For example, it could
compress an outgoing message body.
* Choose to alter a received HTTP response before returning it to the calling library. For example, it could
decompress an incoming message body.

If a Client chooses to alter either the HTTP request or HTTP response, it MUST ensure that the
object remains internally consistent.  For example, if a Client chooses to decompress the message
body then it MUST also remove the `Content-Encoding` header and adjust the `Content-Length` header.

Note that as a result, since [PSR-7 objects are immutable](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-7-http-message-meta.md#why-value-objects),
the Calling Library MUST NOT assume that the object passed to `ClientInterface::sendRequest()` will be the same PHP object
that is actually sent. For example, the Request object that is returned by an exception MAY be a different object than
the one passed to `sendRequest()`, so comparison by reference (===) is not possible.

A Client MUST:

* Reassemble a multi-step HTTP 1xx response itself so that what is returned to the Calling Library is a valid HTTP response
of status code 200 or higher.

## Error handling

A Client MUST NOT treat a well-formed HTTP request or HTTP response as an error condition. For example, response
status codes in the 400 and 500 range MUST NOT cause an exception and MUST be returned to the Calling Library as normal.

A Client MUST throw an instance of `Psr\Http\Client\ClientExceptionInterface` if and only if it is unable to send
the HTTP request at all or if the HTTP response could not be parsed into a PSR-7 response object.

If a request cannot be sent because the request message is not a well-formed HTTP request or is missing some critical
piece of information (such as a Host or Method), the Client MUST throw an instance of `Psr\Http\Client\RequestExceptionInterface`.

If the request cannot be sent due to a network failure of any kind, including a timeout, the Client MUST throw an
instance of `Psr\Http\Client\NetworkExceptionInterface`.

Clients MAY throw more specific exceptions than those defined here (a `TimeOutException` or `HostNotFoundException` for
example), provided they implement the appropriate interface defined above.

## Interfaces

### ClientInterface

```php
namespace Psr\Http\Client;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface ClientInterface
{
    /**
     * Sends a PSR-7 request and returns a PSR-7 response.
     *
     * @param RequestInterface $request
     *
     * @return ResponseInterface
     *
     * @throws \Psr\Http\Client\ClientExceptionInterface If an error happens while processing the request.
     */
    public function sendRequest(RequestInterface $request): ResponseInterface;
}
```

### ClientExceptionInterface

```php
namespace Psr\Http\Client;

/**
 * Every HTTP client related exception MUST implement this interface.
 */
interface ClientExceptionInterface extends \Throwable
{
}
```

### RequestExceptionInterface

```php
namespace Psr\Http\Client;

use Psr\Http\Message\RequestInterface;

/**
 * Exception for when a request failed.
 *
 * Examples:
 *      - Request is invalid (e.g. method is missing)
 *      - Runtime request errors (e.g. the body stream is not seekable)
 */
interface RequestExceptionInterface extends ClientExceptionInterface
{
    /**
     * Returns the request.
     *
     * The request object MAY be a different object from the one passed to ClientInterface::sendRequest()
     *
     * @return RequestInterface
     */
    public function getRequest(): RequestInterface;
}
```

### NetworkExceptionInterface

```php
namespace Psr\Http\Client;

use Psr\Http\Message\RequestInterface;

/**
 * Thrown when the request cannot be completed because of network issues.
 *
 * There is no response object as this exception is thrown when no response has been received.
 *
 * Example: the target host name can not be resolved or the connection failed.
 */
interface NetworkExceptionInterface extends ClientExceptionInterface
{
    /**
     * Returns the request.
     *
     * The request object MAY be a different object from the one passed to ClientInterface::sendRequest()
     *
     * @return RequestInterface
     */
    public function getRequest(): RequestInterface;
}
```

[Liskov]: https://en.wikipedia.org/wiki/Liskov_substitution_principle
