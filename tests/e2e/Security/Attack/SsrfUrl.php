<?php

declare(strict_types=1);

namespace Tests\E2E\Security\Attack;

use Tests\E2E\Security\Attack;
use Tests\E2E\Security\Finding;
use Tests\E2E\Security\Probe;
use Tests\E2E\Security\RouteTarget;
use Tests\E2E\Security\World;

/**
 * URL-typed parameters must refuse loopback, link-local, and metadata targets.
 */
final class SsrfUrl implements Attack
{
    /**
     * @var list<string>
     */
    private const array PAYLOADS = [
        'http://127.0.0.1',
        'http://localhost',
        'http://[::1]',
        'http://169.254.169.254/latest/meta-data/',
        'http://10.0.0.1',
        'http://metadata.google.internal',
    ];

    public static function getName(): string
    {
        return 'ssrf-url';
    }

    public function applies(RouteTarget $route): bool
    {
        return $route->urlParamNames() !== [];
    }

    public function probe(RouteTarget $route, World $world, Probe $http): array
    {
        $findings = [];
        $headers = $world->keyHeaders($world->projectA['id'], $world->fullKeyA);
        if ($this->needsConsole($route)) {
            $headers = $world->consoleHeaders($world->owner['session'], $world->projectA['id'], true);
        }

        foreach ($route->urlParamNames() as $name) {
            foreach (self::PAYLOADS as $payload) {
                $params = [$name => $payload];
                $response = $http->call($route, $headers, $world->victimIds(), $params, false, 4);
                $status = $http->status($response);
                if ($status === 0) {
                    $findings[] = new Finding(
                        attack: self::getName(),
                        method: $route->method,
                        path: $route->path,
                        probe: $this->probeId($name, $payload),
                        detail: 'Request to ' . $payload . ' on ' . $name . ' timed out or failed at the transport — the server may have attempted an outbound connect',
                    );
                    continue;
                }
                if ($http->isDenied($response) || $status === 400 || $status === 404 || $status === 429) {
                    continue;
                }
                if ($status >= 500 || ($http->isSuccess($response) && $this->fetchesUrl($route))) {
                    $findings[] = new Finding(
                        attack: self::getName(),
                        method: $route->method,
                        path: $route->path,
                        probe: $this->probeId($name, $payload),
                        detail: 'URL parameter ' . $name . ' accepted ' . $payload . ' (HTTP ' . $status . ')',
                    );
                }
            }
        }

        return $findings;
    }

    private function needsConsole(RouteTarget $route): bool
    {
        return \str_starts_with($route->path, '/v1/projects')
            || \str_starts_with($route->path, '/v1/project')
            || \str_starts_with($route->path, '/v1/organizations')
            || \str_starts_with($route->path, '/v1/webhooks')
            || \str_starts_with($route->path, '/v1/migrations')
            || \str_starts_with($route->path, '/v1/proxy');
    }

    private function fetchesUrl(RouteTarget $route): bool
    {
        foreach (['/avatars/', '/health/certificate', '/migrations/'] as $fetch) {
            if (\str_contains($route->path, $fetch)) {
                return true;
            }
        }

        return false;
    }

    private function probeId(string $name, string $payload): string
    {
        $host = \parse_url($payload, PHP_URL_HOST);

        return $name . ':' . (\is_string($host) && $host !== '' ? $host : $payload);
    }
}
