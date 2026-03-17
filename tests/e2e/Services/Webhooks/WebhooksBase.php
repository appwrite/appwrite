<?php

namespace Tests\E2E\Services\Webhooks;

use Appwrite\Tests\Async;
use Tests\E2E\Client;

trait WebhooksBase
{
    use Async;

    // Tests for all auth scenarios

    public function testCreateWebhook(): void
    {
    }
    
    public function testCreateWebhookWithSecurity(): void
    {
    }
    
    public function testCreateWebhookWithHttpAuth(): void
    {
    }
    
    public function testCreateWebhookEnabled(): void
    {
    }
    
    public function testCreateWebhookWithoutAuthentication(): void
    {
    }
    
    public function testCreateWebhookInvalidId(): void
    {
    }

    public function testCreateWebhookMissingName(): void
    {
    }
    
    public function testCreateWebhookMissingUrl(): void
    {
    }
    
    public function testCreateWebhookMissingEvents(): void
    {
    }
    
    public function testCreateWebhookDuplicateId(): void
    {
    }
    
    public function testCreateWebhookAudit(): void
    {
    }
    
    public function testUpdateWebhook(): void
    {
    }
    
    public function testUpdateWebhookWithSecurity(): void
    {
    }
    
    public function testUpdateWebhookWithHttpAuth(): void
    {
    }
    
    public function testUpdateWebhookEnabled(): void
    {
    }
    
    public function testUpdateWebhookWithoutAuthentication(): void
    {
    }
    
    public function testUpdateWebhookInvalidId(): void
    {
    }

    public function testUpdateWebhookMissingName(): void
    {
    }
    
    public function testUpdateWebhookMissingUrl(): void
    {
    }
    
    public function testUpdateWebhookMissingEvents(): void
    {
    }
    
    public function testUpdateWebhookDuplicateId(): void
    {
    }
    
    public function testUpdateWebhookAudit(): void
    {
    }
    
    public function testUpdateWebhookSignature(): void
    {
    }

    // Helpers

    /**
     * @param array<string>|null $queries
     */
    protected function listWebhooks(?array $queries, ?bool $total): mixed
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
    
    protected function getWebhook(string $webhookId): mixed
    {
        $webhook = $this->client->call(Client::METHOD_GET, '/webhooks/' . $webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $webhook;
    }
    
    protected function createWebhook(string $webhookId, string $name, array $events, ?bool $enabled, ?string $url, ?bool $security, ?string $httpUser, ?string $httpPass): mixed
    {
        $webhook = $this->client->call(Client::METHOD_POST, '/webhooks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'webhookId' => $webhookId,
            'name' => $name,
            'events' => $events,
            'enabled' => $enabled,
            'url' => $url,
            'security' => $security,
            'httpUser' => $httpUser,
            'httpPass' => $httpPass,
        ]);

        return $webhook;
    }
    
    protected function updateWebhook(string $webhookId, string $name, array $events, ?bool $enabled, ?string $url, ?bool $security, ?string $httpUser, ?string $httpPass): mixed
    {
        $webhook = $this->client->call(Client::METHOD_PUT, '/webhooks/' . $webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => $name,
            'events' => $events,
            'enabled' => $enabled,
            'url' => $url,
            'security' => $security,
            'httpUser' => $httpUser,
            'httpPass' => $httpPass,
        ]);

        return $webhook;
    }
    
    protected function updateWebhookSignature(string $webhookId): mixed
    {
        $webhook = $this->client->call(Client::METHOD_PATCH, '/webhooks/' . $webhookId . '/signature', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $webhook;
    }
}
