<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt\Fakes;

/**
 * The broker's authenticator and authorizer over an in-memory set of accounts: every credential
 * names an active user unless that user is blocked. A credential of the form `<userId>/<sessionId>`
 * also names the session it runs on, which stops authorizing once it has ended. An empty project
 * resolves to the console, as the real authenticator resolves it.
 */
final class Accounts
{
    /** @var array<string, true> userIds that are blocked */
    public array $blocked = [];

    /** @var array<string, true> sessionIds that have ended */
    public array $ended = [];

    public int $authorizations = 0;

    public int $authentications = 0;

    public bool $unavailable = false;

    /** @var (\Closure(string, string): void)|null runs after the status was read, as a block landing mid-CONNECT would */
    public ?\Closure $whileAuthenticating = null;

    /** @return array<string, int|string> */
    public function authenticate(string $projectId, string $authMethod, string $credential): array
    {
        [$userId, $sessionId] = \array_pad(\explode('/', $credential, 2), 2, '');

        if (isset($this->blocked[$userId]) || isset($this->ended[$sessionId])) {
            return [];
        }

        $identity = ['projectId' => $projectId === '' ? 'console' : $projectId, 'userId' => $userId];
        if ($sessionId !== '') {
            // Each connection is due for its next check at its own time, as connections opened apart are.
            $identity['sessionId'] = $sessionId;
            $identity['recheckAt'] = \time() + 60 + $this->authentications;
        }
        $this->authentications++;

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

        return !isset($this->blocked[$identity['userId'] ?? '']) && !isset($this->ended[$identity['sessionId'] ?? '']);
    }
}
