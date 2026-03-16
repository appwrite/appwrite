<?php

namespace Tests\E2E\Services\Messaging;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Helpers\ID;

class OneSignalE2ETest extends ProjectCustom
{
    use MessagingBase;
    use SideConsole;

    public function testCreateOneSignalProvider(): array
    {
        /**
         * Create OneSignal provider
         */
        $response = $this->client->call(Client::METHOD_POST, '/messaging/providers/onesignal', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-sdk-name' => 'Console',
            'x-sdk-platform' => 'Console',
        ], [
            'providerId' => ID::unique(),
            'name' => 'OneSignal Provider',
            'appId' => 'test-app-id',
            'apiKey' => 'test-api-key',
            'enabled' => true,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('OneSignal Provider', $response['body']['name']);
        $this->assertEquals('onesignal', $response['body']['provider']);
        $this->assertEquals('push', $response['body']['type']);
        $this->assertTrue($response['body']['enabled']);
        $this->assertEquals('test-app-id', $response['body']['credentials']['appId']);
        $this->assertEquals('test-api-key', $response['body']['credentials']['apiKey']);

        return [
            'providerId' => $response['body']['$id'],
        ];
    }

    /**
     * @depends testCreateOneSignalProvider
     */
    public function testUpdateOneSignalProvider(array $data): void
    {
        $providerId = $data['providerId'];

        /**
         * Update OneSignal provider
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/providers/onesignal/' . $providerId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-sdk-name' => 'Console',
            'x-sdk-platform' => 'Console',
        ], [
            'name' => 'Updated OneSignal Provider',
            'appId' => 'updated-app-id',
            'apiKey' => 'updated-api-key',
            'enabled' => false,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Updated OneSignal Provider', $response['body']['name']);
        $this->assertEquals('updated-app-id', $response['body']['credentials']['appId']);
        $this->assertEquals('updated-api-key', $response['body']['credentials']['apiKey']);
        $this->assertFalse($response['body']['enabled']);
    }
}
