<?php

declare(strict_types=1);

namespace Utopia\Domains\Tests\Registrar\Adapter;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

/**
 * Records every request and answers each one with the same canned body.
 */
final class Client implements ClientInterface
{
    /**
     * @var list<RequestInterface>
     */
    public array $requests = [];

    public function __construct(private string $body)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return new Response(200, body: new Stream($this->body));
    }

    public function last(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests) ?? throw new RuntimeException('No request sent.')];
    }
}
