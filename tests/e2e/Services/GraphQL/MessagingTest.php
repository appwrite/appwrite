<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\App;
use Utopia\Database\Helpers\ID;

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
            ],
            'Mailgun' => [
                'providerId' => ID::unique(),
                'name' => 'Mailgun1',
                'apiKey' => 'my-apikey',
                'domain' => 'my-domain',
                'from' => 'sender-email@my-domain',
            ],
            'Twilio' => [
                'providerId' => ID::unique(),
                'name' => 'Twilio1',
                'accountSid' => 'my-accountSid',
                'authToken' => 'my-authToken',
            ],
            'Telesign' => [
                'providerId' => ID::unique(),
                'name' => 'Telesign1',
                'username' => 'my-username',
                'password' => 'my-password',
            ],
            'Textmagic' => [
                'providerId' => ID::unique(),
                'name' => 'Textmagic1',
                'username' => 'my-username',
                'apiKey' => 'my-apikey',
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
            ],
            'Fcm' => [
                'providerId' => ID::unique(),
                'name' => 'FCM1',
                'serverKey' => 'my-serverkey',
            ],
            'Apns' => [
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
            \array_push($providers, $response['body']['data']['messagingCreate' . $key . 'Provider']);
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
                'username' => 'my-username',
                'password' => 'my-password',
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
                'serverKey' => 'my-serverkey',
            ],
            'Apns' => [
                'providerId' => $providers[8]['_id'],
                'name' => 'APNS2',
                'authKey' => 'my-authkey',
                'authKeyId' => 'my-authkeyid',
                'teamId' => 'my-teamid',
                'bundleId' => 'my-bundleid',
                'endpoint' => 'my-endpoint',
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
        $providerParam = [
            'sendgrid' => [
                'providerId' => ID::unique(),
                'name' => 'Sengrid1',
                'apiKey' => 'my-apikey',
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

        $query = $this->getQuery(self::$CREATE_TOPIC);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'providerId' => $providerId,
                'topicId' => ID::unique(),
                'name' => 'topic1',
                'description' => 'Active users',
            ],
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('topic1', $response['body']['data']['messagingCreateTopic']['name']);
        $this->assertEquals('Active users', $response['body']['data']['messagingCreateTopic']['description']);

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
                'description' => 'Inactive users',
            ],
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('topic2', $response['body']['data']['messagingUpdateTopic']['name']);
        $this->assertEquals('Inactive users', $response['body']['data']['messagingUpdateTopic']['description']);

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
        $this->assertEquals('Inactive users', $response['body']['data']['messagingGetTopic']['description']);
    }

    /**
     * @depends testCreateTopic
     */
    public function testCreateSubscriber(array $topic)
    {
        $topicId = $topic['_id'];

        $userId = $this->getUser()['$id'];

        $query = $this->getQuery(self::$CREATE_USER_TARGET);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'targetId' => ID::unique(),
                'userId' => $userId,
                'providerId' => $topic['providerId'],
                'identifier' => 'token',
            ],
        ];
        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($userId, $response['body']['data']['usersCreateTarget']['userId']);
        $this->assertEquals('token', $response['body']['data']['usersCreateTarget']['identifier']);

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

        return $response['body']['data']['messagingCreateSubscriber'];
    }

    /**
     * @depends testUpdateTopic
     */
    public function testListSubscribers(string $topicId)
    {
        $query = $this->getQuery(self::$LIST_SUBSCRIBERS);
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
        $to = App::getEnv('_APP_MESSAGE_EMAIL_PROVIDER_MAILGUN_RECEIVER_EMAIL');
        $from = App::getEnv('_APP_MESSAGE_EMAIL_PROVIDER_MAILGUN_FROM');
        $apiKey = App::getEnv('_APP_MESSAGE_EMAIL_PROVIDER_MAILGUN_API_KEY');
        $domain = App::getEnv('_APP_MESSAGE_EMAIL_PROVIDER_MAILGUN_DOMAIN');
        $isEuRegion = App::getEnv('_APP_MESSAGE_EMAIL_PROVIDER_MAILGUN_IS_EU_REGION');
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
                'from' => $from,
                'isEuRegion' => $isEuRegion,
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
                'providerId' => $providerId,
                'topicId' => ID::unique(),
                'name' => 'topic1',
                'description' => 'Active users',
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
                'providerId' => $providerId,
                'to' => [$topic['body']['data']['messagingCreateTopic']['_id']],
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
        $this->assertEquals(1, $message['body']['data']['messagingGetMessage']['deliveredTo']);
        $this->assertEquals(0, \count($message['body']['data']['messagingGetMessage']['deliveryErrors']));

        return $message['body']['data']['messagingGetMessage'];
    }

    /**
     * @depends testSendEmail
     */
    public function testUpdateEmail(array $email)
    {
        $to = App::getEnv('_APP_MESSAGE_EMAIL_PROVIDER_MAILGUN_RECEIVER_EMAIL');
        $from = App::getEnv('_APP_MESSAGE_EMAIL_PROVIDER_MAILGUN_FROM');
        $apiKey = App::getEnv('_APP_MESSAGE_EMAIL_PROVIDER_MAILGUN_API_KEY');
        $domain = App::getEnv('_APP_MESSAGE_EMAIL_PROVIDER_MAILGUN_DOMAIN');
        $isEuRegion = App::getEnv('_APP_MESSAGE_EMAIL_PROVIDER_MAILGUN_IS_EU_REGION');
        if (empty($to) || empty($from) || empty($apiKey) || empty($domain) || empty($isEuRegion)) {
            $this->markTestSkipped('Email provider not configured');
        }

        $query = $this->getQuery(self::$CREATE_MAILGUN_PROVIDER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'providerId' => ID::unique(),
                'name' => 'Mailgun2',
                'apiKey' => $apiKey,
                'domain' => $domain,
                'from' => $from,
                'isEuRegion' => $isEuRegion,
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
                'providerId' => $providerId,
                'topicId' => ID::unique(),
                'name' => 'topic1',
                'description' => 'Active users',
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
                'email' => 'random2-mail@mail.org',
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
                'providerId' => $providerId,
                'status' => 'draft',
                'to' => [$topic['body']['data']['messagingCreateTopic']['_id']],
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
        $this->assertEquals(1, $message['body']['data']['messagingGetMessage']['deliveredTo']);
        $this->assertEquals(0, \count($message['body']['data']['messagingGetMessage']['deliveryErrors']));
    }

    public function testSendSMS()
    {
        $to = App::getEnv('_APP_MESSAGE_SMS_PROVIDER_MSG91_TO');
        $from = App::getEnv('_APP_MESSAGE_SMS_PROVIDER_MSG91_FROM');
        $senderId = App::getEnv('_APP_MESSAGE_SMS_PROVIDER_MSG91_SENDER_ID');
        $authKey = App::getEnv('_APP_MESSAGE_SMS_PROVIDER_MSG91_AUTH_KEY');
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
                'providerId' => $providerId,
                'topicId' => ID::unique(),
                'name' => 'topic1',
                'description' => 'Active users',
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
                'providerId' => $providerId,
                'to' => [$topic['body']['data']['messagingCreateTopic']['_id']],
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
        $this->assertEquals(1, $message['body']['data']['messagingGetMessage']['deliveredTo']);
        $this->assertEquals(0, \count($message['body']['data']['messagingGetMessage']['deliveryErrors']));
        return $message['body']['data']['messagingGetMessage'];
    }

    /**
     * @depends testSendSMS
     */
    public function testUpdateSMS(array $sms)
    {
        $to = App::getEnv('_APP_MESSAGE_SMS_PROVIDER_MSG91_TO');
        $from = App::getEnv('_APP_MESSAGE_SMS_PROVIDER_MSG91_FROM');
        $senderId = App::getEnv('_APP_MESSAGE_SMS_PROVIDER_MSG91_SENDER_ID');
        $authKey = App::getEnv('_APP_MESSAGE_SMS_PROVIDER_MSG91_AUTH_KEY');
        if (empty($to) || empty($from) || empty($senderId) || empty($authKey)) {
            $this->markTestSkipped('SMS provider not configured');
        }

        $query = $this->getQuery(self::$CREATE_MSG91_PROVIDER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'providerId' => ID::unique(),
                'name' => 'Msg91-2',
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
                'providerId' => $providerId,
                'topicId' => ID::unique(),
                'name' => 'topic1',
                'description' => 'Active users',
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
                'email' => 'random4-email@mail.org',
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
                'providerId' => $providerId,
                'status' => 'draft',
                'to' => [$topic['body']['data']['messagingCreateTopic']['_id']],
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
        $this->assertEquals(1, $message['body']['data']['messagingGetMessage']['deliveredTo']);
        $this->assertEquals(0, \count($message['body']['data']['messagingGetMessage']['deliveryErrors']));
    }

    public function testSendPushNotification()
    {
        $to = App::getEnv('_APP_MESSAGE_PUSH_PROVIDER_FCM_RECEIVER_TOKEN');
        $serverKey = App::getEnv('_APP_MESSAGE_PUSH_PROVIDER_FCM_SERVERY_KEY');
        if (empty($to) || empty($serverKey)) {
            $this->markTestSkipped('Push provider not configured');
        }

        $query = $this->getQuery(self::$CREATE_FCM_PROVIDER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'providerId' => ID::unique(),
                'name' => 'FCM1',
                'serverKey' => $serverKey,
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
                'providerId' => $providerId,
                'topicId' => ID::unique(),
                'name' => 'topic1',
                'description' => 'Active users',
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
                'providerId' => $providerId,
                'to' => [$topic['body']['data']['messagingCreateTopic']['_id']],
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
        $this->assertEquals(1, $message['body']['data']['messagingGetMessage']['deliveredTo']);
        $this->assertEquals(0, \count($message['body']['data']['messagingGetMessage']['deliveryErrors']));

        return $message['body']['data']['messagingGetMessage'];
    }

    /**
     * @depends testSendPushNotification
     */
    public function testUpdatePushNotification(array $push)
    {
        $to = App::getEnv('_APP_MESSAGE_PUSH_PROVIDER_FCM_RECEIVER_TOKEN');
        $serverKey = App::getEnv('_APP_MESSAGE_PUSH_PROVIDER_FCM_SERVERY_KEY');
        if (empty($to) || empty($serverKey)) {
            $this->markTestSkipped('Push provider not configured');
        }

        $query = $this->getQuery(self::$CREATE_FCM_PROVIDER);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'providerId' => ID::unique(),
                'name' => 'FCM2',
                'serverKey' => $serverKey,
            ],
        ];
        $provider = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $graphQLPayload);

        $this->assertEquals(200, $provider['headers']['status-code']);
        var_dump($provider['body']);
        $providerId = $provider['body']['data']['messagingCreateFcmProvider']['_id'];

        $query = $this->getQuery(self::$CREATE_TOPIC);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'providerId' => $providerId,
                'topicId' => ID::unique(),
                'name' => 'topic1',
                'description' => 'Active users',
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
                'email' => 'random5-email@mail.org',
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
                'providerId' => $providerId,
                'status' => 'draft',
                'to' => [$topic['body']['data']['messagingCreateTopic']['_id']],
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
        $this->assertEquals(1, $message['body']['data']['messagingGetMessage']['deliveredTo']);
        $this->assertEquals(0, \count($message['body']['data']['messagingGetMessage']['deliveryErrors']));
    }
}
