<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\DSN\DSN;
use Utopia\System\System;

class MessagingTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use Base;

    public function testCreateProviders()
    {
        $providersParams = [
            'Sendgrid' => [
                'providerId' => ID::unique(),
                'name' => 'Sengrid1',
                'apiKey' => 'my-apikey',
                'fromName' => 'Sender Name',
                'fromEmail' => 'sender-email@my-domain.com',
            ],
            'Mailgun' => [
                'providerId' => ID::unique(),
                'name' => 'Mailgun1',
                'apiKey' => 'my-apikey',
                'domain' => 'my-domain',
                'fromName' => 'Sender Name',
                'fromEmail' => 'sender-email@my-domain.com',
                'isEuRegion' => false,
            ],
            'Twilio' => [
                'providerId' => ID::unique(),
                'name' => 'Twilio1',
                'accountSid' => 'my-accountSid',
                'authToken' => 'my-authToken',
                'from' => '+123456789',
            ],
            'Telesign' => [
                'providerId' => ID::unique(),
                'name' => 'Telesign1',
                'customerId' => 'my-username',
                'apiKey' => 'my-password',
                'from' => '+123456789',
            ],
            'Textmagic' => [
                'providerId' => ID::unique(),
                'name' => 'Textmagic1',
                'username' => 'my-username',
                'apiKey' => 'my-apikey',
                'from' => '+123456789',
            ],
            'Msg91' => [
                'providerId' => ID::unique(),
                'name' => 'Ms91-1',
                'senderId' => 'my-senderid',
                'authKey' => 'my-authkey',
                'from' => '+123456789'
            ],
            'Vonage' => [
                'providerId' => ID::unique(),
                'name' => 'Vonage1',
                'apiKey' => 'my-apikey',
                'apiSecret' => 'my-apisecret',
                'from' => '+123456789',
            ],
            'Fcm' => [
                'providerId' => ID::unique(),
                'name' => 'FCM1',
                'serviceAccountJSON' => [
                    'type' => 'service_account',
                    "project_id" => "test-project",
                    "private_key_id" => "test-private-key-id",
                    "private_key" => "test-private-key",
                ]
            ],
            'Apns' => [
                'providerId' => ID::unique(),
                'name' => 'APNS1',
                'authKey' => 'my-authkey',
                'authKeyId' => 'my-authkeyid',
                'teamId' => 'my-teamid',
                'bundleId' => 'my-bundleid',
            ],
        ];

        $providers = [];

        foreach (\array_keys($providersParams) as $key) {
            $query = $this->getQuery('create_' . \strtolower($key) . '_provider');
            $graphQLPayload = [
                'query' => $query,
                'variables' => $providersParams[$key],
            ];
            $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]), $graphQLPayload);

            $providers[] = $response['body']['data']['messagingCreate' . $key . 'Provider'];
            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals($providersParams[$key]['name'], $response['body']['data']['messagingCreate' . $key . 'Provider']['name']);
        }

        return $providers;
    }

    /**
     * @depends testCreateProviders
     */
    public function testUpdateProviders(array $providers): array
    {
        $providersParams = [
            'Sendgrid' => [
                'providerId' => $providers[0]['_id'],
                'name' => 'Sengrid2',
                'apiKey' => 'my-apikey',
            ],
            'Mailgun' => [
                'providerId' => $providers[1]['_id'],
                'name' => 'Mailgun2',
                'apiKey' => 'my-apikey',
                'domain' => 'my-domain',
            ],
            'Twilio' => [
                'providerId' => $providers[2]['_id'],
                'name' => 'Twilio2',
                'accountSid' => 'my-accountSid',
                'authToken' => 'my-authToken',
            ],
            'Telesign' => [
                'providerId' => $providers[3]['_id'],
                'name' => 'Telesign2',
                'customerId' => 'my-username',
                'apiKey' => 'my-password',
            ],
            'Textmagic' => [
                'providerId' => $providers[4]['_id'],
                'name' => 'Textmagic2',
                'username' => 'my-username',
                'apiKey' => 'my-apikey',
            ],
            'Msg91' => [
                'providerId' => $providers[5]['_id'],
                'name' => 'Ms91-2',
                'senderId' => 'my-senderid',
                'authKey' => 'my-authkey',
            ],
            'Vonage' => [
                'providerId' => $providers[6]['_id'],
                'name' => 'Vonage2',
                'apiKey' => 'my-apikey',
                'apiSecret' => 'my-apisecret',
            ],
            'Fcm' => [
                'providerId' => $providers[7]['_id'],
                'name' => 'FCM2',
                'serviceAccountJSON' => [
                    'type' => 'service_account',
                    'project_id' => 'test-project',
                    'private_key_id' => 'test-project-id',
                    'private_key' => "test-private-key",
                ]
            ],
            'Apns' => [
                'providerId' => $providers[8]['_id'],
                'name' => 'APNS2',
                'authKey' => 'my-authkey',
                'authKeyId' => 'my-authkeyid',
                'teamId' => 'my-teamid',
                'bundleId' => 'my-bundleid',
            ],
        ];
        foreach (\array_keys($providersParams) as $index => $key) {
            $query = $this->getQuery('update_' . \strtolower($key) . '_provider');

            $graphQLPayload = [
                'query' => $query,
                'variables' => $providersParams[$key],
            ];

            $response = $this->client->call(Client::METHOD_POST, '/graphql', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], $graphQLPayload);

            $providers[$index] = $response['body']['data']['messagingUpdate' . $key . 'Provider'];
            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals($providersParams[$key]['name'], $response['body']['data']['messagingUpdate' . $key . 'Provider']['name']);
        }

        $response = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'query' => $this->getQuery('update_mailgun_provider'),
            'variables' => [
                'providerId' => $providers[1]['_id'],
                'name' => 'Mailgun2',
                'apiKey' => 'my-apikey',
                'domain' => 'my-domain',
                'isEuRegion' => true,
                'enabled' => false,
            ]
        ]);
        $providers[1] = $response['body']['data']['messagingUpdateMailgunProvider'];
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Mailgun2', $response['body']['data']['messagingUpdateMailgunProvider']['name']);
        $this->assertEquals(false, $response['body']['data']['messagingUpdateMailgunProvider']['enabled']);
        return $providers;
    }

    /**
     * @depends testUpdateProviders
     */
    public function testListProviders(array $providers)
    {
        $query = $this->getQuery(self::$LIST_PROVIDERS);
        $graphQLPayload = [
            'query' => $query,
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $graphQLPayload);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(\count($providers), \count($response['body']['data']['messagingListProviders']['providers']));
    }

    /**
     * @depends testUpdateProviders
     */
    public function testGetProvider(array $providers)
    {
        $query = $this->getQuery(self::$GET_PROVIDER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'providerId' => $providers[0]['_id'],
            ]
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $graphQLPayload);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($providers[0]['name'], $response['body']['data']['messagingGetProvider']['name']);
    }

    /**
     * @depends testUpdateProviders
     */
    public function testDeleteProvider(array $providers)
    {
        foreach ($providers as $provider) {
            $query = $this->getQuery(self::$DELETE_PROVIDER);
            $graphQLPayload = [
                'query' => $query,
                'variables' => [
                    'providerId' => $provider['_id'],
                ]
            ];
            $response = $this->client->call(Client::METHOD_POST, '/graphql', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], $graphQLPayload);
            $this->assertEquals(204, $response['headers']['status-code']);
        }
    }

    public function testCreateTopic()
    {
        $query = $this->getQuery(self::$CREATE_TOPIC);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'topicId' => ID::unique(),
                'name' => 'topic1',
            ],
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('topic1', $response['body']['data']['messagingCreateTopic']['name']);

        return $response['body']['data']['messagingCreateTopic'];
    }

    /**
     * @depends testCreateTopic
     */
    public function testUpdateTopic(array $topic)
    {
        $topicId = $topic['_id'];
        $query = $this->getQuery(self::$UPDATE_TOPIC);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'topicId' => $topicId,
                'name' => 'topic2',
            ],
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('topic2', $response['body']['data']['messagingUpdateTopic']['name']);

        return $topicId;
    }

    /**
     * @depends testCreateTopic
     */
    public function testListTopics()
    {
        $query = $this->getQuery(self::$LIST_TOPICS);
        $graphQLPayload = [
            'query' => $query,
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, \count($response['body']['data']['messagingListTopics']['topics']));
    }

    /**
     * @depends testUpdateTopic
     */
    public function testGetTopic(string $topicId)
    {
        $query = $this->getQuery(self::$GET_TOPIC);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'topicId' => $topicId,
            ],
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('topic2', $response['body']['data']['messagingGetTopic']['name']);
    }

    /**
     * @depends testCreateTopic
     */
    public function testCreateSubscriber(array $topic)
    {
        $topicId = $topic['_id'];

        $userId = $this->getUser()['$id'];

        $providerParam = [
            'sendgrid' => [
                'providerId' => ID::unique(),
                'name' => 'Sengrid1',
                'apiKey' => 'my-apikey',
                'fromName' => 'Sender',
                'fromEmail' => 'sender-email@my-domain.com',
            ]
        ];
        $query = $this->getQuery(self::$CREATE_SENDGRID_PROVIDER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => $providerParam['sendgrid'],
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $providerId = $response['body']['data']['messagingCreateSendgridProvider']['_id'];

        $query = $this->getQuery(self::$CREATE_USER_TARGET);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'targetId' => ID::unique(),
                'providerType' => 'email',
                'userId' => $userId,
                'providerId' => $providerId,
                'identifier' => 'random-email@mail.org',
            ],
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($userId, $response['body']['data']['usersCreateTarget']['userId']);
        $this->assertEquals('random-email@mail.org', $response['body']['data']['usersCreateTarget']['identifier']);

        $targetId = $response['body']['data']['usersCreateTarget']['_id'];

        $query = $this->getQuery(self::$CREATE_SUBSCRIBER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'subscriberId' => ID::unique(),
                'topicId' => $topicId,
                'targetId' => $targetId,
            ],
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($response['body']['data']['messagingCreateSubscriber']['topicId'], $topicId);
        $this->assertEquals($response['body']['data']['messagingCreateSubscriber']['targetId'], $targetId);
        $this->assertEquals($response['body']['data']['messagingCreateSubscriber']['target']['userId'], $userId);

        return $response['body']['data']['messagingCreateSubscriber'];
    }

    /**
     * @depends testCreateSubscriber
     */
    public function testListSubscribers(array $subscriber)
    {
        $query = $this->getQuery(self::$LIST_SUBSCRIBERS);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'topicId' => $subscriber['topicId'],
            ],
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($subscriber['topicId'], $response['body']['data']['messagingListSubscribers']['subscribers'][0]['topicId']);
        $this->assertEquals($subscriber['targetId'], $response['body']['data']['messagingListSubscribers']['subscribers'][0]['targetId']);
        $this->assertEquals($subscriber['target']['userId'], $response['body']['data']['messagingListSubscribers']['subscribers'][0]['target']['userId']);
        $this->assertEquals(1, \count($response['body']['data']['messagingListSubscribers']['subscribers']));
    }

    /**
     * @depends testCreateSubscriber
     */
    public function testGetSubscriber(array $subscriber)
    {
        $topicId = $subscriber['topicId'];
        $subscriberId = $subscriber['_id'];

        $query = $this->getQuery(self::$GET_SUBSCRIBER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'topicId' => $topicId,
                'subscriberId' => $subscriberId,
            ],
        ];

        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($subscriberId, $response['body']['data']['messagingGetSubscriber']['_id']);
        $this->assertEquals($topicId, $response['body']['data']['messagingGetSubscriber']['topicId']);
        $this->assertEquals($subscriber['targetId'], $response['body']['data']['messagingGetSubscriber']['targetId']);
        $this->assertEquals($subscriber['target']['userId'], $response['body']['data']['messagingGetSubscriber']['target']['userId']);
    }

    /**
     * @depends testCreateSubscriber
     */
    public function testDeleteSubscriber(array $subscriber)
    {
        $topicId = $subscriber['topicId'];
        $subscriberId = $subscriber['_id'];

        $query = $this->getQuery(self::$DELETE_SUBSCRIBER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'topicId' => $topicId,
                'subscriberId' => $subscriberId,
            ],
        ];

        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertEquals(200, $response['headers']['status-code']);
    }

    /**
     * @depends testUpdateTopic
     */
    public function testDeleteTopic(string $topicId)
    {
        $query = $this->getQuery(self::$DELETE_TOPIC);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'topicId' => $topicId,
            ],
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(204, $response['headers']['status-code']);
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
        $isEuRegion = $emailDSN->getParam('isEuRegion');
        $apiKey = $emailDSN->getPassword();
        $domain = $emailDSN->getUser();

        if (empty($to) || empty($from) || empty($apiKey) || empty($domain) || empty($isEuRegion)) {
            $this->markTestSkipped('Email provider not configured');
        }

        $query = $this->getQuery(self::$CREATE_MAILGUN_PROVIDER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'providerId' => ID::unique(),
                'name' => 'Mailgun1',
                'apiKey' => $apiKey,
                'domain' => $domain,
                'fromName' => $fromName,
                'fromEmail' => $fromEmail,
                'isEuRegion' => filter_var($isEuRegion, FILTER_VALIDATE_BOOLEAN),
            ],
        ];
        $provider = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $provider['headers']['status-code']);

        $providerId = $provider['body']['data']['messagingCreateMailgunProvider']['_id'];

        $query = $this->getQuery(self::$CREATE_TOPIC);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'topicId' => ID::unique(),
                'name' => 'topic1',
            ],
        ];
        $topic = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $topic['headers']['status-code']);

        $query = $this->getQuery(self::$CREATE_USER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => ID::unique(),
                'email' => 'random1-mail@mail.org',
                'password' => 'password',
                'name' => 'Messaging User',
            ]
        ];
        $user = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $user['headers']['status-code']);

        $query = $this->getQuery(self::$CREATE_USER_TARGET);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'targetId' => ID::unique(),
                'providerType' => 'email',
                'userId' => $user['body']['data']['usersCreate']['_id'],
                'providerId' => $providerId,
                'identifier' => $to,
            ],
        ];
        $target = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $target['headers']['status-code']);

        $query = $this->getQuery(self::$CREATE_SUBSCRIBER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'subscriberId' => ID::unique(),
                'topicId' => $topic['body']['data']['messagingCreateTopic']['_id'],
                'targetId' => $target['body']['data']['usersCreateTarget']['_id'],
            ],
        ];
        $subscriber = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertEquals(200, $subscriber['headers']['status-code']);

        $query = $this->getQuery(self::$CREATE_EMAIL);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'messageId' => ID::unique(),
                'topics' => [$topic['body']['data']['messagingCreateTopic']['_id']],
                'subject' => 'Khali beats Undertaker',
                'content' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            ],
        ];
        $email = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $email['headers']['status-code']);

        \sleep(5);

        $query = $this->getQuery(self::$GET_MESSAGE);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'messageId' => $email['body']['data']['messagingCreateEmail']['_id'],
            ],
        ];
        $message = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(1, $message['body']['data']['messagingGetMessage']['deliveredTotal']);
        $this->assertEquals(0, \count($message['body']['data']['messagingGetMessage']['deliveryErrors']));

        return $message['body']['data']['messagingGetMessage'];
    }

    /**
     * @depends testSendEmail
     */
    public function testUpdateEmail(array $email)
    {
        $query = $this->getQuery(self::$CREATE_EMAIL);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'messageId' => ID::unique(),
                'status' => 'draft',
                'topics' => [$email['topics'][0]],
                'subject' => 'Khali beats Undertaker',
                'content' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            ],
        ];
        $email = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $email['headers']['status-code']);

        $query = $this->getQuery(self::$UPDATE_EMAIL);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'messageId' => $email['body']['data']['messagingCreateEmail']['_id'],
                'status' => 'processing',
            ],
        ];
        $email = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $email['headers']['status-code']);

        \sleep(5);

        $query = $this->getQuery(self::$GET_MESSAGE);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'messageId' => $email['body']['data']['messagingUpdateEmail']['_id'],
            ],
        ];
        $message = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(1, $message['body']['data']['messagingGetMessage']['deliveredTotal']);
        $this->assertEquals(0, \count($message['body']['data']['messagingGetMessage']['deliveryErrors']));
    }

    public function testSendSMS()
    {
        if (empty(System::getEnv('_APP_MESSAGE_SMS_TEST_DSN'))) {
            $this->markTestSkipped('SMS DSN not provided');
        }

        $smsDSN = new DSN(System::getEnv('_APP_MESSAGE_SMS_TEST_DSN'));
        $to = $smsDSN->getParam('to');
        $from = $smsDSN->getParam('from');
        $authKey = $smsDSN->getPassword();
        $senderId = $smsDSN->getUser();

        if (empty($to) || empty($from) || empty($senderId) || empty($authKey)) {
            $this->markTestSkipped('SMS provider not configured');
        }

        $query = $this->getQuery(self::$CREATE_MSG91_PROVIDER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'providerId' => ID::unique(),
                'name' => 'Msg91-1',
                'senderId' => $senderId,
                'authKey' => $authKey,
                'from' => $from,
            ],
        ];
        $provider = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $provider['headers']['status-code']);

        $providerId = $provider['body']['data']['messagingCreateMsg91Provider']['_id'];

        $query = $this->getQuery(self::$CREATE_TOPIC);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'topicId' => ID::unique(),
                'name' => 'topic1',
            ],
        ];
        $topic = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $topic['headers']['status-code']);

        $query = $this->getQuery(self::$CREATE_USER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => ID::unique(),
                'email' => 'random3-email@mail.org',
                'password' => 'password',
                'name' => 'Messaging User',
            ]
        ];
        $user = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $user['headers']['status-code']);

        $query = $this->getQuery(self::$CREATE_USER_TARGET);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'targetId' => ID::unique(),
                'providerType' => 'sms',
                'userId' => $user['body']['data']['usersCreate']['_id'],
                'providerId' => $providerId,
                'identifier' => $to,
            ],
        ];
        $target = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $target['headers']['status-code']);

        $query = $this->getQuery(self::$CREATE_SUBSCRIBER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'subscriberId' => ID::unique(),
                'topicId' => $topic['body']['data']['messagingCreateTopic']['_id'],
                'targetId' => $target['body']['data']['usersCreateTarget']['_id'],
            ],
        ];
        $subscriber = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertEquals(200, $subscriber['headers']['status-code']);

        $query = $this->getQuery(self::$CREATE_SMS);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'messageId' => ID::unique(),
                'topics' => [$topic['body']['data']['messagingCreateTopic']['_id']],
                'content' => '454665',
            ],
        ];
        $sms = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $sms['headers']['status-code']);

        \sleep(5);

        $query = $this->getQuery(self::$GET_MESSAGE);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'messageId' => $sms['body']['data']['messagingCreateSMS']['_id'],
            ],
        ];
        $message = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(1, $message['body']['data']['messagingGetMessage']['deliveredTotal']);
        $this->assertEquals(0, \count($message['body']['data']['messagingGetMessage']['deliveryErrors']));
        return $message['body']['data']['messagingGetMessage'];
    }

    /**
     * @depends testSendSMS
     */
    public function testUpdateSMS(array $sms)
    {
        $query = $this->getQuery(self::$CREATE_SMS);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'messageId' => ID::unique(),
                'status' => 'draft',
                'topics' => [$sms['topics'][0]],
                'content' => '345463',
            ],
        ];
        $sms = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $sms['headers']['status-code']);

        $query = $this->getQuery(self::$UPDATE_SMS);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'messageId' => $sms['body']['data']['messagingCreateSMS']['_id'],
                'status' => 'processing',
            ],
        ];
        $sms = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $sms['headers']['status-code']);

        \sleep(5);

        $query = $this->getQuery(self::$GET_MESSAGE);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'messageId' => $sms['body']['data']['messagingUpdateSMS']['_id'],
            ],
        ];
        $message = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(1, $message['body']['data']['messagingGetMessage']['deliveredTotal']);
        $this->assertEquals(0, \count($message['body']['data']['messagingGetMessage']['deliveryErrors']));
    }

    public function testSendPushNotification()
    {
        if (empty(System::getEnv('_APP_MESSAGE_PUSH_TEST_DSN'))) {
            $this->markTestSkipped('Push DSN empty');
        }

        $pushDSN = new DSN(System::getEnv('_APP_MESSAGE_PUSH_TEST_DSN'));
        $to = $pushDSN->getParam('to');
        $serviceAccountJSON = $pushDSN->getParam('serviceAccountJSON');

        if (empty($to) || empty($serviceAccountJSON)) {
            $this->markTestSkipped('Push provider not configured');
        }

        $query = $this->getQuery(self::$CREATE_FCM_PROVIDER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'providerId' => ID::unique(),
                'name' => 'FCM1',
                'serviceAccountJSON' => [
                    'type' => 'service_account',
                    "project_id" => "test-project",
                    "private_key_id" => "test-private-key-id",
                    "private_key" => "test-private-key",
                ]
            ],
        ];
        $provider = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $provider['headers']['status-code']);

        $providerId = $provider['body']['data']['messagingCreateFcmProvider']['_id'];

        $query = $this->getQuery(self::$CREATE_TOPIC);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'topicId' => ID::unique(),
                'name' => 'topic1',
            ],
        ];
        $topic = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $topic['headers']['status-code']);

        $query = $this->getQuery(self::$CREATE_USER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => ID::unique(),
                'email' => 'random5-mail@mail.org',
                'password' => 'password',
                'name' => 'Messaging User',
            ]
        ];
        $user = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $user['headers']['status-code']);

        $query = $this->getQuery(self::$CREATE_USER_TARGET);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'targetId' => ID::unique(),
                'providerType' => 'push',
                'userId' => $user['body']['data']['usersCreate']['_id'],
                'providerId' => $providerId,
                'identifier' => $to,
            ],
        ];
        $target = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $target['headers']['status-code']);

        $query = $this->getQuery(self::$CREATE_SUBSCRIBER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'subscriberId' => ID::unique(),
                'topicId' => $topic['body']['data']['messagingCreateTopic']['_id'],
                'targetId' => $target['body']['data']['usersCreateTarget']['_id'],
            ],
        ];
        $subscriber = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertEquals(200, $subscriber['headers']['status-code']);

        $query = $this->getQuery(self::$CREATE_PUSH_NOTIFICATION);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'messageId' => ID::unique(),
                'topics' => [$topic['body']['data']['messagingCreateTopic']['_id']],
                'title' => 'Push Notification Title',
                'body' => 'Push Notifiaction Body',
            ],
        ];
        $push = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $push['headers']['status-code']);

        \sleep(5);

        $query = $this->getQuery(self::$GET_MESSAGE);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'messageId' => $push['body']['data']['messagingCreatePushNotification']['_id'],
            ],
        ];
        $message = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(1, $message['body']['data']['messagingGetMessage']['deliveredTotal']);
        $this->assertEquals(0, \count($message['body']['data']['messagingGetMessage']['deliveryErrors']));

        return $message['body']['data']['messagingGetMessage'];
    }

    /**
     * @depends testSendPushNotification
     */
    public function testUpdatePushNotification(array $push)
    {
        $query = $this->getQuery(self::$CREATE_PUSH_NOTIFICATION);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'messageId' => ID::unique(),
                'status' => 'draft',
                'topics' => [$push['topics'][0]],
                'title' => 'Push Notification Title',
                'body' => 'Push Notifiaction Body',
            ],
        ];
        $push = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $push['headers']['status-code']);

        $query = $this->getQuery(self::$UPDATE_PUSH_NOTIFICATION);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'messageId' => $push['body']['data']['messagingCreatePushNotification']['_id'],
                'status' => 'processing',
            ],
        ];
        $push = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $push['headers']['status-code']);

        \sleep(5);

        $query = $this->getQuery(self::$GET_MESSAGE);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'messageId' => $push['body']['data']['messagingUpdatePushNotification']['_id'],
            ],
        ];
        $message = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(1, $message['body']['data']['messagingGetMessage']['deliveredTotal']);
        $this->assertEquals(0, \count($message['body']['data']['messagingGetMessage']['deliveryErrors']));
    }
}
