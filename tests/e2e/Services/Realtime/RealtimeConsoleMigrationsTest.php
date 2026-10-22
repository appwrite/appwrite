<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Realtime;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Id;
use Utopia\System\System;
use WebSocket\Client as WebSocketClient;
use WebSocket\TimeoutException;

final class RealtimeConsoleMigrationsTest extends Scope
{
    use RealtimeBase;
    use ProjectConsole;
    use SideConsole;

    public function testMigrationEventsReachOnlyProjectReaders(): void
    {
        $headers = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ];

        $team = $this->createTeamFixture($headers, [
            'teamId' => Id::unique(),
            'name' => 'Realtime Migrations',
        ]);
        $this->assertSame(200, $team['headers']['status-code']);
        $teamId = $team['body']['$id'];

        $projectId = $this->createOrganizationProject($headers, $teamId);
        $otherProjectId = $this->createOrganizationProject($headers, $teamId);

        $member = $this->createConsoleAccount();
        $this->addOrganizationMember($headers, $teamId, $member['email'], ['project-' . $otherProjectId . '-developer']);

        $owner = $this->getConsoleWebsocket($this->getRoot()['session']);
        $restricted = $this->getConsoleWebsocket($member['session']);

        $migration = $this->client->call(Client::METHOD_POST, '/migrations/appwrite', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => $projectId,
            'x-appwrite-mode' => 'admin',
        ], [
            'resources' => ['user'],
            'endpoint' => 'http://appwrite.test/v1',
            'projectId' => Id::unique(),
            'apiKey' => 'key',
        ]);
        $this->assertSame(202, $migration['headers']['status-code']);
        $event = 'migrations.' . $migration['body']['$id'] . '.create';

        /**
         * Test for SUCCESS
         */
        $this->receiveUntilEvent($owner, fn (array $message): bool => \in_array($event, $message['data']['events'] ?? [], true));
        $owner->close();

        /**
         * Test for FAILURE
         */
        while (true) {
            try {
                $frame = \json_decode($restricted->receive(), true);
            } catch (TimeoutException) {
                break;
            }

            $this->assertNotContains($event, $frame['data']['events'] ?? [], 'A member without access to the project received its migration event.');
        }
        $restricted->close();
    }

    private function getConsoleWebsocket(string $session): WebSocketClient
    {
        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $session,
        ], 'console', timeout: 3);

        $connected = \json_decode($client->receive(), true);
        $this->assertSame('connected', $connected['type']);

        return $client;
    }

    private function createOrganizationProject(array $headers, string $teamId): string
    {
        $project = $this->client->call(Client::METHOD_POST, '/projects', $headers, [
            'projectId' => Id::unique(),
            'region' => System::getEnv('_APP_REGION', 'default'),
            'name' => 'Realtime Migrations',
            'teamId' => $teamId,
        ]);
        $this->assertSame(201, $project['headers']['status-code']);

        return $project['body']['$id'];
    }

    /**
     * @return array{email: string, session: string}
     */
    private function createConsoleAccount(): array
    {
        $email = \uniqid('migrations-', true) . \bin2hex(\random_bytes(4)) . '@localhost.test';
        $headers = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ];

        $account = $this->client->call(Client::METHOD_POST, '/account', $headers, [
            'userId' => Id::unique(),
            'email' => $email,
            'password' => 'password',
            'name' => 'Realtime Member',
        ]);
        $this->assertSame(201, $account['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $headers, [
            'email' => $email,
            'password' => 'password',
        ]);
        $this->assertSame(201, $session['headers']['status-code']);

        return [
            'email' => $email,
            'session' => $session['cookies']['a_session_console'],
        ];
    }

    private function addOrganizationMember(array $headers, string $teamId, string $email, array $roles): void
    {
        $membership = $this->client->call(Client::METHOD_POST, '/teams/' . $teamId . '/memberships', $headers, [
            'email' => $email,
            'roles' => $roles,
            'url' => 'http://localhost:5000/join-us#title',
        ]);
        $this->assertSame(201, $membership['headers']['status-code']);

        $invite = $this->getLastEmailByAddress($email, function (array $email): void {
            $this->assertStringContainsString('/join-us', (string) ($email['html'] ?? ''));
        });
        $tokens = $this->extractQueryParamsFromEmailLink($invite['html']);

        $confirm = $this->client->call(Client::METHOD_PATCH, '/teams/' . $teamId . '/memberships/' . $membership['body']['$id'] . '/status', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'userId' => $tokens['userId'],
            'secret' => $tokens['secret'],
        ]);
        $this->assertSame(200, $confirm['headers']['status-code']);
    }
}
