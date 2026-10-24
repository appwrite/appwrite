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

    public function testWebhookNameWithLineBreaksIsDelivered(): void
    {
        $userId = ID::unique();
        $webhook = $this->createWebhook(ID::unique(), "Line\r\nBreak\tName", ["users.{$userId}.create"], true, 'http://request-catcher-webhook:5000/', false, null, null);
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

            $delivery = $this->getLastRequestForProject($this->getProject()['$id'], self::REQUEST_TYPE_WEBHOOK, [
                'header_X-Appwrite-Webhook-Id' => $webhookId,
            ]);

            $this->assertSame('LineBreakName', $delivery['headers']['X-Appwrite-Webhook-Name'] ?? null);
            $this->assertSame(0, $this->getWebhook($webhookId)['body']['attempts']);
        } finally {
            $this->deleteWebhook($webhookId);
        }
    }
}
