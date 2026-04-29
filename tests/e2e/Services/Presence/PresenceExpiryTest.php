<?php

namespace Tests\E2E\Services\Presence;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\ID;

class PresenceExpiryTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    private static array $presenceApiKeyCache = [];

    private function getPresenceApiKey(): string
    {
        $projectId = $this->getProject()['$id'];

        if (!empty(self::$presenceApiKeyCache[$projectId])) {
            return self::$presenceApiKeyCache[$projectId];
        }

        self::$presenceApiKeyCache[$projectId] = $this->getNewKey([
            'presence.read',
            'presence.write',
        ]);

        return self::$presenceApiKeyCache[$projectId];
    }

    public function testExpiredPresenceDeletedByMaintenance(): void
    {
        $projectId = $this->getProject()['$id'];
        $userId = $this->getUser()['$id'];
        // Must match the format used by the maintenance worker query.
        $expiredAt = DateTime::format((new \DateTime())->modify('-120 seconds'));

        $createServer = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . ID::unique(),
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getPresenceApiKey(),
            ],
            [
                'userId' => $userId,
                'status' => 'online',
                'metadata' => ['test' => 'presence-expiry'],
            ]
        );

        $this->assertEquals(200, $createServer['headers']['status-code']);
        $presenceIdServer = $createServer['body']['$id'];

        $expireServer = $this->client->call(
            Client::METHOD_PATCH,
            '/presences/' . $presenceIdServer,
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getPresenceApiKey(),
            ],
            [
                'userId' => $userId,
                'expiry' => $expiredAt,
            ]
        );

        $this->assertEquals(200, $expireServer['headers']['status-code']);
        $this->assertEquals(
            (new \DateTime($expiredAt))->getTimestamp(),
            (new \DateTime($expireServer['body']['expiry']))->getTimestamp()
        );

        $stdout = '';
        $stderr = '';
        $code = Console::execute('docker exec appwrite maintenance --type=trigger', '', $stdout, $stderr);
        $this->assertSame(0, $code, "Maintenance command failed with code $code: $stderr ($stdout)");

        // Maintenance + delete workers are asynchronous; give extra time to observe cleanup.
        $this->assertEventually(function () use ($presenceIdServer, $projectId) {
            $getServer = $this->client->call(
                Client::METHOD_GET,
                '/presences/' . $presenceIdServer,
                [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $projectId,
                    'x-appwrite-key' => $this->getPresenceApiKey(),
                ]
            );

            $this->assertEquals(404, $getServer['headers']['status-code']);
        }, 30000, 1000);
    }
}
