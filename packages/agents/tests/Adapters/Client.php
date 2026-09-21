<?php

namespace Utopia\Agents\Tests\Adapters;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Psr18\StreamingClientInterface;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

/**
 * Records every request and answers from a queue of canned responses.
 */
class Client implements ClientInterface, StreamingClientInterface
{
    /**
     * @var array<int, RequestInterface>
     */
    public array $requests = [];

    /**
     * @var array<int, array{status: int, body: string, chunks: array<int, string>}>
     */
    private array $responses = [];

    /**
     * @param  array<int, string>  $chunks  Delivered to the sink, in order, when streamed
     */
    public function queue(int $status, string $body = '', array $chunks = []): self
    {
        $this->responses[] = ['status' => $status, 'body' => $body, 'chunks' => $chunks];

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $response = $this->next();

        return new Response($response['status'], body: new Stream($response['body']));
    }

    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        $this->requests[] = $request;
        $response = $this->next();

        foreach ($response['chunks'] as $chunk) {
            $sink($chunk);
        }

        return new Response($response['status']);
    }

    public function lastRequest(): RequestInterface
    {
        $request = end($this->requests);
        if ($request === false) {
            throw new \LogicException('No request was sent');
        }

        return $request;
    }

    /**
     * @return array<mixed>
     */
    public function lastPayload(): array
    {
        $payload = json_decode((string) $this->lastRequest()->getBody(), true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * @return array{status: int, body: string, chunks: array<int, string>}
     */
    private function next(): array
    {
        $response = array_shift($this->responses);
        if ($response === null) {
            throw new \LogicException('No response queued for this request');
        }

        return $response;
    }
}
