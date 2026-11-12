<?php

namespace Utopia\Agents\Tests\Fakes;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Psr18\StreamingClientInterface;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

/**
 * Records every request and answers from a queue of canned responses.
 */
class Client implements ClientInterface, StreamingClientInterface
{
    /**
     * @var list<RequestInterface>
     */
    public array $requests = [];

    /**
     * @var list<array{status: int, body: string, chunks: list<string>, headers: array<string, string>}>
     */
    private array $responses = [];

    /**
     * Queue a response. Streamed requests receive $chunks, or $body as a
     * single chunk when no chunks are given.
     *
     * @param  list<string>  $chunks
     * @param  array<string, string>  $headers
     */
    public function queue(int $status, string $body = '', array $chunks = [], array $headers = []): self
    {
        $this->responses[] = ['status' => $status, 'body' => $body, 'chunks' => $chunks, 'headers' => $headers];

        return $this;
    }

    /**
     * Queue a 200 server-sent event stream with one "data:" event per item.
     *
     * @param  list<array<string, mixed>>  $events
     */
    public function events(array $events, string $separator = "\n\n"): self
    {
        $chunks = array_map(fn (array $event) => 'data: '.json_encode($event).$separator, $events);

        return $this->queue(200, chunks: $chunks);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $response = $this->next();

        return $this->response($response, new Stream($response['body']));
    }

    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        $this->requests[] = $request;
        $response = $this->next();

        foreach ($response['chunks'] === [] ? [$response['body']] : $response['chunks'] as $chunk) {
            $sink($chunk);
        }

        return $this->response($response, new Stream());
    }

    /**
     * @param  array{status: int, body: string, chunks: list<string>, headers: array<string, string>}  $response
     */
    private function response(array $response, Stream $body): ResponseInterface
    {
        $result = new Response($response['status'], body: $body);
        foreach ($response['headers'] as $name => $value) {
            $result = $result->withHeader($name, $value);
        }

        return $result;
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
     * @return array<string, mixed>
     */
    public function lastPayload(): array
    {
        $payload = json_decode((string) $this->lastRequest()->getBody(), true);

        /** @var array<string, mixed> */
        return \is_array($payload) ? $payload : [];
    }

    /**
     * A value from the last JSON payload by its path, such as
     * payload('messages', 0, 'content'); null when the path does not exist.
     */
    public function payload(string|int ...$path): mixed
    {
        $value = $this->lastPayload();
        foreach ($path as $key) {
            if (! \is_array($value) || ! \array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array{status: int, body: string, chunks: list<string>, headers: array<string, string>}
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
