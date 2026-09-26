<?php

declare(strict_types=1);

namespace Utopia\Storage\Device\S3;

use Closure;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Decorator\Retry\Strategy;

/**
 * Retry strategy for transient S3 rate-limiting errors (e.g. SlowDown,
 * ServiceUnavailable), for use with the `utopia-php/client` Retry decorator.
 *
 * The XML body is parsed first so that specific S3 error codes are detected
 * regardless of HTTP status: a 503/429 carrying a parseable but non-transient
 * error code is not retried, while unparseable 429/503 responses fall back to
 * status-code detection.
 *
 * Waits use exponential backoff with full jitter so a fleet throttled at the
 * same moment does not retry in lockstep.
 * @see \Utopia\Tests\Storage\Device\S3\RetryStrategyTest
 */
final readonly class RetryStrategy implements Strategy
{
    /**
     * @var array<int, string>
     */
    private const array TRANSIENT_ERROR_CODES = ['SlowDown', 'ServiceUnavailable', 'Throttling', 'RequestThrottled'];

    /**
     * @var array<int, int>
     */
    private const array TRANSIENT_STATUS_CODES = [429, 503];

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
        $this->randomizer = $randomizer ?? static fn(): float => mt_rand() / mt_getrandmax();
    }

    public function delay(RequestInterface $request, int $attempt, ?ResponseInterface $response, ?ClientExceptionInterface $error): ?float
    {
        if ($attempt > $this->retries || ! $response instanceof ResponseInterface) {
            return null;
        }

        if (! $this->isTransient($response)) {
            return null;
        }

        return ($this->randomizer)() * min($this->maxDelay, $this->delay * 2 ** ($attempt - 1));
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
}
