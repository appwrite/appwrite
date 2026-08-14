<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Realtime;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Tests\E2E\Services\Functions\FunctionsBase;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

final class RealtimeConsoleClientTest extends Scope
{
    use FunctionsBase;
    use RealtimeBase;
    use ProjectCustom;
    use SideConsole;

    /**
     * Helper to create database + collection with a string attribute.
     * Used by tests that need an existing collection setup.
     */
    protected function createCollectionWithAttribute(): array
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'databaseId' => ID::unique(),
            'name' => 'Actors DB',
        ]);

        $databaseId = $database['body']['$id'];

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

        // Create attribute and wait for it to be available
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actorsId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        // Wait for attribute to be available
        $this->assertEventually(function () use ($databaseId, $actorsId) {
            $attribute = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $actorsId . '/attributes/name', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertEquals(200, $attribute['headers']['status-code']);
            $this->assertEquals('available', $attribute['body']['status']);
        }, 120000, 500);

        return ['actorsId' => $actorsId, 'databaseId' => $databaseId];
    }

    /**
     * Helper to create database + table with a string column (for TablesDB).
     */
    protected function createTableWithAttribute(): array
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'databaseId' => ID::unique(),
            'name' => 'Actors Tables DB',
        ]);

        $this->assertEquals(201, $database['headers']['status-code'], 'Database creation failed: ' . json_encode($database['body']));
        $databaseId = $database['body']['$id'];

        $actors = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'tableId' => ID::unique(),
            'name' => 'Actors',
        ]);

        $this->assertEquals(201, $actors['headers']['status-code'], 'Table creation failed: ' . json_encode($actors['body']));
        $actorsId = $actors['body']['$id'];

        // Create column and wait for it to be available
        $column = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $actorsId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->assertEquals(202, $column['headers']['status-code'], 'Column creation failed: ' . json_encode($column['body']));

        // Wait for column to be available
        $this->assertEventually(function () use ($databaseId, $actorsId) {
            $column = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $actorsId . '/columns/name', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertEquals(200, $column['headers']['status-code']);
            $this->assertEquals('available', $column['body']['status']);
        }, 120000, 500);

        return ['actorsId' => $actorsId, 'databaseId' => $databaseId];
    }

    /**
     * Helper to create collection with attribute and index.
     */
    protected function createCollectionWithIndex(): array
    {
        $data = $this->createCollectionWithAttribute();

        $indexResponse = $this->client->call(Client::METHOD_POST, '/databases/' . $data['databaseId'] . '/collections/' . $data['actorsId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'key_name',
            'type' => 'key',
            'attributes' => ['name'],
        ]);

        $this->assertEquals(202, $indexResponse['headers']['status-code'], 'Index creation failed: ' . json_encode($indexResponse['body']));

        // Wait for index to be available
        $this->assertEventually(function () use ($data) {
            $index = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['actorsId'] . '/indexes/key_name', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertEquals(200, $index['headers']['status-code'], 'Index polling returned ' . $index['headers']['status-code'] . ': ' . json_encode($index['body'] ?? ''));
            $this->assertEquals('available', $index['body']['status']);
        }, 120000, 500);

        return $data;
    }

    /**
     * Helper to create table with attribute and index.
     */
    protected function createTableWithIndex(): array
    {
        $data = $this->createTableWithAttribute();

        $indexResponse = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $data['databaseId'] . '/tables/' . $data['actorsId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'key_name',
            'type' => 'key',
            'columns' => ['name'],
        ]);

        $this->assertEquals(202, $indexResponse['headers']['status-code'], 'Index creation failed: ' . json_encode($indexResponse['body']));

        // Wait for index to be available
        $this->assertEventually(function () use ($data) {
            $index = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $data['databaseId'] . '/tables/' . $data['actorsId'] . '/indexes/key_name', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertEquals(200, $index['headers']['status-code'], 'Index polling returned ' . $index['headers']['status-code'] . ': ' . json_encode($index['body'] ?? ''));
            $this->assertEquals('available', $index['body']['status']);
        }, 120000, 500);

        return $data;
    }

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
        $this->assertEquals('Payload is not valid. Session is required', $response['data']['message']);

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

    public function testAttributesCollectionsAPI(): void
    {
        /**
         * Create database and collection BEFORE opening WebSocket
         * to avoid their creation events interfering with attribute events.
         */
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'databaseId' => ID::unique(),
            'name' => 'Actors DB',
        ]);

        $databaseId = $database['body']['$id'];

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
         * Test Attributes
         */
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
    }

    public function testAttributesTablesAPI(): void
    {
        /**
         * Create database and table BEFORE opening WebSocket
         * to avoid their creation events interfering with column events.
         */
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'databaseId' => ID::unique(),
            'name' => 'Actors DB',
        ]);

        $databaseId = $database['body']['$id'];

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
         * Test Attributes
         */
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
    }

    public function testIndexesCollectionAPI(): void
    {
        $data = $this->createCollectionWithAttribute();
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
    }

    public function testIndexesTablesAPI(): void
    {
        $data = $this->createTableWithAttribute();
        $projectId = 'console';
        $actorsId = $data['actorsId'];
        $databaseId = $data['databaseId'];
        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ], $projectId, null, 10);

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
    }

    public function testDeleteIndexCollectionsAPI(): void
    {
        $data = $this->createCollectionWithIndex();
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
    }

    public function testDeleteIndexTablesAPI(): void
    {
        $data = $this->createTableWithIndex();
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
    }

    public function testDeleteAttributeCollectionsAPI(): void
    {
        $data = $this->createCollectionWithAttribute();
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

    public function testDeleteAttributeTablesAPI(): void
    {
        $data = $this->createTableWithAttribute();
        $projectId = 'console';
        $actorsId = $data['actorsId'];
        $databaseId = $data['databaseId'];

        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ], $projectId, null, 10);

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
        ], 'console', null, 10);

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
        ], $projectId, null, 30);

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

        // Consume the build lifecycle updates. The status model is
        // waiting → building → ready/failed (the jobs backend has no intermediate
        // "processing" phase and may interleave extra update events), so assert
        // the meaningful milestones tolerantly rather than a fixed positional
        // sequence: the build reaches "building" with an accumulating log, then
        // a terminal "ready" carrying the build metadata. Consecutive updates
        // may carry unchanged (even still-empty) logs when triggered by a
        // non-log attribute change, so logs are only required to never shrink;
        // the terminal assertions below guarantee they eventually streamed.
        $sawBuilding = false;
        $sawFailed = false;
        $logsShrank = false;
        $previousBuildLogs = null;

        $response = $this->receiveUntilEvent(
            $client,
            function (array $message) use ($functionId, $deploymentId, &$sawBuilding, &$sawFailed, &$logsShrank, &$previousBuildLogs): bool {
                $events = $message['data']['events'] ?? [];
                if (!\in_array("functions.{$functionId}.deployments.{$deploymentId}.update", $events, true)) {
                    return false; // Unrelated project-scoped frame; keep polling.
                }

                $payload = $message['data']['payload'] ?? [];
                $status = $payload['status'] ?? null;

                if ($status === 'failed') {
                    $sawFailed = true;
                    return true; // Stop; the assertion below surfaces the failure.
                }

                if ($status === 'building') {
                    $sawBuilding = true;
                    if ($previousBuildLogs !== null && \strlen((string) ($payload['buildLogs'] ?? '')) < \strlen($previousBuildLogs)) {
                        $logsShrank = true;
                    }
                    $previousBuildLogs = (string) ($payload['buildLogs'] ?? '');
                }

                return $status === 'ready' && !empty($payload['buildDuration']) && !empty($payload['buildEndedAt']);
            },
            120000
        );

        $payload = $response['data']['payload'];

        $this->assertContains("functions.{$functionId}.deployments.{$deploymentId}.update", $response['data']['events']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$projectId}", $response['data']['channels']);
        $this->assertArrayHasKey('buildLogs', $payload);
        $this->assertFalse($sawFailed, 'Deployment build failed. Last payload: ' . \json_encode($payload));
        $this->assertFalse($logsShrank, 'Build logs must never shrink between deployment updates.');
        $this->assertTrue($sawBuilding);
        $this->assertNotEmpty($payload['buildStartedAt']);
        $this->assertNotEmpty($payload['buildPath']);
        $this->assertNotEmpty($payload['buildSize']);
        $this->assertNotEmpty($payload['totalSize']);
        $this->assertNotEmpty($payload['buildLogs']);

        $client->close();
    }
}
