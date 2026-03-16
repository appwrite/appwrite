<?php

namespace Tests\E2E\Services\Messaging;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Helpers\ID;

class AlibabaCloudE2ETest extends ProjectCustom
{
    use MessagingBase;
    use SideConsole;

    public function testCreateAlibabaCloudProvider(): array
    {
        /**
         * Create Alibaba Cloud provider
         */
        $response = $this->client->call(Client::METHOD_POST, '/messaging/providers/alibaba-cloud', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-sdk-name' => 'Console',
            'x-sdk-platform' => 'Console',
        ], [
            'providerId' => ID::unique(),
            'name' => 'Alibaba Cloud Provider',
            'accessKeyId' => 'test-access-key-id',
            'accessKeySecret' => 'test-access-key-secret',
            'signName' => 'test-sign-name',
            'templateCode' => 'test-template-code',
            'enabled' => true,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('Alibaba Cloud Provider', $response['body']['name']);
        $this->assertEquals('alibaba-cloud', $response['body']['provider']);
        $this->assertEquals('sms', $response['body']['type']);
        $this->assertTrue($response['body']['enabled']);
        $this->assertEquals('test-access-key-id', $response['body']['credentials']['accessKeyId']);
        $this->assertEquals('test-access-key-secret', $response['body']['credentials']['accessKeySecret']);
        $this->assertEquals('test-sign-name', $response['body']['credentials']['signName']);
        $this->assertEquals('test-template-code', $response['body']['credentials']['templateCode']);

        return [
            'providerId' => $response['body']['$id'],
        ];
    }

    /**
     * @depends testCreateAlibabaCloudProvider
     */
    public function testUpdateAlibabaCloudProvider(array $data): void
    {
        $providerId = $data['providerId'];

        /**
         * Update Alibaba Cloud provider
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/providers/alibaba-cloud/' . $providerId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-sdk-name' => 'Console',
            'x-sdk-platform' => 'Console',
        ], [
            'name' => 'Updated Alibaba Cloud Provider',
            'accessKeyId' => 'updated-access-key-id',
            'accessKeySecret' => 'updated-access-key-secret',
            'signName' => 'updated-sign-name',
            'templateCode' => 'updated-template-code',
            'enabled' => false,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Updated Alibaba Cloud Provider', $response['body']['name']);
        $this->assertEquals('updated-access-key-id', $response['body']['credentials']['accessKeyId']);
        $this->assertEquals('updated-access-key-secret', $response['body']['credentials']['accessKeySecret']);
        $this->assertEquals('updated-sign-name', $response['body']['credentials']['signName']);
        $this->assertEquals('updated-template-code', $response['body']['credentials']['templateCode']);
        $this->assertFalse($response['body']['enabled']);
    }
}
