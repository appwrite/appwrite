<?php

namespace Appwrite\Sandbox;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory;

class Client
{
    private Factory $factory;

    /**
     * @param ClientInterface $client Configured with the orchestrator sandbox service base URI.
     */
    public function __construct(private readonly ClientInterface $client)
    {
        $this->factory = new Factory();
    }

    /**
     * @param array<string, string> $environment Environment variables for the workload.
     * @param list<int> $ports Extra ports the sandbox serves, beyond the contract's own.
     * @return array<string, mixed> The sandbox status.
     * @throws Exception on an error response
     * @throws ClientExceptionInterface on transport failure
     */
    public function create(
        string $id,
        string $image,
        int $port = 3000,
        string $command = '',
        float $cpu = 1.0,
        int $memory = 512,
        array $environment = [],
        array $ports = [],
        int $timeoutSeconds = 300,
        int $idleTimeoutSeconds = 900,
    ): array {
        $payload = [
            'id' => $id,
            'image' => $image,
            'port' => $port,
            'cpu' => $cpu,
            'memory' => $memory,
            'timeoutSeconds' => $timeoutSeconds,
            'idleTimeoutSeconds' => $idleTimeoutSeconds,
        ];

        // The orchestrator rejects unknown fields and reads an empty one as a
        // value, so anything unset is left off the wire entirely.
        if ($command !== '') {
            $payload['command'] = $command;
        }
        if ($environment !== []) {
            $payload['environment'] = $environment;
        }
        if ($ports !== []) {
            $payload['ports'] = $ports;
        }

        return $this->json($this->factory->json(Method::POST, '/v1/sandbox', $payload));
    }

    /**
     * @return array<string, mixed> The sandbox status.
     * @throws Exception on an error response
     * @throws ClientExceptionInterface on transport failure
     */
    public function get(string $id): array
    {
        return $this->json($this->factory->createRequest(Method::GET, '/v1/sandbox/' . \rawurlencode($id)));
    }

    /**
     * @return array<int, array<string, mixed>> Sandbox statuses.
     * @throws Exception on an error response
     * @throws ClientExceptionInterface on transport failure
     */
    public function list(): array
    {
        return $this->json($this->factory->createRequest(Method::GET, '/v1/sandbox'))['sandboxes'] ?? [];
    }

    /**
     * @throws Exception on an error response
     * @throws ClientExceptionInterface on transport failure
     */
    public function delete(string $id): void
    {
        $this->assertSuccess($this->client->sendRequest($this->factory->createRequest(Method::DELETE, '/v1/sandbox/' . \rawurlencode($id))));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(\Psr\Http\Message\RequestInterface $request): array
    {
        $response = $this->client->sendRequest($request);
        $this->assertSuccess($response);

        $decoded = \json_decode((string)$response->getBody(), true);
        if (!\is_array($decoded)) {
            throw new Exception('Sandbox service returned an invalid response', $response->getStatusCode());
        }

        return $decoded;
    }

    private function assertSuccess(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status < 400) {
            return;
        }

        $error = \json_decode((string)$response->getBody(), true)['error'] ?? null;
        throw new Exception(\is_string($error) ? $error : 'Sandbox request failed with status ' . $status, $status);
    }
}
