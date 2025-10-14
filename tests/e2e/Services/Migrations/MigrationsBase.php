<?php

namespace Tests\E2E\Services\Migrations;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\General\UsageTest;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Services\Functions\FunctionsBase;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Migration\Resource;
use Utopia\Migration\Sources\Appwrite;

trait MigrationsBase
{
    use ProjectCustom;
    use FunctionsBase;

    /**
     * @var array
     */
    protected static array $destinationProject = [];

    /**
     * @param bool $fresh
     * @return array
     */
    public function getDestinationProject(bool $fresh = false): array
    {
        if (!empty(self::$destinationProject) && !$fresh) {
            return self::$destinationProject;
        }

        $projectBackup = self::$project;

        self::$destinationProject = $this->getProject(true);
        self::$project = $projectBackup;

        return self::$destinationProject;
    }

    public function performMigrationSync(array $body): array
    {
        $migration = $this->client->call(Client::METHOD_POST, '/migrations/appwrite', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ], $body);

        $this->assertEquals(202, $migration['headers']['status-code']);
        $this->assertNotEmpty($migration['body']);
        $this->assertNotEmpty($migration['body']['$id']);

        $migrationResult = [];

        $this->assertEventually(function () use ($migration, &$migrationResult) {
            $response = $this->client->call(Client::METHOD_GET, '/migrations/' . $migration['body']['$id'], [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getDestinationProject()['$id'],
                'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']);
            $this->assertNotEmpty($response['body']['$id']);

            if ($response['body']['status'] === 'failed') {
                $this->fail('Migration failed' . json_encode($response['body'], JSON_PRETTY_PRINT));
            }

            $this->assertNotEquals('failed', $response['body']['status']);
            $this->assertEquals('completed', $response['body']['status']);

            $migrationResult = $response['body'];

            return true;
        });

        return $migrationResult;
    }

    /**
     * Appwrite E2E Migration Tests
     */
    public function testCreateAppwriteMigration(): void
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
    public function testAppwriteMigrationAuthUserPassword(): void
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
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($user['email'], $response['body']['email']);
        $this->assertEquals($user['password'], $response['body']['password']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/users/' . $user['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/users/' . $user['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
    }

    public function testAppwriteMigrationAuthUserPhone(): void
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
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
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
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);
    }

    public function testAppwriteMigrationAuthTeam(): void
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
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($team['body']['name'], $response['body']['name']);

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $team['body']['$id'] . '/memberships', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
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
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/users/' . $user['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/users/' . $user['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/teams/' . $team['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/teams/' . $team['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);
    }

    /**
     * Databases
     */
    public function testAppwriteMigrationDatabase(): array
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
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        $this->assertEquals($databaseId, $response['body']['$id']);
        $this->assertEquals('Test Database', $response['body']['name']);

        // Cleanup on destination
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        return [
            'databaseId' => $databaseId,
        ];
    }

    /**
     * @depends testAppwriteMigrationDatabase
     */
    public function testAppwriteMigrationDatabasesTable(array $data): array
    {
        $databaseId = $data['databaseId'];

        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'tableId' => ID::unique(),
            'name' => 'Test Table',
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);

        $tableId = $table['body']['$id'];

        // Create Column
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', [
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

        // Wait for column to be ready
        $this->assertEventually(function () use ($databaseId, $tableId) {
            $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/name', [
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
                Resource::TYPE_TABLE,
                Resource::TYPE_COLUMN,
            ],
            'endpoint' => 'http://localhost/v1',
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_DATABASE, Resource::TYPE_TABLE, Resource::TYPE_COLUMN], $result['resources']);

        foreach ([Resource::TYPE_DATABASE, Resource::TYPE_TABLE, Resource::TYPE_COLUMN] as $resource) {
            $this->assertArrayHasKey($resource, $result['statusCounters']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['error']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['pending']);
            $this->assertEquals(1, $result['statusCounters'][$resource]['success']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['processing']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['warning']);
        }

        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);

        $this->assertEquals($tableId, $response['body']['$id']);
        $this->assertEquals('Test Table', $response['body']['name']);

        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/name', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);

        $this->assertEquals('name', $response['body']['key']);
        $this->assertEquals(100, $response['body']['size']);
        $this->assertEquals(true, $response['body']['required']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        return [
            'databaseId' => $databaseId,
            'tableId' => $tableId,
        ];
    }

    /**
     * @depends testAppwriteMigrationDatabasesTable
     */
    public function testAppwriteMigrationDatabasesRow(array $data): void
    {
        $table = $data['tableId'];
        $databaseId = $data['databaseId'];

        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table . '/rows', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'Test Row',
            ]
        ]);

        $this->assertEquals(201, $row['headers']['status-code']);
        $this->assertNotEmpty($row['body']);
        $this->assertNotEmpty($row['body']['$id']);

        $rowId = $row['body']['$id'];

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_DATABASE,
                Resource::TYPE_TABLE,
                Resource::TYPE_COLUMN,
                Resource::TYPE_ROW,
            ],
            'endpoint' => 'http://localhost/v1',
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $finalStats = $this->client->call(Client::METHOD_GET, '/project/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'startDate' => UsageTest::getYesterday(),
            'endDate' => UsageTest::getTomorrow(),
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_DATABASE, Resource::TYPE_TABLE, Resource::TYPE_COLUMN, Resource::TYPE_ROW], $result['resources']);

        // TODO: Add TYPE_ROW to the migration status counters once pending issue is resolved
        foreach ([Resource::TYPE_DATABASE, Resource::TYPE_TABLE, Resource::TYPE_COLUMN] as $resource) {
            $this->assertArrayHasKey($resource, $result['statusCounters']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['error']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['pending']);
            $this->assertEquals(1, $result['statusCounters'][$resource]['success']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['processing']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['warning']);
        }

        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $table . '/rows/' . $rowId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);

        $this->assertEquals($rowId, $response['body']['$id']);
        $this->assertEquals('Test Row', $response['body']['name']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);
    }

    /**
     * Storage
     */
    public function testAppwriteMigrationStorageBucket(): void
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
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
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
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucket['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
    }

    public function testAppwriteMigrationStorageFiles(): void
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
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
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
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);
    }

    /**
     * Functions
     */
    public function testAppwriteMigrationFunction(): void
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js'
        ]);

        $deploymentId = $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('basic'),
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
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        $this->assertEquals($functionId, $response['body']['$id']);
        $this->assertEquals('Test', $response['body']['name']);
        $this->assertEquals('node-22', $response['body']['runtime']);
        $this->assertEquals('index.js', $response['body']['entrypoint']);


        $this->assertEventually(function () use ($functionId) {
            $deployments = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments/', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getDestinationProject()['$id'],
                'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
            ]));

            $this->assertEquals(200, $deployments['headers']['status-code']);
            $this->assertNotEmpty($deployments['body']);
            $this->assertEquals(1, $deployments['body']['total']);

            $this->assertEquals('ready', $deployments['body']['deployments'][0]['status'], 'Deployment status is not ready, deployment: ' . json_encode($deployments['body']['deployments'][0], JSON_PRETTY_PRINT));
        }, 100000, 500);

        // Attempt execution
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
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
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);
    }

    /**
     * Import documents from a CSV file.
     */
    public function testCreateCsvMigration(): void
    {
        // Make a database
        $response = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Test Database'
        ]);

        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('Test Database', $response['body']['name']);

        $databaseId = $response['body']['$id'];

        // make a table
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Test table',
            'tableId' => ID::unique(),
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals($response['body']['name'], 'Test table');

        $tableId = $response['body']['$id'];

        // make columns
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->assertEquals(202, $response['headers']['status-code']);
        $this->assertEquals($response['body']['key'], 'name');
        $this->assertEquals($response['body']['type'], 'string');
        $this->assertEquals($response['body']['size'], 256);
        $this->assertEquals($response['body']['required'], true);

        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'age',
            'min' => 18,
            'max' => 65,
            'required' => true,
        ]);

        $this->assertEquals(202, $response['headers']['status-code']);
        $this->assertEquals($response['body']['key'], 'age');
        $this->assertEquals($response['body']['type'], 'integer');
        $this->assertEquals($response['body']['min'], 18);
        $this->assertEquals($response['body']['max'], 65);
        $this->assertEquals($response['body']['required'], true);

        // make a bucket, upload a file to it!
        $bucketOne = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
            'maximumFileSize' => 2000000, //2MB
            'allowedFileExtensions' => ['csv'],
            'compression' => 'gzip',
            'encryption' => true
        ]);
        $this->assertEquals(201, $bucketOne['headers']['status-code']);
        $this->assertNotEmpty($bucketOne['body']['$id']);

        $bucketOneId = $bucketOne['body']['$id'];

        $bucketIds = [
            'default' => $bucketOneId,
            'missing-row' => $bucketOneId,
            'missing-column' => $bucketOneId,
            'irrelevant-column' => $bucketOneId,
            'documents-internals' => $bucketOneId,
        ];

        $fileIds = [];

        foreach ($bucketIds as $label => $bucketId) {
            $csvFileName = match ($label) {
                'missing-row',
                'missing-column',
                'irrelevant-column',
                'documents-internals' => "{$label}.csv",
                default => 'documents.csv',
            };

            $mimeType = match ($csvFileName) {
                default => 'text/csv',
                'missing-row.csv' => 'text/plain', // invalid csv structure, falls back to plain text!
            };

            $response = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'fileId' => ID::unique(),
                'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/csv/'.$csvFileName), $mimeType, $csvFileName),
            ]);

            $this->assertEquals(201, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);
            $this->assertEquals($csvFileName, $response['body']['name']);
            $this->assertEquals($mimeType, $response['body']['mimeType']);

            $fileIds[$label] = $response['body']['$id'];
        }

        // missing column, fail in worker.
        $missingColumn = $this->performCsvMigration(
            [
                'fileId' => $fileIds['missing-column'],
                'bucketId' => $bucketIds['missing-column'],
                'resourceId' => $databaseId . ':' . $tableId,
            ]
        );

        $this->assertEventually(function () use ($missingColumn, $databaseId, $tableId) {
            $migrationId = $missingColumn['body']['$id'];
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/'.$migrationId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $migration['headers']['status-code']);
            $this->assertEquals('finished', $migration['body']['stage']);
            $this->assertEquals('failed', $migration['body']['status']);
            $this->assertEquals('CSV', $migration['body']['source']);
            $this->assertEquals('Appwrite', $migration['body']['destination']);
            $this->assertContains(Resource::TYPE_ROW, $migration['body']['resources']);
            $this->assertEmpty($migration['body']['statusCounters']);
            $errorJson = $migration['body']['errors'][0];
            $errorData = json_decode($errorJson, true);

            $this->assertThat(
                implode("\n", $migration['body']['errors']),
                $this->stringContains("CSV header validation failed: Missing required column: 'age'")
            );
        }, 60_000, 500);

        // missing row data, fail in worker.
        $missingColumn = $this->performCsvMigration(
            [
                'fileId' => $fileIds['missing-row'],
                'bucketId' => $bucketIds['missing-row'],
                'resourceId' => $databaseId . ':' . $tableId,
            ]
        );

        $this->assertEventually(function () use ($missingColumn, $databaseId, $tableId) {
            $migrationId = $missingColumn['body']['$id'];
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/'.$migrationId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $migration['headers']['status-code']);
            $this->assertEquals('finished', $migration['body']['stage']);
            $this->assertEquals('failed', $migration['body']['status']);
            $this->assertEquals('CSV', $migration['body']['source']);
            $this->assertEquals('Appwrite', $migration['body']['destination']);
            $this->assertContains(Resource::TYPE_ROW, $migration['body']['resources']);
            $this->assertEmpty($migration['body']['statusCounters']);
            $errorJson = $migration['body']['errors'][0];
            $errorData = json_decode($errorJson, true);

            $this->assertThat(
                implode("\n", $migration['body']['errors']),
                $this->stringContains('CSV row does not match the number of header columns')
            );
        }, 60_000, 500);

        // irrelevant column - email, success.
        $irrelevantColumn = $this->performCsvMigration(
            [
                'fileId' => $fileIds['irrelevant-column'],
                'bucketId' => $bucketIds['irrelevant-column'],
                'resourceId' => $databaseId . ':' . $tableId,
            ]
        );

        $this->assertEventually(function () use ($irrelevantColumn, $databaseId, $tableId) {
            $migrationId = $irrelevantColumn['body']['$id'];
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/'.$migrationId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $migration['headers']['status-code']);
            $this->assertEquals('finished', $migration['body']['stage']);
            $this->assertEquals('completed', $migration['body']['status']);
            $this->assertEquals('CSV', $migration['body']['source']);
            $this->assertEquals('Appwrite', $migration['body']['destination']);
            $this->assertContains(Resource::TYPE_ROW, $migration['body']['resources']);
            $this->assertArrayHasKey(Resource::TYPE_ROW, $migration['body']['statusCounters']);
            $this->assertEquals(100, $migration['body']['statusCounters'][Resource::TYPE_ROW]['success']);
        }, 10_000, 500);

        // all data exists, pass.
        $migration = $this->performCsvMigration(
            [
                'endpoint' => 'http://localhost/v1',
                'fileId' => $fileIds['default'],
                'bucketId' => $bucketIds['default'],
                'resourceId' => $databaseId . ':' . $tableId,
            ]
        );

        $this->assertEventually(function () use ($migration, $databaseId, $tableId) {
            $migrationId = $migration['body']['$id'];
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/'.$migrationId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $migration['headers']['status-code']);
            $this->assertEquals('finished', $migration['body']['stage']);
            $this->assertEquals('completed', $migration['body']['status']);
            $this->assertEquals('CSV', $migration['body']['source']);
            $this->assertEquals('Appwrite', $migration['body']['destination']);
            $this->assertContains(Resource::TYPE_ROW, $migration['body']['resources']);
            $this->assertArrayHasKey(Resource::TYPE_ROW, $migration['body']['statusCounters']);
            $this->assertEquals(100, $migration['body']['statusCounters'][Resource::TYPE_ROW]['success']);
        }, 10_000, 500);

        // get rows count
        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/'.$databaseId.'/tables/'.$tableId.'/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(150)->toString()
            ]
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertIsArray($rows['body']['rows']);
        $this->assertIsNumeric($rows['body']['total']);
        $this->assertEquals(200, $rows['body']['total']);

        // all data exists and includes internals, pass.
        $migration = $this->performCsvMigration(
            [
                'endpoint' => 'http://localhost/v1',
                'fileId' => $fileIds['documents-internals'],
                'bucketId' => $bucketIds['documents-internals'],
                'resourceId' => $databaseId . ':' . $tableId,
            ]
        );

        $this->assertEventually(function () use ($migration, $databaseId, $tableId) {
            $migrationId = $migration['body']['$id'];
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/'.$migrationId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $migration['headers']['status-code']);
            $this->assertEquals('finished', $migration['body']['stage']);
            $this->assertEquals('completed', $migration['body']['status']);
            $this->assertEquals('CSV', $migration['body']['source']);
            $this->assertEquals('Appwrite', $migration['body']['destination']);
            $this->assertContains(Resource::TYPE_ROW, $migration['body']['resources']);
            $this->assertArrayHasKey(Resource::TYPE_ROW, $migration['body']['statusCounters']);
            $this->assertEquals(25, $migration['body']['statusCounters'][Resource::TYPE_ROW]['success']);
        }, 10_000, 500);
    }

    private function performCsvMigration(array $body): array
    {
        return $this->client->call(Client::METHOD_POST, '/migrations/csv', [
            'content-type' => 'application/json',
            'x-appwrite-key' => $this->getProject()['apiKey'],
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $body);
    }

    /**
    * DocumentsDB (schemaless)
    */
    public function testAppwriteMigrationDocumentsDBDatabase(): array
    {
        $response = $this->client->call(Client::METHOD_POST, '/documentsdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'databaseId' => ID::unique(),
            'name' => 'DocsDB - Migration DB'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        $databaseId = $response['body']['$id'];

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_DOCUMENTSDB_DATABASE,
            ],
            'endpoint' => 'http://localhost/v1',
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_DOCUMENTSDB_DATABASE], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_DOCUMENTSDB_DATABASE, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DOCUMENTSDB_DATABASE]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DOCUMENTSDB_DATABASE]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_DOCUMENTSDB_DATABASE]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DOCUMENTSDB_DATABASE]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DOCUMENTSDB_DATABASE]['warning']);

        $response = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($databaseId, $response['body']['$id']);
        $this->assertEquals('DocsDB - Migration DB', $response['body']['name']);

        // Cleanup on destination
        $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        return [
            'databaseId' => $databaseId,
        ];
    }

    /**
     * @depends testAppwriteMigrationDocumentsDBDatabase
     */
    public function testAppwriteMigrationDocumentsDBCollection(array $data): array
    {
        $databaseId = $data['databaseId'];

        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'collectionId' => ID::unique(),
            'name' => 'DocsDB - Movies',
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $collectionId = $collection['body']['$id'];

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_DOCUMENTSDB_DATABASE,
                Resource::TYPE_COLLECTION, // collections in DocumentsDB map to tables in migration
            ],
            'endpoint' => 'http://localhost/v1',
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals('completed', $result['status']);
        foreach ([Resource::TYPE_DOCUMENTSDB_DATABASE, Resource::TYPE_COLLECTION] as $resource) {
            $this->assertArrayHasKey($resource, $result['statusCounters']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['error']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['pending']);
            $this->assertEquals(1, $result['statusCounters'][$resource]['success']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['processing']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['warning']);
        }

        $response = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals($collectionId, $response['body']['$id']);
        $this->assertEquals('DocsDB - Movies', $response['body']['name']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        return [
            'databaseId' => $databaseId,
            'collectionId' => $collectionId,
        ];
    }

    /**
     * @depends testAppwriteMigrationDocumentsDBCollection
     */
    public function testAppwriteMigrationDocumentsDBDocument(array $data): void
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $document = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Migration Test Movie',
                'releaseYear' => 1999,
            ]
        ]);

        $this->assertEquals(201, $document['headers']['status-code']);
        $documentId = $document['body']['$id'];

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_DOCUMENTSDB_DATABASE,
                Resource::TYPE_COLLECTION,
                Resource::TYPE_DOCUMENT,
            ],
            'endpoint' => 'http://localhost/v1',
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);

        foreach ([Resource::TYPE_DOCUMENTSDB_DATABASE] as $resource) {
            $this->assertArrayHasKey($resource, $result['statusCounters']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['error']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['pending']);
            $this->assertEquals(1, $result['statusCounters'][$resource]['success']);
        }

        $response = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals($documentId, $response['body']['$id']);
        $this->assertEquals('Migration Test Movie', $response['body']['title']);
        $this->assertEquals(1999, $response['body']['releaseYear']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
    }

    /**
     * Migrate a project that contains both SQL Databases (/databases) and
     * schemaless DocumentsDB (/documentsdb) in a single run and verify results.
     * Uses a dedicated isolated source project to avoid interference from other tests.
     */
    public function testAppwriteMigrationMixedDatabases(): void
    {
        // Create a fresh isolated source project for this test
        $sourceProject = $this->getProject(true);

        // ====== Create SQL Database (/databases) with table, column, and row ======
        $sql = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProject['$id'],
            'x-appwrite-key' => $sourceProject['apiKey'],
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Mixed SQL DB',
        ]);

        $this->assertEquals(201, $sql['headers']['status-code']);
        $this->assertNotEmpty($sql['body']['$id']);
        $sqlDatabaseId = $sql['body']['$id'];

        // Create Table in SQL Database
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $sqlDatabaseId . '/tables', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProject['$id'],
            'x-appwrite-key' => $sourceProject['apiKey'],
        ], [
            'tableId' => ID::unique(),
            'name' => 'Products',
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);
        $tableId = $table['body']['$id'];

        // Create Column in Table
        $column = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $sqlDatabaseId . '/tables/' . $tableId . '/columns/string', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProject['$id'],
            'x-appwrite-key' => $sourceProject['apiKey'],
        ], [
            'key' => 'productName',
            'size' => 255,
            'required' => true,
        ]);

        $this->assertEquals(202, $column['headers']['status-code']);

        // Wait for column to be ready
        $this->assertEventually(function () use ($sqlDatabaseId, $tableId, $sourceProject) {
            $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $sqlDatabaseId . '/tables/' . $tableId . '/columns/productName', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $sourceProject['$id'],
                'x-appwrite-key' => $sourceProject['apiKey'],
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals('available', $response['body']['status']);
        }, 5000, 500);

        // Create Row in Table
        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $sqlDatabaseId . '/tables/' . $tableId . '/rows', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProject['$id'],
            'x-appwrite-key' => $sourceProject['apiKey'],
        ], [
            'rowId' => ID::unique(),
            'data' => [
                'productName' => 'Laptop',
            ],
        ]);

        $this->assertEquals(201, $row['headers']['status-code']);
        $rowId = $row['body']['$id'];

        // ====== Create DocumentsDB (/documentsdb) with collection and document ======
        $docs = $this->client->call(Client::METHOD_POST, '/documentsdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProject['$id'],
            'x-appwrite-key' => $sourceProject['apiKey'],
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Mixed DocsDB',
        ]);

        $this->assertEquals(201, $docs['headers']['status-code']);
        $this->assertNotEmpty($docs['body']['$id']);
        $docsDatabaseId = $docs['body']['$id'];

        // Create Collection in DocumentsDB
        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $docsDatabaseId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProject['$id'],
            'x-appwrite-key' => $sourceProject['apiKey'],
        ], [
            'collectionId' => ID::unique(),
            'name' => 'Users',
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Create Document in Collection
        $document = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $docsDatabaseId . '/collections/' . $collectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProject['$id'],
            'x-appwrite-key' => $sourceProject['apiKey'],
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'John Doe',
                'email' => 'john@example.com',
            ],
        ]);

        $this->assertEquals(201, $document['headers']['status-code']);
        $documentId = $document['body']['$id'];

        // ====== Perform migration including both database kinds with all child resources ======
        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_DATABASE,
                Resource::TYPE_TABLE,
                Resource::TYPE_COLUMN,
                Resource::TYPE_ROW,
                Resource::TYPE_DOCUMENTSDB_DATABASE,
                Resource::TYPE_COLLECTION,
                Resource::TYPE_DOCUMENT,
            ],
            'endpoint' => 'http://localhost/v1',
            'projectId' => $sourceProject['$id'],
            'apiKey' => $sourceProject['apiKey'],
        ]);

        // ====== Assert migration result ======
        $this->assertEquals('completed', $result['status']);
        $this->assertEquals('Appwrite', $result['source']);
        $this->assertEquals('Appwrite', $result['destination']);
        $this->assertEquals([
            Resource::TYPE_DATABASE,
            Resource::TYPE_TABLE,
            Resource::TYPE_COLUMN,
            Resource::TYPE_ROW,
            Resource::TYPE_DOCUMENTSDB_DATABASE,
            Resource::TYPE_COLLECTION,
            Resource::TYPE_DOCUMENT,
        ], $result['resources']);

        // Assert SQL Database counters
        $this->assertArrayHasKey(Resource::TYPE_DATABASE, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_DATABASE]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE]['warning']);

        // Assert Table counters
        $this->assertArrayHasKey(Resource::TYPE_TABLE, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_TABLE]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_TABLE]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_TABLE]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_TABLE]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_TABLE]['warning']);

        // Assert Column counters
        $this->assertArrayHasKey(Resource::TYPE_COLUMN, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_COLUMN]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_COLUMN]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_COLUMN]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_COLUMN]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_COLUMN]['warning']);

        // Assert Row counters
        $this->assertArrayHasKey(Resource::TYPE_ROW, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_ROW]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_ROW]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_ROW]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_ROW]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_ROW]['warning']);

        // Assert DocumentsDB counters
        $this->assertArrayHasKey(Resource::TYPE_DOCUMENTSDB_DATABASE, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DOCUMENTSDB_DATABASE]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DOCUMENTSDB_DATABASE]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_DOCUMENTSDB_DATABASE]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DOCUMENTSDB_DATABASE]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DOCUMENTSDB_DATABASE]['warning']);

        // Assert Collection counters
        $this->assertArrayHasKey(Resource::TYPE_COLLECTION, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_COLLECTION]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_COLLECTION]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_COLLECTION]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_COLLECTION]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_COLLECTION]['warning']);

        // Assert Document counters
        $this->assertArrayHasKey(Resource::TYPE_DOCUMENT, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DOCUMENT]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DOCUMENT]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_DOCUMENT]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DOCUMENT]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DOCUMENT]['warning']);

        // Ensure only expected counters exist (7 total)
        $this->assertCount(7, $result['statusCounters']);

        // ====== Validate on destination: SQL Database resources ======
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $sqlDatabaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($sqlDatabaseId, $response['body']['$id']);
        $this->assertEquals('Mixed SQL DB', $response['body']['name']);

        // Validate Table
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $sqlDatabaseId . '/tables/' . $tableId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($tableId, $response['body']['$id']);
        $this->assertEquals('Products', $response['body']['name']);

        // Validate Column
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $sqlDatabaseId . '/tables/' . $tableId . '/columns/productName', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('productName', $response['body']['key']);
        $this->assertEquals(255, $response['body']['size']);
        $this->assertEquals(true, $response['body']['required']);

        // Validate Row
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $sqlDatabaseId . '/tables/' . $tableId . '/rows/' . $rowId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($rowId, $response['body']['$id']);
        $this->assertEquals('Laptop', $response['body']['productName']);

        // ====== Validate on destination: DocumentsDB resources ======
        $response = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $docsDatabaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($docsDatabaseId, $response['body']['$id']);
        $this->assertEquals('Mixed DocsDB', $response['body']['name']);

        // Validate Collection
        $response = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $docsDatabaseId . '/collections/' . $collectionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($collectionId, $response['body']['$id']);
        $this->assertEquals('Users', $response['body']['name']);

        // Validate Document
        $response = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $docsDatabaseId . '/collections/' . $collectionId . '/documents/' . $documentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($documentId, $response['body']['$id']);
        $this->assertEquals('John Doe', $response['body']['name']);
        $this->assertEquals('john@example.com', $response['body']['email']);

        // ====== Cleanup both destinations ======
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $sqlDatabaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $docsDatabaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        // ====== Cleanup sources ======
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $sqlDatabaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProject['$id'],
            'x-appwrite-key' => $sourceProject['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $docsDatabaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProject['$id'],
            'x-appwrite-key' => $sourceProject['apiKey'],
        ]);
    }
}
