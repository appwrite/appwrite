<?php

namespace Utopia\Tests\Cdn;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Psr7\Response;

class TestClient implements ClientInterface
{
    /**
     * @var array<int, array{url:string,method:string,body:mixed}>
     */
    public array $calls = [];

    /** @var array<string, string> */
    public array $headers = [];

    /**
     * @param array<int, ResponseInterface> $responses
     */
    public function __construct(private array $responses) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        foreach ($request->getHeaders() as $name => $values) {
            $this->headers[strtolower((string) $name)] = implode(', ', $values);
        }

        $body = (string) $request->getBody();
        $decoded = json_decode($body, true);

        $this->calls[] = [
            'url' => (string) $request->getUri(),
            'method' => $request->getMethod(),
            'body' => json_last_error() === JSON_ERROR_NONE ? $decoded : $body,
        ];

        return array_shift($this->responses) ?? new Response(500);
    }
}
