<?php

declare(strict_types=1);

namespace Tests\E2E\Security;

use Tests\E2E\Client;
use Throwable;

final class Probe
{
    public function __construct(
        private readonly Client $client,
    ) {
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $ids
     * @param array<string, mixed> $params
     * @return array{headers: array<string, mixed>, cookies: array<string, mixed>, body: mixed}
     */
    public function call(
        RouteTarget $route,
        array $headers,
        array $ids = [],
        array $params = [],
        bool $followRedirects = false,
        int $timeout = 8,
    ): array {
        $path = $this->fillPath($route->path, $ids);
        $path = $this->toClientPath($path);
        $payload = $params === [] ? $this->dummyParams($route) : $params;

        try {
            return $this->client->call(
                $route->method,
                $path,
                $headers,
                $payload,
                true,
                $followRedirects,
                $timeout,
            );
        } catch (Throwable $error) {
            return [
                'headers' => ['status-code' => 0],
                'cookies' => [],
                'body' => ['message' => $error->getMessage()],
            ];
        }
    }

    /**
     * @param array<string, string> $ids
     */
    public function fillPath(string $path, array $ids): string
    {
        $parts = \explode('/', $path);
        foreach ($parts as &$part) {
            if ($part === '' || ! \str_starts_with($part, ':')) {
                continue;
            }
            $name = \substr($part, 1);
            $part = $ids[$name] ?? $ids['*'] ?? 'secPlaceholder00000000000001';
        }

        return \implode('/', $parts);
    }

    public function toClientPath(string $path): string
    {
        if (\str_starts_with($path, '/v1/')) {
            return \substr($path, 3);
        }
        if ($path === '/v1') {
            return '/';
        }

        return $path;
    }

    public function status(array $response): int
    {
        return (int) ($response['headers']['status-code'] ?? 0);
    }

    public function type(array $response): string
    {
        $body = $response['body'] ?? null;

        return \is_array($body) ? (string) ($body['type'] ?? '') : '';
    }

    public function isSuccess(array $response): bool
    {
        $status = $this->status($response);

        return $status >= 200 && $status < 400;
    }

    public function isDenied(array $response): bool
    {
        return \in_array($this->status($response), [401, 403], true);
    }

    public function isMissingRoute(array $response): bool
    {
        return $this->status($response) === 404 && $this->type($response) === 'general_route_not_found';
    }

    /**
     * @return array<string, mixed>
     */
    private function dummyParams(RouteTarget $route): array
    {
        $params = [];
        foreach ($route->params as $name => $param) {
            if (! empty($param['optional'])) {
                continue;
            }
            $params[(string) $name] = $this->dummyValue((string) $name);
        }

        return $params;
    }

    private function dummyValue(string $name): mixed
    {
        $needle = \strtolower($name);
        if (\str_contains($needle, 'email')) {
            return 'sec@localhost.test';
        }
        if (\str_contains($needle, 'password')) {
            return 'password';
        }
        if (\str_contains($needle, 'url') || \str_contains($needle, 'endpoint') || \str_contains($needle, 'href')) {
            return 'https://example.com';
        }
        if (\str_contains($needle, 'phone')) {
            return '+15555550100';
        }
        if (\str_ends_with($name, 'Id') || $name === 'userId' || $name === 'teamId') {
            return 'secPlaceholder00000000000001';
        }

        return 'sec';
    }
}
