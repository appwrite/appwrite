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
 * The XML error code wins over the HTTP status; unparseable 429/5xx responses
 * fall back to the status. Transport failures are retried only when replaying
 * cannot apply the request twice. Waits use exponential backoff with full
 * jitter, or a numeric Retry-After.
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
     * @param  float  $maxDelay  Ceiling for the backoff window in seconds
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
        // Nothing was sent.
        if ($error instanceof DnsException || ($error instanceof ConnectionException && $error->getCode() === \CURLE_COULDNT_CONNECT)) {
            return true;
        }

        if ($error instanceof TlsException) {
            return false;
        }

        // A replayed POST may open a second multipart upload.
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
