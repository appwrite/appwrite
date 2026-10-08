<?php

declare(strict_types=1);

namespace Tests\E2E\Security\Attack;

use Tests\E2E\Client;
use Tests\E2E\Security\Attack;
use Tests\E2E\Security\Finding;
use Tests\E2E\Security\Probe;
use Tests\E2E\Security\RouteTarget;
use Tests\E2E\Security\World;

/**
 * A console developer must not perform owner-only organization actions, and
 * must not satisfy scopes their role does not include.
 */
final class ConsoleRole implements Attack
{
    public static function getName(): string
    {
        return 'console-role';
    }

    public function applies(RouteTarget $route): bool
    {
        if ($route->allowsScopes(World::developerScopes())) {
            return false;
        }
        if ($route->allowsGuest(World::guestScopes())) {
            return false;
        }
        // Session-scoped account routes succeed for any logged-in console
        // user; that is not an organization-role escalation.
        return ! $route->allowsScopes(['account', 'home', 'console']);
    }

    public function probe(RouteTarget $route, World $world, Probe $http): array
    {
        $response = $http->call(
            $route,
            $world->consoleHeaders($world->developer['session'], $world->projectA['id'], true),
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
                probe: 'developer-missing-scope',
                detail: 'Console developer received HTTP ' . $http->status($response) . ' on scopes ' . \implode(', ', $route->scopes),
            )];
        }

        return [];
    }

    /**
     * Owner-only mutations that are not fully expressed as scopes.
     *
     * @return list<Finding>
     */
    public function ownerOnly(World $world): array
    {
        $findings = [];
        $headers = $world->consoleHeaders($world->developer['session']);

        $promote = $world->client->call(Client::METHOD_PATCH, '/teams/' . $world->organizationId . '/memberships/' . $world->developer['membershipId'], $headers, [
            'roles' => ['owner'],
        ]);
        $findings = [...$findings, ...$this->mustDeny($promote, 'PATCH', '/v1/teams/:teamId/memberships/:membershipId', 'developer-self-promote', 'Developer promoted themselves to owner')];

        $invite = $world->client->call(Client::METHOD_POST, '/teams/' . $world->organizationId . '/memberships', $headers, [
            'email' => 'sec.invitee.' . \uniqid() . '@localhost.test',
            'name' => 'Invited',
            'roles' => ['owner'],
            'url' => 'http://localhost:5000/join-us',
        ]);
        $findings = [...$findings, ...$this->mustDeny($invite, 'POST', '/v1/teams/:teamId/memberships', 'developer-invite-owner', 'Developer invited a new owner')];

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function mustDeny(array $response, string $method, string $path, string $probe, string $detail): array
    {
        $status = (int) ($response['headers']['status-code'] ?? 0);
        if (\in_array($status, [401, 403], true)) {
            return [];
        }

        return [new Finding(
            attack: self::getName(),
            method: $method,
            path: $path,
            probe: $probe,
            detail: $detail . ' (HTTP ' . $status . ')',
        )];
    }
}
