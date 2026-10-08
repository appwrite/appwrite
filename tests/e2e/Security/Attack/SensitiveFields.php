<?php

declare(strict_types=1);

namespace Tests\E2E\Security\Attack;

use Tests\E2E\Security\Attack;
use Tests\E2E\Security\Finding;
use Tests\E2E\Security\Probe;
use Tests\E2E\Security\RouteTarget;
use Tests\E2E\Security\World;

/**
 * Session/token secrets and execution streams must not appear for a normal user.
 */
final class SensitiveFields implements Attack
{
    /**
     * @var list<string>
     */
    private const array KEYS = ['secret', 'password', 'stdout', 'stderr'];

    public static function getName(): string
    {
        return 'sensitive-fields';
    }

    public function applies(RouteTarget $route): bool
    {
        return $route->method === 'GET' && ! $this->returnsSecretByDesign($route);
    }

    public function probe(RouteTarget $route, World $world, Probe $http): array
    {
        $response = $http->call(
            $route,
            $world->sessionHeaders($world->projectA['id'], $world->userA['session']),
            $world->victimIds(),
        );
        if (! $http->isSuccess($response) || ! \is_array($response['body'])) {
            return [];
        }

        $hits = $this->walk($response['body']);
        if ($hits === []) {
            return [];
        }

        return [new Finding(
            attack: self::getName(),
            method: $route->method,
            path: $route->path,
            probe: 'user-session',
            detail: 'User session response exposed sensitive fields: ' . \implode(', ', $hits),
        )];
    }

    private function returnsSecretByDesign(RouteTarget $route): bool
    {
        foreach (['/sessions', '/tokens', '/keys', '/recovery', '/verification'] as $create) {
            if (\str_contains($route->path, $create) && $route->method !== 'GET') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $value
     * @return list<string>
     */
    private function walk(array $value, string $prefix = ''): array
    {
        $hits = [];
        foreach ($value as $key => $item) {
            $name = \is_string($key) ? \strtolower($key) : '';
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (\in_array($name, self::KEYS, true) && \is_string($item) && $item !== '') {
                $hits[] = $path;
            }
            if (\is_array($item)) {
                \array_push($hits, ...$this->walk($item, $path));
            }
        }

        return $hits;
    }
}
