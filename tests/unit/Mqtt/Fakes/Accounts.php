<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt\Fakes;

/**
 * The broker's authenticator and authorizer over an in-memory set of accounts: every credential
 * names an active user unless that user is blocked. An empty project resolves to the console, as
 * the real authenticator resolves it.
 */
final class Accounts
{
    /** @var array<string, true> userIds that are blocked */
    public array $blocked = [];

    public int $authorizations = 0;

    public bool $unavailable = false;

    /** @var (\Closure(string, string): void)|null runs after the status was read, as a block landing mid-CONNECT would */
    public ?\Closure $whileAuthenticating = null;

    /** @return array<string, string> */
    public function authenticate(string $projectId, string $authMethod, string $credential): array
    {
        if (isset($this->blocked[$credential])) {
            return [];
        }

        $identity = ['projectId' => $projectId === '' ? 'console' : $projectId, 'userId' => $credential];

        if ($this->whileAuthenticating !== null) {
            ($this->whileAuthenticating)($identity['projectId'], $identity['userId']);
        }

        return $identity;
    }

    /** @param array<string, mixed> $identity */
    public function authorize(array $identity): bool
    {
        $this->authorizations++;

        if ($this->unavailable) {
            throw new \RuntimeException('The database is unavailable.');
        }

        return !isset($this->blocked[$identity['userId'] ?? '']);
    }
}
