<?php

declare(strict_types=1);

namespace Tests\E2E\Security\Attack;

use Tests\E2E\Security\Attack;
use Tests\E2E\Security\Finding;
use Tests\E2E\Security\Probe;
use Tests\E2E\Security\RouteTarget;
use Tests\E2E\Security\World;

/**
 * User B's session must not read or mutate user A's identifiers.
 */
final class Idor implements Attack
{
    public static function getName(): string
    {
        return 'idor';
    }

    public function applies(RouteTarget $route): bool
    {
        return $route->hasAnyIdParam();
    }

    public function probe(RouteTarget $route, World $world, Probe $http): array
    {
        $response = $http->call(
            $route,
            $world->sessionHeaders($world->projectA['id'], $world->userB['session']),
            $world->victimIds(),
        );
        if ($http->isMissingRoute($response) || $http->status($response) === 0) {
            return [];
        }
        if ($http->isDenied($response) || $http->status($response) === 404 || $http->status($response) === 400 || $http->status($response) === 429) {
            return [];
        }
        if (! $http->isSuccess($response)) {
            return [];
        }
        if (! $this->leaksVictim($response, $world)) {
            return [];
        }

        return [new Finding(
            attack: self::getName(),
            method: $route->method,
            path: $route->path,
            probe: 'user-b-on-user-a-ids',
            detail: 'User B session received HTTP ' . $http->status($response) . ' containing user A identifiers',
        )];
    }

    private function leaksVictim(array $response, World $world): bool
    {
        $encoded = \json_encode($response['body'] ?? []);
        if ($encoded === false) {
            return false;
        }

        foreach ([$world->userA['id'], $world->userA['email'], $world->teamA['id']] as $token) {
            if ($token !== '' && \str_contains($encoded, $token)) {
                return true;
            }
        }

        return false;
    }
}
