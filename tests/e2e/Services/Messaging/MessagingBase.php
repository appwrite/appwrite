<?php

namespace Tests\E2E\Services\Messaging;

use Tests\E2E\Client;

trait MessagingBase
{
    public function testCreateProviders(): array
    {
        $providersParams = [
            'sendgrid' => [
                'providerId' => 'unique()',
                'name' => 'Sengrid1',
                'apiKey' => 'my-apikey',
            ],
            'mailgun' => [
                'providerId' => 'unique()',
                'name' => 'Mailgun1',
                'apiKey' => 'my-apikey',
                'domain' => 'my-domain',
            ],
            'twilio' => [
                'providerId' => 'unique()',
                'name' => 'Twilio1',
                'accountSid' => 'my-accountSid',
                'authToken' => 'my-authToken',
            ],
            'telesign' => [
                'providerId' => 'unique()',
                'name' => 'Telesign1',
                'username' => 'my-username',
                'password' => 'my-password',
            ],
            'textmagic' => [
                'providerId' => 'unique()',
                'name' => 'Textmagic1',
                'username' => 'my-username',
                'apiKey' => 'my-apikey',
            ],
            'msg91' => [
                'providerId' => 'unique()',
                'name' => 'Ms91-1',
                'senderId' => 'my-senderid',
                'authKey' => 'my-authkey',
            ],
            'vonage' => [
                'providerId' => 'unique()',
                'name' => 'Vonage1',
                'apiKey' => 'my-apikey',
                'apiSecret' => 'my-apisecret',
            ],
            'fcm' => [
                'providerId' => 'unique()',
                'name' => 'FCM1',
                'serverKey' => 'my-serverkey',
            ],
            'apns' => [
                'providerId' => 'unique()',
                'name' => 'APNS1',
                'authKey' => 'my-authkey',
                'authKeyId' => 'my-authkeyid',
                'teamId' => 'my-teamid',
                'bundleId' => 'my-bundleid',
                'endpoint' => 'my-endpoint',
            ],
        ];
        $providers = [];

        foreach (\array_keys($providersParams) as $key) {
            $response = $this->client->call(Client::METHOD_POST, '/messaging/providers/' . $key, \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]), $providersParams[$key]);
            \array_push($providers, $response['body']);
            $this->assertEquals(201, $response['headers']['status-code']);
            $this->assertEquals($providersParams[$key]['name'], $response['body']['name']);
        }

        return $providers;
    }

    /**
     * @depends testCreateProviders
     */
    public function testUpdateProviders(array $providers): array
    {
        $providersParams = [
            'sendgrid' => [
                'name' => 'Sengrid2',
                'apiKey' => 'my-apikey',
            ],
            'mailgun' => [
                'name' => 'Mailgun2',
                'apiKey' => 'my-apikey',
                'domain' => 'my-domain',
            ],
            'twilio' => [
                'name' => 'Twilio2',
                'accountSid' => 'my-accountSid',
                'authToken' => 'my-authToken',
            ],
            'telesign' => [
                'name' => 'Telesign2',
                'username' => 'my-username',
                'password' => 'my-password',
            ],
            'textmagic' => [
                'name' => 'Textmagic2',
                'username' => 'my-username',
                'apiKey' => 'my-apikey',
            ],
            'msg91' => [
                'name' => 'Ms91-2',
                'senderId' => 'my-senderid',
                'authKey' => 'my-authkey',
            ],
            'vonage' => [
                'name' => 'Vonage2',
                'apiKey' => 'my-apikey',
                'apiSecret' => 'my-apisecret',
            ],
            'fcm' => [
                'name' => 'FCM2',
                'serverKey' => 'my-serverkey',
            ],
            'apns' => [
                'name' => 'APNS2',
                'authKey' => 'my-authkey',
                'authKeyId' => 'my-authkeyid',
                'teamId' => 'my-teamid',
                'bundleId' => 'my-bundleid',
                'endpoint' => 'my-endpoint',
            ],
        ];
        foreach (\array_keys($providersParams) as $index => $key) {
            $response = $this->client->call(Client::METHOD_PATCH, '/messaging/providers/' . $providers[$index]['$id'] . '/' . $key, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], $providersParams[$key]);
            $providers[$index] = $response['body'];
            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals($providersParams[$key]['name'], $response['body']['name']);
        }

        return $providers;
    }

    /**
     * @depends testUpdateProviders
     */
    public function testListProviders(array $providers)
    {
        $response = $this->client->call(Client::METHOD_GET, '/messaging/providers/', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(\count($providers), \count($response['body']['providers']));
    }

    /**
     * @depends testUpdateProviders
     */
    public function testGetProvider(array $providers)
    {
        $response = $this->client->call(Client::METHOD_GET, '/messaging/providers/' . $providers[0]['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($providers[0]['name'], $response['body']['name']);
    }

    /**
     * @depends testUpdateProviders
     */
    public function testDeleteProvider(array $providers)
    {
        foreach ($providers as $provider) {
            $response = $this->client->call(Client::METHOD_DELETE, '/messaging/providers/' . $provider['$id'], [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals(204, $response['headers']['status-code']);
        }
    }

    public function testCreateTopic(): string
    {
        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/sendgrid', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'providerId' => 'unique()',
            'name' => 'Sengrid1',
            'apiKey' => 'my-apikey',
        ]);
        $this->assertEquals(201, $provider['headers']['status-code']);
        $response = $this->client->call(Client::METHOD_POST, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'providerId' => $provider['body']['$id'],
            'topicId' => 'unique()',
            'name' => 'my-app',
            'description' => 'web app'
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('my-app', $response['body']['name']);

        return $response['body']['$id'];
    }

    /**
     * @depends testCreateTopic
     */
    public function testUpdateTopic(string $topicId): string
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/topics/' . $topicId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'name' => 'android-app',
            'description' => 'updated-description'
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('android-app', $response['body']['name']);
        $this->assertEquals('updated-description', $response['body']['description']);
        return $topicId;
    }

    public function testListTopic()
    {
        $response = $this->client->call(Client::METHOD_GET, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, \count($response['body']['topics']));
    }

    /**
     * @depends testUpdateTopic
     */
    public function testGetTopic(string $topicId)
    {
        $response = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $topicId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('android-app', $response['body']['name']);
        $this->assertEquals('updated-description', $response['body']['description']);
    }

    /**
     * @depends testUpdateTopic
     */
    public function testDeleteTopic(string $topicId)
    {
        $response = $this->client->call(Client::METHOD_DELETE, '/messaging/topics/' . $topicId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(204, $response['headers']['status-code']);
    }
}
