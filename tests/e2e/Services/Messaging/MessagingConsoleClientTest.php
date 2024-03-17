<?php

namespace Tests\E2E\Services\Messaging;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;

class MessagingConsoleClientTest extends Scope
{
    use MessagingBase;
    use ProjectCustom;
    use SideConsole;

    /**
     * @depends testListProviders
     */
    public function testGetProviderLogs(array $providers): void
    {
        /**
         * Test for SUCCESS
         */
        $logs = $this->client->call(Client::METHOD_GET, '/messaging/providers/' . $providers[0]['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['total']);

        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/sendgrid/', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'providerId' => ID::unique(),
            'name' => 'Sengrid1',
            'apiKey' => 'my-apikey',
            'from' => 'sender-email@my-domain.com',
        ]);

        $this->assertEquals(201, $provider['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/providers/sendgrid/' . $provider['body']['$id'], \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'sendgrid' => [
                'name' => 'Sendgrid2',
            ]]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $logs = $this->client->call(Client::METHOD_GET, '/messaging/providers/' . $provider['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['total']);
        $this->assertCount(2, $logs['body']['logs']);

        $logs = $this->client->call(Client::METHOD_GET, '/messaging/providers/' . $provider['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertLessThanOrEqual(1, count($logs['body']['logs']));
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/messaging/providers/' . $provider['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/messaging/providers/' . $provider['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
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
        $response = $this->client->call(Client::METHOD_GET, '/messaging/providers/' . $provider['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(-1)->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/providers/' . $provider['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(-1)->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/providers/' . $provider['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('$id', ['asdf'])->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/providers/' . $provider['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderAsc('$id')->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/providers/' . $provider['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                '{ "method": "cursorAsc", "attribute":"$id" }'
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);
    }

    /**
     * @depends testListTopic
     */
    public function testGetTopicLogs(string $topicId): void
    {
        /**
         * Test for SUCCESS
         */
        $logs = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $topicId . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['total']);

        $topic = $this->client->call(Client::METHOD_POST, '/messaging/topics', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'topicId' => ID::unique(),
            'name' => 'my-app',
        ]);
        $this->assertEquals(201, $topic['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/topics/' . $topic['body']['$id'], \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'android-app'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $logs = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $topic['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertCount(2, $logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $topic['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertLessThanOrEqual(1, count($logs['body']['logs']));
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $topic['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $topic['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
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
        $response = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $topic['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(-1)->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $topic['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(-1)->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $topic['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('$id', ['asdf'])->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $topic['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderAsc('$id')->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $topic['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                '{"method":"cursorAsc","attribute":"$id","values":[]}'
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);
    }

    /**
     * @depends testSendEmail
     */
    public function testGetMessageLogs(array $email): void
    {
        /**
         * Test for SUCCESS
         */
        $logs = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $email['body']['$id'] . '/logs', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['total']);

        $email = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'messageId' => ID::unique(),
            'status' => 'draft',
            'topics' => [ID::unique()],
            'subject' => 'Khali beats Undertaker',
            'content' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);

        $this->assertEquals(201, $email['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/email/' . $email['body']['$id'], \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'subject' => 'Khali beats John Cena!',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $logs = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $email['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['total']);
        $this->assertCount(2, $logs['body']['logs']);

        $logs = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $email['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertLessThanOrEqual(1, count($logs['body']['logs']));
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $email['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $email['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
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
        $response = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $email['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(-1)->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $email['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(-1)->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $email['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('$id', ['asdf'])->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $email['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderAsc('$id')->toString(),
            ],
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $email['body']['$id'] . '/logs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                '{"method":"cursorAsc","attribute":"$id","values":[]}'
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);
    }
}
