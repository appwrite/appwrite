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
     * @param array<string, mixed> $params Orchestrator create payload (pool or image, ports, environment, ...).
     * @return array<string, mixed> The sandbox status.
     * @throws Exception on an error response
     * @throws ClientExceptionInterface on transport failure
     */
    public function create(array $params): array
    {
        return $this->json($this->factory->json(Method::POST, '/v1/sandbox', $params));
    }

    /**
     * @return array<string, mixed> The sandbox status.
     * @throws Exception on an error response
     * @throws ClientExceptionInterface on transport failure
     */
    public function get(string $sandboxId): array
    {
        return $this->json($this->factory->createRequest(Method::GET, '/v1/sandbox/' . \rawurlencode($sandboxId)));
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
    public function delete(string $sandboxId): void
    {
        $this->assertSuccess($this->client->sendRequest($this->factory->createRequest(Method::DELETE, '/v1/sandbox/' . \rawurlencode($sandboxId))));
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
