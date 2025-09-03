<?php

namespace Tests\E2E\Services\Realtime;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Tests\E2E\Services\Functions\FunctionsBase;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class RealtimeConsoleClientTest extends Scope
{
    use FunctionsBase;
    use RealtimeBase;
    use ProjectCustom;
    use SideConsole;

    public function testManualAuthentication(): void
    {
        $user = $this->getUser();
        $userId = $user['$id'] ?? '';
        $session = $user['session'] ?? '';

        /**
         * Test for SUCCESS
         */
        $client = $this->getWebsocket(['account'], [
            'origin' => 'http://localhost'
        ]);
        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('account', $response['data']['channels']);

        $client->send(\json_encode([
            'type' => 'authentication',
            'data' => [
                'session' => $session
            ]
        ]));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('response', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertEquals('authentication', $response['data']['to']);
        $this->assertTrue($response['data']['success']);
        $this->assertNotEmpty($response['data']['user']);
        $this->assertEquals($userId, $response['data']['user']['$id']);

        /**
         * Test for FAILURE
         */
        $client->send(\json_encode([
            'type' => 'authentication',
            'data' => [
                'session' => 'invalid_session'
            ]
        ]));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('error', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertEquals(1003, $response['data']['code']);
        $this->assertEquals('Session is not valid.', $response['data']['message']);

        $client->send(\json_encode([
            'type' => 'authentication',
            'data' => []
        ]));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('error', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertEquals(1003, $response['data']['code']);
        $this->assertEquals('Payload is not valid.', $response['data']['message']);

        $client->send(\json_encode([
            'type' => 'unknown',
            'data' => [
                'session' => 'invalid_session'
            ]
        ]));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('error', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertEquals(1003, $response['data']['code']);
        $this->assertEquals('Message type is not valid.', $response['data']['message']);

        $client->send(\json_encode([
            'test' => '123',
        ]));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('error', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertEquals(1003, $response['data']['code']);
        $this->assertEquals('Message format is not valid.', $response['data']['message']);


        $client->close();
    }

    public function testAttributesCollectionsAPI(): array
    {
        $projectId = 'console';

        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ], $projectId);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertNotEmpty($response['data']['user']);

        /**
         * Create database
         */
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'databaseId' => ID::unique(),
            'name' => 'Actors DB',
        ]);

        $databaseId = $database['body']['$id'];
        /**
         * Test Attributes
         */
        $actors = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'collectionId' => ID::unique(),
            'name' => 'Actors',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $actorsId = $actors['body']['$id'];

        $name = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actorsId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $projectId = $this->getProject()['$id'];

        $this->assertEquals(202, $name['headers']['status-code']);
        $this->assertEquals('name', $name['body']['key']);
        $this->assertEquals('string', $name['body']['type']);
        $this->assertEquals(256, $name['body']['size']);
        $this->assertTrue($name['body']['required']);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.attributes.*.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.attributes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.attributes.*.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.attributes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals('processing', $response['data']['payload']['status']);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.attributes.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.attributes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.attributes.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.attributes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals('available', $response['data']['payload']['status']);

        $client->close();

        return ['actorsId' => $actorsId, 'databaseId' => $databaseId];
    }

    public function testAttributesTablesAPI(): array
    {
        $projectId = 'console';

        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ], $projectId);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertNotEmpty($response['data']['user']);

        /**
         * Create database
         */
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'databaseId' => ID::unique(),
            'name' => 'Actors DB',
        ]);

        $databaseId = $database['body']['$id'];

        /**
         * Test Attributes
         */
        $actors = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'tableId' => ID::unique(),
            'name' => 'Actors',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $actorsId = $actors['body']['$id'];

        $name = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $actorsId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $projectId = $this->getProject()['$id'];

        $this->assertEquals(202, $name['headers']['status-code']);
        $this->assertEquals('name', $name['body']['key']);
        $this->assertEquals('string', $name['body']['type']);
        $this->assertEquals(256, $name['body']['size']);
        $this->assertTrue($name['body']['required']);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}.columns.*.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}.columns.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*.columns.*.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*.columns.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals('processing', $response['data']['payload']['status']);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}.columns.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}.columns.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*.columns.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*.columns.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals('available', $response['data']['payload']['status']);

        $client->close();

        return ['actorsId' => $actorsId, 'databaseId' => $databaseId];
    }

    /**
     * @depends testAttributesCollectionsAPI
     */
    public function testIndexesCollectionAPI(array $data)
    {
        $projectId = 'console';
        $actorsId = $data['actorsId'];
        $databaseId = $data['databaseId'];
        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ], $projectId);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertNotEmpty($response['data']['user']);

        /**
         * Test Indexes
         */
        $index = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actorsId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'key_name',
            'type' => 'key',
            'attributes' => [
                'name',
            ],
        ]);

        $this->assertEquals(202, $index['headers']['status-code']);

        $projectId = $this->getProject()['$id'];

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.indexes.*.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.indexes.*.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals('processing', $response['data']['payload']['status']);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.indexes.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.indexes.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals('available', $response['data']['payload']['status']);

        $client->close();

        return $data;
    }

    /**
     * @depends testAttributesTablesAPI
     */
    public function testIndexesTablesAPI(array $data)
    {
        $projectId = 'console';
        $actorsId = $data['actorsId'];
        $databaseId = $data['databaseId'];
        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ], $projectId);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertNotEmpty($response['data']['user']);

        /**
         * Test Indexes
         */
        $index = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $actorsId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'key_name',
            'type' => 'key',
            'columns' => [
                'name',
            ],
        ]);

        $this->assertEquals(202, $index['headers']['status-code']);

        $projectId = $this->getProject()['$id'];

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}.indexes.*.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*.indexes.*.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals('processing', $response['data']['payload']['status']);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}.indexes.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*.indexes.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals('available', $response['data']['payload']['status']);

        $client->close();

        return $data;
    }

    /**
     * @depends testIndexesCollectionAPI
     */
    public function testDeleteIndexCollectionsAPI(array $data)
    {
        $actorsId = $data['actorsId'];
        $projectId = 'console';
        $databaseId = $data['databaseId'];

        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ], $projectId);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertNotEmpty($response['data']['user']);

        $projectId = $this->getProject()['$id'];

        /**
         * Test Delete Index
         */
        $indexKey = 'key_name';
        $attribute = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $actorsId . '/indexes/' . $indexKey, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $attribute['headers']['status-code']);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.indexes.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.indexes.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        /** Delete index generates two events. One from the API and one from the database worker */
        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.indexes.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.indexes.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        $client->close();

        return $data;
    }

    /**
     * @depends testIndexesTablesAPI
     */
    public function testDeleteIndexTablesAPI(array $data)
    {
        $projectId = 'console';
        $actorsId = $data['actorsId'];
        $databaseId = $data['databaseId'];

        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ], $projectId);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertNotEmpty($response['data']['user']);

        $projectId = $this->getProject()['$id'];

        /**
         * Test Delete Index
         */
        $indexKey = 'key_name';
        $attribute = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $actorsId . '/indexes/' . $indexKey, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $attribute['headers']['status-code']);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}.indexes.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*.indexes.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        /** Delete index generates two events. One from the API and one from the database worker */
        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}.indexes.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*.indexes.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        $client->close();

        return $data;
    }

    /**
     * @depends testDeleteIndexCollectionsAPI
     */
    public function testDeleteAttributeCollectionsAPI(array $data)
    {
        $projectId = 'console';
        $actorsId = $data['actorsId'];
        $databaseId = $data['databaseId'];

        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ], $projectId);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertNotEmpty($response['data']['user']);

        $attributeKey = 'name';
        $projectId = $this->getProject()['$id'];

        /**
         * Test Delete Attribute
         */
        $attribute = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $data['actorsId'] . '/attributes/' . $attributeKey, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $attribute['headers']['status-code']);
        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.attributes.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.attributes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.attributes.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.attributes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.attributes.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.attributes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.attributes.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.attributes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        $client->close();
    }

    /**
     * @depends testDeleteIndexTablesAPI
     */
    public function testDeleteAttributeTablesAPI(array $data)
    {
        $projectId = 'console';
        $actorsId = $data['actorsId'];
        $databaseId = $data['databaseId'];

        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ], $projectId);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertNotEmpty($response['data']['user']);

        $attributeKey = 'name';
        $projectId = $this->getProject()['$id'];

        /**
         * Test Delete Attribute
         */
        $attribute = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $data['actorsId'] . '/columns/' . $attributeKey, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $attribute['headers']['status-code']);
        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}.columns.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}.columns.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*.columns.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*.columns.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}.columns.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}.columns.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*.columns.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*.columns.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        $client->close();
    }

    public function testPing()
    {
        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ], 'console');

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertEquals('connected', $response['type']);

        $pong = $this->client->call(Client::METHOD_GET, '/ping', [
            'origin' => 'http://localhost',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $pong['headers']['status-code']);
        $this->assertEquals('Pong!', $pong['body']);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$this->getProject()['$id']}", $response['data']['channels']);
        $this->assertContains("projects.{$this->getProject()['$id']}.ping", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertArrayHasKey('pingCount', $response['data']['payload']);
        $this->assertArrayHasKey('pingedAt', $response['data']['payload']);
        $this->assertEquals(1, $response['data']['payload']['pingCount']);

        $client->close();
    }

    public function testCreateDeployment()
    {
        $response1 = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Test',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'events' => [
                'users.*.create',
                'users.*.delete',
            ],
            'schedule' => '0 0 1 1 *',
            'timeout' => 10
        ]);

        $this->assertEquals(201, $response1['headers']['status-code']);

        $functionId = $response1['body']['$id'];
        $this->assertNotEmpty($functionId);

        $projectId = 'console';

        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ], $projectId);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertNotEmpty($response['data']['user']);

        /**
         * Test Create Deployment
         */
        $projectId = $this->getProject()['$id'];
        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'code' => $this->packageFunction('basic'),
            'activate' => true
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);

        $deploymentId = $deployment['body']['$id'];
        $this->assertNotEmpty($deploymentId);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertEquals("waiting", $response['data']['payload']['status']);
        $this->assertContains("functions.{$functionId}.deployments.{$deploymentId}.create", $response['data']['events']);

        $response = json_decode($client->receive(), true);
        $this->assertContains("functions.{$functionId}.deployments.{$deploymentId}.update", $response['data']['events']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertEquals("processing", $response['data']['payload']['status']);

        $response = json_decode($client->receive(), true);
        $this->assertContains("functions.{$functionId}.deployments.{$deploymentId}.update", $response['data']['events']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertEquals("building", $response['data']['payload']['status']);

        $previousBuildLogs = null;
        while (true) {
            $response = json_decode($client->receive(), true);
            $this->assertContains("functions.{$functionId}.deployments.{$deploymentId}.update", $response['data']['events']);
            $this->assertContains('console', $response['data']['channels']);
            $this->assertContains("projects.{$projectId}", $response['data']['channels']);
            $this->assertArrayHasKey('buildLogs', $response['data']['payload']);

            if (!empty($response['data']['payload']['buildSize'])) {
                $this->assertNotEmpty($response['data']['payload']['buildStartedAt']);
                $this->assertNotEmpty($response['data']['payload']['buildPath']);
                $this->assertNotEmpty($response['data']['payload']['buildSize']);
                $this->assertNotEmpty($response['data']['payload']['totalSize']);
                $this->assertNotEmpty($response['data']['payload']['buildLogs']);
                break;
            }

            // Ignore comparison for first payload
            if ($previousBuildLogs !== null) {
                $this->assertNotEquals($previousBuildLogs, $response['data']['payload']['buildLogs']);
            }

            $previousBuildLogs = $response['data']['payload']['buildLogs'];

            $this->assertEquals('building', $response['data']['payload']['status']);
        }

        $response = json_decode($client->receive(), true);
        $this->assertContains("functions.{$functionId}.deployments.{$deploymentId}.update", $response['data']['events']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertEquals("ready", $response['data']['payload']['status']);

        $response = json_decode($client->receive(), true);
        $this->assertContains("functions.{$functionId}.deployments.{$deploymentId}.update", $response['data']['events']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertNotEmpty($response['data']['payload']['buildDuration']);
        $this->assertNotEmpty($response['data']['payload']['buildEndedAt']);

        $client->close();
    }
}
