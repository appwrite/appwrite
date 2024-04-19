<?php

namespace Tests\E2E\Services\Messaging;

use Appwrite\Messaging\Status as MessageStatus;
use CURLFile;
use Tests\E2E\Client;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\DSN\DSN;
use Utopia\System\System;

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
            'smtp' => [
                'providerId' => ID::unique(),
                'name' => 'SMTP1',
                'host' => 'smtp.appwrite.io',
                'port' => 587,
                'security' => 'tls',
                'username' => 'my-username',
                'password' => 'my-password',
                'fromName' => 'sender name',
                'fromEmail' => 'tester@appwrite.io',
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
                'customerId' => 'my-username',
                'apiKey' => 'my-password',
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
                    "project_id" => "test-project",
                    "private_key_id" => "test-private-key-id",
                    "private_key" => "test-private-key",
                ],
            ],
            'apns' => [
                'providerId' => ID::unique(),
                'name' => 'APNS1',
                'authKey' => 'my-authkey',
                'authKeyId' => 'my-authkeyid',
                'teamId' => 'my-teamid',
                'bundleId' => 'my-bundleid',
            ],
        ];
        $providers = [];

        foreach ($providersParams as $key => $params) {
            $response = $this->client->call(Client::METHOD_POST, '/messaging/providers/' . $key, \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]), $params);

            $this->assertEquals(201, $response['headers']['status-code']);
            $this->assertEquals($params['name'], $response['body']['name']);
            $providers[] = $response['body'];

            switch ($key) {
                case 'apns':
                    $this->assertEquals(false, $response['body']['options']['sandbox']);
                    break;
            }
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
            'smtp' => [
                'name' => 'SMTP2',
                'host' => 'smtp.appwrite.io',
                'port' => 587,
                'security' => 'tls',
                'username' => 'my-username',
                'password' => 'my-password',
            ],
            'twilio' => [
                'name' => 'Twilio2',
                'accountSid' => 'my-accountSid',
                'authToken' => 'my-authToken',
            ],
            'telesign' => [
                'name' => 'Telesign2',
                'customerId' => 'my-username',
                'apiKey' => 'my-password',
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
                    "project_id" => "test-project",
                    "private_key_id" => "test-private-key-id",
                    "private_key" => "test-private-key",
                ]
            ],
            'apns' => [
                'name' => 'APNS2',
                'authKey' => 'my-authkey',
                'authKeyId' => 'my-authkeyid',
                'teamId' => 'my-teamid',
                'bundleId' => 'my-bundleid',
            ],
        ];

        foreach (\array_keys($providersParams) as $index => $name) {
            $response = $this->client->call(Client::METHOD_PATCH, '/messaging/providers/' . $name . '/' . $providers[$index]['$id'], [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], $providersParams[$name]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals($providersParams[$name]['name'], $response['body']['name']);

            if ($name === 'smtp') {
                $this->assertArrayHasKey('encryption', $response['body']['options']);
                $this->assertArrayHasKey('autoTLS', $response['body']['options']);
                $this->assertArrayHasKey('mailer', $response['body']['options']);
                $this->assertArrayNotHasKey('encryption', $response['body']['credentials']);
                $this->assertArrayNotHasKey('autoTLS', $response['body']['credentials']);
                $this->assertArrayNotHasKey('mailer', $response['body']['credentials']);
            }

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

    public function testUpdateProviderMissingCredentialsThrows(): void
    {
        // Create new FCM provider with no serviceAccountJSON
        $response = $this->client->call(Client::METHOD_POST, '/messaging/providers/fcm', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'providerId' => ID::unique(),
            'name' => 'FCM3',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Enable provider with no serviceAccountJSON
        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/providers/fcm/' . $response['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'enabled' => true,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
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
        $this->assertEquals(11, \count($response['body']['providers']));

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
        $response1 = $this->client->call(Client::METHOD_POST, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'topicId' => ID::unique(),
            'name' => 'my-app',
        ]);
        $this->assertEquals(201, $response1['headers']['status-code']);
        $this->assertEquals('my-app', $response1['body']['name']);

        $response2 = $this->client->call(Client::METHOD_POST, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'topicId' => ID::unique(),
            'name' => 'my-app2',
            'subscribe' => [Role::user('invalid')->toString()],
        ]);
        $this->assertEquals(201, $response2['headers']['status-code']);
        $this->assertEquals('my-app2', $response2['body']['name']);
        $this->assertEquals(1, \count($response2['body']['subscribe']));

        return [
            'public' => $response1['body'],
            'private' => $response2['body'],
        ];
    }

    /**
     * @depends testCreateTopic
     */
    public function testUpdateTopic(array $topics): string
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/topics/' . $topics['public']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'name' => 'android-app',
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('android-app', $response['body']['name']);

        $response2 = $this->client->call(Client::METHOD_PATCH, '/messaging/topics/' . $topics['private']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'name' => 'ios-app',
            'subscribe' => [Role::user('some-user')->toString()],
        ]);
        $this->assertEquals(200, $response2['headers']['status-code']);
        $this->assertEquals('ios-app', $response2['body']['name']);

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
            'queries' => [
                Query::equal('emailTotal', [0])->toString(),
                Query::equal('smsTotal', [0])->toString(),
                Query::equal('pushTotal', [0])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, \count($response['body']['topics']));

        $response = $this->client->call(Client::METHOD_GET, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'queries' => [
                Query::greaterThan('emailTotal', 0)->toString(),
                Query::greaterThan('smsTotal', 0)->toString(),
                Query::greaterThan('pushTotal', 0)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, \count($response['body']['topics']));

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
        $this->assertEquals(0, $response['body']['emailTotal']);
        $this->assertEquals(0, $response['body']['smsTotal']);
        $this->assertEquals(0, $response['body']['pushTotal']);
    }

    /**
     * @depends testCreateTopic
     */
    public function testCreateSubscriber(array $topics)
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

        $response = $this->client->call(Client::METHOD_POST, '/messaging/topics/' . $topics['public']['$id'] . '/subscribers', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'subscriberId' => ID::unique(),
            'targetId' => $target['body']['$id'],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals($target['body']['userId'], $response['body']['target']['userId']);
        $this->assertEquals($target['body']['providerType'], $response['body']['target']['providerType']);

        // Test duplicate subscribers not allowed
        $failure = $this->client->call(Client::METHOD_POST, '/messaging/topics/' . $topics['public']['$id'] . '/subscribers', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'subscriberId' => ID::unique(),
            'targetId' => $target['body']['$id'],
        ]);

        $this->assertEquals(409, $failure['headers']['status-code']);

        $topic = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $topics['public']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $topic['headers']['status-code']);
        $this->assertEquals('android-app', $topic['body']['name']);
        $this->assertEquals(1, $topic['body']['emailTotal']);
        $this->assertEquals(0, $topic['body']['smsTotal']);
        $this->assertEquals(0, $topic['body']['pushTotal']);

        $response2 = $this->client->call(Client::METHOD_POST, '/messaging/topics/' . $topics['private']['$id'] . '/subscribers', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'subscriberId' => ID::unique(),
            'targetId' => $target['body']['$id'],
        ]);

        if ($this->getSide() === 'client') {
            $this->assertEquals(401, $response2['headers']['status-code']);
        } else {
            $this->assertEquals(201, $response2['headers']['status-code']);
        }

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
        $subscriberId = $data['subscriberId'];
        $targetId = $data['targetId'];
        $userId = $data['userId'];
        $providerType = $data['providerType'];
        $identifier = $data['identifier'];

        $response = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $data['topicId'] . '/subscribers', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);
        $this->assertEquals($userId, $response['body']['subscribers'][0]['target']['userId']);
        $this->assertEquals($providerType, $response['body']['subscribers'][0]['target']['providerType']);
        $this->assertEquals($identifier, $response['body']['subscribers'][0]['target']['identifier']);
        $this->assertEquals(\count($response['body']['subscribers']), $response['body']['total']);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $data['topicId'] . '/subscribers', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'search' => 'DOES_NOT_EXIST',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, $response['body']['total']);

        $searches = [
            $subscriberId,
            $targetId,
            $userId,
            $providerType
        ];
        foreach ($searches as $search) {
            $response = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $data['topicId'] . '/subscribers', \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]), [
                'search' => $search,
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals(1, $response['body']['total']);
        }

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
            'queries' => [
                Query::limit(1)->toString(),
            ],
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
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/messaging/subscribers/' . $data['subscriberId'] . '/logs', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'queries' => [
                Query::limit(1)->toString(),
                Query::offset(1)->toString(),
            ],
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
            'queries' => [
                Query::limit(-1)->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/subscribers/' . $data['subscriberId'] . '/logs', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'queries' => [
                Query::offset(-1)->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/subscribers/' . $data['subscriberId'] . '/logs', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'queries' => [
                Query::equal('$id', ['asdf'])->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/subscribers/' . $data['subscriberId'] . '/logs', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'queries' => [
                Query::orderAsc('$id')->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/subscribers/' . $data['subscriberId'] . '/logs', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'queries' => [
                '{ "method": "cursorAsc", "attribute": "$id" }'
            ]
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
        $this->assertEquals(0, $topic['body']['emailTotal']);
        $this->assertEquals(0, $topic['body']['smsTotal']);
        $this->assertEquals(0, $topic['body']['pushTotal']);
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

    /**
     * @depends testCreateDraftEmail
     */
    public function testListTargets(array $message)
    {
        $response = $this->client->call(Client::METHOD_GET, '/messaging/messages/does_not_exist/targets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $message['$id'] . '/targets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $targetList = $response['body'];
        $this->assertEquals(2, $targetList['total']);
        $this->assertEquals(2, count($targetList['targets']));
        $this->assertEquals($message['targets'][0], $targetList['targets'][0]['$id']);
        $this->assertEquals($message['targets'][1], $targetList['targets'][1]['$id']);

        /**
         * Cursor Test
         */
        $response = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $message['$id'] . '/targets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $targetList['targets'][0]['$id']]))->toString(),
            ]
        ]);
        $this->assertEquals(2, $response['body']['total']);
        $this->assertEquals(1, count($response['body']['targets']));
        $this->assertEquals($targetList['targets'][1]['$id'], $response['body']['targets'][0]['$id']);

        // Test for empty targets
        $response = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'subject' => 'New blog post',
            'content' => 'Check out the new blog post at http://localhost',
            'draft' => true
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $message = $response['body'];

        $response = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $message['$id'] . '/targets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $targetList = $response['body'];
        $this->assertEquals(0, $targetList['total']);
        $this->assertEquals(0, count($targetList['targets']));
    }

    public function testCreateDraftEmail()
    {
        // Create User 1
        $response = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => uniqid() . "@example.com",
            'password' => 'password',
            'name' => 'Messaging User 1',
        ]);

        $this->assertEquals(201, $response['headers']['status-code'], "Error creating user: " . var_export($response['body'], true));

        $user1 = $response['body'];

        $this->assertEquals(1, \count($user1['targets']));
        $targetId1 = $user1['targets'][0]['$id'];

        // Create User 2
        $response = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => uniqid() . "@example.com",
            'password' => 'password',
            'name' => 'Messaging User 2',
        ]);

        $this->assertEquals(201, $response['headers']['status-code'], "Error creating user: " . var_export($response['body'], true));
        $user2 = $response['body'];

        $this->assertEquals(1, \count($user2['targets']));
        $targetId2 = $user2['targets'][0]['$id'];

        // Create Email
        $response = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'targets' => [$targetId1, $targetId2],
            'subject' => 'New blog post',
            'content' => 'Check out the new blog post at http://localhost',
            'draft' => true
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $message = $response['body'];
        $this->assertEquals(MessageStatus::DRAFT, $message['status']);

        return $message;
    }

    public function testCreateDraftPushWithImage()
    {
        // Create User 1
        $user = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => uniqid() . "@example.com",
            'password' => 'password',
            'name' => 'Messaging User 1',
        ]);

        $this->assertEquals(201, $user['headers']['status-code'], "Error creating user: " . var_export($user['body'], true));
        $this->assertEquals(1, \count($user['body']['targets']));

        // Create push target
        $target = $this->client->call(Client::METHOD_POST, '/users/' . $user['body']['$id'] . '/targets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'targetId' => ID::unique(),
            'userId' => $user['body']['$id'],
            'providerType' => 'push',
            'identifier' => '123456',
        ]);

        $targetId = $target['body']['$id'];

        // Create bucket
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
            'fileSecurity' => true,
            'maximumFileSize' => 2000000, // 2MB
            'allowedFileExtensions' => ['jpg', 'png'],
            'permissions' => [
                Permission::read(Role::user('x')),
                Permission::create(Role::user('x')),
                Permission::update(Role::user('x')),
                Permission::delete(Role::user('x')),
            ],
        ]);

        $this->assertEquals(201, $bucket['headers']['status-code']);

        $bucketId = $bucket['body']['$id'];

        \sleep(1);

        // Create file
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $fileId = $file['body']['$id'];

        // Create Push
        $response = $this->client->call(Client::METHOD_POST, '/messaging/messages/push', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'targets' => [$targetId],
            'title' => 'New blog post',
            'body' => 'Check out the new blog post at http://localhost',
            'image' => "{$bucketId}:{$fileId}",
            'draft' => true
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $message = $response['body'];

        $this->assertEquals(MessageStatus::DRAFT, $message['status']);

        $imageUrl = $message['data']['image']['url'];

        $client = new Client();
        $client->setEndpoint('');

        $image = $client->call(Client::METHOD_GET, $imageUrl);

        $this->assertEquals(200, $image['headers']['status-code']);

        return $message;
    }

    public function testScheduledMessage(): void
    {
        // Create user
        $response = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => uniqid() . "@example.com",
            'password' => 'password',
            'name' => 'Messaging User 1',
        ]);

        $targetId = $response['body']['targets'][0]['$id'];

        // Create scheduled message
        $message = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'targets' => [$targetId],
            'subject' => 'New blog post',
            'content' => 'Check out the new blog post at http://localhost',
            'scheduledAt' => DateTime::addSeconds(new \DateTime(), 3),
        ]);

        $this->assertEquals(201, $message['headers']['status-code']);
        $this->assertEquals(MessageStatus::SCHEDULED, $message['body']['status']);

        \sleep(8);

        $message = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $message['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(MessageStatus::FAILED, $message['body']['status']);
    }

    public function testScheduledToDraftMessage(): void
    {
        // Create user
        $response = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => uniqid() . "@example.com",
            'password' => 'password',
            'name' => 'Messaging User 1',
        ]);

        $targetId = $response['body']['targets'][0]['$id'];

        // Create scheduled message
        $message = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'targets' => [$targetId],
            'subject' => 'New blog post',
            'content' => 'Check out the new blog post at http://localhost',
            'scheduledAt' => DateTime::addSeconds(new \DateTime(), 5),
        ]);

        $this->assertEquals(201, $message['headers']['status-code']);
        $this->assertEquals(MessageStatus::SCHEDULED, $message['body']['status']);

        $message = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/email/' . $message['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'draft' => true,
        ]);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(MessageStatus::DRAFT, $message['body']['status']);

        \sleep(8);

        $message = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $message['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(MessageStatus::DRAFT, $message['body']['status']);
    }

    public function testDraftToScheduledMessage(): void
    {
        // Create user
        $response = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => uniqid() . "@example.com",
            'password' => 'password',
            'name' => 'Messaging User 1',
        ]);

        $targetId = $response['body']['targets'][0]['$id'];

        // Create draft message
        $message = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'targets' => [$targetId],
            'subject' => 'New blog post',
            'content' => 'Check out the new blog post at http://localhost',
            'draft' => true,
        ]);

        $this->assertEquals(201, $message['headers']['status-code']);
        $this->assertEquals(MessageStatus::DRAFT, $message['body']['status']);

        $message = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/email/' . $message['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'draft' => false,
            'scheduledAt' => DateTime::addSeconds(new \DateTime(), 3),
        ]);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(MessageStatus::SCHEDULED, $message['body']['status']);

        \sleep(8);

        $message = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $message['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(MessageStatus::FAILED, $message['body']['status']);
    }

    public function testUpdateScheduledAt(): void
    {
        // Create user
        $response = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => uniqid() . "@example.com",
            'password' => 'password',
            'name' => 'Messaging User 1',
        ]);

        $targetId = $response['body']['targets'][0]['$id'];

        // Create scheduled message
        $message = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'targets' => [$targetId],
            'subject' => 'New blog post',
            'content' => 'Check out the new blog post at http://localhost',
            'scheduledAt' => DateTime::addSeconds(new \DateTime(), 3),
        ]);

        $this->assertEquals(201, $message['headers']['status-code']);
        $this->assertEquals(MessageStatus::SCHEDULED, $message['body']['status']);

        $scheduledAt = DateTime::addSeconds(new \DateTime(), 10);

        $message = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/email/' . $message['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'scheduledAt' => $scheduledAt,
        ]);

        $this->assertEquals(200, $message['headers']['status-code']);

        \sleep(8);

        $message = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $message['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(MessageStatus::SCHEDULED, $message['body']['status']);

        \sleep(8);

        $message = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $message['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(MessageStatus::FAILED, $message['body']['status']);
    }

    public function testSendEmail()
    {
        if (empty(System::getEnv('_APP_MESSAGE_EMAIL_TEST_DSN'))) {
            $this->markTestSkipped('Email DSN not provided');
        }

        $emailDSN = new DSN(System::getEnv('_APP_MESSAGE_EMAIL_TEST_DSN'));
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
            'name' => 'Sendgrid-provider',
            'apiKey' => $apiKey,
            'fromName' => $fromName,
            'fromEmail' => $fromEmail,
            'enabled' => true,
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

        // Get target
        $target = $user['body']['targets'][0];

        // Create Subscriber
        $subscriber = $this->client->call(Client::METHOD_POST, '/messaging/topics/' . $topic['body']['$id'] . '/subscribers', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'subscriberId' => ID::unique(),
            'targetId' => $target['$id'],
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

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(1, $message['body']['deliveredTotal']);
        $this->assertEquals(0, \count($message['body']['deliveryErrors']));

        return [
            'message' => $email['body'],
            'topic' => $topic['body'],
        ];
    }

    /**
     * @depends testSendEmail
     */
    public function testUpdateEmail(array $params): void
    {
        $email = $params['message'];

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
            'draft' => true,
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
            'draft' => false,
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
        if (empty(System::getEnv('_APP_MESSAGE_SMS_TEST_DSN'))) {
            $this->markTestSkipped('SMS DSN not provided');
        }

        $smsDSN = new DSN(System::getEnv('_APP_MESSAGE_SMS_TEST_DSN'));
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
            'from' => $from,
            'enabled' => true,
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
            'draft' => true,
            'topics' => [$sms['body']['topics'][0]],
            'content' => 'Your OTP code is 123456',
        ]);

        $this->assertEquals(201, $sms['headers']['status-code']);

        $sms = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/sms/' . $sms['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'draft' => false,
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
        if (empty(System::getEnv('_APP_MESSAGE_PUSH_TEST_DSN'))) {
            $this->markTestSkipped('Push DSN empty');
        }

        $dsn = new DSN(System::getEnv('_APP_MESSAGE_PUSH_TEST_DSN'));
        $to = $dsn->getParam('to');
        $serviceAccountJSON = $dsn->getParam('serviceAccountJSON');

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
            'enabled' => true,
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
            'draft' => true,
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
            'draft' => false,
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

    /**
     * @depends testSendEmail
     * @return void
     * @throws \Exception
     */
    public function testDeleteMessage(array $params): void
    {
        $message = $params['message'];
        $topic = $params['topic'];

        $response = $this->client->call(Client::METHOD_DELETE, '/messaging/messages/' . $message['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(204, $response['headers']['status-code']);

        // Test for FAILURE
        $response = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'topics' => [$topic['$id']],
            'subject' => 'Test subject',
            'content' => 'Test content',
        ]);

        $response = $this->client->call(Client::METHOD_DELETE, '/messaging/messages/' . $response['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/messaging/messages/does_not_exist', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
    }
}
