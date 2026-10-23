<?php

namespace Appwrite\Auth;

/**
 * The scopes the request's caller holds: an API key's own, a console
 * role's, or a user role's. The api hook resolves them and grants them
 * here, so an action can refuse to hand a resource more than its caller
 * has, as when a function or site is given scopes for its ephemeral key.
 */
final class Scopes
{
    /** @var array<string, true> */
    private array $granted = [];

    /**
     * @param list<string> $scopes
     */
    public function grant(array $scopes): self
    {
        foreach ($scopes as $scope) {
            $this->granted[$scope] = true;
        }

        return $this;
    }

    public function holds(string $scope): bool
    {
        return isset($this->granted[$scope]);
    }

    /**
     * The requested scopes the caller does not hold, in request order.
     *
     * @param list<string> $scopes
     * @return list<string>
     */
    public function missing(array $scopes): array
    {
        return \array_values(\array_filter($scopes, fn (string $scope): bool => !$this->holds($scope)));
    }
}
