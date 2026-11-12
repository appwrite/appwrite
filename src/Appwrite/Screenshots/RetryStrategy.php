<?php

declare(strict_types=1);

namespace Appwrite\Screenshots;

use Closure;
use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Decorator\Retry\Strategy;
use Utopia\Psr7\Header;

final readonly class RetryStrategy implements Strategy
{
    private const int UNAVAILABLE = 503;

    private Closure $randomizer;

    /**
     * @param (Closure(): float)|null $randomizer Returns a value in [0, 1]
     */
    public function __construct(
        private int $attempts = 3,
        private float $maxDelay = 10.0,
        private float $jitter = 0.5,
        ?Closure $randomizer = null,
    ) {
        if ($attempts < 1) {
            throw new InvalidArgumentException('Attempts must be at least 1, got ' . $attempts . '.');
        }

        if ($maxDelay < 0.0 || \is_nan($maxDelay)) {
            throw new InvalidArgumentException('Max delay must be a non-negative number, got ' . \var_export($maxDelay, true) . '.');
        }

        if ($jitter < 0.0 || \is_nan($jitter)) {
            throw new InvalidArgumentException('Jitter must be a non-negative number, got ' . \var_export($jitter, true) . '.');
        }

        $this->randomizer = $randomizer ?? static fn (): float => \random_int(0, \PHP_INT_MAX) / \PHP_INT_MAX;
    }

    public function delay(RequestInterface $request, int $attempt, ?ResponseInterface $response, ?ClientExceptionInterface $error): ?float
    {
        if ($attempt >= $this->attempts || $response?->getStatusCode() !== self::UNAVAILABLE) {
            return null;
        }

        $retryAfter = $response->getHeaderLine(Header::RETRY_AFTER);

        if (!\is_numeric($retryAfter)) {
            return null;
        }

        $after = \max(0.0, (float) $retryAfter);

        if ($after > $this->maxDelay) {
            return null;
        }

        return \min($this->maxDelay, $after * (1.0 + $this->jitter * ($this->randomizer)()));
    }
}
