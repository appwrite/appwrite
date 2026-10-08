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
 * Reserved identity headers and mixed-case variants must not authenticate a caller.
 */
final class HeaderSpoof implements Attack
{
    public static function getName(): string
    {
        return 'header-spoof';
    }

    public function applies(RouteTarget $route): bool
    {
        return ! $route->allowsGuest(World::guestScopes());
    }

    public function probe(RouteTarget $route, World $world, Probe $http): array
    {
        $headers = \array_merge($world->guestHeaders($world->projectA['id']), [
            'x-appwrite-user-id' => $world->userA['id'],
            'X-Appwrite-User-Id' => $world->userA['id'],
            'x-appwrite-user' => $world->userA['id'],
            'x-appwrite-mode' => APP_MODE_ADMIN,
        ]);
        $response = $http->call($route, $headers, $world->victimIds());
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
                probe: 'spoofed-identity-headers',
                detail: 'Guest plus reserved x-appwrite-* identity headers received HTTP ' . $http->status($response),
            )];
        }

        return [];
    }

    /**
     * @return list<Finding>
     */
    public function identity(World $world): array
    {
        $findings = [];
        $guest = \array_merge($world->guestHeaders($world->projectA['id']), [
            'x-appwrite-user-id' => $world->userA['id'],
            'X-Appwrite-User-Id' => $world->userA['id'],
            'x-appwrite-user' => $world->userA['id'],
        ]);
        $account = $world->client->call(Client::METHOD_GET, '/account', $guest);
        $status = (int) ($account['headers']['status-code'] ?? 0);
        if ($status === 200 && (($account['body']['$id'] ?? '') === $world->userA['id'])) {
            $findings[] = new Finding(
                attack: self::getName(),
                method: 'GET',
                path: '/v1/account',
                probe: 'spoofed-user-id',
                detail: 'x-appwrite-user-id authenticated as the spoofed user',
            );
        }

        $asB = \array_merge($world->sessionHeaders($world->projectA['id'], $world->userB['session']), [
            'x-appwrite-user-id' => $world->userA['id'],
            'X-Appwrite-User-Id' => $world->userA['id'],
        ]);
        $accountB = $world->client->call(Client::METHOD_GET, '/account', $asB);
        if (
            (int) ($accountB['headers']['status-code'] ?? 0) === 200
            && ($accountB['body']['$id'] ?? '') === $world->userA['id']
        ) {
            $findings[] = new Finding(
                attack: self::getName(),
                method: 'GET',
                path: '/v1/account',
                probe: 'session-b-spoofed-user-a',
                detail: 'User B session plus x-appwrite-user-id returned user A',
            );
        }

        $locale = $world->client->call(Client::METHOD_GET, '/locale', \array_merge($world->guestHeaders($world->projectA['id']), [
            'x-forwarded-for' => '203.0.113.8',
            'x-real-ip' => '203.0.113.8',
            'forwarded' => 'for=203.0.113.8',
        ]));
        if ((int) ($locale['headers']['status-code'] ?? 0) === 200 && ($locale['body']['ip'] ?? '') === '203.0.113.8') {
            $findings[] = new Finding(
                attack: self::getName(),
                method: 'GET',
                path: '/v1/locale',
                probe: 'forwarded-ip',
                detail: 'Locale reflected X-Forwarded-For / X-Real-IP / Forwarded as the client IP',
                severity: Finding::WARNING,
            );
        }

        return $findings;
    }
}
