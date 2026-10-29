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

final class RealtimeConsoleTailTest extends Scope
{
    use RealtimeBase;
    use ProjectConsole;
    use SideConsole;

    public function testTailRequiresProjectAccess(): void
    {
        $headers = $this->getRootHeaders();
        $teamId = $this->createOrganization($headers);
        $projectId = $this->createOrganizationProject($headers, $teamId);
        $otherProjectId = $this->createOrganizationProject($headers, $teamId);

        $member = $this->createConsoleAccount();
        $this->addOrganizationMember($headers, $teamId, $member, ['project-' . $otherProjectId . '-developer']);

        /**
         * Test for SUCCESS
         */
        $client = $this->getConsoleWebsocket($member['session']);
        $response = $this->subscribe($client, ['console.tail.' . $otherProjectId]);
        $this->assertSame('response', $response['type']);
        $this->assertTrue($response['data']['success']);
        $client->close();

        $client = $this->getConsoleWebsocket($this->getRoot()['session']);
        $response = $this->subscribe($client, ['console.tail.' . $projectId]);
        $this->assertSame('response', $response['type']);
        $this->assertTrue($response['data']['success']);
        $client->close();

        /**
         * Test for FAILURE
         */
        $client = $this->getConsoleWebsocket($member['session']);
        $response = $this->subscribe($client, ['console.tail.' . $projectId]);
        $this->assertSame('error', $response['type']);
        $this->assertSame(1008, $response['data']['code']);
        $this->receiveUntilClosed($client);
    }

    public function testTailEndsWhenMembershipIsRemoved(): void
    {
        $headers = $this->getRootHeaders();
        $teamId = $this->createOrganization($headers);
        $projectId = $this->createOrganizationProject($headers, $teamId);

        $member = $this->createConsoleAccount();
        $membershipId = $this->addOrganizationMember($headers, $teamId, $member, ['developer']);

        $client = $this->getConsoleWebsocket($member['session']);
        $response = $this->subscribe($client, ['memberships', 'console.tail.' . $projectId]);
        $this->assertTrue($response['data']['success']);

        $this->createProjectUser($projectId);
        $this->receiveUntilEvent($client, fn (array $message): bool => \in_array('console.tail', $message['data']['events'] ?? [], true));

        $membership = $this->client->call(Client::METHOD_DELETE, '/teams/' . $teamId . '/memberships/' . $membershipId, $headers);
        $this->assertSame(204, $membership['headers']['status-code']);

        $this->receiveUntilEvent($client, fn (array $message): bool => \in_array('teams.' . $teamId . '.memberships.' . $membershipId . '.delete', $message['data']['events'] ?? [], true));

        $this->createProjectUser($projectId);

        $this->assertTailStopped($client, 'Tail kept streaming after the membership was removed.');

        $client->close();
    }

    public function testTailEndsWhenProjectIsTransferred(): void
    {
        $headers = $this->getRootHeaders();
        $teamId = $this->createOrganization($headers);
        $destinationTeamId = $this->createOrganization($headers);
        $projectId = $this->createOrganizationProject($headers, $teamId);

        $member = $this->createConsoleAccount();
        $this->addOrganizationMember($headers, $teamId, $member, ['developer']);

        $client = $this->getConsoleWebsocket($member['session']);
        $response = $this->subscribe($client, ['console.tail.' . $projectId]);
        $this->assertTrue($response['data']['success']);

        $this->createProjectUser($projectId);
        $this->receiveUntilEvent($client, fn (array $message): bool => \in_array('console.tail', $message['data']['events'] ?? [], true));

        $project = $this->client->call(Client::METHOD_PATCH, '/projects/' . $projectId . '/team', $headers, [
            'teamId' => $destinationTeamId,
        ]);
        $this->assertSame(200, $project['headers']['status-code']);
        $this->assertSame($destinationTeamId, $project['body']['teamId']);

        $this->createProjectUser($projectId);

        $this->assertTailStopped($client, 'Tail kept streaming to the previous team after the project was transferred.');

        $client->close();
    }

    public function testTailEndsWhenConnectionAuthenticatesAsAnotherUser(): void
    {
        $headers = $this->getRootHeaders();
        $teamId = $this->createOrganization($headers);
        $projectId = $this->createOrganizationProject($headers, $teamId);

        $stranger = $this->createConsoleAccount();

        $client = $this->getConsoleWebsocket($this->getRoot()['session']);
        $response = $this->subscribe($client, ['console.tail.' . $projectId]);
        $this->assertTrue($response['data']['success']);

        $this->createProjectUser($projectId);
        $this->receiveUntilEvent($client, fn (array $message): bool => \in_array('console.tail', $message['data']['events'] ?? [], true));

        $client->send(\json_encode([
            'type' => 'authentication',
            'data' => [
                'session' => $stranger['session'],
            ],
        ]));
        $response = $this->receiveUntilEvent($client, fn (array $message): bool => ($message['data']['to'] ?? '') === 'authentication');
        $this->assertTrue($response['data']['success']);

        $this->createProjectUser($projectId);

        $this->assertTailStopped($client, 'Tail kept streaming to a connection that authenticated as a user who cannot read the project.');

        $client->close();
    }

    private function assertTailStopped(WebSocketClient $client, string $message): void
    {
        $frames = [];
        while (true) {
            try {
                $frames[] = \json_decode($client->receive(), true);
            } catch (TimeoutException) {
                break;
            }
        }

        foreach ($frames as $frame) {
            $this->assertNotContains('console.tail', $frame['data']['events'] ?? [], $message);
        }
    }

    private function getRootHeaders(): array
    {
        return [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ];
    }

    private function getConsoleWebsocket(string $session): WebSocketClient
    {
        $client = $this->getWebsocket([], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $session,
        ], 'console', timeout: 3);

        $connected = \json_decode($client->receive(), true);
        $this->assertSame('connected', $connected['type']);

        return $client;
    }

    /**
     * @param array<int, string> $channels
     */
    private function subscribe(WebSocketClient $client, array $channels): array
    {
        $client->send(\json_encode([
            'type' => 'subscribe',
            'data' => [[
                'channels' => $channels,
            ]],
        ]));

        return \json_decode($client->receive(), true);
    }

    private function createProjectUser(string $projectId): void
    {
        $user = $this->client->call(Client::METHOD_POST, '/users', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => $projectId,
            'x-appwrite-mode' => 'admin',
        ], [
            'userId' => Id::unique(),
            'email' => \uniqid('tail-', true) . '@localhost.test',
            'password' => 'password',
        ]);
        $this->assertSame(201, $user['headers']['status-code']);
    }

    private function createOrganization(array $headers): string
    {
        $team = $this->createTeamFixture($headers, [
            'teamId' => Id::unique(),
            'name' => 'Realtime Tail',
        ]);
        $this->assertSame(200, $team['headers']['status-code']);

        return $team['body']['$id'];
    }

    private function createOrganizationProject(array $headers, string $teamId): string
    {
        $project = $this->client->call(Client::METHOD_POST, '/projects', $headers, [
            'projectId' => Id::unique(),
            'region' => System::getEnv('_APP_REGION', 'default'),
            'name' => 'Realtime Tail',
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
        $email = \uniqid('tail-', true) . \bin2hex(\random_bytes(4)) . '@localhost.test';
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

    private function addOrganizationMember(array $headers, string $teamId, array $member, array $roles): string
    {
        $email = $member['email'];
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

        // Signed in, as accepting signed out claims the unverified account and revokes its session
        $confirm = $this->client->call(Client::METHOD_PATCH, '/teams/' . $teamId . '/memberships/' . $membership['body']['$id'] . '/status', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $member['session'],
        ], [
            'userId' => $tokens['userId'],
            'secret' => $tokens['secret'],
        ]);
        $this->assertSame(200, $confirm['headers']['status-code']);

        return $membership['body']['$id'];
    }
}
