<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Webhooks;

use PHPUnit\Framework\Attributes\Group;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Id;
use Utopia\System\System;

final class WebhooksCustomServerTest extends Scope
{
    use WebhooksBase;
    use ProjectCustom;
    use SideServer;

    public function testReadOnlyKeyCannotRecoverAuthPassword(): void
    {
        $webhook = $this->createWebhook(
            Id::unique(),
            'Read Scope Password Test',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            'hook-user',
            'hook-password'
        );
        $this->assertSame(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getNewKey(['webhooks.read']),
        ];

        $get = $this->client->call(Client::METHOD_GET, '/webhooks/' . $webhookId, $headers);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('hook-user', $get['body']['authUsername']);
        $this->assertSame('', $get['body']['authPassword']);
        $this->assertSame('', $get['body']['secret']);

        $list = $this->client->call(Client::METHOD_GET, '/webhooks', $headers);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertNotEmpty($list['body']['webhooks']);
        foreach ($list['body']['webhooks'] as $item) {
            $this->assertSame('', $item['authPassword']);
            $this->assertSame('', $item['secret']);
        }

        $this->deleteWebhook($webhookId);
    }

    public function testDeliveryToAPrivateAddressIsRefusedBeforeConnecting(): void
    {
        // Test for FAILURE: a public name resolving to a private address is refused on the
        // address it resolved to, before any connection, so the log says nothing about the port.
        $userId = Id::unique();
        $webhook = $this->createWebhook(Id::unique(), 'Private', ["users.{$userId}.create"], true, 'http://webhook-' . \uniqid() . '.127.0.0.2.nip.io:6379/', false, null, null);
        $this->assertSame(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        try {
            $user = $this->client->call(Client::METHOD_POST, '/users', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'userId' => $userId,
                'email' => $userId . '@localhost.test',
                'password' => 'password',
            ]);
            $this->assertSame(201, $user['headers']['status-code']);

            $this->assertEventually(function () use ($webhookId) {
                $this->assertSame(1, $this->getWebhook($webhookId)['body']['attempts']);
            }, 15000, 500);

            $logs = (string) $this->getWebhook($webhookId)['body']['logs'];
            $this->assertStringContainsString('resolves to private or reserved address 127.0.0.2', $logs);
            $this->assertStringNotContainsString('not an allowed destination', $logs);
            $this->assertStringNotContainsString('connect', \strtolower($logs));
        } finally {
            $this->deleteWebhook($webhookId);
        }
    }

    public function testWebhookNameWithLineBreaksIsDelivered(): void
    {
        // Test for SUCCESS: a name with line breaks is accepted and delivered without them in its header.
        $userId = Id::unique();
        $webhook = $this->createWebhook(Id::unique(), "Line\r\nBreak\tName", ["users.{$userId}.create"], true, 'http://request-catcher-webhook:5000/', false, null, null);
        $this->assertSame(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        try {
            $user = $this->client->call(Client::METHOD_POST, '/users', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'userId' => $userId,
                'email' => $userId . '@localhost.test',
                'password' => 'password',
            ]);
            $this->assertSame(201, $user['headers']['status-code']);

            $this->assertEventually(function () use ($webhookId) {
                $this->assertCount(1, $this->webhookDeliveries($webhookId));
            }, 15000, 500);

            $delivery = $this->webhookDeliveries($webhookId)[0];
            $this->assertSame('LineBreakName', $delivery['headers']['X-Appwrite-Webhook-Name'] ?? null);
            $this->assertSame(0, $this->getWebhook($webhookId)['body']['attempts']);
        } finally {
            $this->deleteWebhook($webhookId);
        }
    }

    #[Group('queueRetry')]
    public function testRetriedEventIsNotDeliveredToRecreatedProject(): void
    {
        // Test for SUCCESS: an event retried after its project was deleted and a new project
        // was created under the same ID is dropped, not sent to the new project's webhooks.
        $projectId = Id::unique();
        $userId = Id::unique();
        $event = "users.{$userId}.create";

        $this->createProjectWithId($projectId);
        $headers = $this->getProjectAdminHeaders($projectId);

        // Nothing listens on this port, so the delivery fails and the broker parks the event.
        $failing = $this->client->call(Client::METHOD_POST, '/webhooks', $headers, [
            'webhookId' => Id::unique(),
            'name' => 'Failing',
            'events' => [$event],
            'url' => 'http://request-catcher-webhook:5001/',
            'tls' => false,
        ]);
        $this->assertSame(201, $failing['headers']['status-code']);
        $failingId = $failing['body']['$id'];

        $user = $this->client->call(Client::METHOD_POST, '/users', $headers, [
            'userId' => $userId,
            'email' => 'deleted-' . $userId . '@localhost.test',
            'password' => 'password',
        ]);
        $this->assertSame(201, $user['headers']['status-code']);

        $this->assertEventually(function () use ($headers, $failingId) {
            $webhook = $this->client->call(Client::METHOD_GET, '/webhooks/' . $failingId, $headers);
            $this->assertSame(1, $webhook['body']['attempts']);
        }, 15000, 500);

        $delete = $this->client->call(Client::METHOD_DELETE, '/projects/' . $projectId, $this->getConsoleSessionHeaders());
        $this->assertSame(204, $delete['headers']['status-code']);

        $this->createProjectWithId($projectId);

        $recreatedUserId = Id::unique();
        $webhook = $this->client->call(Client::METHOD_POST, '/webhooks', $headers, [
            'webhookId' => Id::unique(),
            'name' => 'Recreated',
            'events' => [$event, "users.{$recreatedUserId}.create"],
            'url' => 'http://request-catcher-webhook:5000/',
            'tls' => false,
        ]);
        $this->assertSame(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        // Only the retried event matches this one, and a delivery to it would fail and be recorded on the webhook.
        $stale = $this->client->call(Client::METHOD_POST, '/webhooks', $headers, [
            'webhookId' => Id::unique(),
            'name' => 'Stale',
            'events' => [$event],
            'url' => 'http://request-catcher-webhook:5001/',
            'tls' => false,
        ]);
        $this->assertSame(201, $stale['headers']['status-code']);
        $staleId = $stale['body']['$id'];

        try {
            $this->retryFailedWebhooks();

            // An event of the new project itself, queued after the retried one, is delivered.
            $user = $this->client->call(Client::METHOD_POST, '/users', $headers, [
                'userId' => $recreatedUserId,
                'email' => 'recreated-' . $recreatedUserId . '@localhost.test',
                'password' => 'password',
            ]);
            $this->assertSame(201, $user['headers']['status-code']);

            $this->assertEventually(function () use ($webhookId) {
                $this->assertNotEmpty($this->webhookDeliveries($webhookId));
            }, 15000, 500);

            $settled = \microtime(true) + 10;
            while (\microtime(true) < $settled) {
                $this->assertSame(
                    ['recreated-' . $recreatedUserId . '@localhost.test'],
                    \array_map(fn (array $delivery) => $delivery['data']['email'] ?? null, $this->webhookDeliveries($webhookId)),
                    'The event of the deleted project was delivered to the project created under its ID',
                );

                $staleWebhook = $this->client->call(Client::METHOD_GET, '/webhooks/' . $staleId, $headers);
                $this->assertSame(200, $staleWebhook['headers']['status-code']);
                $this->assertSame(0, $staleWebhook['body']['attempts'], 'The event of the deleted project was processed for the project created under its ID');
                $this->assertSame('', $staleWebhook['body']['logs']);
                \usleep(1000000);
            }
        } finally {
            $this->client->call(Client::METHOD_DELETE, '/projects/' . $projectId, $this->getConsoleSessionHeaders());
        }
    }

    private function createProjectWithId(string $projectId): void
    {
        $team = $this->createTeamFixture($this->getConsoleSessionHeaders(), [
            'teamId' => Id::unique(),
            'name' => 'Recreated Project Team',
        ]);
        $this->assertSame(200, $team['headers']['status-code']);

        $project = $this->client->call(Client::METHOD_POST, '/projects', $this->getConsoleSessionHeaders(), [
            'projectId' => $projectId,
            'region' => System::getEnv('_APP_REGION', 'default'),
            'name' => 'Recreated Project',
            'teamId' => $team['body']['$id'],
        ]);
        $this->assertSame(201, $project['headers']['status-code']);
    }

    private function getConsoleSessionHeaders(): array
    {
        return [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ];
    }

    private function getProjectAdminHeaders(string $projectId): array
    {
        return \array_merge($this->getConsoleSessionHeaders(), [
            'x-appwrite-project' => $projectId,
            'x-appwrite-mode' => 'admin',
        ]);
    }
}
