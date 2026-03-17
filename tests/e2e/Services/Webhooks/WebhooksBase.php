<?php

namespace Tests\E2E\Services\Webhooks;

use Appwrite\Tests\Async;
use Tests\E2E\Client;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

trait WebhooksBase
{
    use Async;

    // Tests for all auth scenarios

    public function testCreateWebhook(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Test Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $this->assertNotEmpty($webhook['body']['$id']);
        $this->assertEquals('Test Webhook', $webhook['body']['name']);
        $this->assertEquals('https://appwrite.io', $webhook['body']['url']);
        $this->assertContains('users.*.create', $webhook['body']['events']);
        $this->assertCount(1, $webhook['body']['events']);
        $this->assertEquals(true, $webhook['body']['enabled']);
        $this->assertEquals(false, $webhook['body']['security']);
        $this->assertEquals('', $webhook['body']['httpUser']);
        $this->assertEquals('', $webhook['body']['httpPass']);
        $this->assertNotEmpty($webhook['body']['signatureKey']);
        $this->assertEquals(128, \strlen($webhook['body']['signatureKey']));
        $this->assertEquals(0, $webhook['body']['attempts']);
        $this->assertEquals('', $webhook['body']['logs']);

        $dateValidator = new DatetimeValidator();
        $this->assertEquals(true, $dateValidator->isValid($webhook['body']['$createdAt']));
        $this->assertEquals(true, $dateValidator->isValid($webhook['body']['$updatedAt']));

        // Verify via GET
        $get = $this->getWebhook($webhook['body']['$id']);
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals($webhook['body']['$id'], $get['body']['$id']);
        $this->assertEquals('Test Webhook', $get['body']['name']);

        // Verify via LIST
        $list = $this->listWebhooks(null, true);
        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);
        $this->assertGreaterThanOrEqual(1, \count($list['body']['webhooks']));

        // Cleanup
        $this->deleteWebhook($webhook['body']['$id']);
    }

    public function testCreateWebhookWithSecurity(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Webhook With Security',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            true,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $this->assertNotEmpty($webhook['body']['$id']);
        $this->assertEquals(true, $webhook['body']['security']);
        $this->assertIsBool($webhook['body']['security']);

        // Cleanup
        $this->deleteWebhook($webhook['body']['$id']);
    }

    public function testCreateWebhookWithHttpAuth(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Webhook With HTTP Auth',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            true,
            'username',
            'password'
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $this->assertNotEmpty($webhook['body']['$id']);
        $this->assertEquals('username', $webhook['body']['httpUser']);
        $this->assertEquals('password', $webhook['body']['httpPass']);
        $this->assertEquals(true, $webhook['body']['security']);

        // Verify via GET
        $get = $this->getWebhook($webhook['body']['$id']);
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals('username', $get['body']['httpUser']);

        // Cleanup
        $this->deleteWebhook($webhook['body']['$id']);
    }

    public function testCreateWebhookEnabled(): void
    {
        // Create disabled webhook
        $webhook = $this->createWebhook(
            ID::unique(),
            'Disabled Webhook',
            ['users.*.create'],
            false,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $this->assertEquals(false, $webhook['body']['enabled']);
        $this->assertIsBool($webhook['body']['enabled']);

        // Cleanup
        $this->deleteWebhook($webhook['body']['$id']);

        // Create enabled webhook explicitly
        $webhook = $this->createWebhook(
            ID::unique(),
            'Enabled Webhook',
            ['users.*.create'],
            true,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $this->assertEquals(true, $webhook['body']['enabled']);

        // Cleanup
        $this->deleteWebhook($webhook['body']['$id']);
    }

    public function testCreateWebhookWithoutAuthentication(): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/webhooks', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'webhookId' => ID::unique(),
            'name' => 'Test Webhook',
            'events' => ['users.*.create'],
            'url' => 'https://appwrite.io',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testCreateWebhookInvalidId(): void
    {
        $webhook = $this->createWebhook(
            '!invalid-id!',
            'Test Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(400, $webhook['headers']['status-code']);
    }

    public function testCreateWebhookMissingName(): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/webhooks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'webhookId' => ID::unique(),
            'events' => ['users.*.create'],
            'url' => 'https://appwrite.io',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateWebhookMissingUrl(): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/webhooks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'webhookId' => ID::unique(),
            'name' => 'Test Webhook',
            'events' => ['users.*.create'],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateWebhookMissingEvents(): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/webhooks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'webhookId' => ID::unique(),
            'name' => 'Test Webhook',
            'url' => 'https://appwrite.io',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateWebhookDuplicateId(): void
    {
        $webhookId = ID::unique();

        $webhook = $this->createWebhook(
            $webhookId,
            'Test Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);

        // Attempt to create with same ID
        $duplicate = $this->createWebhook(
            $webhookId,
            'Duplicate Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(409, $duplicate['headers']['status-code']);
        $this->assertEquals('webhook_already_exists', $duplicate['body']['type']);

        // Cleanup
        $this->deleteWebhook($webhookId);
    }

    public function testCreateWebhookAudit(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Audit Webhook',
            ['users.*.create', 'users.*.update.email'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $this->assertNotEmpty($webhook['body']['$id']);
        $this->assertContains('users.*.create', $webhook['body']['events']);
        $this->assertContains('users.*.update.email', $webhook['body']['events']);
        $this->assertCount(2, $webhook['body']['events']);

        // Cleanup
        $this->deleteWebhook($webhook['body']['$id']);
    }

    public function testUpdateWebhook(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Original Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        // Update the webhook
        $updated = $this->updateWebhook(
            $webhookId,
            'Updated Webhook',
            ['users.*.delete', 'users.*.sessions.*.delete'],
            null,
            'https://appwrite.io/new',
            null,
            null,
            null
        );

        $this->assertEquals(200, $updated['headers']['status-code']);
        $this->assertEquals($webhookId, $updated['body']['$id']);
        $this->assertEquals('Updated Webhook', $updated['body']['name']);
        $this->assertEquals('https://appwrite.io/new', $updated['body']['url']);
        $this->assertContains('users.*.delete', $updated['body']['events']);
        $this->assertContains('users.*.sessions.*.delete', $updated['body']['events']);
        $this->assertCount(2, $updated['body']['events']);

        // Verify update persisted via GET
        $get = $this->getWebhook($webhookId);
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals('Updated Webhook', $get['body']['name']);
        $this->assertEquals('https://appwrite.io/new', $get['body']['url']);

        // Cleanup
        $this->deleteWebhook($webhookId);
    }

    public function testUpdateWebhookWithSecurity(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Security Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            false,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $this->assertEquals(false, $webhook['body']['security']);
        $webhookId = $webhook['body']['$id'];

        // Update to enable security
        $updated = $this->updateWebhook(
            $webhookId,
            'Security Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            true,
            null,
            null
        );

        $this->assertEquals(200, $updated['headers']['status-code']);
        $this->assertEquals(true, $updated['body']['security']);
        $this->assertIsBool($updated['body']['security']);

        // Cleanup
        $this->deleteWebhook($webhookId);
    }

    public function testUpdateWebhookWithHttpAuth(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'HTTP Auth Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            true,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $this->assertEquals('', $webhook['body']['httpUser']);
        $this->assertEquals('', $webhook['body']['httpPass']);
        $webhookId = $webhook['body']['$id'];

        // Update with HTTP auth credentials
        $updated = $this->updateWebhook(
            $webhookId,
            'HTTP Auth Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            true,
            'newuser',
            'newpass'
        );

        $this->assertEquals(200, $updated['headers']['status-code']);
        $this->assertEquals('newuser', $updated['body']['httpUser']);
        $this->assertEquals('newpass', $updated['body']['httpPass']);

        // Verify via GET
        $get = $this->getWebhook($webhookId);
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals('newuser', $get['body']['httpUser']);

        // Cleanup
        $this->deleteWebhook($webhookId);
    }

    public function testUpdateWebhookEnabled(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Enabled Webhook',
            ['users.*.create'],
            true,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $this->assertEquals(true, $webhook['body']['enabled']);
        $webhookId = $webhook['body']['$id'];

        // Disable the webhook
        $updated = $this->updateWebhook(
            $webhookId,
            'Enabled Webhook',
            ['users.*.create'],
            false,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(200, $updated['headers']['status-code']);
        $this->assertEquals(false, $updated['body']['enabled']);
        $this->assertIsBool($updated['body']['enabled']);

        // Re-enable the webhook (should reset attempts to 0)
        $updated = $this->updateWebhook(
            $webhookId,
            'Enabled Webhook',
            ['users.*.create'],
            true,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(200, $updated['headers']['status-code']);
        $this->assertEquals(true, $updated['body']['enabled']);
        $this->assertEquals(0, $updated['body']['attempts']);

        // Cleanup
        $this->deleteWebhook($webhookId);
    }

    public function testUpdateWebhookWithoutAuthentication(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Auth Test Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        // Attempt update without authentication
        $response = $this->client->call(Client::METHOD_PUT, '/webhooks/' . $webhookId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'name' => 'Updated Webhook',
            'events' => ['users.*.create'],
            'url' => 'https://appwrite.io',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // Cleanup
        $this->deleteWebhook($webhookId);
    }

    public function testUpdateWebhookInvalidId(): void
    {
        $updated = $this->updateWebhook(
            'non-existent-id',
            'Updated Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(404, $updated['headers']['status-code']);
        $this->assertEquals('webhook_not_found', $updated['body']['type']);
    }

    public function testUpdateWebhookMissingName(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Missing Name Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        $response = $this->client->call(Client::METHOD_PUT, '/webhooks/' . $webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'events' => ['users.*.create'],
            'url' => 'https://appwrite.io',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Cleanup
        $this->deleteWebhook($webhookId);
    }

    public function testUpdateWebhookMissingUrl(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Missing URL Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        $response = $this->client->call(Client::METHOD_PUT, '/webhooks/' . $webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Missing URL Webhook',
            'events' => ['users.*.create'],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Cleanup
        $this->deleteWebhook($webhookId);
    }

    public function testUpdateWebhookMissingEvents(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Missing Events Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        $response = $this->client->call(Client::METHOD_PUT, '/webhooks/' . $webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Missing Events Webhook',
            'url' => 'https://appwrite.io',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Cleanup
        $this->deleteWebhook($webhookId);
    }

    public function testUpdateWebhookDuplicateId(): void
    {
        // Update endpoint doesn't change the ID, so this tests updating a non-existent webhook
        $updated = $this->updateWebhook(
            'non-existent-id',
            'Duplicate Test Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(404, $updated['headers']['status-code']);
        $this->assertEquals('webhook_not_found', $updated['body']['type']);
    }

    public function testUpdateWebhookAudit(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Audit Update Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        // Update with multiple events
        $updated = $this->updateWebhook(
            $webhookId,
            'Audit Update Webhook Updated',
            ['users.*.delete', 'users.*.sessions.*.delete', 'buckets.*.files.*.create'],
            null,
            'https://appwrite.io/updated',
            true,
            'user',
            'pass'
        );

        $this->assertEquals(200, $updated['headers']['status-code']);
        $this->assertEquals($webhookId, $updated['body']['$id']);
        $this->assertEquals('Audit Update Webhook Updated', $updated['body']['name']);
        $this->assertContains('users.*.delete', $updated['body']['events']);
        $this->assertContains('users.*.sessions.*.delete', $updated['body']['events']);
        $this->assertContains('buckets.*.files.*.create', $updated['body']['events']);
        $this->assertCount(3, $updated['body']['events']);
        $this->assertEquals('https://appwrite.io/updated', $updated['body']['url']);
        $this->assertEquals(true, $updated['body']['security']);
        $this->assertEquals('user', $updated['body']['httpUser']);
        $this->assertEquals('pass', $updated['body']['httpPass']);

        // Cleanup
        $this->deleteWebhook($webhookId);
    }

    public function testUpdateWebhookSignature(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Signature Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];
        $originalSignatureKey = $webhook['body']['signatureKey'];

        $this->assertNotEmpty($originalSignatureKey);
        $this->assertEquals(128, \strlen($originalSignatureKey));

        // Update signature
        $updated = $this->updateWebhookSignature($webhookId);

        $this->assertEquals(200, $updated['headers']['status-code']);
        $this->assertEquals($webhookId, $updated['body']['$id']);
        $this->assertNotEmpty($updated['body']['signatureKey']);
        $this->assertEquals(128, \strlen($updated['body']['signatureKey']));
        $this->assertNotEquals($originalSignatureKey, $updated['body']['signatureKey']);

        // Verify new signature persisted via GET
        $get = $this->getWebhook($webhookId);
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertNotEquals($originalSignatureKey, $get['body']['signatureKey']);

        // Test signature update on non-existent webhook
        $notFound = $this->updateWebhookSignature('non-existent-id');
        $this->assertEquals(404, $notFound['headers']['status-code']);
        $this->assertEquals('webhook_not_found', $notFound['body']['type']);

        // Cleanup
        $this->deleteWebhook($webhookId);
    }

    // URL validation tests

    public function testCreateWebhookWithPrivateDomain(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Private Domain Webhook',
            ['users.*.create'],
            null,
            'http://localhost/webhook',
            null,
            null,
            null
        );

        $this->assertEquals(400, $webhook['headers']['status-code']);
    }

    public function testUpdateWebhookWithPrivateDomain(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Private Domain Update Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        // Attempt to update URL to private domain
        $updated = $this->updateWebhook(
            $webhookId,
            'Private Domain Update Webhook',
            ['users.*.create'],
            null,
            'http://localhost/webhook',
            null,
            null,
            null
        );

        $this->assertEquals(400, $updated['headers']['status-code']);

        // Verify original URL unchanged
        $get = $this->getWebhook($webhookId);
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals('https://appwrite.io', $get['body']['url']);

        // Cleanup
        $this->deleteWebhook($webhookId);
    }

    public function testCreateWebhookInvalidUrlScheme(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Invalid Scheme Webhook',
            ['users.*.create'],
            null,
            'invalid://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(400, $webhook['headers']['status-code']);
    }

    public function testUpdateWebhookInvalidUrlScheme(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Scheme Update Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        // Attempt to update URL to invalid scheme
        $updated = $this->updateWebhook(
            $webhookId,
            'Scheme Update Webhook',
            ['users.*.create'],
            null,
            'invalid://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(400, $updated['headers']['status-code']);

        // Verify original URL unchanged
        $get = $this->getWebhook($webhookId);
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals('https://appwrite.io', $get['body']['url']);

        // Cleanup
        $this->deleteWebhook($webhookId);
    }

    // Event validation tests

    public function testCreateWebhookInvalidEvents(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Invalid Events Webhook',
            ['account.unknown'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(400, $webhook['headers']['status-code']);
    }

    public function testUpdateWebhookInvalidEvents(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Invalid Events Update Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        // Attempt to update with invalid event
        $updated = $this->updateWebhook(
            $webhookId,
            'Invalid Events Update Webhook',
            ['account.unknown'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(400, $updated['headers']['status-code']);

        // Verify original events unchanged
        $get = $this->getWebhook($webhookId);
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertContains('users.*.create', $get['body']['events']);

        // Cleanup
        $this->deleteWebhook($webhookId);
    }

    // Custom ID test

    public function testCreateWebhookCustomId(): void
    {
        $customId = 'my-custom-webhook-id';

        $webhook = $this->createWebhook(
            $customId,
            'Custom ID Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $this->assertEquals($customId, $webhook['body']['$id']);

        // Verify via GET
        $get = $this->getWebhook($customId);
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals($customId, $get['body']['$id']);

        // Cleanup
        $this->deleteWebhook($customId);
    }

    // Get webhook tests

    public function testGetWebhook(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Get Test Webhook',
            ['users.*.create', 'users.*.update.email'],
            null,
            'https://appwrite.io',
            true,
            'myuser',
            'mypass'
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        $get = $this->getWebhook($webhookId);

        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals($webhookId, $get['body']['$id']);
        $this->assertEquals('Get Test Webhook', $get['body']['name']);
        $this->assertEquals('https://appwrite.io', $get['body']['url']);
        $this->assertContains('users.*.create', $get['body']['events']);
        $this->assertContains('users.*.update.email', $get['body']['events']);
        $this->assertCount(2, $get['body']['events']);
        $this->assertEquals(true, $get['body']['enabled']);
        $this->assertEquals(true, $get['body']['security']);
        $this->assertEquals('myuser', $get['body']['httpUser']);
        $this->assertEquals('mypass', $get['body']['httpPass']);
        $this->assertNotEmpty($get['body']['signatureKey']);
        $this->assertEquals(128, \strlen($get['body']['signatureKey']));
        $this->assertEquals(0, $get['body']['attempts']);
        $this->assertEquals('', $get['body']['logs']);

        $dateValidator = new DatetimeValidator();
        $this->assertEquals(true, $dateValidator->isValid($get['body']['$createdAt']));
        $this->assertEquals(true, $dateValidator->isValid($get['body']['$updatedAt']));

        // Cleanup
        $this->deleteWebhook($webhookId);
    }

    public function testGetWebhookNotFound(): void
    {
        $get = $this->getWebhook('non-existent-id');

        $this->assertEquals(404, $get['headers']['status-code']);
        $this->assertEquals('webhook_not_found', $get['body']['type']);
    }

    public function testGetWebhookWithoutAuthentication(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Auth Get Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        // Attempt GET without authentication
        $response = $this->client->call(Client::METHOD_GET, '/webhooks/' . $webhookId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // Cleanup
        $this->deleteWebhook($webhookId);
    }

    // List webhooks tests

    public function testListWebhooks(): void
    {
        // Create multiple webhooks
        $webhook1 = $this->createWebhook(
            ID::unique(),
            'List Webhook Alpha',
            ['users.*.create'],
            true,
            'https://appwrite.io/alpha',
            false,
            null,
            null
        );
        $this->assertEquals(201, $webhook1['headers']['status-code']);

        $webhook2 = $this->createWebhook(
            ID::unique(),
            'List Webhook Beta',
            ['users.*.delete'],
            false,
            'https://appwrite.io/beta',
            true,
            'user',
            'pass'
        );
        $this->assertEquals(201, $webhook2['headers']['status-code']);

        $webhook3 = $this->createWebhook(
            ID::unique(),
            'List Webhook Gamma',
            ['users.*.create', 'users.*.delete'],
            true,
            'https://appwrite.io/gamma',
            false,
            null,
            null
        );
        $this->assertEquals(201, $webhook3['headers']['status-code']);

        // List all
        $list = $this->listWebhooks(null, true);

        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(3, $list['body']['total']);
        $this->assertGreaterThanOrEqual(3, \count($list['body']['webhooks']));
        $this->assertIsArray($list['body']['webhooks']);

        // Verify structure of returned webhooks
        foreach ($list['body']['webhooks'] as $webhook) {
            $this->assertArrayHasKey('$id', $webhook);
            $this->assertArrayHasKey('$createdAt', $webhook);
            $this->assertArrayHasKey('$updatedAt', $webhook);
            $this->assertArrayHasKey('name', $webhook);
            $this->assertArrayHasKey('url', $webhook);
            $this->assertArrayHasKey('events', $webhook);
            $this->assertArrayHasKey('security', $webhook);
            $this->assertArrayHasKey('enabled', $webhook);
            $this->assertArrayHasKey('signatureKey', $webhook);
            $this->assertArrayHasKey('attempts', $webhook);
            $this->assertArrayHasKey('logs', $webhook);
        }

        // Cleanup
        $this->deleteWebhook($webhook1['body']['$id']);
        $this->deleteWebhook($webhook2['body']['$id']);
        $this->deleteWebhook($webhook3['body']['$id']);
    }

    public function testListWebhooksWithLimit(): void
    {
        $webhook1 = $this->createWebhook(
            ID::unique(),
            'Limit Webhook 1',
            ['users.*.create'],
            null,
            'https://appwrite.io/one',
            null,
            null,
            null
        );
        $this->assertEquals(201, $webhook1['headers']['status-code']);

        $webhook2 = $this->createWebhook(
            ID::unique(),
            'Limit Webhook 2',
            ['users.*.create'],
            null,
            'https://appwrite.io/two',
            null,
            null,
            null
        );
        $this->assertEquals(201, $webhook2['headers']['status-code']);

        // List with limit of 1
        $list = $this->listWebhooks([
            Query::limit(1)->toString(),
        ], true);

        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertCount(1, $list['body']['webhooks']);
        $this->assertGreaterThanOrEqual(2, $list['body']['total']);

        // Cleanup
        $this->deleteWebhook($webhook1['body']['$id']);
        $this->deleteWebhook($webhook2['body']['$id']);
    }

    public function testListWebhooksWithOffset(): void
    {
        $webhook1 = $this->createWebhook(
            ID::unique(),
            'Offset Webhook 1',
            ['users.*.create'],
            null,
            'https://appwrite.io/one',
            null,
            null,
            null
        );
        $this->assertEquals(201, $webhook1['headers']['status-code']);

        $webhook2 = $this->createWebhook(
            ID::unique(),
            'Offset Webhook 2',
            ['users.*.create'],
            null,
            'https://appwrite.io/two',
            null,
            null,
            null
        );
        $this->assertEquals(201, $webhook2['headers']['status-code']);

        // List all to get total
        $listAll = $this->listWebhooks(null, true);
        $this->assertEquals(200, $listAll['headers']['status-code']);
        $totalAll = \count($listAll['body']['webhooks']);

        // List with offset
        $listOffset = $this->listWebhooks([
            Query::offset(1)->toString(),
        ], true);

        $this->assertEquals(200, $listOffset['headers']['status-code']);
        $this->assertCount($totalAll - 1, $listOffset['body']['webhooks']);

        // Cleanup
        $this->deleteWebhook($webhook1['body']['$id']);
        $this->deleteWebhook($webhook2['body']['$id']);
    }

    public function testListWebhooksFilterByName(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'UniqueFilterName-XYZ',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );
        $this->assertEquals(201, $webhook['headers']['status-code']);

        $list = $this->listWebhooks([
            Query::equal('name', ['UniqueFilterName-XYZ'])->toString(),
        ], true);

        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertEquals(1, $list['body']['total']);
        $this->assertCount(1, $list['body']['webhooks']);
        $this->assertEquals('UniqueFilterName-XYZ', $list['body']['webhooks'][0]['name']);

        // Cleanup
        $this->deleteWebhook($webhook['body']['$id']);
    }

    public function testListWebhooksFilterByEnabled(): void
    {
        $webhookEnabled = $this->createWebhook(
            ID::unique(),
            'Enabled Filter Webhook',
            ['users.*.create'],
            true,
            'https://appwrite.io/enabled',
            null,
            null,
            null
        );
        $this->assertEquals(201, $webhookEnabled['headers']['status-code']);

        $webhookDisabled = $this->createWebhook(
            ID::unique(),
            'Disabled Filter Webhook',
            ['users.*.create'],
            false,
            'https://appwrite.io/disabled',
            null,
            null,
            null
        );
        $this->assertEquals(201, $webhookDisabled['headers']['status-code']);

        // Filter by enabled=true
        $listEnabled = $this->listWebhooks([
            Query::equal('enabled', [true])->toString(),
        ], true);

        $this->assertEquals(200, $listEnabled['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $listEnabled['body']['total']);
        foreach ($listEnabled['body']['webhooks'] as $webhook) {
            $this->assertEquals(true, $webhook['enabled']);
        }

        // Filter by enabled=false
        $listDisabled = $this->listWebhooks([
            Query::equal('enabled', [false])->toString(),
        ], true);

        $this->assertEquals(200, $listDisabled['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $listDisabled['body']['total']);
        foreach ($listDisabled['body']['webhooks'] as $webhook) {
            $this->assertEquals(false, $webhook['enabled']);
        }

        // Cleanup
        $this->deleteWebhook($webhookEnabled['body']['$id']);
        $this->deleteWebhook($webhookDisabled['body']['$id']);
    }

    public function testListWebhooksFilterByUrl(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'URL Filter Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io/unique-url-filter',
            null,
            null,
            null
        );
        $this->assertEquals(201, $webhook['headers']['status-code']);

        $list = $this->listWebhooks([
            Query::equal('url', ['https://appwrite.io/unique-url-filter'])->toString(),
        ], true);

        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertEquals(1, $list['body']['total']);
        $this->assertCount(1, $list['body']['webhooks']);
        $this->assertEquals('https://appwrite.io/unique-url-filter', $list['body']['webhooks'][0]['url']);

        // Cleanup
        $this->deleteWebhook($webhook['body']['$id']);
    }

    public function testListWebhooksFilterBySecurity(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Security Filter Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io/sec',
            true,
            null,
            null
        );
        $this->assertEquals(201, $webhook['headers']['status-code']);

        $list = $this->listWebhooks([
            Query::equal('security', [true])->toString(),
        ], true);

        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);
        foreach ($list['body']['webhooks'] as $w) {
            $this->assertEquals(true, $w['security']);
        }

        // Cleanup
        $this->deleteWebhook($webhook['body']['$id']);
    }

    public function testListWebhooksWithoutTotal(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'No Total Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io/nototal',
            null,
            null,
            null
        );
        $this->assertEquals(201, $webhook['headers']['status-code']);

        // List with total=false
        $list = $this->listWebhooks(null, false);

        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertEquals(0, $list['body']['total']);
        $this->assertGreaterThanOrEqual(1, \count($list['body']['webhooks']));

        // Cleanup
        $this->deleteWebhook($webhook['body']['$id']);
    }

    public function testListWebhooksCursorPagination(): void
    {
        $webhook1 = $this->createWebhook(
            ID::unique(),
            'Cursor Webhook 1',
            ['users.*.create'],
            null,
            'https://appwrite.io/cursor1',
            null,
            null,
            null
        );
        $this->assertEquals(201, $webhook1['headers']['status-code']);

        $webhook2 = $this->createWebhook(
            ID::unique(),
            'Cursor Webhook 2',
            ['users.*.create'],
            null,
            'https://appwrite.io/cursor2',
            null,
            null,
            null
        );
        $this->assertEquals(201, $webhook2['headers']['status-code']);

        // Get first page with limit 1
        $page1 = $this->listWebhooks([
            Query::limit(1)->toString(),
        ], true);

        $this->assertEquals(200, $page1['headers']['status-code']);
        $this->assertCount(1, $page1['body']['webhooks']);
        $cursorId = $page1['body']['webhooks'][0]['$id'];

        // Get next page using cursor
        $page2 = $this->listWebhooks([
            Query::limit(1)->toString(),
            Query::cursorAfter(new Document(['$id' => $cursorId]))->toString(),
        ], true);

        $this->assertEquals(200, $page2['headers']['status-code']);
        $this->assertCount(1, $page2['body']['webhooks']);
        $this->assertNotEquals($cursorId, $page2['body']['webhooks'][0]['$id']);

        // Cleanup
        $this->deleteWebhook($webhook1['body']['$id']);
        $this->deleteWebhook($webhook2['body']['$id']);
    }

    public function testListWebhooksWithoutAuthentication(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/webhooks', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testListWebhooksInvalidCursor(): void
    {
        $list = $this->listWebhooks([
            Query::cursorAfter(new Document(['$id' => 'non-existent-id']))->toString(),
        ], true);

        $this->assertEquals(400, $list['headers']['status-code']);
    }

    // Delete webhook tests

    public function testDeleteWebhook(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Delete Test Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        // Verify it exists
        $get = $this->getWebhook($webhookId);
        $this->assertEquals(200, $get['headers']['status-code']);

        // Delete
        $delete = $this->deleteWebhook($webhookId);
        $this->assertEquals(204, $delete['headers']['status-code']);
        $this->assertEmpty($delete['body']);

        // Verify it no longer exists
        $get = $this->getWebhook($webhookId);
        $this->assertEquals(404, $get['headers']['status-code']);
        $this->assertEquals('webhook_not_found', $get['body']['type']);
    }

    public function testDeleteWebhookNotFound(): void
    {
        $delete = $this->deleteWebhook('non-existent-id');

        $this->assertEquals(404, $delete['headers']['status-code']);
        $this->assertEquals('webhook_not_found', $delete['body']['type']);
    }

    public function testDeleteWebhookWithoutAuthentication(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Delete Auth Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        // Attempt DELETE without authentication
        $response = $this->client->call(Client::METHOD_DELETE, '/webhooks/' . $webhookId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // Verify it still exists
        $get = $this->getWebhook($webhookId);
        $this->assertEquals(200, $get['headers']['status-code']);

        // Cleanup
        $this->deleteWebhook($webhookId);
    }

    public function testDeleteWebhookRemovedFromList(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Delete List Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        // Get list count before delete
        $listBefore = $this->listWebhooks(null, true);
        $this->assertEquals(200, $listBefore['headers']['status-code']);
        $countBefore = $listBefore['body']['total'];

        // Delete
        $delete = $this->deleteWebhook($webhookId);
        $this->assertEquals(204, $delete['headers']['status-code']);

        // Get list count after delete
        $listAfter = $this->listWebhooks(null, true);
        $this->assertEquals(200, $listAfter['headers']['status-code']);
        $this->assertEquals($countBefore - 1, $listAfter['body']['total']);

        // Verify the deleted webhook is not in the list
        $ids = \array_column($listAfter['body']['webhooks'], '$id');
        $this->assertNotContains($webhookId, $ids);
    }

    public function testDeleteWebhookDoubleDelete(): void
    {
        $webhook = $this->createWebhook(
            ID::unique(),
            'Double Delete Webhook',
            ['users.*.create'],
            null,
            'https://appwrite.io',
            null,
            null,
            null
        );

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        // First delete succeeds
        $delete = $this->deleteWebhook($webhookId);
        $this->assertEquals(204, $delete['headers']['status-code']);

        // Second delete returns 404
        $delete = $this->deleteWebhook($webhookId);
        $this->assertEquals(404, $delete['headers']['status-code']);
        $this->assertEquals('webhook_not_found', $delete['body']['type']);
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
            'queries' => $queries,
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
        $params = [
            'webhookId' => $webhookId,
            'name' => $name,
            'events' => $events,
            'url' => $url,
        ];

        if ($enabled !== null) {
            $params['enabled'] = $enabled;
        }
        if ($security !== null) {
            $params['security'] = $security;
        }
        if ($httpUser !== null) {
            $params['httpUser'] = $httpUser;
        }
        if ($httpPass !== null) {
            $params['httpPass'] = $httpPass;
        }

        $webhook = $this->client->call(Client::METHOD_POST, '/webhooks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $webhook;
    }

    protected function updateWebhook(string $webhookId, string $name, array $events, ?bool $enabled, ?string $url, ?bool $security, ?string $httpUser, ?string $httpPass): mixed
    {
        $params = [
            'name' => $name,
            'events' => $events,
            'url' => $url,
        ];

        if ($enabled !== null) {
            $params['enabled'] = $enabled;
        }
        if ($security !== null) {
            $params['security'] = $security;
        }
        if ($httpUser !== null) {
            $params['httpUser'] = $httpUser;
        }
        if ($httpPass !== null) {
            $params['httpPass'] = $httpPass;
        }

        $webhook = $this->client->call(Client::METHOD_PUT, '/webhooks/' . $webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

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

    protected function deleteWebhook(string $webhookId): mixed
    {
        $webhook = $this->client->call(Client::METHOD_DELETE, '/webhooks/' . $webhookId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $webhook;
    }
}
