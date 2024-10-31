<?php

namespace Tests\E2E\Services\Migrations;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Services\Functions\FunctionsBase;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Migration\Resource;
use Utopia\Migration\Sources\Appwrite;

trait MigrationsBase
{
    use ProjectCustom;
    use FunctionsBase;

    /**
     * @var array
     */
    protected static $destinationProject = [];

    /**
     * @param bool $fresh
     * @return array
     */
    public function getDesintationProject(bool $fresh = false): array
    {
        if (!empty(self::$destinationProject) && !$fresh) {
            return self::$destinationProject;
        }

        $projectBackup = self::$project;

        self::$destinationProject = $this->getProject(true);
        self::$project = $projectBackup;

        return self::$destinationProject;
    }

    public function performMigrationSync(
        array $body,
    ): array {
        $migration = $this->client->call(Client::METHOD_POST, '/migrations/appwrite', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ], $body);

        $this->assertEquals(202, $migration['headers']['status-code']);
        $this->assertNotEmpty($migration['body']);
        $this->assertNotEmpty($migration['body']['$id']);

        $attempts = 0;
        while ($attempts < 5) {
            $response = $this->client->call(Client::METHOD_GET, '/migrations/' . $migration['body']['$id'], [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getDesintationProject()['$id'],
                'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']);
            $this->assertNotEmpty($response['body']['$id']);

            if ($response['body']['status'] === 'failed') {
                $this->fail('Migration failed', json_encode($response['body'], JSON_PRETTY_PRINT));
            }

            $this->assertNotEquals('failed', $response['body']['status']);

            if ($response['body']['status'] === 'completed') {
                return $response['body'];
            }

            if ($attempts === 4) {
                $this->assertEquals('completed', $response['body']['status']);
            }

            $attempts++;
            sleep(5);
        }
    }

    /**
     * Appwrite E2E Migration Tests
     */
    public function testCreateAppwriteMigration()
    {
        $response = $this->performMigrationSync([
            'resources' => Appwrite::getSupportedResources(),
            'endpoint' => 'http://localhost/v1',
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(Appwrite::getSupportedResources(), $response['resources']);
        $this->assertEquals('Appwrite', $response['source']);
        $this->assertEquals('Appwrite', $response['destination']);
        $this->assertEmpty($response['statusCounters']);
    }

    /**
     * Auth
     */
    public function testAppwriteMigrationAuthUserPassword()
    {
        $response = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => 'test@test.com',
            'password' => 'password',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('test@test.com', $response['body']['email']);

        $user = $response['body'];

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_USER,
            ],
            'endpoint' => 'http://localhost/v1',
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_USER], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_USER, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_USER]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_USER]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_USER]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_USER]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_USER]['warning']);

        $response = $this->client->call(Client::METHOD_GET, '/users/' . $user['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($user['email'], $response['body']['email']);
        $this->assertEquals($user['password'], $response['body']['password']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/users/' . $user['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/users/' . $user['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
    }

    public function testAppwriteMigrationAuthUserPhone()
    {
        $response = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'phone' => '+12065550100',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('+12065550100', $response['body']['phone']);

        $user = $response['body'];

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_USER,
            ],
            'endpoint' => 'http://localhost/v1',
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_USER], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_USER, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_USER]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_USER]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_USER]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_USER]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_USER]['warning']);

        $response = $this->client->call(Client::METHOD_GET, '/users/' . $user['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($user['phone'], $response['body']['phone']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/users/' . $user['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/users/' . $user['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);
    }

    public function testAppwriteMigrationAuthTeam()
    {
        $user = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => 'test@test.com',
            'password' => 'password',
        ]);

        $this->assertEquals(201, $user['headers']['status-code']);
        $this->assertNotEmpty($user['body']);
        $this->assertNotEmpty($user['body']['$id']);
        $this->assertEquals('test@test.com', $user['body']['email']);

        $team = $this->client->call(Client::METHOD_POST, '/teams', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'teamId' => ID::unique(),
            'name' => 'Test Team',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertNotEmpty($team['body']);
        $this->assertNotEmpty($team['body']['$id']);

        $membership = $this->client->call(Client::METHOD_POST, '/teams/' . $team['body']['$id'] . '/memberships', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'teamId' => $team['body']['$id'],
            'userId' => $user['body']['$id'],
            'roles' => ['owner'],
        ]);

        $this->assertEquals(201, $membership['headers']['status-code']);
        $this->assertNotEmpty($membership['body']);
        $this->assertNotEmpty($membership['body']['$id']);

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_USER,
                Resource::TYPE_TEAM,
                Resource::TYPE_MEMBERSHIP,
            ],
            'endpoint' => 'http://localhost/v1',
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_USER, Resource::TYPE_TEAM, Resource::TYPE_MEMBERSHIP], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_USER, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_USER]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_USER]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_USER]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_USER]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_USER]['warning']);

        $this->assertArrayHasKey(Resource::TYPE_TEAM, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_TEAM]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_TEAM]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_TEAM]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_TEAM]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_TEAM]['warning']);

        $this->assertArrayHasKey(Resource::TYPE_MEMBERSHIP, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_MEMBERSHIP]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_MEMBERSHIP]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_MEMBERSHIP]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_MEMBERSHIP]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_MEMBERSHIP]['warning']);

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $team['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($team['body']['name'], $response['body']['name']);

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $team['body']['$id'] . '/memberships', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);

        $membership = $response['body']['memberships'][0];

        $this->assertEquals($user['body']['$id'], $membership['userId']);
        $this->assertEquals($team['body']['$id'], $membership['teamId']);
        $this->assertEquals(['owner'], $membership['roles']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/teams/' . $team['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/teams/' . $team['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/users/' . $user['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/users/' . $user['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/teams/' . $team['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/teams/' . $team['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);
    }

    /**
     * Databases
     */
    public function testAppwriteMigrationDatabase()
    {
        $response = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Test Database'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        $databaseId = $response['body']['$id'];

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_DATABASE,
            ],
            'endpoint' => 'http://localhost/v1',
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);


        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_DATABASE], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_DATABASE, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_DATABASE]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE]['warning']);

        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        $this->assertEquals($databaseId, $response['body']['$id']);
        $this->assertEquals('Test Database', $response['body']['name']);

        // Cleanup on destination
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);

        return [
            'databaseId' => $databaseId,
        ];
    }

    /**
     * @depends testAppwriteMigrationDatabase
     */
    public function testAppwriteMigrationDatabasesCollection(array $data)
    {
        $databaseId = $data['databaseId'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'collectionId' => ID::unique(),
            'name' => 'Test Collection',
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $collectionId = $collection['body']['$id'];

        // Create Attribute
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'name',
            'size' => 100,
            'encrypt' => false,
            'required' => true
        ]);

        $this->assertEquals(202, $response['headers']['status-code']);

        // Wait for attribute to be ready
        $this->assertEventually(function () use ($databaseId, $collectionId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/name', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals('available', $response['body']['status']);
        }, 5000, 500);

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_DATABASE,
                Resource::TYPE_COLLECTION,
                Resource::TYPE_ATTRIBUTE,
            ],
            'endpoint' => 'http://localhost/v1',
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_DATABASE, Resource::TYPE_COLLECTION, Resource::TYPE_ATTRIBUTE], $result['resources']);

        foreach ([Resource::TYPE_DATABASE, Resource::TYPE_COLLECTION, Resource::TYPE_ATTRIBUTE] as $resource) {
            $this->assertArrayHasKey($resource, $result['statusCounters']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['error']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['pending']);
            $this->assertEquals(1, $result['statusCounters'][$resource]['success']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['processing']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['warning']);
        }

        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);

        $this->assertEquals($collectionId, $response['body']['$id']);
        $this->assertEquals('Test Collection', $response['body']['name']);

        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/name', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);

        $this->assertEquals('name', $response['body']['key']);
        $this->assertEquals(100, $response['body']['size']);
        $this->assertEquals(true, $response['body']['required']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);

        return [
            'databaseId' => $databaseId,
            'collectionId' => $collectionId,
        ];
    }

    /**
     * @depends testAppwriteMigrationDatabasesCollection
     */
    public function testAppwriteMigrationDatabasesDocument(array $data)
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Test Document',
            ]
        ]);

        $this->assertEquals(201, $document['headers']['status-code']);
        $this->assertNotEmpty($document['body']);
        $this->assertNotEmpty($document['body']['$id']);

        $documentId = $document['body']['$id'];

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_DATABASE,
                Resource::TYPE_COLLECTION,
                Resource::TYPE_ATTRIBUTE,
                Resource::TYPE_DOCUMENT,
            ],
            'endpoint' => 'http://localhost/v1',
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_DATABASE, Resource::TYPE_COLLECTION, Resource::TYPE_ATTRIBUTE, Resource::TYPE_DOCUMENT], $result['resources']);

        //TODO: Add TYPE_DOCUMENT to the migration status counters once pending issue is resolved
        foreach ([Resource::TYPE_DATABASE, Resource::TYPE_COLLECTION, Resource::TYPE_ATTRIBUTE] as $resource) {
            $this->assertArrayHasKey($resource, $result['statusCounters']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['error']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['pending']);
            $this->assertEquals(1, $result['statusCounters'][$resource]['success']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['processing']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['warning']);
        }

        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);

        $this->assertEquals($documentId, $response['body']['$id']);
        $this->assertEquals('Test Document', $response['body']['name']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);
    }

    /**
     * Storage
     */
    public function testAppwriteMigrationStorageBucket()
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'maximumFileSize' => 1000000,
            'allowedFileExtensions' => ['pdf'],
            'compression' => 'gzip',
            'encryption' => false,
            'antivirus' => false
        ]);

        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']);
        $this->assertNotEmpty($bucket['body']['$id']);
        $this->assertEquals('Test Bucket', $bucket['body']['name']);

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_BUCKET
            ],
            'endpoint' => 'http://localhost/v1',
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_BUCKET], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_BUCKET, $result['statusCounters']);

        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_BUCKET]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_BUCKET]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_BUCKET]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_BUCKET]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_BUCKET]['warning']);

        $response = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucket['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        $this->assertEquals($bucket['body']['$id'], $response['body']['$id']);
        $this->assertEquals($bucket['body']['name'], $response['body']['name']);
        $this->assertEquals($bucket['body']['$permissions'], $response['body']['$permissions']);
        $this->assertEquals($bucket['body']['maximumFileSize'], $response['body']['maximumFileSize']);
        $this->assertEquals($bucket['body']['allowedFileExtensions'], $response['body']['allowedFileExtensions']);
        $this->assertEquals($bucket['body']['compression'], $response['body']['compression']);
        $this->assertEquals($bucket['body']['encryption'], $response['body']['encryption']);
        $this->assertEquals($bucket['body']['antivirus'], $response['body']['antivirus']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucket['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucket['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
    }

    public function testAppwriteMigrationStorageFiles()
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
            'fileSecurity' => true,
            'maximumFileSize' => 2000000, //2MB
            'allowedFileExtensions' => ['jpg', 'png', 'jfif'],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);

        $bucketId = $bucket['body']['$id'];

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

        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertNotEmpty($file['body']['$id']);

        $fileId = $file['body']['$id'];

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_BUCKET,
                Resource::TYPE_FILE
            ],
            'endpoint' => 'http://localhost/v1',
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_BUCKET, Resource::TYPE_FILE], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_BUCKET, $result['statusCounters']);

        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_BUCKET]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_BUCKET]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_BUCKET]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_BUCKET]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_BUCKET]['warning']);

        $this->assertArrayHasKey(Resource::TYPE_FILE, $result['statusCounters']);

        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_FILE]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_FILE]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_FILE]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_FILE]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_FILE]['warning']);

        $response = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        $this->assertEquals($fileId, $response['body']['$id']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);
    }

    /**
     * Functions
     */
    public function testAppwriteMigrationFunction()
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php'
        ]);

        $deploymentId = $this->setupDeployment($functionId, [
            'entrypoint' => 'index.php',
            'code' => $this->packageFunction('php'),
            'activate' => true
        ]);

       $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_FUNCTION,
                Resource::TYPE_DEPLOYMENT
            ],
            'endpoint' => 'http://localhost/v1',
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_FUNCTION, Resource::TYPE_DEPLOYMENT], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_FUNCTION, $result['statusCounters']);

        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_FUNCTION]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_FUNCTION]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_FUNCTION]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_FUNCTION]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_FUNCTION]['warning']);

        $this->assertArrayHasKey(Resource::TYPE_DEPLOYMENT, $result['statusCounters']);

        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DEPLOYMENT]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DEPLOYMENT]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_DEPLOYMENT]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DEPLOYMENT]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DEPLOYMENT]['warning']);

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        $this->assertEquals($functionId, $response['body']['$id']);
        $this->assertEquals('Test', $response['body']['name']);
        $this->assertEquals('php-8.0', $response['body']['runtime']);
        $this->assertEquals('index.php', $response['body']['entrypoint']);


        $this->assertEventually(function () use ($functionId) {
            $deployments = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments/', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getDesintationProject()['$id'],
                'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
            ]));

            $this->assertEquals(200, $deployments['headers']['status-code']);
            $this->assertNotEmpty($deployments['body']);
            $this->assertEquals(1, $deployments['body']['total']);

            $this->assertEquals('ready', $deployments['body']['deployments'][0]['status'], 'Deployment status is not ready, deployment: ' . json_encode($deployments['body']['deployments'][0], JSON_PRETTY_PRINT));
        }, 50000, 500);

        // Attempt execution
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ], [
            'body' => 'test'
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertStringContainsString('body-is-test', $execution['body']['logs']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDesintationProject()['$id'],
            'x-appwrite-key' => $this->getDesintationProject()['apiKey'],
        ]);
    }
}
