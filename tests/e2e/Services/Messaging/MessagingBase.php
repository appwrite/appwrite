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
    /**
     * Static caches for test data
     */
    private static array $createdProviders = [];
    private static array $updatedProviders = [];
    private static array $createdTopics = [];
    private static array $updatedTopicId = [];
    private static array $subscriberData = [];
    private static array $draftEmailMessage = [];
    private static array $sentEmailData = [];
    private static array $sentSmsData = [];
    private static array $sentPushData = [];

    /**
     * Helper to get or create providers
     */
    protected function setupCreatedProviders(): array
    {
        $cacheKey = $this->getProject()['$id'];
        if (!empty(self::$createdProviders[$cacheKey])) {
            return self::$createdProviders[$cacheKey];
        }

        $providersParams = [
            'sendgrid' => [
                'providerId' => ID::unique(),
                'name' => 'Sengrid1',
                'apiKey' => 'my-apikey',
                'from' => 'sender-email@my-domain.com',
            ],
            'resend' => [
                'providerId' => ID::unique(),
                'name' => 'Resend1',
                'apiKey' => 'my-apikey',
                'fromName' => 'Sender Name',
                'fromEmail' => 'sender-email@my-domain.com',
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
                'templateId' => '123456'
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

            $providers[] = $response['body'];
        }

        self::$createdProviders[$cacheKey] = $providers;
        return $providers;
    }

    /**
     * Helper to get or create updated providers
     */
    protected function setupUpdatedProviders(): array
    {
        $cacheKey = $this->getProject()['$id'];
        if (!empty(self::$updatedProviders[$cacheKey])) {
            return self::$updatedProviders[$cacheKey];
        }

        $providers = $this->setupCreatedProviders();

        $providersParams = [
            'sendgrid' => [
                'name' => 'Sengrid2',
                'apiKey' => 'my-apikey',
            ],
            'resend' => [
                'name' => 'Resend2',
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

            $providers[$index] = $response['body'];
        }

        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/providers/mailgun/' . $providers[2]['$id'], [
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

        $providers[2] = $response['body'];

        self::$updatedProviders[$cacheKey] = $providers;
        return $providers;
    }

    /**
     * Helper to get or create topics
     */
    protected function setupCreatedTopics(): array
    {
        $cacheKey = $this->getProject()['$id'];
        if (!empty(self::$createdTopics[$cacheKey])) {
            return self::$createdTopics[$cacheKey];
        }

        $response1 = $this->client->call(Client::METHOD_POST, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'topicId' => ID::unique(),
            'name' => 'my-app',
        ]);

        $response2 = $this->client->call(Client::METHOD_POST, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'topicId' => ID::unique(),
            'name' => 'my-app2',
            'subscribe' => [Role::user('invalid')->toString()],
        ]);

        self::$createdTopics[$cacheKey] = [
            'public' => $response1['body'],
            'private' => $response2['body'],
        ];

        return self::$createdTopics[$cacheKey];
    }

    /**
     * Helper to get or create updated topic ID
     */
    protected function setupUpdatedTopicId(): string
    {
        $cacheKey = $this->getProject()['$id'];
        if (!empty(self::$updatedTopicId[$cacheKey])) {
            return self::$updatedTopicId[$cacheKey];
        }

        $topics = $this->setupCreatedTopics();

        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/topics/' . $topics['public']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'name' => 'android-app',
        ]);

        $this->client->call(Client::METHOD_PATCH, '/messaging/topics/' . $topics['private']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'name' => 'ios-app',
            'subscribe' => [Role::user('some-user')->toString()],
        ]);

        self::$updatedTopicId[$cacheKey] = $response['body']['$id'];
        return self::$updatedTopicId[$cacheKey];
    }

    /**
     * Helper to get or create subscriber data
     */
    protected function setupSubscriberData(): array
    {
        $cacheKey = $this->getProject()['$id'];
        if (!empty(self::$subscriberData[$cacheKey])) {
            return self::$subscriberData[$cacheKey];
        }

        $topics = $this->setupCreatedTopics();
        // Ensure topics are updated (to get 'android-app' name)
        $this->setupUpdatedTopicId();

        $userId = $this->getUser()['$id'];

        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/sendgrid', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'providerId' => ID::unique(),
            'name' => 'Sendgrid-subscriber-test',
            'apiKey' => 'my-apikey',
            'from' => 'sender-email@my-domain.com',
        ]);

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

        $response = $this->client->call(Client::METHOD_POST, '/messaging/topics/' . $topics['public']['$id'] . '/subscribers', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'subscriberId' => ID::unique(),
            'targetId' => $target['body']['$id'],
        ]);

        self::$subscriberData[$cacheKey] = [
            'topicId' => $topics['public']['$id'],
            'targetId' => $target['body']['$id'],
            'userId' => $target['body']['userId'],
            'subscriberId' => $response['body']['$id'],
            'identifier' => $target['body']['identifier'],
            'providerType' => $target['body']['providerType'],
        ];

        return self::$subscriberData[$cacheKey];
    }

    /**
     * Helper to get or create draft email message
     */
    protected function setupDraftEmailMessage(): array
    {
        $cacheKey = $this->getProject()['$id'];
        if (!empty(self::$draftEmailMessage[$cacheKey])) {
            return self::$draftEmailMessage[$cacheKey];
        }

        // Create User 1
        $response = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => uniqid() . "@example.com",
            'password' => 'password',
            'name' => 'Messaging User Draft 1',
        ]);

        $user1 = $response['body'];
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
            'name' => 'Messaging User Draft 2',
        ]);

        $user2 = $response['body'];
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

        self::$draftEmailMessage[$cacheKey] = $response['body'];
        return self::$draftEmailMessage[$cacheKey];
    }

    /**
     * Helper to get or create sent email data
     */
    protected function setupSentEmailData(): array
    {
        if (empty(System::getEnv('_APP_MESSAGE_EMAIL_TEST_DSN'))) {
            return [];
        }

        $cacheKey = $this->getProject()['$id'];
        if (!empty(self::$sentEmailData[$cacheKey])) {
            return self::$sentEmailData[$cacheKey];
        }

        $emailDSN = new DSN(System::getEnv('_APP_MESSAGE_EMAIL_TEST_DSN'));
        $to = $emailDSN->getParam('to');
        $fromName = $emailDSN->getParam('fromName');
        $fromEmail = $emailDSN->getParam('fromEmail');
        $apiKey = $emailDSN->getPassword();

        if (empty($to) || empty($apiKey)) {
            return [];
        }

        // Create provider
        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/sendgrid', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'providerId' => ID::unique(),
            'name' => 'Sendgrid-provider-sent',
            'apiKey' => $apiKey,
            'fromName' => $fromName,
            'fromEmail' => $fromEmail,
            'enabled' => true,
        ]);

        // Create Topic
        $topic = $this->client->call(Client::METHOD_POST, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'topicId' => ID::unique(),
            'name' => 'topic-sent-email',
        ]);

        // Create User
        $user = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => $to,
            'password' => 'password',
            'name' => 'Messaging User Sent',
        ]);

        // Get target
        $target = $user['body']['targets'][0];

        // Create Subscriber
        $this->client->call(Client::METHOD_POST, '/messaging/topics/' . $topic['body']['$id'] . '/subscribers', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'subscriberId' => ID::unique(),
            'targetId' => $target['$id'],
        ]);

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

        \sleep(2);

        self::$sentEmailData[$cacheKey] = [
            'message' => $email['body'],
            'topic' => $topic['body'],
        ];

        return self::$sentEmailData[$cacheKey];
    }

    /**
     * Helper to get or create sent SMS data
     */
    protected function setupSentSmsData(): array
    {
        if (empty(System::getEnv('_APP_MESSAGE_SMS_TEST_DSN'))) {
            return [];
        }

        $cacheKey = $this->getProject()['$id'];
        if (!empty(self::$sentSmsData[$cacheKey])) {
            return self::$sentSmsData[$cacheKey];
        }

        $smsDSN = new DSN(System::getEnv('_APP_MESSAGE_SMS_TEST_DSN'));
        $to = $smsDSN->getParam('to');
        $senderId = $smsDSN->getUser();
        $authKey = $smsDSN->getPassword();
        $templateId = $smsDSN->getParam('templateId');

        if (empty($to) || empty($senderId) || empty($authKey)) {
            return [];
        }

        // Create provider
        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/msg91', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'providerId' => ID::unique(),
            'name' => 'Msg91Sender-sent',
            'senderId' => $senderId,
            'authKey' => $authKey,
            'templateId' => $templateId,
            'enabled' => true,
        ]);

        // Create Topic
        $topic = $this->client->call(Client::METHOD_POST, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'topicId' => ID::unique(),
            'name' => 'topic-sent-sms',
        ]);

        // Create User
        $user = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => 'random-sms-sent@mail.org',
            'password' => 'password',
            'name' => 'Messaging User SMS Sent',
        ]);

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

        // Create Subscriber
        $this->client->call(Client::METHOD_POST, '/messaging/topics/' . $topic['body']['$id'] . '/subscribers', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'subscriberId' => ID::unique(),
            'targetId' => $target['body']['$id'],
        ]);

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

        \sleep(5);

        self::$sentSmsData[$cacheKey] = $sms['body'];

        return self::$sentSmsData[$cacheKey];
    }

    /**
     * Helper to get or create sent push notification data
     */
    protected function setupSentPushData(): array
    {
        if (empty(System::getEnv('_APP_MESSAGE_PUSH_TEST_DSN'))) {
            return [];
        }

        $cacheKey = $this->getProject()['$id'];
        if (!empty(self::$sentPushData[$cacheKey])) {
            return self::$sentPushData[$cacheKey];
        }

        $dsn = new DSN(System::getEnv('_APP_MESSAGE_PUSH_TEST_DSN'));
        $to = $dsn->getParam('to');
        $serviceAccountJSON = $dsn->getParam('serviceAccountJSON');

        if (empty($to) || empty($serviceAccountJSON)) {
            return [];
        }

        // Create provider
        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/fcm', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'providerId' => ID::unique(),
            'name' => 'FCM-sent',
            'serviceAccountJSON' => $serviceAccountJSON,
            'enabled' => true,
        ]);

        // Create Topic
        $topic = $this->client->call(Client::METHOD_POST, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'topicId' => ID::unique(),
            'name' => 'topic-sent-push',
        ]);

        // Create User
        $user = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => 'random-push-sent@mail.org',
            'password' => 'password',
            'name' => 'Messaging User Push Sent',
        ]);

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

        // Create Subscriber
        $this->client->call(Client::METHOD_POST, '/messaging/topics/' . $topic['body']['$id'] . '/subscribers', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'subscriberId' => ID::unique(),
            'targetId' => $target['body']['$id'],
        ]);

        // Create push notification
        $push = $this->client->call(Client::METHOD_POST, '/messaging/messages/push', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'topics' => [$topic['body']['$id']],
            'title' => 'Test-Notification-Sent',
            'body' => 'Test-Notification-Body-Sent',
        ]);

        \sleep(5);

        self::$sentPushData[$cacheKey] = $push['body'];

        return self::$sentPushData[$cacheKey];
    }

    public function testCreateProviders(): void
    {
        $providersParams = [
            'sendgrid' => [
                'providerId' => ID::unique(),
                'name' => 'Sengrid1',
                'apiKey' => 'my-apikey',
                'from' => 'sender-email@my-domain.com',
            ],
            'resend' => [
                'providerId' => ID::unique(),
                'name' => 'Resend1',
                'apiKey' => 'my-apikey',
                'fromName' => 'Sender Name',
                'fromEmail' => 'sender-email@my-domain.com',
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
                'templateId' => '123456'
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

        foreach ($providersParams as $key => $params) {
            $response = $this->client->call(Client::METHOD_POST, '/messaging/providers/' . $key, \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]), $params);

            $this->assertEquals(201, $response['headers']['status-code']);
            $this->assertEquals($params['name'], $response['body']['name']);

            switch ($key) {
                case 'apns':
                    $this->assertEquals(false, $response['body']['options']['sandbox']);
                    break;
            }
        }
    }

    public function testUpdateProviders(): void
    {
        $providers = $this->setupCreatedProviders();

        $providersParams = [
            'sendgrid' => [
                'name' => 'Sengrid2',
                'apiKey' => 'my-apikey',
            ],
            'resend' => [
                'name' => 'Resend2',
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
        }

        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/providers/mailgun/' . $providers[2]['$id'], [
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

    public function testListProviders(): array
    {
        $providers = $this->setupUpdatedProviders();

        $response = $this->client->call(Client::METHOD_GET, '/messaging/providers/', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        // Count may vary due to other tests creating providers
        $this->assertGreaterThanOrEqual(11, \count($response['body']['providers']));

        return $providers;
    }

    public function testGetProvider(): void
    {
        $providers = $this->setupUpdatedProviders();

        $response = $this->client->call(Client::METHOD_GET, '/messaging/providers/' . $providers[0]['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($providers[0]['name'], $response['body']['name']);
    }

    public function testDeleteProvider(): void
    {
        // Create fresh providers for deletion test to avoid affecting other tests
        $providersParams = [
            'sendgrid' => [
                'providerId' => ID::unique(),
                'name' => 'Sengrid-delete',
                'apiKey' => 'my-apikey',
                'from' => 'sender-email@my-domain.com',
            ],
        ];

        foreach ($providersParams as $key => $params) {
            $response = $this->client->call(Client::METHOD_POST, '/messaging/providers/' . $key, \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]), $params);

            $this->assertEquals(201, $response['headers']['status-code']);

            $deleteResponse = $this->client->call(Client::METHOD_DELETE, '/messaging/providers/' . $response['body']['$id'], [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals(204, $deleteResponse['headers']['status-code']);
        }
    }

    public function testCreateTopic(): void
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
    }

    public function testUpdateTopic(): void
    {
        $topics = $this->setupCreatedTopics();

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
    }

    public function testListTopic(): string
    {
        $topicId = $this->setupUpdatedTopicId();

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
        $this->assertGreaterThanOrEqual(2, \count($response['body']['topics']));

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

    public function testGetTopic(): void
    {
        $topicId = $this->setupUpdatedTopicId();

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

    public function testCreateSubscriber(): void
    {
        $topics = $this->setupCreatedTopics();
        // Ensure topics are updated first
        $this->setupUpdatedTopicId();

        $userId = $this->getUser()['$id'];

        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/sendgrid', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'providerId' => ID::unique(),
            'name' => 'Sendgrid-create-sub',
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
            'identifier' => 'random-email-create-sub@mail.org',
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
        $this->assertGreaterThanOrEqual(1, $topic['body']['emailTotal']);
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
    }

    public function testSubscriberTargetSubQuery()
    {
        $response = $this->client->call(Client::METHOD_POST, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'topicId' => 'sub-query-test',
            'name' => 'sub-query-test',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $topic = $response['body'];

        $prefix = uniqid();

        for ($i = 1; $i <= 101; $i++) {
            $response = $this->client->call(Client::METHOD_POST, '/users', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], [
                'userId' => "$prefix-$i",
                'email' => "$prefix-$i@example.com",
                'password' => 'password',
                'name' => "User $prefix $i",
            ]);

            $this->assertEquals(201, $response['headers']['status-code']);
            $user = $response['body'];
            $targets = $user['targets'] ?? [];

            $this->assertGreaterThan(0, count($targets));

            $target = $targets[0];

            $response = $this->client->call(Client::METHOD_POST, '/messaging/topics/' . $topic['$id'] . '/subscribers', \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'subscriberId' => $user['$id'],
                'targetId' => $target['$id'],
            ]);

            $this->assertEquals(201, $response['headers']['status-code']);
        }
    }

    public function testGetSubscriber(): void
    {
        $data = $this->setupSubscriberData();

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

    public function testListSubscribers(): void
    {
        $data = $this->setupSubscriberData();

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
        $this->assertGreaterThanOrEqual(1, $response['body']['total']);
        // Find our subscriber by ID (may not be first in parallel execution)
        $ourSubscriber = null;
        foreach ($response['body']['subscribers'] as $subscriber) {
            if ($subscriber['$id'] === $subscriberId) {
                $ourSubscriber = $subscriber;
                break;
            }
        }
        $this->assertNotNull($ourSubscriber, 'Created subscriber should exist in subscriber list');
        $this->assertEquals($userId, $ourSubscriber['target']['userId']);
        $this->assertEquals($providerType, $ourSubscriber['target']['providerType']);
        $this->assertEquals($identifier, $ourSubscriber['target']['identifier']);
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
            $this->assertGreaterThanOrEqual(1, $response['body']['total']);
        }

        /**
         * Test for SUCCESS with total=false
         */
        $subscribersWithIncludeTotalFalse = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $data['topicId'] . '/subscribers', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'total' => false
        ]);

        $this->assertEquals(200, $subscribersWithIncludeTotalFalse['headers']['status-code']);
        $this->assertIsArray($subscribersWithIncludeTotalFalse['body']);
        $this->assertIsArray($subscribersWithIncludeTotalFalse['body']['subscribers']);
        $this->assertIsInt($subscribersWithIncludeTotalFalse['body']['total']);
        $this->assertEquals(0, $subscribersWithIncludeTotalFalse['body']['total']);
        $this->assertGreaterThan(0, count($subscribersWithIncludeTotalFalse['body']['subscribers']));
    }

    public function testGetSubscriberLogs(): void
    {
        $data = $this->setupSubscriberData();
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

    public function testDeleteSubscriber(): void
    {
        // Create fresh resources for deletion test
        $topics = $this->setupCreatedTopics();
        $this->setupUpdatedTopicId();

        $userId = $this->getUser()['$id'];

        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/sendgrid', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'providerId' => ID::unique(),
            'name' => 'Sendgrid-delete-sub',
            'apiKey' => 'my-apikey',
            'from' => 'sender-email@my-domain.com',
        ]);

        $target = $this->client->call(Client::METHOD_POST, '/users/' . $userId . '/targets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'targetId' => ID::unique(),
            'providerType' => 'email',
            'providerId' => $provider['body']['$id'],
            'identifier' => 'random-email-delete@mail.org',
        ]);

        $subscriber = $this->client->call(Client::METHOD_POST, '/messaging/topics/' . $topics['public']['$id'] . '/subscribers', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'subscriberId' => ID::unique(),
            'targetId' => $target['body']['$id'],
        ]);

        $response = $this->client->call(Client::METHOD_DELETE, '/messaging/topics/' . $topics['public']['$id'] . '/subscribers/' . $subscriber['body']['$id'], \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);

        $topic = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $topics['public']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $topic['headers']['status-code']);
        $this->assertEquals('android-app', $topic['body']['name']);
    }

    public function testDeleteTopic(): void
    {
        // Create a fresh topic for deletion
        $response = $this->client->call(Client::METHOD_POST, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'topicId' => ID::unique(),
            'name' => 'topic-to-delete',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $deleteResponse = $this->client->call(Client::METHOD_DELETE, '/messaging/topics/' . $response['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(204, $deleteResponse['headers']['status-code']);
    }

    public function testListTargets(): void
    {
        $message = $this->setupDraftEmailMessage();

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

        $emptyMessage = $response['body'];

        $response = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $emptyMessage['$id'] . '/targets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $targetList = $response['body'];
        $this->assertEquals(0, $targetList['total']);
        $this->assertEquals(0, count($targetList['targets']));
    }

    public function testCreateDraftEmail(): void
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
    }

    public function testCreateDraftPushWithImage(): void
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

        $messageId = $message['body']['$id'];
        // Use longer timeout for CI stability as scheduler interval may vary
        $this->assertEventually(function () use ($messageId) {
            $message = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $messageId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            $this->assertEquals(200, $message['headers']['status-code']);
            $this->assertEquals(MessageStatus::FAILED, $message['body']['status']);
        }, 180000, 1000);
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

        $messageId = $message['body']['$id'];
        // Use longer timeout for CI stability as scheduler interval may vary
        $this->assertEventually(function () use ($messageId) {
            $message = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $messageId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            $this->assertEquals(200, $message['headers']['status-code']);
            $this->assertEquals(MessageStatus::FAILED, $message['body']['status']);
        }, 180000, 1000);
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

        $messageId = $message['body']['$id'];

        // Wait 8 seconds - message should still be scheduled (scheduled for 10 seconds)
        \sleep(8);

        $message = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $messageId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(MessageStatus::SCHEDULED, $message['body']['status']);

        // Wait for message to be processed and fail
        // Use longer timeout for CI stability as scheduler interval may vary
        $this->assertEventually(function () use ($messageId) {
            $message = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $messageId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            $this->assertEquals(200, $message['headers']['status-code']);
            $this->assertEquals(MessageStatus::FAILED, $message['body']['status']);
        }, 180000, 1000);
    }

    public function testSendEmail(): array
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

    public function testUpdateEmail(): void
    {
        $params = $this->setupSentEmailData();

        if (empty($params)) {
            $this->markTestSkipped('Email DSN not provided');
        }

        $email = $params['message'];

        $message = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/email/' . $email['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        // Test failure as the message has already been sent.
        $this->assertEquals(400, $message['headers']['status-code']);

        // Create Email
        $newEmail = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'draft' => true,
            'topics' => [$email['topics'][0]],
            'subject' => 'Khali beats Undertaker',
            'content' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);

        $this->assertEquals(201, $newEmail['headers']['status-code']);

        $updatedEmail = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/email/' . $newEmail['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'draft' => false,
        ]);

        $this->assertEquals(200, $updatedEmail['headers']['status-code']);

        \sleep(5);

        $message = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $updatedEmail['body']['$id'], [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(1, $message['body']['deliveredTotal']);
        $this->assertEquals(0, \count($message['body']['deliveryErrors']));
    }

    public function testSendSMS(): void
    {
        if (empty(System::getEnv('_APP_MESSAGE_SMS_TEST_DSN'))) {
            $this->markTestSkipped('SMS DSN not provided');
        }

        $smsDSN = new DSN(System::getEnv('_APP_MESSAGE_SMS_TEST_DSN'));
        $to = $smsDSN->getParam('to');
        $senderId = $smsDSN->getUser();
        $authKey = $smsDSN->getPassword();
        $templateId = $smsDSN->getParam('templateId');

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
            'templateId' => $templateId,
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
    }

    public function testUpdateSMS(): void
    {
        $sms = $this->setupSentSmsData();

        if (empty($sms)) {
            $this->markTestSkipped('SMS DSN not provided');
        }

        $message = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/sms/' . $sms['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        // Test failure as the message has already been sent.
        $this->assertEquals(400, $message['headers']['status-code']);

        // Create SMS
        $newSms = $this->client->call(Client::METHOD_POST, '/messaging/messages/sms', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'draft' => true,
            'topics' => [$sms['topics'][0]],
            'content' => 'Your OTP code is 123456',
        ]);

        $this->assertEquals(201, $newSms['headers']['status-code']);

        $updatedSms = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/sms/' . $newSms['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'draft' => false,
        ]);

        $this->assertEquals(200, $updatedSms['headers']['status-code']);

        \sleep(2);

        $message = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $updatedSms['body']['$id'], [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $message['headers']['status-code']);
        $this->assertEquals(1, $message['body']['deliveredTotal']);
        $this->assertEquals(0, \count($message['body']['deliveryErrors']));
    }

    public function testSendPushNotification(): void
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
    }

    public function testUpdatePushNotification(): void
    {
        $push = $this->setupSentPushData();

        if (empty($push)) {
            $this->markTestSkipped('Push DSN not provided');
        }

        $message = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/push/' . $push['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        // Test failure as the message has already been sent.
        $this->assertEquals(400, $message['headers']['status-code']);

        // Create push notification
        $newPush = $this->client->call(Client::METHOD_POST, '/messaging/messages/push', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'draft' => true,
            'topics' => [$push['topics'][0]],
            'title' => 'Test-Notification',
            'body' => 'Test-Notification-Body',
        ]);

        $this->assertEquals(201, $newPush['headers']['status-code']);

        $updatedPush = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/push/' . $newPush['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'draft' => false,
        ]);

        $this->assertEquals(200, $updatedPush['headers']['status-code']);

        \sleep(5);

        $message = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $updatedPush['body']['$id'], [
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
     * @return void
     * @throws \Exception
     */
    public function testDeleteMessage(): void
    {
        $params = $this->setupSentEmailData();

        if (empty($params)) {
            $this->markTestSkipped('Email DSN not provided');
        }

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
