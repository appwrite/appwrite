<?php

declare(strict_types=1);

namespace Tests\E2E\Security\Attack;

use Tests\E2E\Security\Attack;
use Tests\E2E\Security\Finding;
use Tests\E2E\Security\Probe;
use Tests\E2E\Security\RouteTarget;
use Tests\E2E\Security\World;

/**
 * A key that does not hold the route's declared scope must be rejected.
 */
final class ScopeLeastPrivilege implements Attack
{
    public static function getName(): string
    {
        return 'scope-least-privilege';
    }

    public function applies(RouteTarget $route): bool
    {
        // Guests already satisfy these scopes, so a limited key succeeding
        // is the public surface, not a privilege bypass.
        return ! $route->allowsGuest(World::guestScopes())
            && ! $route->allowsScopes(World::keyScopes(['locale.read']));
    }

    public function probe(RouteTarget $route, World $world, Probe $http): array
    {
        $response = $http->call(
            $route,
            $world->keyHeaders($world->projectA['id'], $world->limitedKeyA),
            $world->victimIds(),
        );
        if ($http->isMissingRoute($response) || $http->status($response) === 0) {
            return [];
        }
        if ($http->isDenied($response) || $http->status($response) === 429) {
            return [];
        }
        if ($http->isSuccess($response)) {
            return [new Finding(
                attack: self::getName(),
                method: $route->method,
                path: $route->path,
                probe: 'locale-read-key',
                detail: 'API key with only locale.read received HTTP ' . $http->status($response) . ' on scopes ' . \implode(', ', $route->scopes),
            )];
        }

        return [];
    }
}
