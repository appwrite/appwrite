<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Override;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FailingClient implements ClientInterface
{
    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new \RuntimeException('ClickHouse unavailable');
    }
}
