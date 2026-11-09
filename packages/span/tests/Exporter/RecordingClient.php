<?php

declare(strict_types=1);

namespace Utopia\Span\Tests\Exporter;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Psr7\Response\Factory as ResponseFactory;

/**
 * PSR-18 client that records the requests it is sent and accepts each one.
 */
final class RecordingClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return new ResponseFactory()->createResponse(202);
    }
}
