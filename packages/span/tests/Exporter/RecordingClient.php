<?php

declare(strict_types=1);

namespace Utopia\Span\Tests\Exporter;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Psr7\Response\Factory as ResponseFactory;

/**
 * Keeps the last request and accepts it, so exporter tests never reach a real Sentry.
 */
final class RecordingClient implements ClientInterface
{
    public ?RequestInterface $request = null;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return new ResponseFactory()->createResponse(202);
    }
}
