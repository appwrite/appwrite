<?php

namespace Tests\E2E\Services\Webhooks;

use Appwrite\Tests\Async;
use Tests\E2E\Client;

trait WebhooksBase
{
    use Async;

    // Tests for all auth scenarios

    public function testListWebhooks(): void
    {
        $webhooks = $this->listWebhooks();

        $this->assertSame(200, $webhooks['headers']['status-code']);
        $this->assertSame(1, $webhooks['body']['total']); // One created during project setup
        $this->assertIsArray($webhooks['body']['webhooks']);
        $this->assertCount(1, $webhooks['body']['webhooks']);
    }

    // Helpers

    /**
     * @param array<string> $queries
     */
    protected function listWebhooks(array $queries = [], bool $total = true): mixed
    {
        $webhooks = $this->client->call(Client::METHOD_GET, '/webhooks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'querues' => $queries,
            'total' => $total
        ]);

        return $webhooks;
    }
}
