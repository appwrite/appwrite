<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
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
                'id' => $providers[0]['_id'],
                'name' => 'Sengrid2',
                'apiKey' => 'my-apikey',
            ],
            'Mailgun' => [
                'id' => $providers[1]['_id'],
                'name' => 'Mailgun2',
                'apiKey' => 'my-apikey',
                'domain' => 'my-domain',
            ],
            'Twilio' => [
                'id' => $providers[2]['_id'],
                'name' => 'Twilio2',
                'accountSid' => 'my-accountSid',
                'authToken' => 'my-authToken',
            ],
            'Telesign' => [
                'id' => $providers[3]['_id'],
                'name' => 'Telesign2',
                'username' => 'my-username',
                'password' => 'my-password',
            ],
            'Textmagic' => [
                'id' => $providers[4]['_id'],
                'name' => 'Textmagic2',
                'username' => 'my-username',
                'apiKey' => 'my-apikey',
            ],
            'Msg91' => [
                'id' => $providers[5]['_id'],
                'name' => 'Ms91-2',
                'senderId' => 'my-senderid',
                'authKey' => 'my-authkey',
            ],
            'Vonage' => [
                'id' => $providers[6]['_id'],
                'name' => 'Vonage2',
                'apiKey' => 'my-apikey',
                'apiSecret' => 'my-apisecret',
            ],
            'Fcm' => [
                'id' => $providers[7]['_id'],
                'name' => 'FCM2',
                'serverKey' => 'my-serverkey',
            ],
            'Apns' => [
                'id' => $providers[8]['_id'],
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
                'id' => $providers[1]['_id'],
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
                'id' => $providers[0]['_id'],
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
                    'id' => $provider['_id'],
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
}
