<?php

declare(strict_types=1);

namespace Utopia\Client\Decorator\Retry;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface Strategy
{
    /**
     * Decide whether to retry after an attempt. Return the number of seconds to
     * wait before the next attempt, or null to stop and surface the outcome.
     *
     * Exactly one of $response or $error is non-null: $response when the adapter
     * returned (including 4xx/5xx), $error when it threw.
     */
    public function delay(RequestInterface $request, int $attempt, ?ResponseInterface $response, ?ClientExceptionInterface $error): ?float;
}
