<?php

namespace Tests\Unit\Network;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Decorator;
use Utopia\Psr7\Response\Factory as ResponseFactory;
use Utopia\Psr7\Stream\Factory as StreamFactory;

/**
 * Answers every request with a 200 whose body the callable returns, without touching the network.
 */
final class CannedTransport extends Decorator
{
    /**
     * @param callable(RequestInterface): string $body
     */
    public function __construct(private readonly mixed $body)
    {
        parent::__construct(new CurlAdapter());
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return (new ResponseFactory())
            ->createResponse(200)
            ->withBody((new StreamFactory())->createStream(($this->body)($request)));
    }
}
