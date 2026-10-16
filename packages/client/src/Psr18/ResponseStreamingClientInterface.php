<?php

declare(strict_types=1);

namespace Utopia\Client\Psr18;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Response status and headers are available before any body bytes reach the sink. */
interface ResponseStreamingClientInterface extends StreamingClientInterface
{
    /**
     * @param callable(string): void $sink
     * @param (callable(ResponseInterface): void)|null $onResponse Called once per attempt, before its first body chunk (or at completion for an empty body)
     */
    public function stream(RequestInterface $request, callable $sink, ?callable $onResponse = null): ResponseInterface;
}
