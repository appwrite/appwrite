<?php

namespace Tests\Compat\Domains;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Compat\Fault;
use Utopia\Psr7\Response\Factory as ResponseFactory;
use Utopia\Psr7\Stream\Factory as StreamFactory;

/**
 * A recorded transport for the registrar adapters: answers each request with
 * the next recorded response and keeps every request it was sent. No
 * registrar is ever called. Its twin is `Recorded` in
 * crates/tools/compat/src/libs/domains.rs.
 */
final class Transport implements ClientInterface
{
    /** @var list<array{status: int, headers: array<string, string>, body: string}> */
    private array $responses = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    private array $requests = [];

    /**
     * @param list<array{status?: int, headers?: array<string, string>, body?: string}> $responses
     */
    public function queue(array $responses): void
    {
        foreach ($responses as $response) {
            $status = $response['status'] ?? 200;
            if ($status < 100 || $status > 599) {
                throw new Fault("a recorded response needs a status in 100..=599, got {$status}");
            }
            $this->responses[] = [
                'status' => $status,
                'headers' => $response['headers'] ?? [],
                'body' => $response['body'] ?? '',
            ];
        }
    }

    /**
     * The requests sent since the last call: header names lower-cased and
     * sorted, without `Host`.
     *
     * @return list<array{method: string, url: string, headers: array<string, string>, body: string}>
     */
    public function take(): array
    {
        $requests = $this->requests;
        $this->requests = [];

        return $requests;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];
        foreach (array_keys($request->getHeaders()) as $name) {
            if (strtolower((string) $name) !== 'host') {
                $headers[strtolower((string) $name)] = $request->getHeaderLine((string) $name);
            }
        }
        ksort($headers);
        $this->requests[] = [
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'headers' => $headers,
            'body' => (string) $request->getBody(),
        ];

        $next = array_shift($this->responses);
        if ($next === null) {
            throw new class ('no recorded response') extends \RuntimeException implements ClientExceptionInterface {
            };
        }

        $response = (new ResponseFactory())->createResponse($next['status']);
        foreach ($next['headers'] as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response->withBody((new StreamFactory())->createStream($next['body']));
    }
}
