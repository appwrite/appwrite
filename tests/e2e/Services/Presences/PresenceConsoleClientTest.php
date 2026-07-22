<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Presences;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use WebSocket\Client as WebSocketClient;
use WebSocket\TimeoutException;

final class PresenceConsoleClientTest extends Scope
{
    use PresenceBase;
    use ProjectCustom {
        getProject as getCustomProject;
    }
    use SideConsole {
        getHeaders as getAdminHeaders;
    }

    public function getProject(bool $fresh = false): array
    {
        return ['$id' => 'console'];
    }

    // `x-appwrite-mode: admin` is forbidden for the console project, so authenticate
    // as a console session user instead — `getUser()` signs them up against project=console.
    public function getHeaders(): array
    {
        return [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getUser()['session'],
        ];
    }

    public function testConsolePresenceUpsertAndUpdateBroadcastRealtime(): void
    {
        $user = $this->getUser();
        $presenceId = ID::unique();

        $client = $this->openConsolePresenceSocket($user, $presenceId);
        $needsCleanup = false;

        try {
            $upsertMetadata = ['testRunId' => ID::unique(), 'case' => 'console-upsert'];
            $upsert = $this->client->call(
                Client::METHOD_PUT,
                '/presences/' . $presenceId,
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => 'console',
                ], $this->getHeaders()),
                [
                    'status' => 'online',
                    'metadata' => $upsertMetadata,
                    'permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::any()),
                        Permission::delete(Role::any()),
                    ],
                ]
            );
            $this->assertSame(200, $upsert['headers']['status-code']);
            $needsCleanup = true;

            $upsertEvent = $this->receivePresenceFrame($client, $presenceId, 'upsert');
            $this->assertSame('online', $upsertEvent['data']['payload']['status'] ?? null);
            $this->assertSame($upsertMetadata, $upsertEvent['data']['payload']['metadata'] ?? null);
            $this->assertSame($user['$id'], $upsertEvent['data']['payload']['userId'] ?? null);
            // Internal fields must not leak through the realtime broadcast.
            $this->assertArrayNotHasKey('userInternalId', $upsertEvent['data']['payload']);
            $this->assertArrayNotHasKey('permissionsHash', $upsertEvent['data']['payload']);
            $this->assertArrayNotHasKey('hostname', $upsertEvent['data']['payload']);

            $updateMetadata = ['testRunId' => $upsertMetadata['testRunId'], 'case' => 'console-update'];
            $update = $this->client->call(
                Client::METHOD_PATCH,
                '/presences/' . $presenceId,
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => 'console',
                ], $this->getHeaders()),
                [
                    'status' => 'away',
                    'metadata' => $updateMetadata,
                ]
            );
            $this->assertSame(200, $update['headers']['status-code']);

            $updateEvent = $this->receivePresenceFrame($client, $presenceId, 'update');
            $this->assertSame('away', $updateEvent['data']['payload']['status'] ?? null);
            $this->assertSame($updateMetadata, $updateEvent['data']['payload']['metadata'] ?? null);
            $this->assertSame($user['$id'], $updateEvent['data']['payload']['userId'] ?? null);
        } finally {
            $client->close();

            if ($needsCleanup) {
                // Drop the row so reruns / parallel users don't accumulate orphaned presences.
                $this->client->call(
                    Client::METHOD_DELETE,
                    '/presences/' . $presenceId,
                    \array_merge([
                        'content-type' => 'application/json',
                        'x-appwrite-project' => 'console',
                    ], $this->getHeaders())
                );
            }
        }
    }

    public function testExpiredConsolePresenceDeletedByMaintenance(): void
    {
        $presenceId = ID::unique();
        // Set a near-future expiry to satisfy validation, then wait until it is in the past.
        $expiresAt = DateTime::format((new \DateTime())->modify('+2 seconds'));

        $upsert = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . $presenceId,
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => 'console',
            ], $this->getHeaders()),
            [
                'status' => 'online',
                'metadata' => ['case' => 'console-expiry'],
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ]
        );
        $this->assertSame(200, $upsert['headers']['status-code']);

        $expire = $this->client->call(
            Client::METHOD_PATCH,
            '/presences/' . $presenceId,
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => 'console',
            ], $this->getHeaders()),
            [
                'expiresAt' => $expiresAt,
            ]
        );
        $this->assertSame(200, $expire['headers']['status-code']);
        $this->assertSame(
            (new \DateTime($expiresAt))->getTimestamp(),
            (new \DateTime($expire['body']['expiresAt']))->getTimestamp()
        );

        \sleep(3);

        $stdout = '';
        $stderr = '';
        $code = Console::execute('docker exec appwrite maintenance --type=trigger', '', $stdout, $stderr);
        $this->assertSame(0, $code, "Maintenance command failed with code $code: $stderr ($stdout)");

        // Maintenance + delete workers are asynchronous; give extra time to observe cleanup.
        $this->assertEventually(function () use ($presenceId) {
            $get = $this->client->call(
                Client::METHOD_GET,
                '/presences/' . $presenceId,
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => 'console',
                ], $this->getHeaders())
            );

            $this->assertSame(404, $get['headers']['status-code']);
        }, 30000, 1000);
    }

    private function openConsolePresenceSocket(array $user, string $presenceId): WebSocketClient
    {
        $queryString = \http_build_query([
            'project' => 'console',
            'channels' => ['presences', 'presences.' . $presenceId],
        ]);

        $client = new WebSocketClient(
            'ws://appwrite.test/v1/realtime?' . $queryString,
            [
                'headers' => [
                    'origin' => 'http://localhost',
                    'cookie' => 'a_session_console=' . $user['session'],
                ],
                'timeout' => 1,
            ]
        );

        $connected = \json_decode($client->receive(), true);
        $this->assertSame('connected', $connected['type'] ?? null);

        return $client;
    }

    private function receivePresenceFrame(
        WebSocketClient $client,
        string $presenceId,
        string $action,
        int $timeoutMs = 3000
    ): array {
        $deadline = \microtime(true) + ($timeoutMs / 1000);
        $lastFrame = [];

        while (\microtime(true) < $deadline) {
            try {
                $raw = $client->receive();
            } catch (TimeoutException) {
                continue;
            }

            $frame = \json_decode($raw, true);
            if (!\is_array($frame)) {
                continue;
            }

            $lastFrame = $frame;
            if (
                ($frame['type'] ?? null) === 'event'
                && ($frame['data']['payload']['$id'] ?? null) === $presenceId
                && \in_array(
                    'presences.' . $presenceId . '.' . $action,
                    $frame['data']['events'] ?? [],
                    true
                )
            ) {
                $this->assertContains('presences', $frame['data']['channels'] ?? []);
                $this->assertContains('presences.' . $presenceId, $frame['data']['channels'] ?? []);
                return $frame;
            }
        }

        $this->fail(
            'Timed out waiting for presences.' . $presenceId . '.' . $action
            . ' frame on console. Last frame: ' . \json_encode($lastFrame)
        );
    }
}
