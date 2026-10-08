<?php

declare(strict_types=1);

namespace Tests\E2E\Security;

use Appwrite\Utopia\Database\Documents\User;
use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\System\System;

/**
 * Shared identities for every attack class: two projects, two users, a
 * least-privilege key, and a console developer on the owner organization.
 */
final class World
{
    /**
     * @param array{id: string, email: string, session: string} $owner
     * @param array{id: string, teamId: string} $projectA
     * @param array{id: string, teamId: string} $projectB
     * @param array{id: string, email: string, session: string, sessionId: string} $userA
     * @param array{id: string, email: string, session: string, sessionId: string} $userB
     * @param array{id: string, membershipId: string} $teamA
     * @param array{id: string, email: string, session: string, membershipId: string} $developer
     */
    public function __construct(
        public readonly Client $client,
        public readonly Probe $probe,
        public readonly array $owner,
        public readonly array $projectA,
        public readonly array $projectB,
        public readonly string $fullKeyA,
        public readonly string $fullKeyB,
        public readonly string $limitedKeyA,
        public readonly array $userA,
        public readonly array $userB,
        public readonly array $teamA,
        public readonly array $developer,
        public readonly string $organizationId,
    ) {
    }

    public static function boot(): self
    {
        $client = new Client();
        $client->setEndpoint('http://appwrite/v1');

        $format = System::getEnv('_APP_E2E_RESPONSE_FORMAT');
        if (! empty($format)) {
            $client->setResponseFormat($format);
        }

        $probe = new Probe($client);
        $owner = self::createConsoleUser($client);
        $projectA = self::createProject($client, $owner['session'], 'Security Project A');
        $projectB = self::createProject($client, $owner['session'], 'Security Project B');
        $fullKeyA = self::createKey($client, $owner['session'], $projectA['id'], self::broadScopes());
        $fullKeyB = self::createKey($client, $owner['session'], $projectB['id'], self::broadScopes());
        $limitedKeyA = self::createKey($client, $owner['session'], $projectA['id'], ['locale.read']);
        $userA = self::createProjectUser($client, $projectA['id']);
        $userB = self::createProjectUser($client, $projectA['id']);
        $teamA = self::createUserTeam($client, $projectA['id'], $userA['session']);
        $developer = self::createDeveloper($client, $owner, $projectA['teamId']);

        return new self(
            client: $client,
            probe: $probe,
            owner: $owner,
            projectA: $projectA,
            projectB: $projectB,
            fullKeyA: $fullKeyA,
            fullKeyB: $fullKeyB,
            limitedKeyA: $limitedKeyA,
            userA: $userA,
            userB: $userB,
            teamA: $teamA,
            developer: $developer,
            organizationId: $projectA['teamId'],
        );
    }

    /**
     * @return array<string, string>
     */
    public function guestHeaders(string $projectId): array
    {
        return [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function keyHeaders(string $projectId, string $key): array
    {
        return [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function sessionHeaders(string $projectId, string $session): array
    {
        return [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function consoleHeaders(string $session, ?string $projectId = null, bool $admin = false): array
    {
        $headers = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId ?? 'console',
            'cookie' => 'a_session_console=' . $session,
            'x-appwrite-organization' => $this->organizationId,
        ];
        if ($admin && $projectId !== null && $projectId !== 'console') {
            $headers['x-appwrite-mode'] = APP_MODE_ADMIN;
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    public function victimIds(): array
    {
        return [
            'userId' => $this->userA['id'],
            'sessionId' => $this->userA['sessionId'],
            'teamId' => $this->teamA['id'],
            'membershipId' => $this->teamA['membershipId'],
            'projectId' => $this->projectA['id'],
            '*' => $this->userA['id'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function guestScopes(): array
    {
        return self::roleScopes(User::ROLE_GUESTS);
    }

    /**
     * @return list<string>
     */
    public static function developerScopes(): array
    {
        return self::roleScopes(User::ROLE_DEVELOPER);
    }

    /**
     * @return list<string>
     */
    public static function keyScopes(array $extra): array
    {
        return \array_values(\array_unique([...self::roleScopes(User::ROLE_KEYS), ...$extra]));
    }

    /**
     * @return list<string>
     */
    private static function roleScopes(string $role): array
    {
        $roles = require \dirname(__DIR__, 3) . '/app/config/roles.php';
        $scopes = $roles[$role]['scopes'] ?? [];

        return \array_values(\array_map(static fn (mixed $scope): string => (string) $scope, $scopes));
    }

    /**
     * @return array{id: string, email: string, session: string}
     */
    private static function createConsoleUser(Client $client): array
    {
        $email = self::email('owner');
        $account = self::must($client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => 'password',
            'name' => 'Security Owner',
        ]), 201, 'console account');

        $session = self::must($client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $email,
            'password' => 'password',
        ]), 201, 'console session');

        return [
            'id' => (string) $account['body']['$id'],
            'email' => $email,
            'session' => (string) $session['cookies']['a_session_console'],
        ];
    }

    /**
     * @return array{id: string, teamId: string}
     */
    private static function createProject(Client $client, string $ownerSession, string $name): array
    {
        $headers = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $ownerSession,
            'x-appwrite-project' => 'console',
        ];

        $team = null;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $team = $client->call(Client::METHOD_POST, '/teams', $headers, [
                'teamId' => ID::unique(),
                'name' => $name . ' Team',
            ]);
            $status = (int) ($team['headers']['status-code'] ?? 0);
            if (\in_array($status, [200, 201], true)) {
                break;
            }
            if ($status === 409) {
                \usleep(250_000);
                continue;
            }
            break;
        }
        $team = self::must($team ?? [], [200, 201], 'organization');

        $project = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $project = $client->call(Client::METHOD_POST, '/projects', $headers, [
                'projectId' => ID::unique(),
                'region' => System::getEnv('_APP_REGION', 'default'),
                'name' => $name,
                'teamId' => $team['body']['$id'],
                'description' => $name,
                'url' => 'https://appwrite.io',
            ]);
            if (($project['headers']['status-code'] ?? 0) === 201) {
                break;
            }
            \usleep(400_000);
        }

        $project = self::must($project ?? [], 201, 'project');

        return [
            'id' => (string) $project['body']['$id'],
            'teamId' => (string) $team['body']['$id'],
        ];
    }

    private static function createKey(Client $client, string $ownerSession, string $projectId, array $scopes): string
    {
        $key = self::must($client->call(Client::METHOD_POST, '/projects/' . $projectId . '/keys', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $ownerSession,
            'x-appwrite-project' => 'console',
        ], [
            'keyId' => ID::unique(),
            'name' => 'Security Key ' . $projectId,
            'scopes' => $scopes,
        ]), 201, 'api key');

        return (string) $key['body']['secret'];
    }

    /**
     * @return array{id: string, email: string, session: string, sessionId: string}
     */
    private static function createProjectUser(Client $client, string $projectId): array
    {
        $email = self::email('user');
        $account = self::must($client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => 'password',
            'name' => 'Security User',
        ]), 201, 'project account');

        $session = self::must($client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $email,
            'password' => 'password',
        ]), 201, 'project session');

        return [
            'id' => (string) $account['body']['$id'],
            'email' => $email,
            'session' => (string) $session['cookies']['a_session_' . $projectId],
            'sessionId' => (string) $session['body']['$id'],
        ];
    }

    /**
     * @return array{id: string, membershipId: string}
     */
    private static function createUserTeam(Client $client, string $projectId, string $session): array
    {
        $team = self::must($client->call(Client::METHOD_POST, '/teams', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], [
            'teamId' => ID::unique(),
            'name' => 'Victim Team',
        ]), 201, 'user team');

        $memberships = self::must($client->call(Client::METHOD_GET, '/teams/' . $team['body']['$id'] . '/memberships', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ]), 200, 'user team memberships');

        $membershipId = (string) ($memberships['body']['memberships'][0]['$id'] ?? $team['body']['$id']);

        return [
            'id' => (string) $team['body']['$id'],
            'membershipId' => $membershipId,
        ];
    }

    /**
     * @param array{id: string, email: string, session: string} $owner
     * @return array{id: string, email: string, session: string, membershipId: string}
     */
    private static function createDeveloper(Client $client, array $owner, string $organizationId): array
    {
        $email = self::email('developer');
        self::must($client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => 'password',
            'name' => 'Security Developer',
        ]), 201, 'developer account');

        $invite = self::must($client->call(Client::METHOD_POST, '/teams/' . $organizationId . '/memberships', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $owner['session'],
        ], [
            'email' => $email,
            'name' => 'Security Developer',
            'roles' => ['developer'],
            'url' => 'http://localhost:5000/join-us',
        ]), 201, 'developer invite');

        $tokens = self::waitForInvite($email);
        $accepted = self::must($client->call(
            Client::METHOD_PATCH,
            '/teams/' . $organizationId . '/memberships/' . $tokens['membershipId'] . '/status',
            [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => 'console',
            ],
            [
                'secret' => $tokens['secret'],
                'userId' => $tokens['userId'],
            ]
        ), 200, 'developer invite accept');

        $session = (string) ($accepted['cookies']['a_session_console'] ?? '');
        if ($session === '') {
            throw new \RuntimeException('Developer invite accept did not return a console session');
        }

        return [
            'id' => (string) $tokens['userId'],
            'email' => $email,
            'session' => $session,
            'membershipId' => (string) ($tokens['membershipId'] ?: $invite['body']['$id']),
        ];
    }

    /**
     * @return array{membershipId: string, userId: string, secret: string}
     */
    private static function waitForInvite(string $email): array
    {
        $deadline = \microtime(true) + 15.0;
        do {
            $payload = @\file_get_contents('http://maildev:1080/email');
            $emails = \is_string($payload) ? \json_decode($payload, true) : [];
            if (\is_array($emails)) {
                for ($index = \count($emails) - 1; $index >= 0; $index--) {
                    $message = $emails[$index];
                    foreach ($message['to'] ?? [] as $recipient) {
                        if (($recipient['address'] ?? '') !== $email) {
                            continue;
                        }
                        $tokens = self::queryParamsFromEmail((string) ($message['html'] ?? ''));
                        if (($tokens['secret'] ?? '') !== '' && ($tokens['membershipId'] ?? '') !== '') {
                            return [
                                'membershipId' => (string) $tokens['membershipId'],
                                'userId' => (string) $tokens['userId'],
                                'secret' => (string) $tokens['secret'],
                            ];
                        }
                    }
                }
            }
            \usleep(400_000);
        } while (\microtime(true) < $deadline);

        throw new \RuntimeException('Developer invite email did not arrive for ' . $email);
    }

    /**
     * @return array<string, string>
     */
    private static function queryParamsFromEmail(string $html): array
    {
        foreach (['/join-us?', '/verification?', '/recovery?'] as $prefix) {
            $linkStart = \strpos($html, $prefix);
            if ($linkStart === false) {
                continue;
            }
            $hrefStart = \strrpos(\substr($html, 0, $linkStart), 'href="');
            if ($hrefStart === false) {
                continue;
            }
            $hrefStart += 6;
            $hrefEnd = \strpos($html, '"', $hrefStart);
            if ($hrefEnd === false || $hrefStart >= $hrefEnd) {
                continue;
            }
            $link = \substr($html, $hrefStart, $hrefEnd - $hrefStart);
            $hash = \strpos($link, '#');
            if ($hash !== false) {
                $link = \substr($link, 0, $hash);
            }
            $queryStart = \strpos($link, '?');
            if ($queryStart === false) {
                continue;
            }
            \parse_str(\html_entity_decode(\substr($link, $queryStart + 1)), $queryParams);
            $tokens = [];
            foreach ($queryParams as $key => $value) {
                if (\is_string($value) || \is_int($value)) {
                    $tokens[(string) $key] = (string) $value;
                }
            }

            return $tokens;
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function broadScopes(): array
    {
        return [
            'users.read', 'users.write',
            'teams.read', 'teams.write',
            'databases.read', 'databases.write',
            'collections.read', 'collections.write',
            'documents.read', 'documents.write',
            'rows.read', 'rows.write',
            'files.read', 'files.write',
            'buckets.read', 'buckets.write',
            'functions.read', 'functions.write',
            'sites.read', 'sites.write',
            'executions.read', 'executions.write',
            'locale.read', 'avatars.read', 'health.read',
            'sessions.write',
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @param int|list<int> $expected
     * @return array<string, mixed>
     */
    private static function must(array $response, int|array $expected, string $what): array
    {
        $status = (int) ($response['headers']['status-code'] ?? 0);
        $allowed = \is_array($expected) ? $expected : [$expected];
        if (! \in_array($status, $allowed, true)) {
            $body = $response['body'] ?? [];
            throw new \RuntimeException(
                'Failed to create ' . $what . ' (HTTP ' . $status . '): ' . \json_encode($body)
            );
        }

        return $response;
    }

    private static function email(string $role): string
    {
        return $role . '.' . \str_replace('.', '', \uniqid('', true)) . \getmypid() . '@localhost.test';
    }
}
