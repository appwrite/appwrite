<?php

declare(strict_types=1);

namespace Utopia\Psr18;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The streaming counterpart to PSR-18's ClientInterface: instead of buffering
 * the whole response body, each chunk is passed to $sink as it arrives, keeping
 * memory bounded regardless of body size.
 */
interface StreamingClientInterface
{
    /**
     * Send a request and pass each response body chunk to $sink as it arrives.
     * The returned response carries the status and headers; its body is empty
     * because the body was delivered to $sink.
     *
     * @param callable(string): void $sink
     *
     * @throws ClientExceptionInterface
     */
    public function stream(RequestInterface $request, callable $sink): ResponseInterface;
}
