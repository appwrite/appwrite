<?php

namespace Tests\E2E\Services\Migrations;

use Appwrite\Tests\Retry;
use CURLFile;
use PHPUnit\Framework\Attributes\Depends;
use Tests\E2E\Client;
use Tests\E2E\General\UsageTest;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Services\Functions\FunctionsBase;
use Utopia\Console;
use Utopia\Database\Database;
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
        if (!empty(self::$cachedDatabaseData)) {
            return self::$cachedDatabaseData;
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

        self::$cachedDatabaseData = [
            'databaseId' => $response['body']['$id'],
        ];

        return self::$cachedDatabaseData;
    }

    /**
     * Set up a table with column for migration tests with static caching
     * @return array
     */
    protected function setupMigrationTable(): array
    {
        if (!empty(self::$cachedTableData)) {
            return self::$cachedTableData;
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

        self::$cachedTableData = [
            'databaseId' => $databaseId,
            'tableId' => $tableId,
        ];

        return self::$cachedTableData;
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
     * Get migration status by ID (without creating a new migration)
     *
     * @param string $migrationId
     * @return array
     */
    public function getMigrationStatus(string $migrationId): array
    {
        $response = $this->client->call(Client::METHOD_GET, '/migrations/' . $migrationId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);

        return $response['body'];
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
        self::$cachedDatabaseData = [];
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
        self::$cachedDatabaseData = [];
        self::$cachedTableData = [];
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
     * Sites
     */
    public function testAppwriteMigrationSite(): void
    {
        $site = $this->client->call(Client::METHOD_POST, '/sites', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'siteId' => ID::unique(),
            'name' => 'Test Site',
            'framework' => 'other',
            'buildRuntime' => 'node-22',
            'adapter' => 'static',
            'outputDirectory' => './',
        ]);

        $this->assertEquals(201, $site['headers']['status-code'], 'Create site failed: ' . json_encode($site['body'], JSON_PRETTY_PRINT));
        $this->assertNotEmpty($site['body']['$id']);

        $siteId = $site['body']['$id'];

        // Create deployment
        $deployment = $this->client->call(Client::METHOD_POST, '/sites/' . $siteId . '/deployments', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'code' => $this->packageSite('static'),
            'activate' => true,
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);

        $deploymentId = $deployment['body']['$id'];

        // Wait for deployment to be ready
        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $response = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/deployments/' . $deploymentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals('ready', $response['body']['status'], 'Deployment status is not ready, deployment: ' . json_encode($response['body'], JSON_PRETTY_PRINT));
        }, 300000, 500);

        // Create environment variable
        $variable = $this->client->call(Client::METHOD_POST, '/sites/' . $siteId . '/variables', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'TEST_VAR',
            'value' => 'test_value',
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);

        // Perform migration
        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_SITE,
                Resource::TYPE_SITE_DEPLOYMENT,
                Resource::TYPE_SITE_VARIABLE,
            ],
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_SITE, Resource::TYPE_SITE_DEPLOYMENT, Resource::TYPE_SITE_VARIABLE], $result['resources']);

        foreach ([Resource::TYPE_SITE, Resource::TYPE_SITE_DEPLOYMENT, Resource::TYPE_SITE_VARIABLE] as $resource) {
            $this->assertArrayHasKey($resource, $result['statusCounters']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['error']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['pending']);
            $this->assertEquals(1, $result['statusCounters'][$resource]['success']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['processing']);
            $this->assertEquals(0, $result['statusCounters'][$resource]['warning']);
        }

        // Verify site in destination
        $response = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals($siteId, $response['body']['$id']);
        $this->assertEquals('Test Site', $response['body']['name']);
        $this->assertEquals('node-22', $response['body']['buildRuntime']);
        $this->assertEquals('other', $response['body']['framework']);
        $this->assertEquals('static', $response['body']['adapter']);

        // Verify deployment in destination
        $this->assertEventually(function () use ($siteId) {
            $deployments = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/deployments', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getDestinationProject()['$id'],
                'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
            ]);

            $this->assertEquals(200, $deployments['headers']['status-code']);
            $this->assertNotEmpty($deployments['body']);
            $this->assertEquals(1, $deployments['body']['total']);
            $this->assertEquals('ready', $deployments['body']['deployments'][0]['status'], 'Deployment status is not ready, deployment: ' . json_encode($deployments['body']['deployments'][0], JSON_PRETTY_PRINT));
        }, 100000, 500);

        // Verify variable in destination
        $variables = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/variables', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $variables['headers']['status-code']);
        $this->assertEquals(1, $variables['body']['total']);
        $this->assertEquals('TEST_VAR', $variables['body']['variables'][0]['key']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/sites/' . $siteId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/sites/' . $siteId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);
    }

    private function packageSite(string $site): CURLFile
    {
        $stdout = '';
        $stderr = '';
        $folderPath = realpath(__DIR__ . '/../../../resources/sites') . "/$site";
        $tarPath = "$folderPath/code.tar.gz";

        Console::execute("cd $folderPath && tar --exclude code.tar.gz --exclude node_modules -czf code.tar.gz .", '', $stdout, $stderr);

        return new CURLFile($tarPath, 'application/x-gzip', \basename($tarPath));
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

        $this->assertEventually(function () use ($missingColumn) {
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

        $this->assertEventually(function () use ($missingColumn) {
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

        $this->assertEventually(function () use ($irrelevantColumn) {
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

        $this->assertEventually(function () use ($migration) {
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

        $this->assertEventually(function () use ($migration) {
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
        $lastEmail = $this->getLastEmail(probe: function ($email) {
            $this->assertEquals('Your CSV export is ready', $email['subject']);
        });
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

    public function testAppwriteMigrationMessagingProviderSMTP(): void
    {
        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/smtp', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'providerId' => ID::unique(),
            'name' => 'Migration SMTP',
            'host' => 'smtp.test.com',
            'port' => 587,
            'from' => 'migration-smtp@test.com',
        ]);

        $this->assertEquals(201, $provider['headers']['status-code']);
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
        $this->assertArrayHasKey(Resource::TYPE_PROVIDER, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROVIDER]['error']);
        $this->assertGreaterThanOrEqual(1, $result['statusCounters'][Resource::TYPE_PROVIDER]['success']);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/providers/' . $providerId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($providerId, $response['body']['$id']);
        $this->assertEquals('Migration SMTP', $response['body']['name']);
        $this->assertEquals('email', $response['body']['type']);
        $this->assertEquals('smtp', $response['body']['provider']);

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

    public function testAppwriteMigrationMessagingProviderTwilio(): void
    {
        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/twilio', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'providerId' => ID::unique(),
            'name' => 'Migration Twilio',
            'from' => '+15551234567',
            'accountSid' => 'test-account-sid',
            'authToken' => 'test-auth-token',
        ]);

        $this->assertEquals(201, $provider['headers']['status-code']);
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
        $this->assertArrayHasKey(Resource::TYPE_PROVIDER, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROVIDER]['error']);
        $this->assertGreaterThanOrEqual(1, $result['statusCounters'][Resource::TYPE_PROVIDER]['success']);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/providers/' . $providerId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($providerId, $response['body']['$id']);
        $this->assertEquals('Migration Twilio', $response['body']['name']);
        $this->assertEquals('sms', $response['body']['type']);
        $this->assertEquals('twilio', $response['body']['provider']);

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

    public function testAppwriteMigrationMessagingSmsMessage(): void
    {
        $this->getDestinationProject(true);

        $user = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => uniqid() . '-migration-sms@test.com',
            'phone' => '+1' . str_pad((string) rand(200000000, 999999999), 10, '0', STR_PAD_LEFT),
            'password' => 'password',
        ]);

        $this->assertEquals(201, $user['headers']['status-code']);
        $userId = $user['body']['$id'];
        $this->assertGreaterThanOrEqual(1, \count($user['body']['targets']));

        $smsTarget = null;
        foreach ($user['body']['targets'] as $target) {
            if ($target['providerType'] === 'sms') {
                $smsTarget = $target;
                break;
            }
        }
        $this->assertNotNull($smsTarget);
        $targetId = $smsTarget['$id'];

        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/twilio', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'providerId' => ID::unique(),
            'name' => 'Migration Twilio SMS Msg',
            'from' => '+15559876543',
            'accountSid' => 'test-account-sid',
            'authToken' => 'test-auth-token',
        ]);

        $this->assertEquals(201, $provider['headers']['status-code']);
        $providerId = $provider['body']['$id'];

        $topic = $this->client->call(Client::METHOD_POST, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'topicId' => ID::unique(),
            'name' => 'Migration SMS Topic',
        ]);

        $this->assertEquals(201, $topic['headers']['status-code']);
        $topicId = $topic['body']['$id'];

        $message = $this->client->call(Client::METHOD_POST, '/messaging/messages/sms', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'targets' => [$targetId],
            'topics' => [$topicId],
            'content' => 'Migration SMS test content',
            'draft' => true,
        ]);

        $this->assertEquals(201, $message['headers']['status-code']);
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
        $this->assertGreaterThanOrEqual(1, $result['statusCounters'][Resource::TYPE_MESSAGE]['success']);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $messageId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($messageId, $response['body']['$id']);
        $this->assertEquals('draft', $response['body']['status']);
        $this->assertEquals('Migration SMS test content', $response['body']['data']['content']);

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

    public function testAppwriteMigrationMessagingScheduledMessage(): void
    {
        $this->getDestinationProject(true);

        $user = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => uniqid() . '-migration-sched@test.com',
            'password' => 'password',
        ]);

        $this->assertEquals(201, $user['headers']['status-code']);
        $userId = $user['body']['$id'];
        $targetId = $user['body']['targets'][0]['$id'];

        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/sendgrid', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'providerId' => ID::unique(),
            'name' => 'Migration Sendgrid Scheduled',
            'apiKey' => 'my-apikey',
            'from' => 'migration-sched@test.com',
        ]);

        $this->assertEquals(201, $provider['headers']['status-code']);
        $providerId = $provider['body']['$id'];

        $topic = $this->client->call(Client::METHOD_POST, '/messaging/topics', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'topicId' => ID::unique(),
            'name' => 'Migration Scheduled Topic',
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

        // Create a scheduled message with a future date using topics only
        // Direct targets use source IDs which won't resolve in the destination via API
        $futureDate = (new \DateTime('+1 year'))->format(\DateTime::ATOM);
        $message = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'messageId' => ID::unique(),
            'topics' => [$topicId],
            'subject' => 'Migration Scheduled Email',
            'content' => 'This is a scheduled migration test email',
            'scheduledAt' => $futureDate,
        ]);

        $this->assertEquals(201, $message['headers']['status-code']);
        $messageId = $message['body']['$id'];
        $this->assertEquals('scheduled', $message['body']['status']);

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
        $this->assertGreaterThanOrEqual(1, $result['statusCounters'][Resource::TYPE_MESSAGE]['success']);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $messageId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($messageId, $response['body']['$id']);
        $this->assertEquals('scheduled', $response['body']['status']);
        $this->assertEquals('Migration Scheduled Email', $response['body']['data']['subject']);
        $this->assertEquals(
            (new \DateTime($futureDate))->getTimestamp(),
            (new \DateTime($response['body']['scheduledAt']))->getTimestamp(),
        );

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

    /**
     * Import VectorsDB documents from CSV
     */
    public function testImportVectordbCSV(): void
    {
        $databaseId = null;
        $collectionId = null;
        $bucketId = null;

        try {
            $database = $this->client->call(Client::METHOD_POST, '/vectorsdb', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ], [
                'databaseId' => ID::unique(),
                'name' => 'Vector CSV Import DB'
            ]);

            $this->assertEquals(201, $database['headers']['status-code']);
            $databaseId = $database['body']['$id'];

            $collection = $this->client->call(Client::METHOD_POST, '/vectorsdb/' . $databaseId . '/collections', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ], [
                'collectionId' => ID::unique(),
                'name' => 'Vector CSV Import Collection',
                'dimension' => 3,
                'documentSecurity' => true,
            ]);

            $this->assertEquals(201, $collection['headers']['status-code']);
            $collectionId = $collection['body']['$id'];

            $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], [
                'bucketId' => ID::unique(),
                'name' => 'Vector CSV Bucket',
                'maximumFileSize' => 2000000,
                'allowedFileExtensions' => ['csv'],
            ]);

            $this->assertEquals(201, $bucket['headers']['status-code']);
            $bucketId = $bucket['body']['$id'];

            $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], [
                'fileId' => ID::unique(),
                'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/csv/vectorsdb-documents.csv'), 'text/csv', 'vectorsdb-documents.csv'),
            ]);

            $this->assertEquals(201, $file['headers']['status-code']);
            $fileId = $file['body']['$id'];

            $migration = $this->performCsvMigration([
                'fileId' => $fileId,
                'bucketId' => $bucketId,
                'resourceId' => $databaseId . ':' . $collectionId,
            ]);

            $this->assertEquals(202, $migration['headers']['status-code']);

            $this->assertEventually(function () use ($migration) {
                $migrationId = $migration['body']['$id'];
                $status = $this->client->call(Client::METHOD_GET, '/migrations/' . $migrationId, [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ]);

                $this->assertEquals(200, $status['headers']['status-code']);
                $this->assertEquals('finished', $status['body']['stage']);
                $this->assertEquals('completed', $status['body']['status']);
                $this->assertContains(Resource::TYPE_DOCUMENT, $status['body']['resources']);
                $this->assertArrayHasKey(Resource::TYPE_DOCUMENT, $status['body']['statusCounters']);
                $this->assertEquals(2, $status['body']['statusCounters'][Resource::TYPE_DOCUMENT]['success']);

                return true;
            }, 60_000, 500);

            $documents = $this->client->call(Client::METHOD_GET, '/vectorsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], [
                'queries' => [
                    Query::limit(10)->toString(),
                ],
            ]);

            $this->assertEquals(200, $documents['headers']['status-code']);
            $this->assertEquals(2, $documents['body']['total']);

            $titles = array_map(fn ($doc) => $doc['metadata']['title'] ?? null, $documents['body']['documents']);
            $this->assertContains('Vector Alpha', $titles);
            $this->assertContains('Vector Beta', $titles);
        } finally {
            if ($bucketId) {
                $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId, [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ]);
            }

            if ($databaseId) {
                $this->client->call(Client::METHOD_DELETE, '/vectorsdb/' . $databaseId, [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ]);
            }
        }
    }

    /**
     * Export VectorsDB documents to CSV
     */
    #[Retry(count: 1)]
    public function testExportVectordbCSV(): void
    {
        $databaseId = null;

        try {
            $database = $this->client->call(Client::METHOD_POST, '/vectorsdb', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], [
                'databaseId' => ID::unique(),
                'name' => 'Vector CSV Export DB',
            ]);

            $this->assertEquals(201, $database['headers']['status-code']);
            $databaseId = $database['body']['$id'];

            $collectionId = null;
            $this->assertEventually(function () use ($databaseId, &$collectionId) {
                $collection = $this->client->call(Client::METHOD_POST, '/vectorsdb/' . $databaseId . '/collections', [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ], [
                    'collectionId' => ID::unique(),
                    'name' => 'Vector CSV Export Collection',
                    'dimension' => 3,
                    'documentSecurity' => true,
                ]);

                $this->assertEquals(201, $collection['headers']['status-code']);
                $collectionId = $collection['body']['$id'];
            });

            $documentsPayload = [
                [
                    'documentId' => ID::unique(),
                    'data' => [
                        'embeddings' => [0.11, 0.22, 0.33],
                        'metadata' => ['title' => 'Vector Sample One', 'category' => 'alpha'],
                    ],
                ],
                [
                    'documentId' => ID::unique(),
                    'data' => [
                        'embeddings' => [0.44, 0.55, 0.66],
                        'metadata' => ['title' => 'Vector Sample Two', 'category' => 'beta'],
                    ],
                ],
            ];

            foreach ($documentsPayload as $payload) {
                $response = $this->client->call(Client::METHOD_POST, '/vectorsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ], $payload);

                $this->assertEquals(201, $response['headers']['status-code']);
            }

            $filename = 'vectorsdb-export-' . ID::unique();
            $migration = $this->client->call(Client::METHOD_POST, '/migrations/csv/exports', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'resourceId' => $databaseId . ':' . $collectionId,
                'filename' => $filename,
                'columns' => [],
                'queries' => [],
                'delimiter' => ',',
                'enclosure' => '"',
                'escape' => '\\',
                'header' => true,
                'notify' => true,
            ]);

            $this->assertEquals(202, $migration['headers']['status-code']);

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

                return true;
            }, 30_000, 500);

            $this->assertEventually(function () {
                $email = $this->getLastEmail(1, function (array $email) {
                    $this->assertEquals('Your CSV export is ready', $email['subject']);
                });
                $this->assertNotEmpty($email);
                $this->assertEquals('Your CSV export is ready', $email['subject']);
                \preg_match('/href="([^"]*\/storage\/buckets\/[^"]*\/push[^"]*)"/', $email['html'], $matches);
                $this->assertNotEmpty($matches[1], 'Download URL not found in email');
                $downloadUrl = html_entity_decode($matches[1]);
                $components = \parse_url($downloadUrl);
                $this->assertNotEmpty($components);
                \parse_str($components['query'] ?? '', $queryParams);
                $this->assertArrayHasKey('jwt', $queryParams);
                $this->assertArrayHasKey('project', $queryParams);

                $path = \str_replace('/v1', '', $components['path']);
                $downloadResponse = $this->client->call(Client::METHOD_GET, $path . '?project=' . $queryParams['project'] . '&jwt=' . $queryParams['jwt']);
                $this->assertEquals(200, $downloadResponse['headers']['status-code']);

                $csvData = $downloadResponse['body'];
                $this->assertStringContainsString('Vector Sample One', $csvData);
                $this->assertStringContainsString('Vector Sample Two', $csvData);
                $this->assertStringContainsString('[0.11,0.22,0.33]', $csvData);
            }, 30_000, 500);
        } finally {
            if ($databaseId) {
                $this->client->call(Client::METHOD_DELETE, '/vectorsdb/' . $databaseId, [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ]);
            }
        }
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
                Resource::TYPE_DATABASE_DOCUMENTSDB,
            ],
            'endpoint' => $this->endpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_DATABASE_DOCUMENTSDB], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_DATABASE_DOCUMENTSDB, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE_DOCUMENTSDB]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE_DOCUMENTSDB]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_DATABASE_DOCUMENTSDB]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE_DOCUMENTSDB]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE_DOCUMENTSDB]['warning']);

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
     * VectorsDB (embeddings collections)
     */
    public function testAppwriteMigrationVectorsDBDatabase(): array
    {
        $response = $this->client->call(Client::METHOD_POST, '/vectorsdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'databaseId' => ID::unique(),
            'name' => 'VDB - Migration DB'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        $databaseId = $response['body']['$id'];

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_DATABASE_VECTORSDB,
            ],
            'endpoint' => $this->endpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_DATABASE_VECTORSDB], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_DATABASE_VECTORSDB, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE_VECTORSDB]['error'] ?? 0);

        $response = $this->client->call(Client::METHOD_GET, '/vectorsdb/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($databaseId, $response['body']['$id']);
        $this->assertEquals('VDB - Migration DB', $response['body']['name']);

        // Cleanup on destination
        $this->client->call(Client::METHOD_DELETE, '/vectorsdb/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        return [
            'databaseId' => $databaseId,
        ];
    }

    #[Depends('testAppwriteMigrationVectorsDBDatabase')]
    public function testAppwriteMigrationVectorsDBCollection(array $data): array
    {
        $databaseId = $data['databaseId'];

        $collection = $this->client->call(Client::METHOD_POST, '/vectorsdb/' . $databaseId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'collectionId' => ID::unique(),
            'name' => 'VDB - Movies',
            'dimension' => 3,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $collectionId = $collection['body']['$id'];

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_DATABASE_VECTORSDB,
                Resource::TYPE_COLLECTION,
                Resource::TYPE_ATTRIBUTE,
            ],
            'endpoint' => $this->endpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals('completed', $result['status']);

        $response = $this->client->call(Client::METHOD_GET, '/vectorsdb/' . $databaseId . '/collections/' . $collectionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals($collectionId, $response['body']['$id']);
        $this->assertEquals('VDB - Movies', $response['body']['name']);
        // Verify attributes are present (embeddings and metadata are default attributes)
        $this->assertArrayHasKey('attributes', $response['body']);
        $this->assertIsArray($response['body']['attributes']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/vectorsdb/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        return [
            'databaseId' => $databaseId,
            'collectionId' => $collectionId,
        ];
    }

    #[Depends('testAppwriteMigrationVectorsDBCollection')]
    public function testAppwriteMigrationVectorsDBDocument(array $data): void
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $document = $this->client->call(Client::METHOD_POST, '/vectorsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'embeddings' => [1.0, 0.0, 0.0],
                'metadata' => ['title' => 'Migration Test Movie'],
            ]
        ]);

        $this->assertEquals(201, $document['headers']['status-code']);
        $documentId = $document['body']['$id'];

        // Ensure attributes are exported before documents
        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_DATABASE_VECTORSDB,
                Resource::TYPE_COLLECTION,
                Resource::TYPE_ATTRIBUTE,
                Resource::TYPE_DOCUMENT,
            ],
            'endpoint' => $this->endpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        // Verify that TYPE_ATTRIBUTE appears in the resources array for VectorsDB
        $this->assertContains(Resource::TYPE_ATTRIBUTE, $result['resources'], 'TYPE_ATTRIBUTE should be in resources array for VectorsDB');

        // Verify attributes exist on destination before checking document
        $collectionResponse = $this->client->call(Client::METHOD_GET, '/vectorsdb/' . $databaseId . '/collections/' . $collectionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $collectionResponse['headers']['status-code']);
        $this->assertArrayHasKey('attributes', $collectionResponse['body']);
        $this->assertIsArray($collectionResponse['body']['attributes']);

        $response = $this->client->call(Client::METHOD_GET, '/vectorsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals($documentId, $response['body']['$id']);
        $this->assertEquals('Migration Test Movie', $response['body']['metadata']['title']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/vectorsdb/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/vectorsdb/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
    }

    #[Depends('testAppwriteMigrationDocumentsDBDatabase')]
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
                Resource::TYPE_DATABASE_DOCUMENTSDB,
                Resource::TYPE_COLLECTION, // collections in DocumentsDB map to tables in migration
            ],
            'endpoint' => $this->endpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals('completed', $result['status']);
        foreach ([Resource::TYPE_DATABASE_DOCUMENTSDB, Resource::TYPE_COLLECTION] as $resource) {
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

    #[Depends('testAppwriteMigrationDocumentsDBCollection')]
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
                Resource::TYPE_DATABASE_DOCUMENTSDB,
                Resource::TYPE_COLLECTION,
                Resource::TYPE_DOCUMENT,
            ],
            'endpoint' => $this->endpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);

        foreach ([Resource::TYPE_DATABASE_DOCUMENTSDB] as $resource) {
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

        $sqlIndexKey = 'product_unique';

        $sqlIndex = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $sqlDatabaseId . '/tables/' . $tableId . '/indexes', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProject['$id'],
            'x-appwrite-key' => $sourceProject['apiKey'],
        ], [
            'key' => $sqlIndexKey,
            'type' => Database::INDEX_UNIQUE,
            'columns' => ['productName'],
        ]);

        $this->assertEquals(202, $sqlIndex['headers']['status-code']);

        $this->assertEventually(function () use ($sqlDatabaseId, $tableId, $sqlIndexKey, $sourceProject) {
            $index = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $sqlDatabaseId . '/tables/' . $tableId . '/indexes/' . $sqlIndexKey, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $sourceProject['$id'],
                'x-appwrite-key' => $sourceProject['apiKey'],
            ]);

            $this->assertEquals(200, $index['headers']['status-code']);
            $this->assertEquals('available', $index['body']['status']);
        }, 30000, 500);

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

        $documentsIndexKey = 'email_unique';

        $documentsIndex = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $docsDatabaseId . '/collections/' . $collectionId . '/indexes', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProject['$id'],
            'x-appwrite-key' => $sourceProject['apiKey'],
        ], [
            'key' => $documentsIndexKey,
            'type' => Database::INDEX_UNIQUE,
            'attributes' => ['email'],
        ]);

        $this->assertEquals(202, $documentsIndex['headers']['status-code']);

        $this->assertEventually(function () use ($docsDatabaseId, $collectionId, $documentsIndexKey, $sourceProject) {
            $index = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $docsDatabaseId . '/collections/' . $collectionId . '/indexes/' . $documentsIndexKey, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $sourceProject['$id'],
                'x-appwrite-key' => $sourceProject['apiKey'],
            ]);

            $this->assertEquals(200, $index['headers']['status-code']);
            $this->assertEquals('available', $index['body']['status']);
        }, 30000, 500);

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

        // ====== Create VectorsDB (/vectorsdb) with collection and document ======
        $vector = $this->client->call(Client::METHOD_POST, '/vectorsdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProject['$id'],
            'x-appwrite-key' => $sourceProject['apiKey'],
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Mixed VectorsDB',
        ]);

        $this->assertEquals(201, $vector['headers']['status-code']);
        $this->assertNotEmpty($vector['body']['$id']);
        $vectorDatabaseId = $vector['body']['$id'];

        // Create Collection in VectorsDB
        $vectorCollection = $this->client->call(Client::METHOD_POST, '/vectorsdb/' . $vectorDatabaseId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProject['$id'],
            'x-appwrite-key' => $sourceProject['apiKey'],
        ], [
            'collectionId' => ID::unique(),
            'name' => 'Products',
            'dimension' => 3,
        ]);

        $this->assertEquals(201, $vectorCollection['headers']['status-code']);
        $vectorCollectionId = $vectorCollection['body']['$id'];

        // Wait for VectorsDB collection attributes to be ready
        $this->assertEventually(function () use ($vectorDatabaseId, $vectorCollectionId, $sourceProject) {
            $response = $this->client->call(Client::METHOD_GET, '/vectorsdb/' . $vectorDatabaseId . '/collections/' . $vectorCollectionId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $sourceProject['$id'],
                'x-appwrite-key' => $sourceProject['apiKey'],
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertArrayHasKey('attributes', $response['body']);
            $this->assertIsArray($response['body']['attributes']);
            // Check that default attributes (embeddings and metadata) are present and ready
            $attributeKeys = array_column($response['body']['attributes'], 'key');
            $this->assertContains('embeddings', $attributeKeys);
            $this->assertContains('metadata', $attributeKeys);
            // Check that attributes are available (if status field exists)
            foreach ($response['body']['attributes'] as $attribute) {
                if (isset($attribute['status']) && $attribute['status'] !== 'available') {
                    return false;
                }
            }
            return true;
        }, 10000, 500);

        $metadataIndexKey = '_key_metadata';
        $vectorIndexes = $this->client->call(Client::METHOD_GET, '/vectorsdb/' . $vectorDatabaseId . '/collections/' . $vectorCollectionId . '/indexes', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProject['$id'],
            'x-appwrite-key' => $sourceProject['apiKey'],
        ]);
        $this->assertEquals(200, $vectorIndexes['headers']['status-code']);
        $metadataIndex = null;
        foreach ($vectorIndexes['body']['indexes'] ?? [] as $index) {
            if (($index['key'] ?? '') === $metadataIndexKey) {
                $metadataIndex = $index;
                break;
            }
        }
        $this->assertNotNull($metadataIndex, 'Default metadata index should exist on source collection');
        $this->assertEquals(Database::INDEX_OBJECT, $metadataIndex['type']);

        $vectorEmbeddingIndexKey = 'embedding_euclidean';
        $vectorEmbeddingIndex = $this->client->call(Client::METHOD_POST, '/vectorsdb/' . $vectorDatabaseId . '/collections/' . $vectorCollectionId . '/indexes', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProject['$id'],
            'x-appwrite-key' => $sourceProject['apiKey'],
        ], [
            'key' => $vectorEmbeddingIndexKey,
            'type' => Database::INDEX_HNSW_EUCLIDEAN,
            'attributes' => ['embeddings'],
        ]);
        $this->assertEquals(202, $vectorEmbeddingIndex['headers']['status-code']);

        $this->assertEventually(function () use ($vectorDatabaseId, $vectorCollectionId, $vectorEmbeddingIndexKey, $sourceProject) {
            $index = $this->client->call(Client::METHOD_GET, '/vectorsdb/' . $vectorDatabaseId . '/collections/' . $vectorCollectionId . '/indexes/' . $vectorEmbeddingIndexKey, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $sourceProject['$id'],
                'x-appwrite-key' => $sourceProject['apiKey'],
            ]);

            $this->assertEquals(200, $index['headers']['status-code']);
            $this->assertEquals(Database::INDEX_HNSW_EUCLIDEAN, $index['body']['type']);
            if (isset($index['body']['status'])) {
                $this->assertEquals('available', $index['body']['status']);
            }
        }, 30000, 500);

        // Create Document in VectorsDB Collection
        $vectorDocument = $this->client->call(Client::METHOD_POST, '/vectorsdb/' . $vectorDatabaseId . '/collections/' . $vectorCollectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProject['$id'],
            'x-appwrite-key' => $sourceProject['apiKey'],
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'embeddings' => [0.5, 0.3, 0.2],
                'metadata' => ['name' => 'Product Vector'],
            ],
        ]);

        $this->assertEquals(201, $vectorDocument['headers']['status-code']);
        $vectorDocumentId = $vectorDocument['body']['$id'];

        // ====== Perform migration including all three database kinds with all child resources ======
        $migrationConfig = [
            'resources' => [
                Resource::TYPE_DATABASE,
                Resource::TYPE_TABLE,
                Resource::TYPE_COLUMN,
                Resource::TYPE_ROW,
                Resource::TYPE_DATABASE_DOCUMENTSDB,
                Resource::TYPE_COLLECTION,
                Resource::TYPE_DOCUMENT,
                Resource::TYPE_DATABASE_VECTORSDB,
                Resource::TYPE_ATTRIBUTE,
                Resource::TYPE_INDEX,
            ],
            'endpoint' => $this->endpoint,
            'projectId' => $sourceProject['$id'],
            'apiKey' => $sourceProject['apiKey'],
        ];

        // Perform migration sync once and get migration ID
        $result = $this->performMigrationSync($migrationConfig);
        $migrationId = $result['$id'];
        $this->assertEquals('completed', $result['status']);
        $this->assertEquals('Appwrite', $result['source']);
        $this->assertEquals('Appwrite', $result['destination']);
        $this->assertEquals([
            Resource::TYPE_DATABASE,
            Resource::TYPE_TABLE,
            Resource::TYPE_COLUMN,
            Resource::TYPE_ROW,
            Resource::TYPE_DATABASE_DOCUMENTSDB,
            Resource::TYPE_COLLECTION,
            Resource::TYPE_DOCUMENT,
            Resource::TYPE_DATABASE_VECTORSDB,
            Resource::TYPE_ATTRIBUTE,
            Resource::TYPE_INDEX,
        ], $result['resources']);

        // Get migration status before asserting SQL Database counters
        $result = $this->getMigrationStatus($migrationId);
        // Assert SQL Database counters
        $this->assertArrayHasKey(Resource::TYPE_DATABASE, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_DATABASE]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE]['warning']);

        // Get migration status before asserting Table counters
        $result = $this->getMigrationStatus($migrationId);
        // Assert Table counters
        $this->assertArrayHasKey(Resource::TYPE_TABLE, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_TABLE]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_TABLE]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_TABLE]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_TABLE]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_TABLE]['warning']);

        // Get migration status before asserting Column counters
        $result = $this->getMigrationStatus($migrationId);
        // Assert Column counters
        $this->assertArrayHasKey(Resource::TYPE_COLUMN, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_COLUMN]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_COLUMN]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_COLUMN]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_COLUMN]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_COLUMN]['warning']);

        // Get migration status before asserting Row counters
        $result = $this->getMigrationStatus($migrationId);
        // Assert Row counters
        $this->assertArrayHasKey(Resource::TYPE_ROW, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_ROW]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_ROW]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_ROW]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_ROW]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_ROW]['warning']);

        // Get migration status before asserting DocumentsDB counters
        $result = $this->getMigrationStatus($migrationId);
        // Assert DocumentsDB counters
        $this->assertArrayHasKey(Resource::TYPE_DATABASE_DOCUMENTSDB, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE_DOCUMENTSDB]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE_DOCUMENTSDB]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_DATABASE_DOCUMENTSDB]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE_DOCUMENTSDB]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE_DOCUMENTSDB]['warning']);

        // Wait for all collections to be fully processed and status counters to be updated
        // Note: Collections are being transferred but status counters may not be updated immediately
        // This wait ensures the migration worker has finished processing all collections
        $result = null;
        $this->assertEventually(function () use ($migrationId, &$result) {
            $result = $this->getMigrationStatus($migrationId);

            // Check if collections status counters exist
            if (!isset($result['statusCounters'][Resource::TYPE_COLLECTION])) {
                return false;
            }

            $pendingCount = $result['statusCounters'][Resource::TYPE_COLLECTION]['pending'] ?? 0;

            // Return true only when pending count is 0
            return $pendingCount === 0;
        }, 30000, 1000); // 30 second timeout, check every 1 second

        // Assert Collection counters (covers both DocumentsDB and VectorsDB collections)
        $this->assertArrayHasKey(Resource::TYPE_COLLECTION, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_COLLECTION]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_COLLECTION]['pending']);
        $this->assertGreaterThanOrEqual(1, $result['statusCounters'][Resource::TYPE_COLLECTION]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_COLLECTION]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_COLLECTION]['warning']);

        // Get migration status before asserting Document counters
        $result = $this->getMigrationStatus($migrationId);
        // Assert Document counters (covers both DocumentsDB and VectorsDB documents)
        $this->assertArrayHasKey(Resource::TYPE_DOCUMENT, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DOCUMENT]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DOCUMENT]['pending']);
        $this->assertGreaterThanOrEqual(1, $result['statusCounters'][Resource::TYPE_DOCUMENT]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DOCUMENT]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DOCUMENT]['warning']);

        // Get migration status before asserting VectorsDB counters
        $result = $this->getMigrationStatus($migrationId);
        // Assert VectorsDB counters
        $this->assertArrayHasKey(Resource::TYPE_DATABASE_VECTORSDB, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE_VECTORSDB]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE_VECTORSDB]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_DATABASE_VECTORSDB]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE_VECTORSDB]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_DATABASE_VECTORSDB]['warning']);

        // Get migration status before asserting Attribute counters
        $result = $this->getMigrationStatus($migrationId);
        // Assert Attribute counters (for VectorsDB)
        $this->assertArrayHasKey(Resource::TYPE_ATTRIBUTE, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_ATTRIBUTE]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_ATTRIBUTE]['pending']);
        $this->assertGreaterThanOrEqual(1, $result['statusCounters'][Resource::TYPE_ATTRIBUTE]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_ATTRIBUTE]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_ATTRIBUTE]['warning']);

        // Get migration status before asserting Index counters
        $result = $this->getMigrationStatus($migrationId);
        $this->assertArrayHasKey(Resource::TYPE_INDEX, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_INDEX]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_INDEX]['pending']);
        $this->assertGreaterThanOrEqual(4, $result['statusCounters'][Resource::TYPE_INDEX]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_INDEX]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_INDEX]['warning']);

        // Get migration status before asserting counter count
        $result = $this->getMigrationStatus($migrationId);
        // Ensure only expected counters exist (10 total)
        $this->assertCount(10, $result['statusCounters']);

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

        $sqlIndexDestination = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $sqlDatabaseId . '/tables/' . $tableId . '/indexes/' . $sqlIndexKey, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);
        $this->assertEquals(200, $sqlIndexDestination['headers']['status-code']);
        $this->assertEquals($sqlIndexKey, $sqlIndexDestination['body']['key']);
        $this->assertEquals(Database::INDEX_UNIQUE, $sqlIndexDestination['body']['type']);
        if (isset($sqlIndexDestination['body']['columns'])) {
            $this->assertEquals(['productName'], $sqlIndexDestination['body']['columns']);
        }

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

        $documentsIndexDestination = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $docsDatabaseId . '/collections/' . $collectionId . '/indexes/' . $documentsIndexKey, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);
        $this->assertEquals(200, $documentsIndexDestination['headers']['status-code']);
        $this->assertEquals($documentsIndexKey, $documentsIndexDestination['body']['key']);
        $this->assertEquals(Database::INDEX_UNIQUE, $documentsIndexDestination['body']['type']);
        if (isset($documentsIndexDestination['body']['attributes'])) {
            $this->assertEquals(['email'], $documentsIndexDestination['body']['attributes']);
        }

        // ====== Validate on destination: VectorsDB resources ======
        $response = $this->client->call(Client::METHOD_GET, '/vectorsdb/' . $vectorDatabaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($vectorDatabaseId, $response['body']['$id']);
        $this->assertEquals('Mixed VectorsDB', $response['body']['name']);

        // Validate VectorsDB Collection
        $response = $this->client->call(Client::METHOD_GET, '/vectorsdb/' . $vectorDatabaseId . '/collections/' . $vectorCollectionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($vectorCollectionId, $response['body']['$id']);
        $this->assertEquals('Products', $response['body']['name']);
        // Verify attributes are present (embeddings and metadata are default attributes)
        $this->assertArrayHasKey('attributes', $response['body']);
        $this->assertIsArray($response['body']['attributes']);

        $vectorIndexesDestination = $this->client->call(Client::METHOD_GET, '/vectorsdb/' . $vectorDatabaseId . '/collections/' . $vectorCollectionId . '/indexes', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);
        $this->assertEquals(200, $vectorIndexesDestination['headers']['status-code']);
        $indexByKey = [];
        foreach ($vectorIndexesDestination['body']['indexes'] ?? [] as $index) {
            if (isset($index['key'])) {
                $indexByKey[$index['key']] = $index;
            }
        }
        $this->assertArrayHasKey($metadataIndexKey, $indexByKey, 'Metadata index should exist on destination');
        $this->assertEquals(Database::INDEX_OBJECT, $indexByKey[$metadataIndexKey]['type']);
        $this->assertArrayHasKey($vectorEmbeddingIndexKey, $indexByKey, 'Embeddings HNSW index should exist on destination');
        $this->assertEquals(Database::INDEX_HNSW_EUCLIDEAN, $indexByKey[$vectorEmbeddingIndexKey]['type']);

        // Validate VectorsDB Document
        $response = $this->client->call(Client::METHOD_GET, '/vectorsdb/' . $vectorDatabaseId . '/collections/' . $vectorCollectionId . '/documents/' . $vectorDocumentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($vectorDocumentId, $response['body']['$id']);
        $this->assertEquals('Product Vector', $response['body']['metadata']['name']);

        // ====== Cleanup all destinations ======
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

        $this->client->call(Client::METHOD_DELETE, '/vectorsdb/' . $vectorDatabaseId, [
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

        $this->client->call(Client::METHOD_DELETE, '/vectorsdb/' . $vectorDatabaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProject['$id'],
            'x-appwrite-key' => $sourceProject['apiKey'],
        ]);
    }

    public function testCreateJSONImport(): void
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
            'allowedFileExtensions' => ['json'],
            'compression' => 'gzip',
            'encryption' => true
        ]);
        $this->assertEquals(201, $bucketOne['headers']['status-code']);
        $this->assertNotEmpty($bucketOne['body']['$id']);

        $bucketOneId = $bucketOne['body']['$id'];

        $bucketIds = [
            'default' => $bucketOneId,
            'missing-column' => $bucketOneId,
            'irrelevant-column' => $bucketOneId,
            'documents-internals' => $bucketOneId,
        ];

        $fileIds = [];

        foreach ($bucketIds as $label => $bucketId) {
            $jsonFileName = match ($label) {
                'missing-column',
                'irrelevant-column',
                'documents-internals' => "$label.json",
                default => 'documents.json',
            };

            $response = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'fileId' => ID::unique(),
                'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/json/'.$jsonFileName), 'application/json', $jsonFileName),
            ]);

            $this->assertEquals(201, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);
            $this->assertEquals($jsonFileName, $response['body']['name']);
            $this->assertEquals('application/json', $response['body']['mimeType']);

            $fileIds[$label] = $response['body']['$id'];
        }

        // missing column, fail in worker.
        $missingColumn = $this->performJsonMigration(
            [
                'fileId' => $fileIds['missing-column'],
                'bucketId' => $bucketIds['missing-column'],
                'resourceId' => $databaseId . ':' . $tableId,
            ]
        );

        $this->assertEventually(function () use ($missingColumn) {
            $migrationId = $missingColumn['body']['$id'];
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/'.$migrationId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $migration['headers']['status-code']);
            $this->assertEquals('finished', $migration['body']['stage']);
            $this->assertEquals('failed', $migration['body']['status']);
            $this->assertEquals('JSON', $migration['body']['source']);
            $this->assertEquals('Appwrite', $migration['body']['destination']);
            $this->assertContains(Resource::TYPE_ROW, $migration['body']['resources']);

            /* fails in batch create documents unlike csv which checks headers first! */
            $this->assertArrayHasKey(Resource::TYPE_ROW, $migration['body']['statusCounters']);
            $this->assertGreaterThan(0, $migration['body']['statusCounters'][Resource::TYPE_ROW]['error']);

            $this->assertThat(
                implode("\n", $migration['body']['errors']),
                $this->stringContains('Missing required attribute')
            );
            $this->assertThat(
                implode("\n", $migration['body']['errors']),
                $this->stringContains('age')
            );
        }, 60_000, 500);

        // irrelevant column - email, success.
        $irrelevantColumn = $this->performJsonMigration(
            [
                'fileId' => $fileIds['irrelevant-column'],
                'bucketId' => $bucketIds['irrelevant-column'],
                'resourceId' => $databaseId . ':' . $tableId,
            ]
        );

        $this->assertEventually(function () use ($irrelevantColumn) {
            $migrationId = $irrelevantColumn['body']['$id'];
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/'.$migrationId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $migration['headers']['status-code']);
            $this->assertEquals('finished', $migration['body']['stage']);
            $this->assertEquals('completed', $migration['body']['status']);
            $this->assertEquals('JSON', $migration['body']['source']);
            $this->assertEquals('Appwrite', $migration['body']['destination']);
            $this->assertContains(Resource::TYPE_ROW, $migration['body']['resources']);
            $this->assertArrayHasKey(Resource::TYPE_ROW, $migration['body']['statusCounters']);
            $this->assertEquals(100, $migration['body']['statusCounters'][Resource::TYPE_ROW]['success']);
        }, 10_000, 500);

        // all data exists, pass.
        $migration = $this->performJsonMigration(
            [
                'endpoint' => $this->endpoint,
                'fileId' => $fileIds['default'],
                'bucketId' => $bucketIds['default'],
                'resourceId' => $databaseId . ':' . $tableId,
            ]
        );

        $this->assertEventually(function () use ($migration) {
            $migrationId = $migration['body']['$id'];
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/'.$migrationId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $migration['headers']['status-code']);
            $this->assertEquals('finished', $migration['body']['stage']);
            $this->assertEquals('completed', $migration['body']['status']);
            $this->assertEquals('JSON', $migration['body']['source']);
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
                Query::limit(250)->toString()
            ]
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertIsArray($rows['body']['rows']);
        $this->assertIsNumeric($rows['body']['total']);
        $this->assertEquals(200, $rows['body']['total']);

        // all data exists and includes internals, pass.
        $migration = $this->performJsonMigration(
            [
                'endpoint' => $this->endpoint,
                'fileId' => $fileIds['documents-internals'],
                'bucketId' => $bucketIds['documents-internals'],
                'resourceId' => $databaseId . ':' . $tableId,
            ]
        );

        $this->assertEventually(function () use ($migration) {
            $migrationId = $migration['body']['$id'];
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/'.$migrationId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $migration['headers']['status-code']);
            $this->assertEquals('finished', $migration['body']['stage']);
            $this->assertEquals('completed', $migration['body']['status']);
            $this->assertEquals('JSON', $migration['body']['source']);
            $this->assertEquals('Appwrite', $migration['body']['destination']);
            $this->assertContains(Resource::TYPE_ROW, $migration['body']['resources']);
            $this->assertArrayHasKey(Resource::TYPE_ROW, $migration['body']['statusCounters']);
            $this->assertEquals(25, $migration['body']['statusCounters'][Resource::TYPE_ROW]['success']);
        }, 10_000, 500);
    }

    private function performJsonMigration(array $body): array
    {
        return $this->client->call(Client::METHOD_POST, '/migrations/json/imports', [
            'content-type' => 'application/json',
            'x-appwrite-key' => $this->getProject()['apiKey'],
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $body);
    }

    /**
     * Test JSON export with email notification
     */
    public function testCreateJSONExport(): void
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

        \sleep(3);

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
                    'email' => 'user' . $i . '@appwrite.io'
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

        // Perform JSON export with notification enabled (uses internal bucket)
        $migration = $this->client->call(Client::METHOD_POST, '/migrations/json/exports', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'resourceId' => $databaseId . ':' . $collectionId,
            'filename' => 'test-json-export',
            'columns' => [],
            'queries' => [],
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
            $this->assertEquals('JSON', $response['body']['destination']);

            return true;
        }, 30_000, 500);

        // Check that email was sent with download link
        $lastEmail = $this->getLastEmail();
        $this->assertNotEmpty($lastEmail);
        $this->assertEquals('Your JSON export is ready', $lastEmail['subject']);
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

        // Verify the downloaded content is valid JSON
        $jsonData = $downloadWithJwt['body'];
        $this->assertNotEmpty($jsonData, 'JSON export should not be empty');
        $decoded = json_decode($jsonData, true);
        $this->assertIsArray($decoded, 'JSON should be valid and decodable');
        $this->assertCount(10, $decoded, 'JSON should contain 10 documents');
        $this->assertArrayHasKey('name', $decoded[0], 'JSON documents should contain name field');
        $this->assertArrayHasKey('email', $decoded[0], 'JSON documents should contain email field');
        $this->assertStringContainsString('Test User', $decoded[0]['name'], 'JSON should contain test data');

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, [
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
    }

    public function testCreateVectorsDBJSONExport(): void
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ];

        // Create vectorsdb database
        $database = $this->client->call(Client::METHOD_POST, '/vectorsdb', $headers, [
            'databaseId' => ID::unique(),
            'name' => 'VectorsDB Export Test'
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create collection with dimension 16
        $collection = $this->client->call(Client::METHOD_POST, '/vectorsdb/' . $databaseId . '/collections', $headers, [
            'collectionId' => ID::unique(),
            'name' => 'VecExportCol',
            'dimension' => 16,
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Seed 5 documents
        for ($i = 1; $i <= 5; $i++) {
            $embeddings = array_map(fn () => round((mt_rand() / mt_getrandmax()) * 2 - 1, 6), range(1, 16));
            $doc = $this->client->call(Client::METHOD_POST, '/vectorsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', $headers, [
                'documentId' => ID::unique(),
                'data' => [
                    'embeddings' => $embeddings,
                    'metadata' => ['title' => 'Doc ' . $i, 'score' => round($i * 0.2, 1)]
                ]
            ]);
            $this->assertEquals(201, $doc['headers']['status-code'], 'Failed to create vector document ' . $i);
        }

        // Trigger JSON export
        $migration = $this->client->call(Client::METHOD_POST, '/migrations/json/exports', $headers, [
            'resourceId' => $databaseId . ':' . $collectionId,
            'filename' => 'vectorsdb-export-test',
            'columns' => [],
            'queries' => [],
            'notify' => false,
        ]);
        $this->assertEquals(202, $migration['headers']['status-code']);
        $migrationId = $migration['body']['$id'];

        // Poll until completed
        $this->assertEventually(function () use ($migrationId, $headers) {
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/' . $migrationId, $headers);

            $this->assertEquals(200, $migration['headers']['status-code']);
            $this->assertEquals('finished', $migration['body']['stage']);
            $this->assertEquals('completed', $migration['body']['status']);
            $this->assertEquals('Appwrite', $migration['body']['source']);
            $this->assertEquals('JSON', $migration['body']['destination']);
        }, 30_000, 500);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/vectorsdb/' . $databaseId, $headers);
    }

    public function testCreateVectorsDBJSONImport(): void
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ];

        // Create vectorsdb database
        $database = $this->client->call(Client::METHOD_POST, '/vectorsdb', $headers, [
            'databaseId' => ID::unique(),
            'name' => 'VectorsDB Import Test'
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create collection with dimension 16
        $collection = $this->client->call(Client::METHOD_POST, '/vectorsdb/' . $databaseId . '/collections', $headers, [
            'collectionId' => ID::unique(),
            'name' => 'VecImportCol',
            'dimension' => 16,
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Create bucket and upload test file
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', $headers, [
            'bucketId' => ID::unique(),
            'name' => 'VectorsDB Import Bucket',
            'maximumFileSize' => 2000000,
            'allowedFileExtensions' => ['json'],
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $bucketId = $bucket['body']['$id'];

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new \CURLFile(realpath(__DIR__ . '/../../../resources/json/vectorsdb-documents.json'), 'application/json', 'vectorsdb-documents.json'),
        ]);
        $this->assertEquals(201, $file['headers']['status-code']);
        $fileId = $file['body']['$id'];

        // Trigger import
        $migration = $this->performJsonMigration([
            'fileId' => $fileId,
            'bucketId' => $bucketId,
            'resourceId' => $databaseId . ':' . $collectionId,
        ]);
        $this->assertEquals(202, $migration['headers']['status-code']);

        // Poll until completed
        $this->assertEventually(function () use ($migration, $headers) {
            $migrationId = $migration['body']['$id'];
            $result = $this->client->call(Client::METHOD_GET, '/migrations/' . $migrationId, $headers);

            $this->assertEquals(200, $result['headers']['status-code']);
            $this->assertEquals('finished', $result['body']['stage']);
            $this->assertEquals('completed', $result['body']['status']);
            $this->assertEquals('JSON', $result['body']['source']);
            $this->assertEquals('Appwrite', $result['body']['destination']);
        }, 30_000, 500);

        // Verify documents were imported
        $docs = $this->client->call(Client::METHOD_GET, '/vectorsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', $headers);
        $this->assertEquals(200, $docs['headers']['status-code']);
        $this->assertEquals(10, $docs['body']['total'], 'Should have imported 10 vectorsdb documents');

        // Verify first document structure
        $firstDoc = $docs['body']['documents'][0];
        $this->assertArrayHasKey('embeddings', $firstDoc);
        $this->assertCount(16, $firstDoc['embeddings'], 'Imported embeddings should have 16 dimensions');
        $this->assertArrayHasKey('metadata', $firstDoc);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/vectorsdb/' . $databaseId, $headers);
    }

    public function testCreateDocumentsDBJSONExport(): void
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ];

        // Create documentsdb database
        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', $headers, [
            'databaseId' => ID::unique(),
            'name' => 'DocumentsDB Export Test'
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create collection (schemaless — no attributes needed)
        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', $headers, [
            'collectionId' => ID::unique(),
            'name' => 'DocExportCol',
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Seed 5 documents
        for ($i = 1; $i <= 5; $i++) {
            $doc = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', $headers, [
                'documentId' => ID::unique(),
                'data' => [
                    'name' => 'User ' . $i,
                    'email' => 'user' . $i . '@test.com',
                    'age' => 20 + $i,
                    'address' => ['city' => 'City ' . $i, 'zip' => '1000' . $i]
                ]
            ]);
            $this->assertEquals(201, $doc['headers']['status-code'], 'Failed to create document ' . $i);
        }

        // Trigger JSON export
        $migration = $this->client->call(Client::METHOD_POST, '/migrations/json/exports', $headers, [
            'resourceId' => $databaseId . ':' . $collectionId,
            'filename' => 'documentsdb-export-test',
            'columns' => [],
            'queries' => [],
            'notify' => false,
        ]);
        $this->assertEquals(202, $migration['headers']['status-code']);
        $migrationId = $migration['body']['$id'];

        // Poll until completed
        $this->assertEventually(function () use ($migrationId, $headers) {
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/' . $migrationId, $headers);

            $this->assertEquals(200, $migration['headers']['status-code']);
            $this->assertEquals('finished', $migration['body']['stage']);
            $this->assertEquals('completed', $migration['body']['status']);
            $this->assertEquals('Appwrite', $migration['body']['source']);
            $this->assertEquals('JSON', $migration['body']['destination']);
        }, 30_000, 500);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId, $headers);
    }

    public function testCreateDocumentsDBJSONImport(): void
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ];

        // Create documentsdb database
        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', $headers, [
            'databaseId' => ID::unique(),
            'name' => 'DocumentsDB Import Test'
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create collection (schemaless)
        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', $headers, [
            'collectionId' => ID::unique(),
            'name' => 'DocImportCol',
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Create bucket and upload test file
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', $headers, [
            'bucketId' => ID::unique(),
            'name' => 'DocumentsDB Import Bucket',
            'maximumFileSize' => 2000000,
            'allowedFileExtensions' => ['json'],
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $bucketId = $bucket['body']['$id'];

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new \CURLFile(realpath(__DIR__ . '/../../../resources/json/documentsdb-documents.json'), 'application/json', 'documentsdb-documents.json'),
        ]);
        $this->assertEquals(201, $file['headers']['status-code']);
        $fileId = $file['body']['$id'];

        // Trigger import
        $migration = $this->performJsonMigration([
            'fileId' => $fileId,
            'bucketId' => $bucketId,
            'resourceId' => $databaseId . ':' . $collectionId,
        ]);
        $this->assertEquals(202, $migration['headers']['status-code']);

        // Poll until completed
        $this->assertEventually(function () use ($migration, $headers) {
            $migrationId = $migration['body']['$id'];
            $result = $this->client->call(Client::METHOD_GET, '/migrations/' . $migrationId, $headers);

            $this->assertEquals(200, $result['headers']['status-code']);
            $this->assertEquals('finished', $result['body']['stage']);
            $this->assertEquals('completed', $result['body']['status']);
            $this->assertEquals('JSON', $result['body']['source']);
            $this->assertEquals('Appwrite', $result['body']['destination']);
        }, 30_000, 500);

        // Verify documents were imported
        $docs = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', $headers);
        $this->assertEquals(200, $docs['headers']['status-code']);
        $this->assertEquals(10, $docs['body']['total'], 'Should have imported 10 documentsdb documents');

        // Verify first document has nested data
        $firstDoc = $docs['body']['documents'][0];
        $this->assertArrayHasKey('name', $firstDoc);
        $this->assertArrayHasKey('address', $firstDoc);
        $this->assertIsArray($firstDoc['address']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId, $headers);
    }

}
