<?php

namespace Tests\E2E\Services\Messaging;

use Tests\E2E\Client;
use Utopia\App;
use Utopia\Database\Helpers\ID;
use Utopia\DSN\DSN;

trait MessagingBase
{
    public function testCreateProviders(): array
    {
        $providersParams = [
            'sendgrid' => [
                'providerId' => ID::unique(),
                'name' => 'Sengrid1',
                'apiKey' => 'my-apikey',
                'from' => 'sender-email@my-domain.com',
            ],
            'mailgun' => [
                'providerId' => ID::unique(),
                'name' => 'Mailgun1',
                'apiKey' => 'my-apikey',
                'domain' => 'my-domain',
                'fromName' => 'sender name',
                'fromEmail' => 'sender-email@my-domain.com',
                'isEuRegion' => false,
            ],
            'twilio' => [
                'providerId' => ID::unique(),
                'name' => 'Twilio1',
                'accountSid' => 'my-accountSid',
                'authToken' => 'my-authToken',
                'from' => '+123456789',
            ],
            'telesign' => [
                'providerId' => ID::unique(),
                'name' => 'Telesign1',
                'username' => 'my-username',
                'password' => 'my-password',
                'from' => '+123456789',
            ],
            'textmagic' => [
                'providerId' => ID::unique(),
                'name' => 'Textmagic1',
                'username' => 'my-username',
                'apiKey' => 'my-apikey',
                'from' => '+123456789',
            ],
            'msg91' => [
                'providerId' => ID::unique(),
                'name' => 'Ms91-1',
                'senderId' => 'my-senderid',
                'authKey' => 'my-authkey',
                'from' => '+123456789'
            ],
            'vonage' => [
                'providerId' => ID::unique(),
                'name' => 'Vonage1',
                'apiKey' => 'my-apikey',
                'apiSecret' => 'my-apisecret',
                'from' => '+123456789',
            ],
            'fcm' => [
                'providerId' => ID::unique(),
                'name' => 'FCM1',
                'serviceAccountJSON' => [
                    'type' => 'service_account',
                    "project_id" => "omegle-copy",
                    "private_key_id" => "ewfwefwefwefwef",
                    "private_key" => "-----BEGIN PRIVATE KEY-----\nMIIEvAIBADANBgkqhkAgEAAoIBAQCeKDbvv4XvGuNAOxZBcxoNnvbINKlq0FtiqgsqLAgDOMt\nGPEANfni+D7lZRrMPhZhcL4YCjAUg+0ZI0D9d2LGofasj9GlBb57SZc/ud2L9FZZ\nk5liXrUk0SUirffBUmj5F/XPTJ+JXc89qPtt15+hqx30h2ID/wxN0AhmViLikR3o\n3YBHAYq0NbmAfQSfdsHX+lvNKvsAxU+LatRPE3tVcvSd3ZnP0zlHYVmp6UCeBWeW\nOTcSPecCcVXmBdMPtHGWdrNG2op1CmHc8JeYJMQ4xgz3obQcOX9+3USsysANjgta\nb07m6xS3AgMBAAECggEAIeSTVVCRZrq36zk8VgJ/r/NE4r95xEk2K/K/Lvb0fx75\no0BO5gsAkYqvgzem/LrVFCEFRkDGMbAhVQ5Fw1pN2U6CyA0hL4jUqgALtMImKJdX\nDa6I5Gibwd5+qt9NOZSgC/Kq14zAxhfQE3U2hyatohyx3Rsz/3lmJo90bX7Jp5md\nGBDOB3pFBqyfUvyHgeqCgvJvidJjxmwArLhUF8szuDRvmSs0lGsfqYprK0sb9phL\nP7Z3qMJk1J4IDL2abSGrTcMP+hk7ju1iqo7WfhIQCvM1TD5dRjYg2IYPIAIzszWz\nxSA67eJpQGSFfOuk82g3UMhfCD2DY2mCE/zkeid9jQKBgQDSB2xA+LpQDX2nuoDR\niZbPYBitxQtkbjieYTR8vwrIzyAvRtOwjnVKsXLyIbUYyHd6RFRDPeBcHb39KuRO\nz7VljQKTVB5RYUmqeGilor0TFaKMnneC7GFH6mWOJyf16DU7bkQw27Pg1e3xbF28\n5ig7QYPqEaDKLg6TMSLsBhdRDQKBgQDAxj9jS9UOTmF3N9T1JFzWfUB2r+AgwE4N\nSITmG/fSz9rlSg+XPh2ijpSrboUbuY/GYq5aCIy1twx09eta07Y/uD/GKLYrk873\no0TxQrnHSKl82fCyd2JPG/W8ocGDnj3u0Dp+tBrLxDiZN2pRurnlkt7P3QUg/gEG\nAovyd3ij0wKBgBbA7x1q1ORvUbmmHuaUfV4iDwpkWoOa3U9rQIBzQfvXVKlKhwyN\nom9hIg7RUAlLToZUeLyAK5pPLpIK34kaP5Cs4iaL6mzumUh6mvu20b0Ljvyk/lWU\nvkVIQ5BO9alSatHxdDnG04n8IzcQgmdAmAMzadMl7cF5k+KmZB4l2sjRAoGAP8JS\nPNlcAntSKUhCG0KHojmTFK5fBvYT2rjdm+4sLYGp+KRiO7fDvXxDF+BaDi11rDv/\nRrAFOiTs7dJYoZXcdX7POQ9GEWu1zJont1RGde9Gf5Dl12E9FsU8pcMqagnwmggt\nELMpGbQwtBxsAdQsoA3PvBhyFdNtKzu0ZeG1+RkCgYBOPhOCR88QPTmQANkwIMH+\n0vt+KhSjE3dhX7rzVkhoNmYF5AaSSpQ3F1JUlYntjblMQVjLesGvWa4gwCOF87xC\nJxHL6LkbNjAyUGZp7to6/F4vTmKoC6Xu/jTRRy2SVjdqiIa0Pm0eLLfRHmSI06pS\n+zLmdpZv/msPfGibbHcXUA==\n-----END PRIVATE KEY-----\n",
                ],
            ],
            'apns' => [
                'providerId' => ID::unique(),
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

            $this->assertEquals(201, $response['headers']['status-code']);
            $this->assertEquals($providersParams[$key]['name'], $response['body']['name']);
            \array_push($providers, $response['body']);
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
                'serviceAccountJSON' => [
                    'type' => 'service_account',
                    "project_id" => "omegle-copy",
                    "private_key_id" => "ewfwefwefwefwef",
                    "private_key" => "-----BEGIN PRIVATE KEY-----\nMIIEvAIBADANBgkqhkAgEAAoIBAQCeKDbvv4XvGuNAOxZBcxoNnvbINKlq0FtiqgsqLAgDOMt\nGPEANfni+D7lZRrMPhZhcL4YCjAUg+0ZI0D9d2LGofasj9GlBb57SZc/ud2L9FZZ\nk5liXrUk0SUirffBUmj5F/XPTJ+JXc89qPtt15+hqx30h2ID/wxN0AhmViLikR3o\n3YBHAYq0NbmAfQSfdsHX+lvNKvsAxU+LatRPE3tVcvSd3ZnP0zlHYVmp6UCeBWeW\nOTcSPecCcVXmBdMPtHGWdrNG2op1CmHc8JeYJMQ4xgz3obQcOX9+3USsysANjgta\nb07m6xS3AgMBAAECggEAIeSTVVCRZrq36zk8VgJ/r/NE4r95xEk2K/K/Lvb0fx75\no0BO5gsAkYqvgzem/LrVFCEFRkDGMbAhVQ5Fw1pN2U6CyA0hL4jUqgALtMImKJdX\nDa6I5Gibwd5+qt9NOZSgC/Kq14zAxhfQE3U2hyatohyx3Rsz/3lmJo90bX7Jp5md\nGBDOB3pFBqyfUvyHgeqCgvJvidJjxmwArLhUF8szuDRvmSs0lGsfqYprK0sb9phL\nP7Z3qMJk1J4IDL2abSGrTcMP+hk7ju1iqo7WfhIQCvM1TD5dRjYg2IYPIAIzszWz\nxSA67eJpQGSFfOuk82g3UMhfCD2DY2mCE/zkeid9jQKBgQDSB2xA+LpQDX2nuoDR\niZbPYBitxQtkbjieYTR8vwrIzyAvRtOwjnVKsXLyIbUYyHd6RFRDPeBcHb39KuRO\nz7VljQKTVB5RYUmqeGilor0TFaKMnneC7GFH6mWOJyf16DU7bkQw27Pg1e3xbF28\n5ig7QYPqEaDKLg6TMSLsBhdRDQKBgQDAxj9jS9UOTmF3N9T1JFzWfUB2r+AgwE4N\nSITmG/fSz9rlSg+XPh2ijpSrboUbuY/GYq5aCIy1twx09eta07Y/uD/GKLYrk873\no0TxQrnHSKl82fCyd2JPG/W8ocGDnj3u0Dp+tBrLxDiZN2pRurnlkt7P3QUg/gEG\nAovyd3ij0wKBgBbA7x1q1ORvUbmmHuaUfV4iDwpkWoOa3U9rQIBzQfvXVKlKhwyN\nom9hIg7RUAlLToZUeLyAK5pPLpIK34kaP5Cs4iaL6mzumUh6mvu20b0Ljvyk/lWU\nvkVIQ5BO9alSatHxdDnG04n8IzcQgmdAmAMzadMl7cF5k+KmZB4l2sjRAoGAP8JS\nPNlcAntSKUhCG0KHojmTFK5fBvYT2rjdm+4sLYGp+KRiO7fDvXxDF+BaDi11rDv/\nRrAFOiTs7dJYoZXcdX7POQ9GEWu1zJont1RGde9Gf5Dl12E9FsU8pcMqagnwmggt\nELMpGbQwtBxsAdQsoA3PvBhyFdNtKzu0ZeG1+RkCgYBOPhOCR88QPTmQANkwIMH+\n0vt+KhSjE3dhX7rzVkhoNmYF5AaSSpQ3F1JUlYntjblMQVjLesGvWa4gwCOF87xC\nJxHL6LkbNjAyUGZp7to6/F4vTmKoC6Xu/jTRRy2SVjdqiIa0Pm0eLLfRHmSI06pS\n+zLmdpZv/msPfGibbHcXUA==\n-----END PRIVATE KEY-----\n",
                ]
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
            $response = $this->client->call(Client::METHOD_PATCH, '/messaging/providers/' . $key . '/' . $providers[$index]['$id'], [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], $providersParams[$key]);
            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals($providersParams[$key]['name'], $response['body']['name']);
            $providers[$index] = $response['body'];
        }

        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/providers/mailgun/' . $providers[1]['$id'], [
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
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Mailgun2', $response['body']['name']);
        $this->assertEquals(false, $response['body']['enabled']);
        $providers[1] = $response['body'];
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

        return $providers;
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

    public function testCreateTopic(): array
    {
        $response = $this->client->call(Client::METHOD_POST, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'topicId' => ID::unique(),
            'name' => 'my-app',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('my-app', $response['body']['name']);

        return $response['body'];
    }

    /**
     * @depends testCreateTopic
     */
    public function testUpdateTopic(array $topic): string
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/topics/' . $topic['$id'], [
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
        return $response['body']['$id'];
    }

    /**
     * @depends testUpdateTopic
     */
    public function testListTopic(string $topicId)
    {
        $response = $this->client->call(Client::METHOD_GET, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'search' => 'updated-description',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, \count($response['body']['topics']));

        return $topicId;
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
        $this->assertEquals(0, $response['body']['total']);
    }

    /**
     * @depends testCreateTopic
     */
    public function testCreateSubscriber(array $topic)
    {
        $userId = $this->getUser()['$id'];

        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/sendgrid', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'providerId' => ID::unique(),
            'name' => 'Sendgrid1',
            'apiKey' => 'my-apikey',
            'from' => 'sender-email@my-domain.com',
        ]);

        $this->assertEquals(201, $provider['headers']['status-code']);

        $target = $this->client->call(Client::METHOD_POST, '/users/' . $userId . '/targets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'targetId' => ID::unique(),
            'providerType' => 'email',
            'providerId' => $provider['body']['$id'],
            'identifier' => 'random-email@mail.org',
        ]);

        $this->assertEquals(201, $target['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/messaging/topics/' . $topic['$id'] . '/subscribers', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'subscriberId' => ID::unique(),
            'targetId' => $target['body']['$id'],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals($target['body']['userId'], $response['body']['target']['userId']);
        $this->assertEquals($target['body']['providerType'], $response['body']['target']['providerType']);

        $topic = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $topic['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $topic['headers']['status-code']);
        $this->assertEquals('android-app', $topic['body']['name']);
        $this->assertEquals('updated-description', $topic['body']['description']);
        $this->assertEquals(1, $topic['body']['total']);

        return [
            'topicId' => $topic['body']['$id'],
            'targetId' => $target['body']['$id'],
            'userId' => $target['body']['userId'],
            'subscriberId' => $response['body']['$id'],
            'identifier' => $target['body']['identifier'],
            'providerType' => $target['body']['providerType'],
        ];
    }

    /**
     * @depends testCreateSubscriber
     */
    public function testGetSubscriber(array $data)
    {
        $response = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $data['topicId'] . '/subscribers/' . $data['subscriberId'], \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($data['topicId'], $response['body']['topicId']);
        $this->assertEquals($data['targetId'], $response['body']['targetId']);
        $this->assertEquals($data['userId'], $response['body']['target']['userId']);
        $this->assertEquals($data['providerType'], $response['body']['target']['providerType']);
        $this->assertEquals($data['identifier'], $response['body']['target']['identifier']);
    }

    /**
     * @depends testCreateSubscriber
     */
    public function testListSubscribers(array $data)
    {
        $response = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $data['topicId'] . '/subscribers', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);
        $this->assertEquals($data['userId'], $response['body']['subscribers'][0]['target']['userId']);
        $this->assertEquals($data['providerType'], $response['body']['subscribers'][0]['target']['providerType']);
        $this->assertEquals($data['identifier'], $response['body']['subscribers'][0]['target']['identifier']);
        $this->assertEquals(\count($response['body']['subscribers']), $response['body']['total']);

        return $data;
    }

    /**
     * @depends testListSubscribers
     */
    public function testGetSubscriberLogs(array $data): void
    {
        /**
         * Test for SUCCESS
         */
        $logs = $this->client->call(Client::METHOD_GET, '/messaging/subscribers/' . $data['subscriberId'] . '/logs', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/messaging/subscribers/' . $data['subscriberId'] . '/logs', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'queries' => ['limit(1)'],
        ]);

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertLessThanOrEqual(1, count($logs['body']['logs']));
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/messaging/subscribers/' . $data['subscriberId'] . '/logs', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'queries' => ['offset(1)'],
        ]);

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/messaging/subscribers/' . $data['subscriberId'] . '/logs', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'queries' => ['limit(1)', 'offset(1)'],
        ]);

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertLessThanOrEqual(1, count($logs['body']['logs']));
        $this->assertIsNumeric($logs['body']['total']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/messaging/subscribers/' . $data['subscriberId'] . '/logs', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'queries' => ['limit(-1)']
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/subscribers/' . $data['subscriberId'] . '/logs', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'queries' => ['offset(-1)']
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/subscribers/' . $data['subscriberId'] . '/logs', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'queries' => ['equal("$id", "asdf")']
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/subscribers/' . $data['subscriberId'] . '/logs', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'queries' => ['orderAsc("$id")']
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/subscribers/' . $data['subscriberId'] . '/logs', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'queries' => ['cursorAsc("$id")']
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);
    }

    /**
     * @depends testCreateSubscriber
     */
    public function testDeleteSubscriber(array $data)
    {
        $response = $this->client->call(Client::METHOD_DELETE, '/messaging/topics/' . $data['topicId'] . '/subscribers/' . $data['subscriberId'], \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);

        $topic = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $data['topicId'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $topic['headers']['status-code']);
        $this->assertEquals('android-app', $topic['body']['name']);
        $this->assertEquals('updated-description', $topic['body']['description']);
        $this->assertEquals(0, $topic['body']['total']);
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

    public function testSendEmail()
    {
        if (empty(App::getEnv('_APP_MESSAGE_EMAIL_TEST_DSN'))) {
            $this->markTestSkipped('Email DSN not provided');
        }

        $emailDSN = new DSN(App::getEnv('_APP_MESSAGE_EMAIL_TEST_DSN'));
        $to = $emailDSN->getParam('to');
        $fromName = $emailDSN->getParam('fromName');
        $fromEmail = $emailDSN->getParam('fromEmail');
        $apiKey = $emailDSN->getPassword();

        if (empty($to) || empty($apiKey)) {
            $this->markTestSkipped('Email provider not configured');
        }

        // Create provider
        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/sendgrid', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'providerId' => ID::unique(),
            'name' => 'Mailgun-provider',
            'apiKey' => $apiKey,
            'fromName' => $fromName,
            'fromEmail' => $fromEmail
        ]);

        $this->assertEquals(201, $provider['headers']['status-code']);

        // Create Topic
        $topic = $this->client->call(Client::METHOD_POST, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'topicId' => ID::unique(),
            'name' => 'topic1',
            'description' => 'Test Topic'
        ]);

        $this->assertEquals(201, $topic['headers']['status-code']);

        // Create User
        $user = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => $to,
            'password' => 'password',
            'name' => 'Messaging User',
        ]);

        $this->assertEquals(201, $user['headers']['status-code']);

        // Create Subscriber
        $subscriber = $this->client->call(Client::METHOD_POST, '/messaging/topics/' . $topic['body']['$id'] . '/subscribers', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'subscriberId' => ID::unique(),
            'targetId' => $user['body']['targets'][0]['$id'],
        ]);

        $this->assertEquals(201, $subscriber['headers']['status-code']);

        // Create Email
        $email = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'topics' => [$topic['body']['$id']],
            'subject' => 'New blog post',
            'content' => 'Check out the new blog post at http://localhost',
        ]);

        $this->assertEquals(201, $email['headers']['status-code']);

        \sleep(2);

        $message = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $email['body']['$id'], [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        \var_dump($message);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(1, $message['body']['deliveredTotal']);
        $this->assertEquals(0, \count($message['body']['deliveryErrors']));

        return $message;
    }

    /**
     * @depends testSendEmail
     */
    public function testUpdateEmail(array $email): void
    {
        $message = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/email/' . $email['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        // Test failure as the message has already been sent.
        $this->assertEquals(400, $message['headers']['status-code']);

        // Create Email
        $email = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'status' => 'draft',
            'topics' => [$email['body']['topics'][0]],
            'subject' => 'Khali beats Undertaker',
            'content' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);

        $this->assertEquals(201, $email['headers']['status-code']);

        $email = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/email/' . $email['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'status' => 'processing',
        ]);

        $this->assertEquals(200, $email['headers']['status-code']);

        \sleep(5);

        $message = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $email['body']['$id'], [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(1, $message['body']['deliveredTotal']);
        $this->assertEquals(0, \count($message['body']['deliveryErrors']));
    }

    public function testSendSMS()
    {
        if (empty(App::getEnv('_APP_MESSAGE_SMS_TEST_DSN'))) {
            $this->markTestSkipped('SMS DSN not provided');
        }

        $smsDSN = new DSN(App::getEnv('_APP_MESSAGE_SMS_TEST_DSN'));
        $to = $smsDSN->getParam('to');
        $from = $smsDSN->getParam('from');
        $senderId = $smsDSN->getUser();
        $authKey = $smsDSN->getPassword();

        if (empty($to) || empty($from) || empty($senderId) || empty($authKey)) {
            $this->markTestSkipped('SMS provider not configured');
        }

        // Create provider
        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/msg91', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'providerId' => ID::unique(),
            'name' => 'Msg91Sender',
            'senderId' => $senderId,
            'authKey' => $authKey,
            'from' => $from
        ]);

        $this->assertEquals(201, $provider['headers']['status-code']);

        // Create Topic
        $topic = $this->client->call(Client::METHOD_POST, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'topicId' => ID::unique(),
            'name' => 'topic1',
            'description' => 'Test Topic'
        ]);

        $this->assertEquals(201, $topic['headers']['status-code']);

        // Create User
        $user = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => 'random1-email@mail.org',
            'password' => 'password',
            'name' => 'Messaging User',
        ]);

        $this->assertEquals(201, $user['headers']['status-code']);

        // Create Target
        $target = $this->client->call(Client::METHOD_POST, '/users/' . $user['body']['$id'] . '/targets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'targetId' => ID::unique(),
            'providerType' => 'sms',
            'providerId' => $provider['body']['$id'],
            'identifier' => $to,
        ]);

        $this->assertEquals(201, $target['headers']['status-code']);

        // Create Subscriber
        $subscriber = $this->client->call(Client::METHOD_POST, '/messaging/topics/' . $topic['body']['$id'] . '/subscribers', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'subscriberId' => ID::unique(),
            'targetId' => $target['body']['$id'],
        ]);

        $this->assertEquals(201, $subscriber['headers']['status-code']);

        // Create SMS
        $sms = $this->client->call(Client::METHOD_POST, '/messaging/messages/sms', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'topics' => [$topic['body']['$id']],
            'content' => '064763',
        ]);

        $this->assertEquals(201, $sms['headers']['status-code']);

        \sleep(5);

        $message = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $sms['body']['$id'], [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(1, $message['body']['deliveredTotal']);
        $this->assertEquals(0, \count($message['body']['deliveryErrors']));

        return $message;
    }

    /**
     * @depends testSendSMS
     */
    public function testUpdateSMS(array $sms)
    {
        $message = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/sms/' . $sms['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        // Test failure as the message has already been sent.
        $this->assertEquals(400, $message['headers']['status-code']);

        // Create SMS
        $sms = $this->client->call(Client::METHOD_POST, '/messaging/messages/sms', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'status' => 'draft',
            'topics' => [$sms['body']['topics'][0]],
            'content' => 'Your OTP code is 123456',
        ]);

        $this->assertEquals(201, $sms['headers']['status-code']);

        $sms = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/sms/' . $sms['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'status' => 'processing',
        ]);

        $this->assertEquals(200, $sms['headers']['status-code']);

        \sleep(2);

        $message = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $sms['body']['$id'], [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(1, $message['body']['deliveredTotal']);
        $this->assertEquals(0, \count($message['body']['deliveryErrors']));
    }

    public function testSendPushNotification()
    {
        if (empty(App::getEnv('_APP_MESSAGE_PUSH_TEST_DSN'))) {
            $this->markTestSkipped('Push DSN empty');
        }

        $dsn = new DSN(App::getEnv('_APP_MESSAGE_PUSH_TEST_DSN'));
        $to = $dsn->getParam('to');
        $serviceAccountJSON = $dsn->getParam('saj');

        if (empty($to) || empty($serviceAccountJSON)) {
            $this->markTestSkipped('Push provider not configured');
        }

        // Create provider
        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/fcm', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'providerId' => ID::unique(),
            'name' => 'FCM-1',
            'serviceAccountJSON' => $serviceAccountJSON,
        ]);

        $this->assertEquals(201, $provider['headers']['status-code']);

        // Create Topic
        $topic = $this->client->call(Client::METHOD_POST, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'topicId' => ID::unique(),
            'name' => 'topic1',
            'description' => 'Test Topic'
        ]);

        $this->assertEquals(201, $topic['headers']['status-code']);

        // Create User
        $user = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => 'random3-email@mail.org',
            'password' => 'password',
            'name' => 'Messaging User',
        ]);

        $this->assertEquals(201, $user['headers']['status-code']);

        // Create Target
        $target = $this->client->call(Client::METHOD_POST, '/users/' . $user['body']['$id'] . '/targets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'targetId' => ID::unique(),
            'providerType' => 'push',
            'providerId' => $provider['body']['$id'],
            'identifier' => $to,
        ]);

        $this->assertEquals(201, $target['headers']['status-code']);

        // Create Subscriber
        $subscriber = $this->client->call(Client::METHOD_POST, '/messaging/topics/' . $topic['body']['$id'] . '/subscribers', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'subscriberId' => ID::unique(),
            'targetId' => $target['body']['$id'],
        ]);

        $this->assertEquals(201, $subscriber['headers']['status-code']);

        // Create push notification
        $push = $this->client->call(Client::METHOD_POST, '/messaging/messages/push', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'topics' => [$topic['body']['$id']],
            'title' => 'Test-Notification',
            'body' => 'Test-Notification-Body',
        ]);

        $this->assertEquals(201, $push['headers']['status-code']);

        \sleep(5);

        $message = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $push['body']['$id'], [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(1, $message['body']['deliveredTotal']);
        $this->assertEquals(0, \count($message['body']['deliveryErrors']));

        return $message;
    }

    /**
     * @depends testSendPushNotification
     */
    public function testUpdatePushNotification(array $push)
    {
        $message = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/push/' . $push['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        // Test failure as the message has already been sent.
        $this->assertEquals(400, $message['headers']['status-code']);

        // Create push notification
        $push = $this->client->call(Client::METHOD_POST, '/messaging/messages/push', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'status' => 'draft',
            'topics' => [$push['body']['topics'][0]],
            'title' => 'Test-Notification',
            'body' => 'Test-Notification-Body',
        ]);

        $this->assertEquals(201, $push['headers']['status-code']);

        $push = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/push/' . $push['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'status' => 'processing',
        ]);

        $this->assertEquals(200, $push['headers']['status-code']);

        \sleep(5);

        $message = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $push['body']['$id'], [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(1, $message['body']['deliveredTotal']);
        $this->assertEquals(0, \count($message['body']['deliveryErrors']));
    }
}
