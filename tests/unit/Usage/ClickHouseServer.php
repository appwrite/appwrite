<?php

declare(strict_types=1);

namespace Tests\Unit\Usage;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

/**
 * Answers the usage adapter's HTTP calls the way ClickHouse does: every insert
 * is stored while the server is up, and every request gets a 503 while it is down.
 */
final class ClickHouseServer implements ClientInterface
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public bool $down = false;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($this->down) {
            return new Response(503, 'Service Unavailable', new Stream('Service Unavailable'));
        }

        \parse_str($request->getUri()->getQuery(), $parameters);
        $query = $parameters['query'] ?? '';
        if (\is_string($query) && \str_starts_with($query, 'INSERT')) {
            foreach (\explode("\n", (string) $request->getBody()) as $line) {
                $row = \json_decode($line, true);
                if (\is_array($row)) {
                    $this->rows[] = $row;
                }
            }
        }

        return new Response(200, 'OK', new Stream(''));
    }
}
