<?php

declare(strict_types=1);

namespace Tests\E2E\Security\Attack;

use Tests\E2E\Security\Attack;
use Tests\E2E\Security\Finding;
use Tests\E2E\Security\Probe;
use Tests\E2E\Security\RouteTarget;
use Tests\E2E\Security\World;

/**
 * A project-A key or session must not act as a credential for project B.
 */
final class CrossTenant implements Attack
{
    public static function getName(): string
    {
        return 'cross-tenant';
    }

    public function applies(RouteTarget $route): bool
    {
        // Guest-callable routes succeed without a key or session; a foreign
        // credential being ignored is not a tenant bypass.
        return ! $route->allowsGuest(World::guestScopes())
            && ! $route->allowsScopes(['public', 'global']);
    }

    public function probe(RouteTarget $route, World $world, Probe $http): array
    {
        $findings = [];
        $ids = $world->victimIds();

        $keyOnOther = $http->call(
            $route,
            $world->keyHeaders($world->projectB['id'], $world->fullKeyA),
            $ids,
        );
        $findings = [...$findings, ...$this->unexpected(
            $route,
            $http,
            $keyOnOther,
            'key-a-on-project-b',
            'Project A API key was accepted against project B',
        )];

        $sessionOnOther = $http->call(
            $route,
            $world->sessionHeaders($world->projectB['id'], $world->userA['session']),
            $ids,
        );
        $findings = [...$findings, ...$this->unexpected(
            $route,
            $http,
            $sessionOnOther,
            'session-a-on-project-b',
            'Project A user session was accepted against project B',
        )];

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function unexpected(RouteTarget $route, Probe $http, array $response, string $probe, string $detail): array
    {
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
                probe: $probe,
                detail: $detail . ' (HTTP ' . $http->status($response) . ')',
            )];
        }

        return [];
    }
}
