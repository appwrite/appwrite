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
                'from' => 'sender-email@my-domain',
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
                'from' => '+123456789'
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

        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/providers/' . $providers[1]['$id'] . '/mailgun', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
          'name' => 'Mailgun2',
          'apiKey' => 'my-apikey',
          'domain' => 'my-domain',
          'isEuRegion' => true,
          'enabled' => false,
        ]);
        $providers[1] = $response['body'];
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Mailgun2', $response['body']['name']);
        $this->assertEquals(false, $response['body']['enabled']);
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
}
