<?php

declare(strict_types=1);

namespace Tests\E2E\Security;

use Utopia\Http\Http;
use Utopia\Http\Route;

/**
 * Live HTTP route list from the process router after the same bootstrap the
 * specs task uses (`app/controllers/general.php` + `Service::TYPE_HTTP`).
 */
final class Catalog
{
    /**
     * @var list<RouteTarget>|null
     */
    private static ?array $routes = null;

    /**
     * @return list<RouteTarget>
     */
    public static function load(): array
    {
        if (self::$routes !== null) {
            return self::$routes;
        }

        self::register();
        self::$routes = self::fromRouter(Http::getRoutes());

        return self::$routes;
    }

    /**
     * @param array<string, array<string, Route>> $router
     * @return list<RouteTarget>
     */
    public static function fromRouter(array $router): array
    {
        $seen = [];
        $targets = [];

        foreach ($router as $method => $routes) {
            foreach ($routes as $route) {
                $path = $route->getPath();
                $id = \strtoupper((string) $method) . ' ' . $path;
                if (isset($seen[$id])) {
                    continue;
                }

                $target = self::toTarget(\strtoupper((string) $method), $route);
                if ($target === null) {
                    continue;
                }

                $seen[$id] = true;
                $targets[] = $target;
            }
        }

        \usort($targets, static fn (RouteTarget $left, RouteTarget $right): int => $left->id() <=> $right->id());

        return $targets;
    }

    /**
     * @return list<string>
     */
    public static function ids(array $routes): array
    {
        return \array_values(\array_map(static fn (RouteTarget $route): string => $route->id(), $routes));
    }

    private static function register(): void
    {
        foreach (Http::getRoutes() as $routes) {
            foreach ($routes as $route) {
                if (\str_starts_with($route->getPath(), '/v1')) {
                    return;
                }
            }
        }

        require_once \dirname(__DIR__, 3) . '/app/controllers/general.php';
    }

    private static function toTarget(string $method, Route $route): ?RouteTarget
    {
        $path = $route->getPath();
        if ($path === '' || $path === '*' || ! \str_starts_with($path, '/v1')) {
            return null;
        }
        if ($path === '/v1/graphql' || \str_starts_with($path, '/v1/graphql/')) {
            return null;
        }
        if ($route->getLabel('mock', false)) {
            return null;
        }

        $scopes = $route->getLabel('scope', 'none');
        if (! \is_array($scopes)) {
            $scopes = [$scopes];
        }
        $scopes = \array_values(\array_map(static fn (mixed $scope): string => (string) $scope, $scopes));

        $groups = \array_values(\array_map(static fn (mixed $group): string => (string) $group, $route->getGroups()));

        return new RouteTarget(
            method: $method,
            path: $path,
            groups: $groups,
            scopes: $scopes,
            params: $route->getParams(),
            docs: (bool) $route->getLabel('docs', true),
            mock: (bool) $route->getLabel('mock', false),
            inApiGroup: \in_array('api', $groups, true),
        );
    }
}
