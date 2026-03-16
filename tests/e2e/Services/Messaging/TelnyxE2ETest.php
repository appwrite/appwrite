<?php

namespace Tests\E2E\Services\Messaging;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Helpers\ID;

class TelnyxE2ETest extends ProjectCustom
{
    use MessagingBase;
    use SideConsole;

    public function testCreateTelnyxProvider(): array
    {
        /**
         * Create Telnyx provider
         */
        $response = $this->client->call(Client::METHOD_POST, '/messaging/providers/telnyx', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-sdk-name' => 'Console',
            'x-sdk-platform' => 'Console',
        ], [
            'providerId' => ID::unique(),
            'name' => 'Telnyx Provider',
            'apiKey' => 'test-api-key',
            'from' => '+1234567890',
            'enabled' => true,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('Telnyx Provider', $response['body']['name']);
        $this->assertEquals('telnyx', $response['body']['provider']);
        $this->assertEquals('sms', $response['body']['type']);
        $this->assertTrue($response['body']['enabled']);
        $this->assertEquals('test-api-key', $response['body']['credentials']['apiKey']);
        $this->assertEquals('+1234567890', $response['body']['credentials']['from']);

        return [
            'providerId' => $response['body']['$id'],
        ];
    }

    /**
     * @depends testCreateTelnyxProvider
     */
    public function testUpdateTelnyxProvider(array $data): void
    {
        $providerId = $data['providerId'];

        /**
         * Update Telnyx provider
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/providers/telnyx/' . $providerId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-sdk-name' => 'Console',
            'x-sdk-platform' => 'Console',
        ], [
            'name' => 'Updated Telnyx Provider',
            'apiKey' => 'updated-api-key',
            'from' => '+0987654321',
            'enabled' => false,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Updated Telnyx Provider', $response['body']['name']);
        $this->assertEquals('updated-api-key', $response['body']['credentials']['apiKey']);
        $this->assertEquals('+0987654321', $response['body']['credentials']['from']);
        $this->assertFalse($response['body']['enabled']);
    }
}
