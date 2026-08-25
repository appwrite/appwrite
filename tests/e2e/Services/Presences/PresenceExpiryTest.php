<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Presences;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\ID;

final class PresenceExpiryTest extends Scope
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
            'presences.read',
            'presences.write',
        ]);

        return self::$presenceApiKeyCache[$projectId];
    }

    public function testExpiredPresenceDeletedByMaintenance(): void
    {
        $projectId = $this->getProject()['$id'];
        $userId = $this->getUser()['$id'];

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

        // Compute expiry immediately before PATCH. The API requires a future
        // datetime; stamping it before create can already be in the past under load.
        $expiresAt = DateTime::format((new \DateTime())->modify('+2 seconds'));
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
                'expiresAt' => $expiresAt,
            ]
        );

        $this->assertEquals(200, $expireServer['headers']['status-code']);
        $this->assertSame(
            (new \DateTime($expiresAt))->getTimestamp(),
            (new \DateTime($expireServer['body']['expiresAt']))->getTimestamp()
        );

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getPresenceApiKey(),
        ];

        // Wait until expiresAt is in the past, then run maintenance and observe cleanup.
        $this->assertEventually(function () use ($expiresAt) {
            $this->assertGreaterThan(
                (new \DateTime($expiresAt))->getTimestamp(),
                (new \DateTime())->getTimestamp()
            );
        }, 30000, 50);

        $stdout = '';
        $stderr = '';
        $code = Console::execute('docker exec appwrite maintenance --type=trigger', '', $stdout, $stderr);
        $this->assertSame(0, $code, "Maintenance command failed with code $code: $stderr ($stdout)");

        $this->assertEventually(function () use ($presenceIdServer, $headers) {
            $getServer = $this->client->call(
                Client::METHOD_GET,
                '/presences/' . $presenceIdServer,
                $headers
            );

            $this->assertEquals(404, $getServer['headers']['status-code']);
        }, 30000, 1000);
    }
}
