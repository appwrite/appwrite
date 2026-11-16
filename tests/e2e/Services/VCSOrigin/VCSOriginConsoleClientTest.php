<?php

declare(strict_types=1);

namespace Tests\E2E\Services\VCSOrigin;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

/**
 * Origin has no self-hostable instance and registers apps out of band on
 * Cursor, so these cover the surface the server answers on its own: the
 * install entry point without a configured app, and the public callback and
 * delivery endpoints refusing what they cannot authenticate - all without
 * fetching Cursor's signing keys.
 */
final class VCSOriginConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    public function testCreateInstallationWhenNotConfigured(): void
    {
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/vcs/origin/authorize', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(500, $response['headers']['status-code']);

        // Starting an installation creates one, so read access alone is not enough
        $response = $this->client->call(Client::METHOD_GET, '/vcs/origin/authorize', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getNewKey(['vcs.read']),
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testCreateInstallationWithoutReceipt(): void
    {
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/vcs/origin/callback', [
            'content-type' => 'application/json',
        ], [
            'installation_id' => 'inst_0123456789',
            'state' => '{"projectId":"' . $this->getProject()['$id'] . '"}',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateInstallationWithUnsignedReceipt(): void
    {
        /**
         * Test for FAILURE
         */
        // A receipt that names no EdDSA signature is refused before any key is fetched
        $encode = fn (array $data): string => \rtrim(\strtr(\base64_encode((string) \json_encode($data)), '+/', '-_'), '=');
        $receipt = $encode(['alg' => 'none', 'typ' => 'origin-installation-receipt+jwt'])
            . '.' . $encode(['iss' => 'https://api.cursor.com/v1/origin', 'aud' => 'appwrite', 'sub' => 'inst_0123456789', 'exp' => \time() + 300])
            . '.';

        $response = $this->client->call(Client::METHOD_GET, '/vcs/origin/callback', [
            'content-type' => 'application/json',
        ], [
            'installation_id' => 'inst_0123456789',
            'installation_receipt' => $receipt,
            'state' => '{"projectId":"' . $this->getProject()['$id'] . '"}',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateEventWithoutSignature(): void
    {
        /**
         * Test for FAILURE
         */
        $event = $this->client->call(Client::METHOD_POST, '/vcs/origin/events', [
            'content-type' => 'application/json',
            'webhook-event-type' => 'repository.pushed',
        ], $this->pushPayload());

        $this->assertEquals(401, $event['headers']['status-code']);
        $this->assertEquals('general_access_forbidden', $event['body']['type']);
    }

    public function testCreateEventWithStaleTimestamp(): void
    {
        /**
         * Test for FAILURE
         */
        // A delivery older than five minutes is a replay, whatever it is signed with
        $event = $this->client->call(Client::METHOD_POST, '/vcs/origin/events', [
            'content-type' => 'application/json',
            'webhook-event-type' => 'repository.pushed',
            'webhook-id' => 'msg_0123456789',
            'webhook-timestamp' => (string) (\time() - 3600),
            'webhook-signature' => 'v1a,' . \base64_encode(\str_repeat('a', 64)),
        ], $this->pushPayload());

        $this->assertEquals(401, $event['headers']['status-code']);
        $this->assertEquals('general_access_forbidden', $event['body']['type']);
    }

    /**
     * @return array<string, mixed>
     */
    private function pushPayload(): array
    {
        return [
            'deliveryId' => 'whd_0123456789',
            'appId' => 'app_0123456789',
            'installationId' => 'inst_0123456789',
            'event' => [
                'id' => 'evt_0123456789',
                'type' => 'repository.pushed',
                'eventTime' => '2026-11-13T10:00:00Z',
                'payload' => [
                    'repository' => [
                        'id' => 'repo_0123456789',
                        'name' => 'api',
                        'owner' => ['slug' => 'acme', 'id' => 'ns_0123456789'],
                    ],
                    'refUpdates' => [[
                        'ref' => 'refs/heads/main',
                        'before' => \str_repeat('a', 40),
                        'after' => \str_repeat('b', 40),
                        'created' => false,
                        'deleted' => false,
                        'forced' => false,
                        'headCommit' => [
                            'sha' => \str_repeat('b', 40),
                            'author' => ['name' => 'Alice', 'email' => 'alice@example.com'],
                            'message' => 'Update',
                        ],
                    ]],
                    'refUpdatesCount' => 1,
                ],
            ],
        ];
    }
}
