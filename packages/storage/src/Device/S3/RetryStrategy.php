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
 * A rejected request (throttled, or never sent) is always retried. One that may
 * have been applied (internal error, dropped or timed-out connection) is only
 * retried when replaying it is harmless: not a CreateMultipartUpload, which
 * would open a second upload, and not a conditional write, whose condition the
 * first attempt may already have changed. The XML error code wins over the HTTP
 * status. Waits use exponential backoff with full jitter, or a numeric
 * Retry-After.
 * @see \Utopia\Storage\Tests\Device\S3\RetryStrategyTest
 */
final readonly class RetryStrategy implements Strategy
{
    /**
     * @var array<int, string>
     */
    private const array REJECTED_ERROR_CODES = ['SlowDown', 'ServiceUnavailable', 'Throttling', 'RequestThrottled'];

    /**
     * @var array<int, string>
     */
    private const array UNKNOWN_ERROR_CODES = ['InternalError'];

    /**
     * @var array<int, int>
     */
    private const array REJECTED_STATUS_CODES = [429, 503];

    /**
     * @var array<int, int>
     */
    private const array UNKNOWN_STATUS_CODES = [500, 502, 504];

    private const string REJECTED = 'rejected';

    private const string UNKNOWN = 'unknown';

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

        $outcome = $response instanceof ResponseInterface
            ? $this->classifyResponse($response)
            : $this->classifyError($error);

        if ($outcome === null || ($outcome === self::UNKNOWN && ! $this->isReplayable($request))) {
            return null;
        }

        return $this->retryAfter($response) ?? ($this->randomizer)() * min($this->maxDelay, $this->delay * 2 ** ($attempt - 1));
    }

    private function classifyResponse(ResponseInterface $response): ?string
    {
        $body = (string) $response->getBody();

        $trimmed = ltrim($body);
        if (str_starts_with($trimmed, '<?xml') || str_starts_with($trimmed, '<Error')) {
            $xml = @simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            $code = $xml === false ? '' : (string) ($xml->Code ?? '');
            if ($code !== '') {
                return match (true) {
                    \in_array($code, self::REJECTED_ERROR_CODES, true) => self::REJECTED,
                    \in_array($code, self::UNKNOWN_ERROR_CODES, true) => self::UNKNOWN,
                    default => null,
                };
            }
        }

        return match (true) {
            \in_array($response->getStatusCode(), self::REJECTED_STATUS_CODES, true) => self::REJECTED,
            \in_array($response->getStatusCode(), self::UNKNOWN_STATUS_CODES, true) => self::UNKNOWN,
            default => null,
        };
    }

    private function classifyError(?ClientExceptionInterface $error): ?string
    {
        // Nothing was sent: cURL and Swoole report a refused connection differently.
        $refused = [\CURLE_COULDNT_CONNECT, \defined('SOCKET_ECONNREFUSED') ? \SOCKET_ECONNREFUSED : 111];
        if ($error instanceof DnsException || ($error instanceof ConnectionException && \in_array($error->getCode(), $refused, true))) {
            return self::REJECTED;
        }

        if ($error instanceof TlsException) {
            return null;
        }

        return $error instanceof ConnectionException || $error instanceof TimeoutException ? self::UNKNOWN : null;
    }

    private function isReplayable(RequestInterface $request): bool
    {
        if ($request->getMethod() === Method::PUT) {
            return ! $request->hasHeader(Header::IF_MATCH) && ! $request->hasHeader(Header::IF_NONE_MATCH);
        }

        parse_str($request->getUri()->getQuery(), $query);

        return $request->getMethod() !== Method::POST || !\array_key_exists('uploads', $query);
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
