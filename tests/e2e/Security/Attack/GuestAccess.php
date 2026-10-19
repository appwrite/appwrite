<?php

declare(strict_types=1);

namespace Tests\E2E\Security\Attack;

use Tests\E2E\Security\Attack;
use Tests\E2E\Security\Finding;
use Tests\E2E\Security\Probe;
use Tests\E2E\Security\RouteTarget;
use Tests\E2E\Security\World;

/**
 * Guests may call a route only when its declared scope intersects the guest role.
 */
final class GuestAccess implements Attack
{
    public static function getName(): string
    {
        return 'guest-access';
    }

    public function applies(RouteTarget $route): bool
    {
        return true;
    }

    public function probe(RouteTarget $route, World $world, Probe $http): array
    {
        if ($route->allowsGuest(World::guestScopes())) {
            return [];
        }

        $response = $http->call($route, $world->guestHeaders($world->projectA['id']), $world->victimIds());
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
                probe: 'guest',
                detail: 'Unauthenticated caller received HTTP ' . $http->status($response) . ' on a route whose scopes are ' . \implode(', ', $route->scopes),
            )];
        }

        return [];
    }
}
