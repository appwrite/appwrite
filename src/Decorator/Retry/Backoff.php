<?php

declare(strict_types=1);

namespace Utopia\Client\Decorator\Retry;

use Closure;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;

/**
 * The default, best-practice strategy: retry only idempotent methods, only on
 * transient transport failures (NetworkExceptionInterface) and overloaded
 * responses (429/502/503/504), with exponential backoff and full jitter. A
 * numeric Retry-After header is honoured when present.
 */
final readonly class Backoff implements Strategy
{
    /**
     * @var array<int, string>
     */
    private const array IDEMPOTENT_METHODS = [
        Method::GET,
        Method::HEAD,
        Method::PUT,
        Method::DELETE,
        Method::OPTIONS,
        Method::TRACE,
    ];

    /**
     * @var array<int, int>
     */
    private const array RETRYABLE_STATUS_CODES = [429, 502, 503, 504];

    private Closure $randomizer;

    /**
     * @param (Closure(): float)|null $randomizer returns a value in [0, 1) for jitter
     */
    public function __construct(
        private int $maxAttempts = 3,
        private float $baseDelay = 0.1,
        private float $maxDelay = 10.0,
        private float $multiplier = 2.0,
        ?Closure $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? static fn(): float => mt_rand() / mt_getrandmax();
    }

    public function delay(RequestInterface $request, int $attempt, ?ResponseInterface $response, ?ClientExceptionInterface $error): ?float
    {
        if ($attempt >= $this->maxAttempts) {
            return null;
        }

        if (!\in_array($request->getMethod(), self::IDEMPOTENT_METHODS, true)) {
            return null;
        }

        if (!$this->isRetryable($response, $error)) {
            return null;
        }

        return $this->retryAfter($response) ?? $this->backoff($attempt);
    }

    private function isRetryable(?ResponseInterface $response, ?ClientExceptionInterface $error): bool
    {
        if ($error instanceof \Psr\Http\Client\ClientExceptionInterface) {
            return $error instanceof NetworkExceptionInterface;
        }

        return $response instanceof \Psr\Http\Message\ResponseInterface && \in_array($response->getStatusCode(), self::RETRYABLE_STATUS_CODES, true);
    }

    private function retryAfter(?ResponseInterface $response): ?float
    {
        if (!$response instanceof \Psr\Http\Message\ResponseInterface) {
            return null;
        }

        $value = $response->getHeaderLine(Header::RETRY_AFTER);

        if (!is_numeric($value)) {
            return null;
        }

        return min($this->maxDelay, max(0.0, (float) $value));
    }

    private function backoff(int $attempt): float
    {
        $ceiling = min($this->maxDelay, $this->baseDelay * $this->multiplier ** ($attempt - 1));

        return ($this->randomizer)() * $ceiling;
    }
}
