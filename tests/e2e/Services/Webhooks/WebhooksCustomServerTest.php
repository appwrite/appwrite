<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Webhooks;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

final class WebhooksCustomServerTest extends Scope
{
    use WebhooksBase;
    use ProjectCustom;
    use SideServer;

    public function testReadOnlyKeyCannotRecoverAuthPassword(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
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
}
