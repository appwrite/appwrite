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
     * Cached database data for independent test execution
     * @var array
     */
    protected static array $cachedDatabaseData = [];

    /**
     * Cached table data for independent test execution
     * @var array
     */
    protected static array $cachedTableData = [];

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

    /**
     * Set up a database for migration tests with static caching
     * @return array
     */
    protected function setupMigrationDatabase(): array
    {
        if (!empty(static::$cachedDatabaseData)) {
            return static::$cachedDatabaseData;
        }

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

        static::$cachedDatabaseData = [
            'databaseId' => $response['body']['$id'],
        ];

        return static::$cachedDatabaseData;
    }

    /**
     * Set up a table with column for migration tests with static caching
     * @return array
     */
    protected function setupMigrationTable(): array
    {
        if (!empty(static::$cachedTableData)) {
            return static::$cachedTableData;
        }

        // Ensure database exists first
        $dbData = $this->setupMigrationDatabase();
        $databaseId = $dbData['databaseId'];

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

        static::$cachedTableData = [
            'databaseId' => $databaseId,
            'tableId' => $tableId,
        ];

        return static::$cachedTableData;
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
        }, 60_000, 1_000);

        return $migrationResult;
    }

    /**
     * Appwrite E2E Migration Tests
     */
    public function testCreateAppwriteMigration(): void
    {
        $response = $this->performMigrationSync([
            'resources' => Appwrite::getSupportedResources(),
            'endpoint' => $this->webEndpoint,
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
            'endpoint' => $this->webEndpoint,
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
            'endpoint' => $this->webEndpoint,
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
            'endpoint' => $this->webEndpoint,
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
    public function testAppwriteMigrationDatabase(): void
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
            'endpoint' => $this->webEndpoint,
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

        // Cleanup on source
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
    }

    public function testAppwriteMigrationDatabasesTable(): void
    {
        // Set up database using helper method (with static caching)
        $data = $this->setupMigrationDatabase();
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
            'endpoint' => $this->webEndpoint,
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

        // Cleanup on destination
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        // Cleanup on source
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        // Clear the cache since we cleaned up
        static::$cachedDatabaseData = [];
    }

    public function testAppwriteMigrationDatabasesRow(): void
    {
        // Set up table using helper method (with static caching)
        $data = $this->setupMigrationTable();
        $tableId = $data['tableId'];
        $databaseId = $data['databaseId'];

        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', [
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
            'endpoint' => $this->webEndpoint,
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

        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);

        $this->assertEquals($rowId, $response['body']['$id']);
        $this->assertEquals('Test Row', $response['body']['name']);

        // Cleanup on destination
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        // Cleanup on source
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        // Clear the caches since we cleaned up
        static::$cachedDatabaseData = [];
        static::$cachedTableData = [];
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
            'endpoint' => $this->webEndpoint,
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
            'endpoint' => $this->webEndpoint,
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
            'endpoint' => $this->webEndpoint,
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
    public function testCreateCSVImport(): void
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
                'endpoint' => $this->webEndpoint,
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
                'endpoint' => $this->webEndpoint,
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
     * Test CSV export with email notification
     */
    public function testCreateCSVExport(): void
    {
        // Create a database
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Test Export Database'
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create a collection
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'collectionId' => ID::unique(),
            'name' => 'Test Export Collection',
            'permissions' => []
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Create a simple attribute like the basic test
        $name = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'name',
            'size' => 255,
            'required' => true,
        ]);

        $this->assertEquals(202, $name['headers']['status-code']);

        // Create a simple attribute like the basic test
        $email = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'email',
            'size' => 255,
            'required' => false,
        ]);

        $this->assertEquals(202, $email['headers']['status-code']);

        $text = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/text', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'regulartext',
            'required' => false,
        ]);

        $this->assertEquals(202, $text['headers']['status-code']);

        $varchar = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'varchar',
            'size' => 1000,
            'required' => false,
        ]);

        $this->assertEquals(202, $varchar['headers']['status-code']);

        $mediumtext = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/mediumtext', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'mediumtext',
            'required' => false,
        ]);

        $this->assertEquals(202, $mediumtext['headers']['status-code']);

        $longtext = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/longtext', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'longtext',
            'required' => false,
        ]);

        $this->assertEquals(202, $longtext['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $collectionId) {
            $collection = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals(200, $collection['headers']['status-code']);
            $this->assertNotEmpty($collection['body']['attributes']);
            foreach ($collection['body']['attributes'] as $attr) {
                $this->assertEquals('available', $attr['status'], "Attribute '{$attr['key']}' is not available yet");
            }
        }, 30_000, 500);

        // Create sample documents
        for ($i = 1; $i <= 10; $i++) {
            $doc = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ], [
                'documentId' => ID::unique(),
                'data' => [
                    'name' => 'Test User ' . $i,
                    'email' => 'user' . $i . '@appwrite.io',
                    'regulartext' => 'regularText',
                    'mediumtext' => 'mediumText',
                    'longtext' => 'longText',
                    'varchar' => 'varchar',
                ]
            ]);

            $this->assertEquals(201, $doc['headers']['status-code'], 'Failed to create document ' . $i);
        }

        // Verify documents were created
        $docs = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $docs['headers']['status-code']);
        $this->assertEquals(10, $docs['body']['total'], 'Expected 10 documents but got ' . $docs['body']['total']);

        // Perform CSV export with notification enabled (uses internal bucket)
        $migration = $this->client->call(Client::METHOD_POST, '/migrations/csv/exports', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'resourceId' => $databaseId . ':' . $collectionId,
            'filename' => 'test-export',
            'columns' => [],
            'delimiter' => ',',
            'enclosure' => '"',
            'escape' => '\\',
            'header' => true,
            'notify' => true
        ]);

        $this->assertEquals(202, $migration['headers']['status-code']);
        $this->assertNotEmpty($migration['body']['$id']);
        $migrationId = $migration['body']['$id'];

        $this->assertEventually(function () use ($migrationId) {
            $response = $this->client->call(Client::METHOD_GET, '/migrations/' . $migrationId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals('finished', $response['body']['stage']);
            $this->assertEquals('completed', $response['body']['status']);
            $this->assertEquals('Appwrite', $response['body']['source']);
            $this->assertEquals('CSV', $response['body']['destination']);

            return true;
        }, 30_000, 500);

        // Check that email was sent with download link
        $lastEmail = $this->getLastEmail();
        $this->assertNotEmpty($lastEmail);
        $this->assertEquals('Your CSV export is ready', $lastEmail['subject']);
        $this->assertStringContainsStringIgnoringCase('Your data export has been completed successfully', $lastEmail['text']);

        // Extract download URL from email HTML
        \preg_match('/href="([^"]*\/storage\/buckets\/[^"]*\/push[^"]*)"/', $lastEmail['html'], $matches);
        $this->assertNotEmpty($matches[1], 'Download URL not found in email');
        $downloadUrl = html_entity_decode($matches[1]);

        // Parse the URL to extract components
        $components = \parse_url($downloadUrl);
        $this->assertNotEmpty($components);
        \parse_str($components['query'] ?? '', $queryParams);
        $this->assertArrayHasKey('jwt', $queryParams, 'JWT not found in download URL');
        $this->assertNotEmpty($queryParams['jwt']);
        $this->assertArrayHasKey('project', $queryParams, 'Project not found in download URL');
        $this->assertStringContainsString('/storage/buckets/default/files/', $downloadUrl);

        // Test download with JWT
        $path = \str_replace('/v1', '', $components['path']);
        $downloadWithJwt = $this->client->call(Client::METHOD_GET, $path . '?project=' . $queryParams['project'] . '&jwt=' . $queryParams['jwt']);
        $this->assertEquals(200, $downloadWithJwt['headers']['status-code'], 'Failed to download file with JWT');

        // Verify the downloaded content is valid CSV
        $csvData = $downloadWithJwt['body'];

        $this->assertNotEmpty($csvData, 'CSV export should not be empty');
        $this->assertStringContainsString('name', $csvData, 'CSV should contain the name column header');
        $this->assertStringContainsString('email', $csvData, 'CSV should contain the email column header');
        $this->assertStringContainsString('Test User 1', $csvData, 'CSV should contain test data');

        $this->assertStringContainsString('regularText', $csvData, 'CSV should contain the text column header');
        $this->assertStringContainsString('mediumText', $csvData, 'CSV should contain the medium column header');
        $this->assertStringContainsString('longText', $csvData, 'CSV should contain the long text column header');
        $this->assertStringContainsString('varchar', $csvData, 'CSV should contain the varchar column header');

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, [
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
    }

    /**
     * Messaging
     */
    public function testAppwriteMigrationMessagingProvider(): void
    {
        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/sendgrid', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'providerId' => ID::unique(),
            'name' => 'Migration Sendgrid',
            'apiKey' => 'my-apikey',
            'from' => 'migration@test.com',
        ]);

        $this->assertEquals(201, $provider['headers']['status-code']);
        $this->assertNotEmpty($provider['body']['$id']);

        $providerId = $provider['body']['$id'];

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_PROVIDER,
            ],
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_PROVIDER], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_PROVIDER, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROVIDER]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROVIDER]['pending']);
        $this->assertGreaterThanOrEqual(1, $result['statusCounters'][Resource::TYPE_PROVIDER]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROVIDER]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROVIDER]['warning']);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/providers/' . $providerId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($providerId, $response['body']['$id']);
        $this->assertEquals('Migration Sendgrid', $response['body']['name']);
        $this->assertEquals('email', $response['body']['type']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/messaging/providers/' . $providerId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/messaging/providers/' . $providerId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);
    }

    public function testAppwriteMigrationMessagingTopic(): void
    {
        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/sendgrid', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'providerId' => ID::unique(),
            'name' => 'Migration Sendgrid Topic',
            'apiKey' => 'my-apikey',
            'from' => 'migration-topic@test.com',
        ]);

        $this->assertEquals(201, $provider['headers']['status-code']);
        $providerId = $provider['body']['$id'];

        $topic = $this->client->call(Client::METHOD_POST, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'topicId' => ID::unique(),
            'name' => 'Migration Topic',
        ]);

        $this->assertEquals(201, $topic['headers']['status-code']);
        $this->assertNotEmpty($topic['body']['$id']);

        $topicId = $topic['body']['$id'];

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_PROVIDER,
                Resource::TYPE_TOPIC,
            ],
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertArrayHasKey(Resource::TYPE_TOPIC, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_TOPIC]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_TOPIC]['pending']);
        $this->assertGreaterThanOrEqual(1, $result['statusCounters'][Resource::TYPE_TOPIC]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_TOPIC]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_TOPIC]['warning']);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $topicId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($topicId, $response['body']['$id']);
        $this->assertEquals('Migration Topic', $response['body']['name']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/messaging/topics/' . $topicId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/messaging/topics/' . $topicId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/messaging/providers/' . $providerId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/messaging/providers/' . $providerId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);
    }

    public function testAppwriteMigrationMessagingSubscriber(): void
    {
        $user = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => uniqid() . '-migration-sub@test.com',
            'password' => 'password',
        ]);

        $this->assertEquals(201, $user['headers']['status-code']);
        $userId = $user['body']['$id'];
        $this->assertEquals(1, \count($user['body']['targets']));
        $targetId = $user['body']['targets'][0]['$id'];

        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/sendgrid', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'providerId' => ID::unique(),
            'name' => 'Migration Sendgrid Subscriber',
            'apiKey' => 'my-apikey',
            'from' => uniqid() . '-migration-sub@test.com',
        ]);

        $this->assertEquals(201, $provider['headers']['status-code']);
        $providerId = $provider['body']['$id'];

        $topic = $this->client->call(Client::METHOD_POST, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'topicId' => ID::unique(),
            'name' => 'Migration Subscriber Topic',
        ]);

        $this->assertEquals(201, $topic['headers']['status-code']);
        $topicId = $topic['body']['$id'];

        $subscriber = $this->client->call(Client::METHOD_POST, '/messaging/topics/' . $topicId . '/subscribers', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'subscriberId' => ID::unique(),
            'targetId' => $targetId,
        ]);

        $this->assertEquals(201, $subscriber['headers']['status-code']);
        $subscriberId = $subscriber['body']['$id'];

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_USER,
                Resource::TYPE_PROVIDER,
                Resource::TYPE_TOPIC,
                Resource::TYPE_SUBSCRIBER,
            ],
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertArrayHasKey(Resource::TYPE_SUBSCRIBER, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_SUBSCRIBER]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_SUBSCRIBER]['pending']);
        $this->assertGreaterThanOrEqual(1, $result['statusCounters'][Resource::TYPE_SUBSCRIBER]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_SUBSCRIBER]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_SUBSCRIBER]['warning']);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $topicId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($topicId, $response['body']['$id']);
        $this->assertGreaterThanOrEqual(1, $response['body']['emailTotal']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/messaging/topics/' . $topicId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/messaging/topics/' . $topicId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/messaging/providers/' . $providerId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/messaging/providers/' . $providerId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/users/' . $userId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/users/' . $userId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);
    }

    public function testAppwriteMigrationMessagingMessage(): void
    {
        $this->getDestinationProject(true);

        $user = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => uniqid() . '-migration-msg@test.com',
            'password' => 'password',
        ]);

        $this->assertEquals(201, $user['headers']['status-code']);
        $userId = $user['body']['$id'];
        $this->assertEquals(1, \count($user['body']['targets']));
        $targetId = $user['body']['targets'][0]['$id'];

        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/sendgrid', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'providerId' => ID::unique(),
            'name' => 'Migration Sendgrid Message',
            'apiKey' => 'my-apikey',
            'from' => 'migration-msg@test.com',
        ]);

        $this->assertEquals(201, $provider['headers']['status-code']);
        $providerId = $provider['body']['$id'];

        $topic = $this->client->call(Client::METHOD_POST, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'topicId' => ID::unique(),
            'name' => 'Migration Message Topic',
        ]);

        $this->assertEquals(201, $topic['headers']['status-code']);
        $topicId = $topic['body']['$id'];

        $message = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'targets' => [$targetId],
            'topics' => [$topicId],
            'subject' => 'Migration Test Email',
            'content' => 'This is a migration test email',
            'draft' => true,
        ]);

        $this->assertEquals(201, $message['headers']['status-code']);
        $this->assertNotEmpty($message['body']['$id']);

        $messageId = $message['body']['$id'];

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_USER,
                Resource::TYPE_PROVIDER,
                Resource::TYPE_TOPIC,
                Resource::TYPE_SUBSCRIBER,
                Resource::TYPE_MESSAGE,
            ],
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertArrayHasKey(Resource::TYPE_MESSAGE, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_MESSAGE]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_MESSAGE]['pending']);
        $this->assertGreaterThanOrEqual(1, $result['statusCounters'][Resource::TYPE_MESSAGE]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_MESSAGE]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_MESSAGE]['warning']);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $messageId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($messageId, $response['body']['$id']);
        $this->assertEquals('draft', $response['body']['status']);
        $this->assertEquals('Migration Test Email', $response['body']['data']['subject']);
        $this->assertEquals('This is a migration test email', $response['body']['data']['content']);
        $this->assertContains($topicId, $response['body']['topics']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/messaging/messages/' . $messageId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/messaging/messages/' . $messageId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/messaging/topics/' . $topicId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/messaging/topics/' . $topicId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/messaging/providers/' . $providerId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/messaging/providers/' . $providerId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/users/' . $userId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/users/' . $userId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);
    }
}
