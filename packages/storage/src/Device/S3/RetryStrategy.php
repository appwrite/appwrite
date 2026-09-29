<?php

declare(strict_types=1);

namespace Utopia\Storage\Device\S3;

use Closure;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Decorator\Retry\Strategy;
use Utopia\Client\Exception\ConnectionException;
use Utopia\Client\Exception\DnsException;
use Utopia\Client\Exception\TimeoutException;
use Utopia\Client\Exception\TlsException;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;

/**
 * Retry strategy for transient S3 failures, for use with the
 * `utopia-php/client` Retry decorator.
 *
 * A response is retried when the service reports it is throttled or failed
 * internally (SlowDown, InternalError, ...). The XML body is parsed first so a
 * specific error code wins over the HTTP status: a 5xx carrying a parseable but
 * non-transient code is not retried, while unparseable 429/5xx responses fall
 * back to status-code detection.
 *
 * A transport failure is retried when the request never reached the service
 * (unresolvable host, refused connection), or when it dropped or timed out and
 * replaying it cannot apply it twice. S3 writes with PUT to a fixed key or part
 * number, so a replayed PUT overwrites itself; a replayed POST may create a
 * second multipart upload, so it is not. TLS, proxy and protocol failures point
 * at configuration rather than a passing fault and are not retried.
 *
 * Waits use exponential backoff with full jitter so a fleet throttled at the
 * same moment does not retry in lockstep. A numeric Retry-After is honoured.
 * @see \Utopia\Storage\Tests\Device\S3\RetryStrategyTest
 */
final readonly class RetryStrategy implements Strategy
{
    /**
     * @var array<int, string>
     */
    private const array TRANSIENT_ERROR_CODES = ['SlowDown', 'ServiceUnavailable', 'Throttling', 'RequestThrottled', 'InternalError'];

    /**
     * @var array<int, int>
     */
    private const array TRANSIENT_STATUS_CODES = [429, 500, 502, 503, 504];

    private Closure $randomizer;

    /**
     * @param  int  $retries  Retries after the initial attempt
     * @param  float  $delay  Base delay in seconds; the wait before retry N is drawn uniformly from [0, min(maxDelay, delay * 2^(N-1)))
     * @param  float  $maxDelay  Ceiling for the backoff window and for Retry-After, in seconds
     * @param  (Closure(): float)|null  $randomizer  Returns a value in [0, 1) for jitter
     */
    public function __construct(
        private int $retries = 3,
        private float $delay = 0.5,
        private float $maxDelay = 20.0,
        ?Closure $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? static fn (): float => mt_rand() / mt_getrandmax();
    }

    public function delay(RequestInterface $request, int $attempt, ?ResponseInterface $response, ?ClientExceptionInterface $error): ?float
    {
        if ($attempt > $this->retries) {
            return null;
        }

        $transient = $response instanceof ResponseInterface
            ? $this->isTransient($response)
            : $this->isReplayable($request, $error);

        if (! $transient) {
            return null;
        }

        return $this->retryAfter($response) ?? ($this->randomizer)() * min($this->maxDelay, $this->delay * 2 ** ($attempt - 1));
    }

    private function isTransient(ResponseInterface $response): bool
    {
        $body = (string) $response->getBody();

        $trimmed = ltrim($body);
        if (str_starts_with($trimmed, '<?xml') || str_starts_with($trimmed, '<Error')) {
            $xml = @simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($xml !== false) {
                $code = (string) ($xml->Code ?? '');
                if (\in_array($code, self::TRANSIENT_ERROR_CODES, true)) {
                    return true;
                }
                // Successfully parsed XML with a non-transient error code — do not retry.
                if ($code !== '') {
                    return false;
                }
            }
        }

        // Fall back to HTTP status code for responses that cannot be parsed as XML.
        return \in_array($response->getStatusCode(), self::TRANSIENT_STATUS_CODES, true);
    }

    private function isReplayable(RequestInterface $request, ?ClientExceptionInterface $error): bool
    {
        // Nothing was sent: the host did not resolve or refused the connection.
        if ($error instanceof DnsException || ($error instanceof ConnectionException && $error->getCode() === \CURLE_COULDNT_CONNECT)) {
            return true;
        }

        // A TLS failure is a ConnectionException too, but points at configuration.
        if ($error instanceof TlsException) {
            return false;
        }

        // The request may have been applied before the connection dropped or timed out.
        return ($error instanceof ConnectionException || $error instanceof TimeoutException) && $request->getMethod() !== Method::POST;
    }

    private function retryAfter(?ResponseInterface $response): ?float
    {
        $value = $response?->getHeaderLine(Header::RETRY_AFTER) ?? '';

        if (! is_numeric($value)) {
            return null;
        }

        return min($this->maxDelay, max(0.0, (float) $value));
    }
}
