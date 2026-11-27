<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

/**
 * A jobs service that answers every request with the status set for its
 * method and records what it was sent.
 */
final class Orchestrator implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /**
     * @param array<string, int> $statuses Method => status; anything unset succeeds
     */
    public function __construct(private array $statuses = [])
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $status = $this->statuses[$request->getMethod()] ?? 200;

        if ($status >= 300) {
            return new Response($status, body: new Stream((string) \json_encode(['error' => 'Request failed with status ' . $status])));
        }

        if ($request->getMethod() === 'POST') {
            $job = \json_decode((string) $request->getBody(), true);

            return new Response(202, body: new Stream((string) \json_encode(['id' => $job['id'] ?? '', 'status' => 'accepted'])));
        }

        return new Response(204);
    }

    /**
     * @return array<string, mixed>
     */
    public function job(): array
    {
        foreach ($this->requests as $request) {
            if ($request->getMethod() === 'POST' && $request->getUri()->getPath() === '/v1/jobs') {
                return \json_decode((string) $request->getBody(), true);
            }
        }

        return [];
    }
}
