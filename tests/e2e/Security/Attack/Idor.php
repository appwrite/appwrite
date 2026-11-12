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

    /**
     * A path ID we supplied echoing back as `$id` is not a leak (callers
     * choose presence IDs). A `userId` / `email` field owned by A is.
     *
     * @param array<string, mixed> $response
     */
    private function leaksVictim(array $response, World $world): bool
    {
        $body = $response['body'] ?? [];
        if (! \is_array($body)) {
            return false;
        }

        return $this->containsOwner($body, $world);
    }

    /**
     * @param array<mixed> $value
     */
    private function containsOwner(array $value, World $world): bool
    {
        $id = $world->userA['id'];
        $email = $world->userA['email'];

        foreach ($value as $key => $item) {
            $name = \is_string($key) ? $key : '';
            if ($name === 'email' && $item === $email) {
                return true;
            }
            if (($name === 'userId' || $name === 'userInternalId') && $item === $id) {
                return true;
            }
            if (\is_array($item) && $this->containsOwner($item, $world)) {
                return true;
            }
        }

        return false;
    }
}
