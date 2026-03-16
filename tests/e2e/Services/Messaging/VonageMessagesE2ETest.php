<?php

namespace Tests\E2E\Services\Messaging;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Helpers\ID;

class VonageMessagesE2ETest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    public function testCreateVonageMessagesProvider(): array
    {
        $providerId = ID::unique();
        $response = $this->client->call(Client::METHOD_POST, '/messaging/providers/vonage-messages', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'providerId' => $providerId,
            'name' => 'Vonage Messages',
            'applicationId' => 'test-app-id',
            'privateKey' => 'test-private-key',
            'from' => '+1234567890',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('Vonage Messages', $response['body']['name']);
        $this->assertEquals('sms', $response['body']['type']); // Default type for Vonage Messages if not specified or just how it's stored
        
        return $response['body'];
    }

    /**
     * @depends testCreateVonageMessagesProvider
     */
    public function testUpdateVonageMessagesProvider(array $provider): array
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/providers/vonage-messages/' . $provider['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'name' => 'Vonage Messages Updated',
            'applicationId' => 'updated-app-id',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Vonage Messages Updated', $response['body']['name']);
        
        return $response['body'];
    }

    /**
     * @depends testUpdateVonageMessagesProvider
     */
    public function testCreateWhatsAppProvider(): void
    {
        $providerId = ID::unique();
        $response = $this->client->call(Client::METHOD_POST, '/messaging/providers/vonage-messages', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'providerId' => $providerId,
            'name' => 'Vonage WhatsApp',
            'type' => 'whatsapp',
            'applicationId' => 'test-app-id',
            'privateKey' => 'test-private-key',
            'from' => '+1234567890',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('whatsapp', $response['body']['type']);
    }

    /**
     * @depends testCreateVonageMessagesProvider
     */
    public function testCreateViberProvider(): void
    {
        $providerId = ID::unique();
        $response = $this->client->call(Client::METHOD_POST, '/messaging/providers/vonage-messages', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'providerId' => $providerId,
            'name' => 'Vonage Viber',
            'type' => 'viber',
            'applicationId' => 'test-app-id',
            'privateKey' => 'test-private-key',
            'from' => '+1234567890',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('viber', $response['body']['type']);
    }

    /**
     * @depends testCreateVonageMessagesProvider
     */
    public function testCreateMmsProvider(): void
    {
        $providerId = ID::unique();
        $response = $this->client->call(Client::METHOD_POST, '/messaging/providers/vonage-messages', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'providerId' => $providerId,
            'name' => 'Vonage MMS',
            'type' => 'mms',
            'applicationId' => 'test-app-id',
            'privateKey' => 'test-private-key',
            'from' => '+1234567890',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('mms', $response['body']['type']);
    }
}
