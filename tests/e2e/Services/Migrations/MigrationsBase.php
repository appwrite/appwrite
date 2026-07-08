<?php

namespace Tests\E2E\Services\Migrations;

use Appwrite\Tests\Retry;
use CURLFile;
use PHPUnit\Framework\Attributes\Depends;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Services\Functions\FunctionsBase;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
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

    protected function assertNoMigrationCounterErrors(array $migration): void
    {
        foreach ($migration['statusCounters'] as $resource => $counters) {
            $this->assertSame(0, $counters['error'], $resource . ' should not have migration errors');
            $this->assertSame(0, $counters['pending'], $resource . ' should not have pending resources');
            $this->assertSame(0, $counters['processing'], $resource . ' should not have processing resources');
        }
    }

    protected function assertMigrationSkipAndOverwrite(
        array $resources,
        callable $mutateDestination,
        callable $assertSkipped,
        callable $mutateSource,
        callable $assertOverwritten,
    ): void {
        $mutateDestination();

        $skip = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
            'onDuplicate' => 'skip',
        ]);
        $this->assertSame('completed', $skip['status']);
        $this->assertNoMigrationCounterErrors($skip);
        $assertSkipped($skip);

        sleep(1);
        $mutateSource();

        $overwrite = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
            'onDuplicate' => 'overwrite',
        ]);
        $this->assertSame('completed', $overwrite['status']);
        $this->assertNoMigrationCounterErrors($overwrite);
        $assertOverwritten($overwrite);
    }

    protected function assertMigrationDuplicateModesComplete(array $resources): void
    {
        foreach (['skip', 'overwrite'] as $onDuplicate) {
            $result = $this->performMigrationSync([
                'resources' => $resources,
                'endpoint' => $this->webEndpoint,
                'projectId' => $this->getProject()['$id'],
                'apiKey' => $this->getProject()['apiKey'],
                'onDuplicate' => $onDuplicate,
            ]);

            $this->assertSame('completed', $result['status']);
            $this->assertNoMigrationCounterErrors($result);
        }
    }

    protected function getProjectVariableByKey(array $headers, string $key): ?array
    {
        $cursorId = null;

        do {
            $queries = [
                Query::limit(100)->toString(),
            ];

            if ($cursorId !== null) {
                $queries[] = Query::cursorAfter(new Document(['$id' => $cursorId]))->toString();
            }

            $response = $this->client->call(Client::METHOD_GET, '/project/variables', $headers, [
                'queries' => $queries,
                'total' => false,
            ]);
            $this->assertSame(200, $response['headers']['status-code']);

            $variables = $response['body']['variables'];
            foreach ($variables as $variable) {
                if ($variable['key'] === $key) {
                    return $variable;
                }
            }

            $cursorId = !empty($variables) ? $variables[array_key_last($variables)]['$id'] : null;
        } while (\count($variables) === 100);

        return null;
    }

    protected function functionUpdatePayload(string $name): array
    {
        return [
            'name' => $name,
            'runtime' => 'node-22',
            'execute' => [],
            'events' => [],
            'schedule' => '',
            'timeout' => 15,
            'enabled' => true,
            'logging' => true,
            'entrypoint' => 'index.js',
            'commands' => '',
            'scopes' => [],
        ];
    }

    protected function siteUpdatePayload(string $name): array
    {
        return [
            'name' => $name,
            'framework' => 'other',
            'enabled' => true,
            'logging' => true,
            'timeout' => 30,
            'installCommand' => '',
            'buildCommand' => '',
            'startCommand' => '',
            'outputDirectory' => './',
            'buildRuntime' => 'node-22',
            'adapter' => 'static',
            'fallbackFile' => '',
        ];
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

        // ProjectCustom provisions an api-key and a webhook on each project, so both show up
        // here. Other resources stay empty and getStatusCounters strips them.
        // Webhook is name-deduped on the destination — source ProjectCustom's 'Webhook Test'
        // collides with destination ProjectCustom's, so it lands in 'skip', not 'success'.
        $this->assertArrayHasKey(Resource::TYPE_API_KEY, $response['statusCounters']);
        $this->assertArrayHasKey(Resource::TYPE_WEBHOOK, $response['statusCounters']);

        $apiKeyCounts = $response['statusCounters'][Resource::TYPE_API_KEY];
        $this->assertEquals(0, $apiKeyCounts['error']);
        $this->assertGreaterThan(0, $apiKeyCounts['success']);

        $webhookCounts = $response['statusCounters'][Resource::TYPE_WEBHOOK];
        $this->assertEquals(0, $webhookCounts['error']);
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

        $this->assertMigrationDuplicateModesComplete([Resource::TYPE_USER]);

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

        $this->assertMigrationDuplicateModesComplete([
            Resource::TYPE_USER,
            Resource::TYPE_TEAM,
            Resource::TYPE_MEMBERSHIP,
        ]);

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

    /** Rows under all three modes; schema tolerance lets every run hit 'completed'. */
    public function testAppwriteMigrationRowsOnDuplicate(): void
    {
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        $data = $this->setupMigrationTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', $sourceHeaders, [
            'rowId' => ID::unique(),
            'data' => ['name' => 'Original'],
        ]);
        $this->assertEquals(201, $row['headers']['status-code']);
        $rowId = $row['body']['$id'];

        $resources = [
            Resource::TYPE_DATABASE,
            Resource::TYPE_TABLE,
            Resource::TYPE_COLUMN,
            Resource::TYPE_ROW,
        ];

        // First migration: destination is empty, strict completion expected.
        $first = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals('completed', $first['status']);

        // Mutate destination row to prove onDuplicate=skip preserves it.
        $mutate = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, $destHeaders, [
            'data' => ['name' => 'Mutated'],
        ]);
        $this->assertEquals(200, $mutate['headers']['status-code']);
        $this->assertEquals('Mutated', $mutate['body']['name']);

        // Re-migration with onDuplicate=skip — completion is strict because
        // DestinationAppwrite tolerates existing schema resources.
        $skipResult = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
            'onDuplicate' => 'skip',
        ]);
        $this->assertEquals('completed', $skipResult['status']);

        $rowAfterSkip = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, $destHeaders);
        $this->assertEquals(200, $rowAfterSkip['headers']['status-code']);
        $this->assertEquals('Mutated', $rowAfterSkip['body']['name'], 'onDuplicate=skip must not overwrite destination row');

        // Re-migration with onDuplicate=overwrite — strict completion; destination
        // row restored to source value.
        $overwriteResult = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
            'onDuplicate' => 'overwrite',
        ]);
        $this->assertEquals('completed', $overwriteResult['status']);

        $rowAfterOverwrite = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, $destHeaders);
        $this->assertEquals(200, $rowAfterOverwrite['headers']['status-code']);
        $this->assertEquals('Original', $rowAfterOverwrite['body']['name'], 'onDuplicate=overwrite must restore source value');

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $destHeaders);
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $sourceHeaders);

        self::$cachedDatabaseData = [];
        self::$cachedTableData = [];
    }

    /** Unchanged source under Skip/Overwrite is a no-op — every resource Tolerated. */
    public function testAppwriteMigrationReRunIsIdempotent(): void
    {
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        $data = $this->setupMigrationTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Seed two rows on source so the row-level tolerance is exercised too.
        foreach (['row-a', 'row-b'] as $rowId) {
            $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', $sourceHeaders, [
                'rowId' => $rowId,
                'data' => ['name' => 'Seeded ' . $rowId],
            ]);
            $this->assertEquals(201, $row['headers']['status-code']);
        }

        $resources = [
            Resource::TYPE_DATABASE,
            Resource::TYPE_TABLE,
            Resource::TYPE_COLUMN,
            Resource::TYPE_ROW,
        ];

        // First migration: fresh destination.
        $first = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals('completed', $first['status']);

        // Re-run under Skip: nothing on source has changed. Destination
        // schema + rows are already correct — expect clean completion.
        $reRunSkip = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
            'onDuplicate' => 'skip',
        ]);
        $this->assertEquals('completed', $reRunSkip['status']);

        // Re-run under Overwrite: same unchanged source. Schema tolerance path
        // fires for each resource; rows go through DB-native upsert.
        $reRunOverwrite = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
            'onDuplicate' => 'overwrite',
        ]);
        $this->assertEquals('completed', $reRunOverwrite['status']);

        foreach (['row-a', 'row-b'] as $rowId) {
            $check = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, $destHeaders);
            $this->assertEquals(200, $check['headers']['status-code']);
            $this->assertEquals('Seeded ' . $rowId, $check['body']['name']);
        }

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $destHeaders);
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $sourceHeaders);

        self::$cachedDatabaseData = [];
        self::$cachedTableData = [];
    }

    /** Overwrite reconciles container drift via UpdateInPlace; children (rows) preserved. */
    public function testAppwriteMigrationOverwriteUpdatesContainerMetadata(): void
    {
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        $data = $this->setupMigrationTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];
        $rowId = 'persist-me';

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', $sourceHeaders, [
            'rowId' => $rowId,
            'data' => ['name' => 'SeedRow'],
        ]);

        $resources = [
            Resource::TYPE_DATABASE,
            Resource::TYPE_TABLE,
            Resource::TYPE_COLUMN,
            Resource::TYPE_ROW,
        ];

        // First migration — dest empty, strict completion.
        $first = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals('completed', $first['status']);

        // `_updatedAt` is stored at second granularity (strtotime) — ensure
        // the source edits below produce a strictly-newer timestamp than
        // dest's first-migration timestamp.
        sleep(1);

        // Mutate source: rename database + toggle table enabled.
        $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId, $sourceHeaders, [
            'name' => 'Renamed Source DB',
        ]);
        $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $tableId, $sourceHeaders, [
            'name' => 'Renamed Source Table',
            'permissions' => [Permission::read(Role::any())],
            'rowSecurity' => true,
            'enabled' => false,
        ]);

        // Overwrite re-migration: UpdateInPlace path fires for database + table.
        $overwriteResult = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
            'onDuplicate' => 'overwrite',
        ]);
        $this->assertEquals('completed', $overwriteResult['status']);

        // Assert dest database metadata reflects source's new values.
        $destDb = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId, $destHeaders);
        $this->assertEquals(200, $destDb['headers']['status-code']);
        $this->assertEquals('Renamed Source DB', $destDb['body']['name']);

        // Assert dest table metadata reflects source's new values.
        $destTable = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId, $destHeaders);
        $this->assertEquals(200, $destTable['headers']['status-code']);
        $this->assertEquals('Renamed Source Table', $destTable['body']['name']);
        $this->assertFalse($destTable['body']['enabled'], 'Overwrite must propagate source enabled=false');
        $this->assertTrue($destTable['body']['documentSecurity'] ?? $destTable['body']['rowSecurity'], 'Overwrite must propagate source rowSecurity=true');

        // Child row untouched — UpdateInPlace only rewrites container metadata.
        $row = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, $destHeaders);
        $this->assertEquals(200, $row['headers']['status-code']);
        $this->assertEquals('SeedRow', $row['body']['name'], 'Overwrite must not touch child rows when updating container metadata');

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $destHeaders);
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $sourceHeaders);

        self::$cachedDatabaseData = [];
        self::$cachedTableData = [];
    }

    /** Skip preserves dest container drift even when source has diverged. */
    public function testAppwriteMigrationSkipPreservesContainerDrift(): void
    {
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        $data = $this->setupMigrationTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $resources = [
            Resource::TYPE_DATABASE,
            Resource::TYPE_TABLE,
            Resource::TYPE_COLUMN,
        ];

        // First migration: dest gets whatever source had.
        $first = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals('completed', $first['status']);

        sleep(1);

        // Mutate dest: ops tightens permissions and renames the table for
        // its production-specific branding.
        $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $tableId, $destHeaders, [
            'name' => 'Dest-Managed Table',
            'permissions' => [Permission::read(Role::users())],
            'rowSecurity' => false,
            'enabled' => true,
        ]);

        // Also mutate source so the second run has a real divergence.
        $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $tableId, $sourceHeaders, [
            'name' => 'Source Renamed',
            'permissions' => [Permission::read(Role::any())],
            'rowSecurity' => true,
            'enabled' => false,
        ]);

        // Skip re-migration: must tolerate existing destination — no update.
        $skipResult = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
            'onDuplicate' => 'skip',
        ]);
        $this->assertEquals('completed', $skipResult['status']);

        // Dest kept its tightened values.
        $destTable = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId, $destHeaders);
        $this->assertEquals(200, $destTable['headers']['status-code']);
        $this->assertEquals('Dest-Managed Table', $destTable['body']['name'], 'Skip must not propagate source name over dest drift');
        $this->assertTrue($destTable['body']['enabled'], 'Skip must preserve dest enabled flag');

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $destHeaders);
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $sourceHeaders);

        self::$cachedDatabaseData = [];
        self::$cachedTableData = [];
    }

    /** Overwrite drops dest columns source no longer declares; cleanup runs before rows land. */
    public function testAppwriteMigrationOverwriteDropsOrphanColumn(): void
    {
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        $data = $this->setupMigrationTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $resources = [
            Resource::TYPE_DATABASE,
            Resource::TYPE_TABLE,
            Resource::TYPE_COLUMN,
            Resource::TYPE_ROW,
        ];

        // First migration: dest mirrors source (one column 'name').
        $first = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals('completed', $first['status']);

        // Add an orphan column directly on destination (not on source).
        // Simulates the post-rename state: source dropped a column, dest
        // still has it — or a dest-only column added by a separate app.
        $orphanResp = $this->client->call(
            Client::METHOD_POST,
            '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string',
            $destHeaders,
            [
                'key' => 'orphan_col',
                'size' => 50,
                'required' => false,
            ]
        );
        $this->assertEquals(202, $orphanResp['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $tableId, $destHeaders) {
            $r = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/orphan_col', $destHeaders);
            $this->assertEquals(200, $r['headers']['status-code']);
            $this->assertEquals('available', $r['body']['status']);
        }, 5000, 500);

        // Seed a row on source so per-table orphan cleanup fires inside
        // createRecord (before rows land), not just at end of run.
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', $sourceHeaders, [
            'rowId' => ID::unique(),
            'data' => ['name' => 'seed'],
        ]);

        // Overwrite re-migration: orphan_col must be dropped from dest.
        $overwriteResult = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
            'onDuplicate' => 'overwrite',
        ]);
        $this->assertEquals('completed', $overwriteResult['status']);

        // Orphan column dropped.
        $orphanCheck = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/orphan_col', $destHeaders);
        $this->assertEquals(404, $orphanCheck['headers']['status-code'], 'Overwrite must drop destination column source no longer declares');

        // Source's column preserved.
        $nameCheck = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/name', $destHeaders);
        $this->assertEquals(200, $nameCheck['headers']['status-code'], 'Overwrite must preserve columns source declared');

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $destHeaders);
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $sourceHeaders);

        self::$cachedDatabaseData = [];
        self::$cachedTableData = [];
    }

    /** Skip preserves orphan columns; cleanup is Overwrite-only. */
    public function testAppwriteMigrationSkipKeepsOrphanColumn(): void
    {
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        $data = $this->setupMigrationTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $resources = [
            Resource::TYPE_DATABASE,
            Resource::TYPE_TABLE,
            Resource::TYPE_COLUMN,
            Resource::TYPE_ROW,
        ];

        $first = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals('completed', $first['status']);

        $orphanResp = $this->client->call(
            Client::METHOD_POST,
            '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string',
            $destHeaders,
            [
                'key' => 'dest_only_col',
                'size' => 50,
                'required' => false,
            ]
        );
        $this->assertEquals(202, $orphanResp['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $tableId, $destHeaders) {
            $r = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/dest_only_col', $destHeaders);
            $this->assertEquals(200, $r['headers']['status-code']);
            $this->assertEquals('available', $r['body']['status']);
        }, 5000, 500);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', $sourceHeaders, [
            'rowId' => ID::unique(),
            'data' => ['name' => 'seed'],
        ]);

        // Skip re-migration: orphan column must NOT be dropped.
        $skipResult = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
            'onDuplicate' => 'skip',
        ]);
        $this->assertEquals('completed', $skipResult['status']);

        $orphanCheck = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/dest_only_col', $destHeaders);
        $this->assertEquals(200, $orphanCheck['headers']['status-code'], 'Skip must preserve destination columns, including orphans');

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $destHeaders);
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $sourceHeaders);

        self::$cachedDatabaseData = [];
        self::$cachedTableData = [];
    }

    /** SDK-reachable attribute change propagates via updateAttributeInPlace; row data preserved. */
    public function testAppwriteMigrationOverwriteUpdatesAttributeInPlace(): void
    {
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        $data = $this->setupMigrationTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];
        $rowId = 'persist-on-inplace';

        // Seed a row that proves drop+recreate didn't happen — recreate would
        // have wiped this column's data on the destination.
        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', $sourceHeaders, [
            'rowId' => $rowId,
            'data' => ['name' => 'SeedRow'],
        ]);
        $this->assertEquals(201, $row['headers']['status-code']);

        $resources = [
            Resource::TYPE_DATABASE,
            Resource::TYPE_TABLE,
            Resource::TYPE_COLUMN,
            Resource::TYPE_ROW,
        ];

        // First migration — dest gets the column as required:true.
        $first = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals('completed', $first['status']);

        $beforeUpdate = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/name', $destHeaders);
        $this->assertEquals(200, $beforeUpdate['headers']['status-code']);
        $this->assertTrue($beforeUpdate['body']['required']);

        // _updatedAt has second granularity; ensure source's PATCH produces a
        // strictly-newer timestamp than the dest's first-migration value.
        sleep(1);

        // SDK-reachable change set: required true→false, default null→'unknown'.
        // Both fields are supported by PATCH /columns/string/:key — must route
        // through updateAttributeInPlace, not DropAndRecreate.
        $patch = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string/name', $sourceHeaders, [
            'required' => false,
            'default' => 'unknown',
        ]);
        $this->assertEquals(200, $patch['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $tableId, $sourceHeaders) {
            $r = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/name', $sourceHeaders);
            $this->assertEquals(200, $r['headers']['status-code']);
            $this->assertEquals('available', $r['body']['status']);
            $this->assertFalse($r['body']['required']);
            $this->assertEquals('unknown', $r['body']['default']);
        }, 5000, 500);

        $overwriteResult = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
            'onDuplicate' => 'overwrite',
        ]);
        $this->assertEquals('completed', $overwriteResult['status']);

        $this->assertEventually(function () use ($databaseId, $tableId, $destHeaders) {
            $r = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/name', $destHeaders);
            $this->assertEquals(200, $r['headers']['status-code']);
            $this->assertEquals('available', $r['body']['status']);
            $this->assertFalse($r['body']['required'], 'updateAttributeInPlace must propagate source required=false');
            $this->assertEquals('unknown', $r['body']['default'], 'updateAttributeInPlace must propagate source default');
        }, 10000, 500);

        // Pre-existing row preserved — proof that the path was UpdateInPlace
        // and not DropAndRecreate (which would have nulled this column).
        $rowAfter = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, $destHeaders);
        $this->assertEquals(200, $rowAfter['headers']['status-code']);
        $this->assertEquals('SeedRow', $rowAfter['body']['name'], 'updateAttributeInPlace must not touch row data');

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $destHeaders);
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $sourceHeaders);

        self::$cachedDatabaseData = [];
        self::$cachedTableData = [];
    }

    /** Skip preserves dest attribute drift; leaf-level analog of the container drift test. */
    public function testAppwriteMigrationSkipPreservesAttributeDrift(): void
    {
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        $data = $this->setupMigrationTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $resources = [
            Resource::TYPE_DATABASE,
            Resource::TYPE_TABLE,
            Resource::TYPE_COLUMN,
        ];

        $first = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals('completed', $first['status']);

        sleep(1);

        // Dest divergence: ops loosens the column for a production-only need.
        $destPatch = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string/name', $destHeaders, [
            'required' => false,
            'default' => 'dest-default',
        ]);
        $this->assertEquals(200, $destPatch['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $tableId, $destHeaders) {
            $r = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/name', $destHeaders);
            $this->assertEquals(200, $r['headers']['status-code']);
            $this->assertEquals('available', $r['body']['status']);
            $this->assertFalse($r['body']['required']);
        }, 5000, 500);

        sleep(1);

        // Source advances strictly later (and to a different value). Under
        // Overwrite this would propagate to dest; under Skip it must not.
        $sourcePatch = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string/name', $sourceHeaders, [
            'required' => true,
            'default' => null,
        ]);
        $this->assertEquals(200, $sourcePatch['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $tableId, $sourceHeaders) {
            $r = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/name', $sourceHeaders);
            $this->assertEquals(200, $r['headers']['status-code']);
            $this->assertEquals('available', $r['body']['status']);
            $this->assertTrue($r['body']['required']);
        }, 5000, 500);

        $skipResult = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
            'onDuplicate' => 'skip',
        ]);
        $this->assertEquals('completed', $skipResult['status']);

        $destAttr = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/name', $destHeaders);
        $this->assertEquals(200, $destAttr['headers']['status-code']);
        $this->assertFalse($destAttr['body']['required'], 'Skip must not propagate source required over dest drift');
        $this->assertEquals('dest-default', $destAttr['body']['default'], 'Skip must preserve dest default');

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $destHeaders);
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $sourceHeaders);

        self::$cachedDatabaseData = [];
        self::$cachedTableData = [];
    }

    /** Two-way onDelete change updates in place on both sides; partner meta refreshed by hand. */
    public function testAppwriteMigrationOverwriteUpdatesRelationshipOnDeleteInPlace(): void
    {
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        $databaseId = ID::unique();
        $createDb = $this->client->call(Client::METHOD_POST, '/databases', $sourceHeaders, [
            'databaseId' => $databaseId,
            'name' => 'Rel In-Place DB',
        ]);
        $this->assertEquals(201, $createDb['headers']['status-code']);

        foreach (['parents', 'children'] as $tbl) {
            $createTable = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', $sourceHeaders, [
                'tableId' => $tbl,
                'name' => $tbl,
            ]);
            $this->assertEquals(201, $createTable['headers']['status-code']);
        }

        // Two-way: parents.kids ↔ children.parent. Required to hit the in-place path.
        $createRel = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/parents/columns/relationship', $sourceHeaders, [
            'relatedTableId' => 'children',
            'type' => Database::RELATION_ONE_TO_MANY,
            'twoWay' => true,
            'key' => 'kids',
            'twoWayKey' => 'parent',
            'onDelete' => Database::RELATION_MUTATE_CASCADE,
        ]);
        $this->assertEquals(202, $createRel['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $sourceHeaders) {
            $r = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/parents/columns/kids', $sourceHeaders);
            $this->assertEquals(200, $r['headers']['status-code']);
            $this->assertEquals('available', $r['body']['status']);
            $this->assertEquals(Database::RELATION_MUTATE_CASCADE, $r['body']['onDelete']);
        }, 10000, 500);

        $resources = [
            Resource::TYPE_DATABASE,
            Resource::TYPE_TABLE,
            Resource::TYPE_COLUMN,
        ];

        $first = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals('completed', $first['status']);

        // Both sides land on dest with onDelete=cascade.
        $this->assertEventually(function () use ($databaseId, $destHeaders) {
            $parent = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/parents/columns/kids', $destHeaders);
            $this->assertEquals(200, $parent['headers']['status-code']);
            $this->assertEquals('available', $parent['body']['status']);
            $this->assertEquals(Database::RELATION_MUTATE_CASCADE, $parent['body']['onDelete']);

            $child = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/children/columns/parent', $destHeaders);
            $this->assertEquals(200, $child['headers']['status-code']);
            $this->assertEquals('available', $child['body']['status']);
            $this->assertEquals(Database::RELATION_MUTATE_CASCADE, $child['body']['onDelete']);
        }, 10000, 500);

        sleep(1);

        // SDK-reachable: PATCH /columns/:key/relationship accepts onDelete.
        $patch = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/parents/columns/kids/relationship', $sourceHeaders, [
            'onDelete' => Database::RELATION_MUTATE_RESTRICT,
        ]);
        $this->assertEquals(200, $patch['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $sourceHeaders) {
            $r = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/parents/columns/kids', $sourceHeaders);
            $this->assertEquals(200, $r['headers']['status-code']);
            $this->assertEquals('available', $r['body']['status']);
            $this->assertEquals(Database::RELATION_MUTATE_RESTRICT, $r['body']['onDelete']);
        }, 5000, 500);

        $overwriteResult = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
            'onDuplicate' => 'overwrite',
        ]);
        $this->assertEquals('completed', $overwriteResult['status']);

        // Both sides on dest must reflect onDelete=restrict. Asserting the
        // partner side is the regression guard for the previously-missed
        // partner meta refresh in updateRelationshipInPlace.
        $this->assertEventually(function () use ($databaseId, $destHeaders) {
            $parent = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/parents/columns/kids', $destHeaders);
            $this->assertEquals(200, $parent['headers']['status-code']);
            $this->assertEquals('available', $parent['body']['status']);
            $this->assertEquals(Database::RELATION_MUTATE_RESTRICT, $parent['body']['onDelete'], 'parent-side onDelete must reflect source');
            $this->assertEquals(Database::RELATION_ONE_TO_MANY, $parent['body']['relationType'], 'In-place update must not change relationType');
            $this->assertTrue($parent['body']['twoWay'], 'In-place update must not change twoWay');

            $child = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/children/columns/parent', $destHeaders);
            $this->assertEquals(200, $child['headers']['status-code']);
            $this->assertEquals('available', $child['body']['status']);
            $this->assertEquals(Database::RELATION_MUTATE_RESTRICT, $child['body']['onDelete'], 'partner-side onDelete must reflect source after in-place update');
        }, 10000, 500);

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $destHeaders);
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $sourceHeaders);

        self::$cachedDatabaseData = [];
        self::$cachedTableData = [];
    }

    /** Two-way recreate with same spec: spec-match guard tolerates parent; pair-key dedup tolerates partner. Both sides + child rows preserved. */
    public function testAppwriteMigrationOverwriteTwoWayRecreateSkipsPartnerSide(): void
    {
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        $databaseId = ID::unique();
        $createDb = $this->client->call(Client::METHOD_POST, '/databases', $sourceHeaders, [
            'databaseId' => $databaseId,
            'name' => 'Two-Way Recreate DB',
        ]);
        $this->assertEquals(201, $createDb['headers']['status-code']);

        foreach (['parents', 'children'] as $tbl) {
            $createTable = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', $sourceHeaders, [
                'tableId' => $tbl,
                'name' => $tbl,
                'permissions' => [
                    Permission::create(Role::any()),
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ]);
            $this->assertEquals(201, $createTable['headers']['status-code']);
        }

        // Add a non-relationship column on parents so we can POST a row with
        // non-empty data. tablesdb POST /rows rejects empty data arrays in
        // 1.9.x (Create.php:161 — getSupportForEmptyDocument() defaults false).
        $createLabel = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/parents/columns/string', $sourceHeaders, [
            'key' => 'label',
            'size' => 32,
            'required' => false,
        ]);
        $this->assertEquals(202, $createLabel['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $sourceHeaders) {
            $r = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/parents/columns/label', $sourceHeaders);
            $this->assertEquals(200, $r['headers']['status-code']);
            $this->assertEquals('available', $r['body']['status']);
        }, 10000, 500);

        $createRel = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/parents/columns/relationship', $sourceHeaders, [
            'relatedTableId' => 'children',
            'type' => Database::RELATION_ONE_TO_MANY,
            'twoWay' => true,
            'key' => 'kids',
            'twoWayKey' => 'parent',
            'onDelete' => Database::RELATION_MUTATE_CASCADE,
        ]);
        $this->assertEquals(202, $createRel['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $sourceHeaders) {
            $r = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/parents/columns/kids', $sourceHeaders);
            $this->assertEquals(200, $r['headers']['status-code']);
            $this->assertEquals('available', $r['body']['status']);
        }, 10000, 500);

        $parentRow = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/parents/rows', $sourceHeaders, [
            'rowId' => 'parent-1',
            'data' => ['label' => 'p1'],
        ]);
        $this->assertEquals(201, $parentRow['headers']['status-code']);
        $childRow = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/children/rows', $sourceHeaders, [
            'rowId' => 'child-1',
            'data' => ['parent' => 'parent-1'],
        ]);
        $this->assertEquals(201, $childRow['headers']['status-code']);

        $resources = [
            Resource::TYPE_DATABASE,
            Resource::TYPE_TABLE,
            Resource::TYPE_COLUMN,
            Resource::TYPE_ROW,
        ];

        $first = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals('completed', $first['status']);

        // Recreate the relationship on source so its createdAt advances past
        // dest's stored value — forces SchemaAction::DropAndRecreate on the
        // parent side, which is the path the partner-side dedup guards.
        sleep(1);
        $deleteRel = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/parents/columns/kids', $sourceHeaders);
        $this->assertEquals(204, $deleteRel['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $sourceHeaders) {
            $r = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/parents/columns/kids', $sourceHeaders);
            $this->assertEquals(404, $r['headers']['status-code']);
        }, 10000, 500);

        sleep(1);
        $recreate = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/parents/columns/relationship', $sourceHeaders, [
            'relatedTableId' => 'children',
            'type' => Database::RELATION_ONE_TO_MANY,
            'twoWay' => true,
            'key' => 'kids',
            'twoWayKey' => 'parent',
            'onDelete' => Database::RELATION_MUTATE_CASCADE,
        ]);
        $this->assertEquals(202, $recreate['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $sourceHeaders) {
            $r = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/parents/columns/kids', $sourceHeaders);
            $this->assertEquals(200, $r['headers']['status-code']);
            $this->assertEquals('available', $r['body']['status']);
        }, 10000, 500);

        // Child-row's relationship was wiped by the source-side delete. Re-link.
        $relink = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/children/rows/child-1', $sourceHeaders, [
            'data' => ['parent' => 'parent-1'],
        ]);
        $this->assertEquals(200, $relink['headers']['status-code']);

        $overwriteResult = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
            'onDuplicate' => 'overwrite',
        ]);
        $this->assertEquals('completed', $overwriteResult['status']);

        $this->assertEventually(function () use ($databaseId, $destHeaders) {
            $parent = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/parents/columns/kids', $destHeaders);
            $this->assertEquals(200, $parent['headers']['status-code']);
            $this->assertEquals('available', $parent['body']['status']);

            $child = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/children/columns/parent', $destHeaders);
            $this->assertEquals(200, $child['headers']['status-code']);
            $this->assertEquals('available', $child['body']['status']);
        }, 10000, 500);

        // Both rows survive the re-migration. If the partner-side dedup were
        // missing and the partner pass re-fired DropAndRecreate, the partner
        // (children) table's row would have been wiped before the row pass.
        $destChild = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/children/rows/child-1', $destHeaders);
        $this->assertEquals(200, $destChild['headers']['status-code'], 'partner-table row must survive two-way recreate re-migration');
        $this->assertEquals('parent-1', $destChild['body']['parent']['$id'] ?? $destChild['body']['parent'], 'partner-table row relationship must point to the migrated parent');

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $destHeaders);
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $sourceHeaders);

        self::$cachedDatabaseData = [];
        self::$cachedTableData = [];
    }

    /** One-way + onDelete change falls through to DropAndRecreate (in-place gated off for one-way). */
    public function testAppwriteMigrationOverwriteOneWayRelationshipDropAndRecreate(): void
    {
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        $databaseId = ID::unique();
        $createDb = $this->client->call(Client::METHOD_POST, '/databases', $sourceHeaders, [
            'databaseId' => $databaseId,
            'name' => 'One-Way DropAndRecreate DB',
        ]);
        $this->assertEquals(201, $createDb['headers']['status-code']);

        foreach (['parents', 'children'] as $tbl) {
            $createTable = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', $sourceHeaders, [
                'tableId' => $tbl,
                'name' => $tbl,
            ]);
            $this->assertEquals(201, $createTable['headers']['status-code']);
        }

        $createRel = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/parents/columns/relationship', $sourceHeaders, [
            'relatedTableId' => 'children',
            'type' => Database::RELATION_ONE_TO_MANY,
            'twoWay' => false,
            'key' => 'kids',
            'onDelete' => Database::RELATION_MUTATE_CASCADE,
        ]);
        $this->assertEquals(202, $createRel['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $sourceHeaders) {
            $r = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/parents/columns/kids', $sourceHeaders);
            $this->assertEquals(200, $r['headers']['status-code']);
            $this->assertEquals('available', $r['body']['status']);
        }, 10000, 500);

        $resources = [
            Resource::TYPE_DATABASE,
            Resource::TYPE_TABLE,
            Resource::TYPE_COLUMN,
        ];

        $first = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals('completed', $first['status']);

        $this->assertEventually(function () use ($databaseId, $destHeaders) {
            $r = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/parents/columns/kids', $destHeaders);
            $this->assertEquals(200, $r['headers']['status-code']);
            $this->assertEquals('available', $r['body']['status']);
            $this->assertEquals(Database::RELATION_MUTATE_CASCADE, $r['body']['onDelete']);
        }, 10000, 500);

        sleep(1);

        $patch = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/parents/columns/kids/relationship', $sourceHeaders, [
            'onDelete' => Database::RELATION_MUTATE_RESTRICT,
        ]);
        $this->assertEquals(200, $patch['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $sourceHeaders) {
            $r = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/parents/columns/kids', $sourceHeaders);
            $this->assertEquals('available', $r['body']['status']);
            $this->assertEquals(Database::RELATION_MUTATE_RESTRICT, $r['body']['onDelete']);
        }, 5000, 500);

        $overwriteResult = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
            'onDuplicate' => 'overwrite',
        ]);
        $this->assertEquals('completed', $overwriteResult['status']);

        $this->assertEventually(function () use ($databaseId, $destHeaders) {
            $r = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/parents/columns/kids', $destHeaders);
            $this->assertEquals(200, $r['headers']['status-code']);
            $this->assertEquals('available', $r['body']['status']);
            $this->assertEquals(Database::RELATION_MUTATE_RESTRICT, $r['body']['onDelete'], 'one-way DropAndRecreate must propagate source onDelete');
            $this->assertEquals(Database::RELATION_ONE_TO_MANY, $r['body']['relationType'], 'DropAndRecreate must preserve relationType');
            $this->assertFalse($r['body']['twoWay'], 'DropAndRecreate must preserve twoWay=false');
        }, 10000, 500);

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $destHeaders);
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $sourceHeaders);

        self::$cachedDatabaseData = [];
        self::$cachedTableData = [];
    }

    /** Recreate with non-SDK spec change (array toggle): updateAttributeInPlace bails → drop+recreate; row pass refills. */
    public function testAppwriteMigrationOverwriteAttributeRecreateDropsAndRecreates(): void
    {
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        $data = $this->setupMigrationTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];
        $rowId = 'row-after-recreate';

        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', $sourceHeaders, [
            'rowId' => $rowId,
            'data' => ['name' => 'before-recreate'],
        ]);
        $this->assertEquals(201, $row['headers']['status-code']);

        $resources = [
            Resource::TYPE_DATABASE,
            Resource::TYPE_TABLE,
            Resource::TYPE_COLUMN,
            Resource::TYPE_ROW,
        ];

        $first = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals('completed', $first['status']);

        sleep(1);

        // Drop + recreate the column on source. createdAt advances → re-migration
        // must take the createdAt-diff DropAndRecreate path on dest.
        $delete = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/name', $sourceHeaders);
        $this->assertEquals(204, $delete['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $tableId, $sourceHeaders) {
            $r = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/name', $sourceHeaders);
            $this->assertEquals(404, $r['headers']['status-code']);
        }, 10000, 500);

        // Recreate with `array: true` — a non-SDK change (`array` is in
        // ATTRIBUTE_NON_SDK_FIELDS). Forces updateAttributeInPlace to bail
        // and the caller to fall through to drop+recreate, which is what
        // this test pins.
        $recreate = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', $sourceHeaders, [
            'key' => 'name',
            'size' => 100,
            'required' => false,
            'array' => true,
        ]);
        $this->assertEquals(202, $recreate['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $tableId, $sourceHeaders) {
            $r = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/name', $sourceHeaders);
            $this->assertEquals(200, $r['headers']['status-code']);
            $this->assertEquals('available', $r['body']['status']);
        }, 10000, 500);

        // Source row's data was nulled by the source-side delete. Set a list value (column is array=true now).
        $relink = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, $sourceHeaders, [
            'data' => ['name' => ['after-recreate']],
        ]);
        $this->assertEquals(200, $relink['headers']['status-code']);

        $overwriteResult = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
            'onDuplicate' => 'overwrite',
        ]);
        $this->assertEquals('completed', $overwriteResult['status']);

        $this->assertEventually(function () use ($databaseId, $tableId, $destHeaders) {
            $col = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/name', $destHeaders);
            $this->assertEquals(200, $col['headers']['status-code']);
            $this->assertEquals('available', $col['body']['status']);
            $this->assertTrue($col['body']['array'], 'recreated column must reflect the new spec (array=true)');
            $this->assertFalse($col['body']['required']);
        }, 10000, 500);

        $rowAfter = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, $destHeaders);
        $this->assertEquals(200, $rowAfter['headers']['status-code']);
        $this->assertEquals(['after-recreate'], $rowAfter['body']['name'], 'row pass must repopulate the recreated column with source value');

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $destHeaders);
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $sourceHeaders);

        self::$cachedDatabaseData = [];
        self::$cachedTableData = [];
    }

    /** Source drops+recreates with SAME spec: spec-match guard forces Tolerate; dest meta untouched. */
    public function testAppwriteMigrationOverwriteSameSpecRecreateTolerates(): void
    {
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        $data = $this->setupMigrationTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];
        $rowId = 'row-spec-match';

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', $sourceHeaders, [
            'rowId' => $rowId,
            'data' => ['name' => 'before-recreate'],
        ]);

        $resources = [
            Resource::TYPE_DATABASE,
            Resource::TYPE_TABLE,
            Resource::TYPE_COLUMN,
            Resource::TYPE_ROW,
        ];

        $first = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals('completed', $first['status']);

        $destBefore = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/name', $destHeaders);
        $this->assertEquals(200, $destBefore['headers']['status-code']);
        $destCreatedAtBefore = $destBefore['body']['$createdAt'];

        sleep(1);

        // Drop + recreate with the EXACT same spec as setupMigrationTable
        // (size=100, required=true). Source's $createdAt advances but the
        // spec is identical → spec-match guard must force Tolerate.
        $delete = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/name', $sourceHeaders);
        $this->assertEquals(204, $delete['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $tableId, $sourceHeaders) {
            $r = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/name', $sourceHeaders);
            $this->assertEquals(404, $r['headers']['status-code']);
        }, 10000, 500);

        $recreate = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', $sourceHeaders, [
            'key' => 'name',
            'size' => 100,
            'required' => true,
        ]);
        $this->assertEquals(202, $recreate['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $tableId, $sourceHeaders) {
            $r = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/name', $sourceHeaders);
            $this->assertEquals(200, $r['headers']['status-code']);
            $this->assertEquals('available', $r['body']['status']);
        }, 10000, 500);

        $relink = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, $sourceHeaders, [
            'data' => ['name' => 'after-recreate'],
        ]);
        $this->assertEquals(200, $relink['headers']['status-code']);

        $overwriteResult = $this->performMigrationSync([
            'resources' => $resources,
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
            'onDuplicate' => 'overwrite',
        ]);
        $this->assertEquals('completed', $overwriteResult['status']);

        // Spec-match guard fired → dest column's $createdAt stayed at the
        // first-migration value. If DropAndRecreate had run, $createdAt
        // would have been bumped to source's NEW createdAt.
        $destAfter = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/name', $destHeaders);
        $this->assertEquals(200, $destAfter['headers']['status-code']);
        $this->assertEquals($destCreatedAtBefore, $destAfter['body']['$createdAt'], 'spec-match guard must keep dest column meta untouched');
        $this->assertEquals(100, $destAfter['body']['size']);
        $this->assertTrue($destAfter['body']['required']);

        // Row pass under Overwrite still propagated source's new row value.
        $rowAfter = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, $destHeaders);
        $this->assertEquals(200, $rowAfter['headers']['status-code']);
        $this->assertEquals('after-recreate', $rowAfter['body']['name']);

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $destHeaders);
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $sourceHeaders);

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

        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destinationHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];
        $bucketId = $bucket['body']['$id'];

        $this->assertMigrationSkipAndOverwrite(
            [Resource::TYPE_BUCKET],
            function () use ($bucketId, $destinationHeaders, $bucket): void {
                $response = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId, $destinationHeaders, [
                    'name' => 'Destination Bucket',
                    'permissions' => $bucket['body']['$permissions'],
                    'fileSecurity' => $bucket['body']['fileSecurity'],
                    'maximumFileSize' => $bucket['body']['maximumFileSize'],
                    'allowedFileExtensions' => $bucket['body']['allowedFileExtensions'],
                    'compression' => $bucket['body']['compression'],
                    'encryption' => $bucket['body']['encryption'],
                    'antivirus' => $bucket['body']['antivirus'],
                ]);
                $this->assertEquals(200, $response['headers']['status-code']);
            },
            function (array $skip) use ($bucketId, $destinationHeaders): void {
                $this->assertGreaterThanOrEqual(1, $skip['statusCounters'][Resource::TYPE_BUCKET]['skip']);
                $bucket = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId, $destinationHeaders);
                $this->assertEquals(200, $bucket['headers']['status-code']);
                $this->assertSame('Destination Bucket', $bucket['body']['name']);
            },
            function () use ($bucketId, $sourceHeaders, $bucket): void {
                $response = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId, $sourceHeaders, [
                    'name' => 'Source Bucket Overwrite',
                    'permissions' => $bucket['body']['$permissions'],
                    'fileSecurity' => $bucket['body']['fileSecurity'],
                    'maximumFileSize' => $bucket['body']['maximumFileSize'],
                    'allowedFileExtensions' => $bucket['body']['allowedFileExtensions'],
                    'compression' => $bucket['body']['compression'],
                    'encryption' => $bucket['body']['encryption'],
                    'antivirus' => $bucket['body']['antivirus'],
                ]);
                $this->assertEquals(200, $response['headers']['status-code']);
            },
            function (array $overwrite) use ($bucketId, $destinationHeaders): void {
                $this->assertGreaterThanOrEqual(1, $overwrite['statusCounters'][Resource::TYPE_BUCKET]['success']);
                $bucket = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId, $destinationHeaders);
                $this->assertEquals(200, $bucket['headers']['status-code']);
                $this->assertSame('Source Bucket Overwrite', $bucket['body']['name']);
            },
        );

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

        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destinationHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        $this->assertMigrationSkipAndOverwrite(
            [Resource::TYPE_BUCKET, Resource::TYPE_FILE],
            function () use ($bucketId, $fileId, $destinationHeaders, $file): void {
                $response = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, $destinationHeaders, [
                    'name' => 'destination-logo.png',
                    'permissions' => $file['body']['$permissions'],
                ]);
                $this->assertEquals(200, $response['headers']['status-code']);
            },
            function (array $skip) use ($bucketId, $fileId, $destinationHeaders): void {
                $this->assertGreaterThanOrEqual(1, $skip['statusCounters'][Resource::TYPE_FILE]['skip']);
                $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, $destinationHeaders);
                $this->assertEquals(200, $file['headers']['status-code']);
                $this->assertSame('destination-logo.png', $file['body']['name']);
            },
            function () use ($bucketId, $fileId, $sourceHeaders, $file): void {
                $response = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, $sourceHeaders, [
                    'name' => 'source-logo.png',
                    'permissions' => $file['body']['$permissions'],
                ]);
                $this->assertEquals(200, $response['headers']['status-code']);
            },
            function (array $overwrite) use ($bucketId, $fileId, $destinationHeaders): void {
                $this->assertGreaterThanOrEqual(1, $overwrite['statusCounters'][Resource::TYPE_FILE]['skip']);
                $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, $destinationHeaders);
                $this->assertEquals(200, $file['headers']['status-code']);
                $this->assertSame('destination-logo.png', $file['body']['name']);
            },
        );

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
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destinationHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js'
        ]);

        $variable = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/variables', $sourceHeaders, [
            'variableId' => ID::unique(),
            'key' => 'FUNCTION_DUPLICATE_MODE',
            'value' => 'source-original',
        ]);
        $this->assertEquals(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        $deploymentId = $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('basic'),
            'activate' => true
        ]);

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_FUNCTION,
                Resource::TYPE_ENVIRONMENT_VARIABLE,
                Resource::TYPE_DEPLOYMENT
            ],
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_FUNCTION, Resource::TYPE_ENVIRONMENT_VARIABLE, Resource::TYPE_DEPLOYMENT], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_FUNCTION, $result['statusCounters']);

        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_FUNCTION]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_FUNCTION]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_FUNCTION]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_FUNCTION]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_FUNCTION]['warning']);

        $this->assertArrayHasKey(Resource::TYPE_ENVIRONMENT_VARIABLE, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_ENVIRONMENT_VARIABLE]['error']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_ENVIRONMENT_VARIABLE]['success']);

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

        $this->assertMigrationSkipAndOverwrite(
            [Resource::TYPE_FUNCTION, Resource::TYPE_ENVIRONMENT_VARIABLE],
            function () use ($functionId, $variableId, $destinationHeaders): void {
                $this->client->call(Client::METHOD_PUT, '/functions/' . $functionId, $destinationHeaders, $this->functionUpdatePayload('Destination Function'));
                $this->client->call(Client::METHOD_PUT, '/functions/' . $functionId . '/variables/' . $variableId, $destinationHeaders, [
                    'key' => 'FUNCTION_DUPLICATE_MODE',
                    'value' => 'destination-only',
                ]);
            },
            function (array $skip) use ($functionId, $variableId, $destinationHeaders): void {
                $this->assertGreaterThanOrEqual(1, $skip['statusCounters'][Resource::TYPE_FUNCTION]['skip']);
                $this->assertGreaterThanOrEqual(1, $skip['statusCounters'][Resource::TYPE_ENVIRONMENT_VARIABLE]['skip']);
                $function = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId, $destinationHeaders);
                $this->assertEquals(200, $function['headers']['status-code']);
                $this->assertSame('Destination Function', $function['body']['name']);
                $variable = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/variables/' . $variableId, $destinationHeaders);
                $this->assertEquals(200, $variable['headers']['status-code']);
                $this->assertSame('destination-only', $variable['body']['value']);
            },
            function () use ($functionId, $variableId, $sourceHeaders): void {
                $this->client->call(Client::METHOD_PUT, '/functions/' . $functionId, $sourceHeaders, $this->functionUpdatePayload('Source Function Overwrite'));
                $this->client->call(Client::METHOD_PUT, '/functions/' . $functionId . '/variables/' . $variableId, $sourceHeaders, [
                    'key' => 'FUNCTION_DUPLICATE_MODE',
                    'value' => 'source-overwrite',
                ]);
            },
            function (array $overwrite) use ($functionId, $variableId, $destinationHeaders): void {
                $this->assertGreaterThanOrEqual(1, $overwrite['statusCounters'][Resource::TYPE_FUNCTION]['success']);
                $this->assertGreaterThanOrEqual(1, $overwrite['statusCounters'][Resource::TYPE_ENVIRONMENT_VARIABLE]['success']);
                $function = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId, $destinationHeaders);
                $this->assertEquals(200, $function['headers']['status-code']);
                $this->assertSame('Source Function Overwrite', $function['body']['name']);
                $variable = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/variables/' . $variableId, $destinationHeaders);
                $this->assertEquals(200, $variable['headers']['status-code']);
                $this->assertSame('source-overwrite', $variable['body']['value']);
            },
        );

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
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destinationHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

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
            'x-appwrite-response-format' => '1.9.3'
        ], [
            'key' => 'TEST_VAR',
            'value' => 'test_value',
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

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

        $this->assertMigrationSkipAndOverwrite(
            [Resource::TYPE_SITE, Resource::TYPE_SITE_VARIABLE],
            function () use ($siteId, $variableId, $destinationHeaders): void {
                $this->client->call(Client::METHOD_PUT, '/sites/' . $siteId, $destinationHeaders, $this->siteUpdatePayload('Destination Site'));
                $this->client->call(Client::METHOD_PUT, '/sites/' . $siteId . '/variables/' . $variableId, $destinationHeaders, [
                    'key' => 'TEST_VAR',
                    'value' => 'destination-only',
                ]);
            },
            function (array $skip) use ($siteId, $variableId, $destinationHeaders): void {
                $this->assertGreaterThanOrEqual(1, $skip['statusCounters'][Resource::TYPE_SITE]['skip']);
                $this->assertGreaterThanOrEqual(1, $skip['statusCounters'][Resource::TYPE_SITE_VARIABLE]['skip']);
                $site = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId, $destinationHeaders);
                $this->assertEquals(200, $site['headers']['status-code']);
                $this->assertSame('Destination Site', $site['body']['name']);
                $variable = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/variables/' . $variableId, $destinationHeaders);
                $this->assertEquals(200, $variable['headers']['status-code']);
                $this->assertSame('destination-only', $variable['body']['value']);
            },
            function () use ($siteId, $variableId, $sourceHeaders): void {
                $this->client->call(Client::METHOD_PUT, '/sites/' . $siteId, $sourceHeaders, $this->siteUpdatePayload('Source Site Overwrite'));
                $this->client->call(Client::METHOD_PUT, '/sites/' . $siteId . '/variables/' . $variableId, $sourceHeaders, [
                    'key' => 'TEST_VAR',
                    'value' => 'source-overwrite',
                ]);
            },
            function (array $overwrite) use ($siteId, $variableId, $destinationHeaders): void {
                $this->assertGreaterThanOrEqual(1, $overwrite['statusCounters'][Resource::TYPE_SITE]['success']);
                $this->assertGreaterThanOrEqual(1, $overwrite['statusCounters'][Resource::TYPE_SITE_VARIABLE]['success']);
                $site = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId, $destinationHeaders);
                $this->assertEquals(200, $site['headers']['status-code']);
                $this->assertSame('Source Site Overwrite', $site['body']['name']);
                $variable = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/variables/' . $variableId, $destinationHeaders);
                $this->assertEquals(200, $variable['headers']['status-code']);
                $this->assertSame('source-overwrite', $variable['body']['value']);
            },
        );

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
     * Integrations
     */
    public function testAppwriteMigrationPlatform(): void
    {
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $destinationHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        // Create platform on source project
        $response = $this->client->call(Client::METHOD_POST, '/project/platforms/web', $sourceHeaders, [
            'platformId' => ID::unique(),
            'name' => 'Test Platform',
            'hostname' => 'localhost',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        $platform = $response['body'];

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_PLATFORM,
            ],
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_PLATFORM], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_PLATFORM, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PLATFORM]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PLATFORM]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_PLATFORM]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PLATFORM]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PLATFORM]['warning']);

        // Verify platform on destination project using the project's API key
        $response = $this->client->call(Client::METHOD_GET, '/project/platforms', $destinationHeaders);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertGreaterThan(0, $response['body']['total']);

        $foundPlatform = null;

        foreach ($response['body']['platforms'] as $p) {
            if ($p['name'] === 'Test Platform' && $p['type'] === 'web') {
                $foundPlatform = $p;

                break;
            }
        }

        $this->assertNotNull($foundPlatform);
        $this->assertEquals('web', $foundPlatform['type']);
        $this->assertEquals('Test Platform', $foundPlatform['name']);
        $this->assertEquals('localhost', $foundPlatform['hostname']);

        $destinationPlatformId = $foundPlatform['$id'];
        $this->assertMigrationSkipAndOverwrite(
            [Resource::TYPE_PLATFORM],
            function () use ($destinationHeaders, $destinationPlatformId): void {
                $response = $this->client->call(Client::METHOD_PUT, '/project/platforms/web/' . $destinationPlatformId, $destinationHeaders, [
                    'name' => 'Test Platform',
                    'hostname' => 'destination.localhost',
                ]);
                $this->assertEquals(200, $response['headers']['status-code']);
            },
            function (array $migration) use ($destinationHeaders, $destinationPlatformId): void {
                $this->assertGreaterThanOrEqual(1, $migration['statusCounters'][Resource::TYPE_PLATFORM]['skip']);

                $response = $this->client->call(Client::METHOD_GET, '/project/platforms/' . $destinationPlatformId, $destinationHeaders);
                $this->assertEquals(200, $response['headers']['status-code']);
                $this->assertSame('destination.localhost', $response['body']['hostname']);
            },
            function () use ($sourceHeaders, $platform): void {
                $response = $this->client->call(Client::METHOD_PUT, '/project/platforms/web/' . $platform['$id'], $sourceHeaders, [
                    'name' => 'Test Platform',
                    'hostname' => 'source.localhost',
                ]);
                $this->assertEquals(200, $response['headers']['status-code']);
            },
            function (array $migration) use ($destinationHeaders, $destinationPlatformId): void {
                $this->assertGreaterThanOrEqual(1, $migration['statusCounters'][Resource::TYPE_PLATFORM]['success']);

                $response = $this->client->call(Client::METHOD_GET, '/project/platforms/' . $destinationPlatformId, $destinationHeaders);
                $this->assertEquals(200, $response['headers']['status-code']);
                $this->assertSame('source.localhost', $response['body']['hostname']);
            },
        );

        // Cleanup on destination
        $this->client->call(Client::METHOD_DELETE, '/project/platforms/' . $destinationPlatformId, $destinationHeaders);

        // Cleanup on source
        $this->client->call(Client::METHOD_DELETE, '/project/platforms/' . $platform['$id'], $sourceHeaders);
    }

    public function testAppwriteMigrationApiKey(): void
    {
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $destinationHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        // Create API key on source project
        $response = $this->client->call(Client::METHOD_POST, '/project/keys', $sourceHeaders, [
            'keyId' => ID::unique(),
            'name' => 'Test API Key',
            'scopes' => ['databases.read', 'databases.write'],
            'expire' => null,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        $apiKey = $response['body'];

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_API_KEY,
            ],
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_API_KEY], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_API_KEY, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_API_KEY]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_API_KEY]['pending']);
        $this->assertGreaterThanOrEqual(1, $result['statusCounters'][Resource::TYPE_API_KEY]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_API_KEY]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_API_KEY]['warning']);

        // Verify API key on destination project using the project's API key
        $response = $this->client->call(Client::METHOD_GET, '/project/keys', $destinationHeaders);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertGreaterThan(0, $response['body']['total']);

        $foundKey = null;

        foreach ($response['body']['keys'] as $k) {
            if ($k['name'] === 'Test API Key') {
                $foundKey = $k;

                break;
            }
        }

        $this->assertNotNull($foundKey);
        $this->assertEquals('Test API Key', $foundKey['name']);
        $this->assertEqualsCanonicalizing(['databases.read', 'databases.write'], $foundKey['scopes']);
        $this->assertEmpty($foundKey['expire']);
        $this->assertNotEquals($apiKey['secret'], $foundKey['secret']);

        $destinationKeyId = $foundKey['$id'];
        $this->assertMigrationSkipAndOverwrite(
            [Resource::TYPE_API_KEY],
            function () use ($destinationHeaders, $destinationKeyId): void {
                $response = $this->client->call(Client::METHOD_PUT, '/project/keys/' . $destinationKeyId, $destinationHeaders, [
                    'name' => 'Test API Key',
                    'scopes' => ['users.read'],
                    'expire' => null,
                ]);
                $this->assertEquals(200, $response['headers']['status-code']);
            },
            function (array $migration) use ($destinationHeaders, $destinationKeyId): void {
                $this->assertGreaterThanOrEqual(1, $migration['statusCounters'][Resource::TYPE_API_KEY]['skip']);

                $response = $this->client->call(Client::METHOD_GET, '/project/keys/' . $destinationKeyId, $destinationHeaders);
                $this->assertEquals(200, $response['headers']['status-code']);
                $this->assertEqualsCanonicalizing(['users.read'], $response['body']['scopes']);
            },
            function () use ($sourceHeaders, $apiKey): void {
                $response = $this->client->call(Client::METHOD_PUT, '/project/keys/' . $apiKey['$id'], $sourceHeaders, [
                    'name' => 'Test API Key',
                    'scopes' => ['users.read', 'users.write'],
                    'expire' => null,
                ]);
                $this->assertEquals(200, $response['headers']['status-code']);
            },
            function (array $migration) use ($destinationHeaders, $destinationKeyId): void {
                $this->assertGreaterThanOrEqual(1, $migration['statusCounters'][Resource::TYPE_API_KEY]['success']);

                $response = $this->client->call(Client::METHOD_GET, '/project/keys/' . $destinationKeyId, $destinationHeaders);
                $this->assertEquals(200, $response['headers']['status-code']);
                $this->assertEqualsCanonicalizing(['users.read', 'users.write'], $response['body']['scopes']);
            },
        );

        // Cleanup migrated keys on destination — delete anything that isn't the destination's own auth key,
        // otherwise later tests inherit duplicated apiKeys and fail on conflict.
        $destinationAuthSecret = $this->getDestinationProject()['apiKey'];
        foreach ($response['body']['keys'] as $k) {
            if ($k['secret'] === $destinationAuthSecret) {
                continue;
            }
            $this->client->call(Client::METHOD_DELETE, '/project/keys/' . $k['$id'], $destinationHeaders);
        }

        // Cleanup on source
        $this->client->call(Client::METHOD_DELETE, '/project/keys/' . $apiKey['$id'], $sourceHeaders);
    }

    public function testAppwriteMigrationWebhook(): void
    {
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $destinationHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        // Unique name so re-runs and parallel suites can't match the wrong webhook
        // on the destination list.
        $webhookName = 'Test Webhook ' . ID::unique();

        $createResp = $this->client->call(Client::METHOD_POST, '/webhooks', $sourceHeaders, [
            'webhookId' => ID::unique(),
            'url' => 'https://appwrite.io/hook',
            'name' => $webhookName,
            'events' => ['users.*.create', 'users.*.delete'],
            'enabled' => true,
            'tls' => true,
            'authUsername' => 'hook-user',
            'authPassword' => 'hook-pass',
        ]);
        $this->assertEquals(201, $createResp['headers']['status-code']);
        $sourceWebhook = $createResp['body'];

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_WEBHOOK,
            ],
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_WEBHOOK], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_WEBHOOK, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_WEBHOOK]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_WEBHOOK]['pending']);
        $this->assertGreaterThanOrEqual(1, $result['statusCounters'][Resource::TYPE_WEBHOOK]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_WEBHOOK]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_WEBHOOK]['warning']);

        $listResp = $this->client->call(Client::METHOD_GET, '/webhooks', $destinationHeaders);
        $this->assertEquals(200, $listResp['headers']['status-code']);

        $foundWebhook = null;
        foreach ($listResp['body']['webhooks'] as $w) {
            if ($w['name'] === $webhookName) {
                $foundWebhook = $w;
                break;
            }
        }

        $this->assertNotNull($foundWebhook, 'Migrated webhook not found on destination');
        $this->assertEquals($webhookName, $foundWebhook['name']);
        $this->assertEquals('https://appwrite.io/hook', $foundWebhook['url']);
        $this->assertEqualsCanonicalizing(['users.*.create', 'users.*.delete'], $foundWebhook['events']);
        $this->assertTrue($foundWebhook['enabled']);
        $this->assertTrue($foundWebhook['tls']);
        $this->assertEquals('hook-user', $foundWebhook['authUsername']);
        $this->assertEquals('hook-pass', $foundWebhook['authPassword']);
        // secret is regenerated on the destination because the SDK strips it from list
        // responses on read — same caveat as api keys.
        if (!empty($sourceWebhook['secret'])) {
            $this->assertNotEquals($sourceWebhook['secret'], $foundWebhook['secret'] ?? '');
        }

        $destinationWebhookId = $foundWebhook['$id'];
        $this->assertMigrationSkipAndOverwrite(
            [Resource::TYPE_WEBHOOK],
            function () use ($destinationHeaders, $destinationWebhookId, $webhookName): void {
                $response = $this->client->call(Client::METHOD_PUT, '/webhooks/' . $destinationWebhookId, $destinationHeaders, [
                    'name' => $webhookName,
                    'events' => ['users.*.create'],
                    'url' => 'https://appwrite.io/destination-hook',
                    'enabled' => false,
                    'tls' => true,
                    'authUsername' => 'destination-user',
                    'authPassword' => 'destination-pass',
                ]);
                $this->assertEquals(200, $response['headers']['status-code']);
            },
            function (array $migration) use ($destinationHeaders, $destinationWebhookId): void {
                $this->assertGreaterThanOrEqual(1, $migration['statusCounters'][Resource::TYPE_WEBHOOK]['skip']);

                $response = $this->client->call(Client::METHOD_GET, '/webhooks/' . $destinationWebhookId, $destinationHeaders);
                $this->assertEquals(200, $response['headers']['status-code']);
                $this->assertSame('https://appwrite.io/destination-hook', $response['body']['url']);
                $this->assertFalse($response['body']['enabled']);
            },
            function () use ($sourceHeaders, $sourceWebhook, $webhookName): void {
                $response = $this->client->call(Client::METHOD_PUT, '/webhooks/' . $sourceWebhook['$id'], $sourceHeaders, [
                    'name' => $webhookName,
                    'events' => ['users.*.create', 'users.*.update'],
                    'url' => 'https://appwrite.io/source-hook',
                    'enabled' => true,
                    'tls' => true,
                    'authUsername' => 'source-user',
                    'authPassword' => 'source-pass',
                ]);
                $this->assertEquals(200, $response['headers']['status-code']);
            },
            function (array $migration) use ($destinationHeaders, $destinationWebhookId): void {
                $this->assertGreaterThanOrEqual(1, $migration['statusCounters'][Resource::TYPE_WEBHOOK]['success']);

                $response = $this->client->call(Client::METHOD_GET, '/webhooks/' . $destinationWebhookId, $destinationHeaders);
                $this->assertEquals(200, $response['headers']['status-code']);
                $this->assertSame('https://appwrite.io/source-hook', $response['body']['url']);
                $this->assertEqualsCanonicalizing(['users.*.create', 'users.*.update'], $response['body']['events']);
                $this->assertTrue($response['body']['enabled']);
            },
        );

        // Cleanup on destination
        $this->client->call(Client::METHOD_DELETE, '/webhooks/' . $destinationWebhookId, $destinationHeaders);

        // Cleanup on source
        $this->client->call(Client::METHOD_DELETE, '/webhooks/' . $sourceWebhook['$id'], $sourceHeaders);
    }

    public function testAppwriteMigrationProjectVariable(): void
    {
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $destinationHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        $listDestinationVariables = function () use ($destinationHeaders): array {
            $variables = [];
            $cursorId = null;

            do {
                $queries = [
                    Query::limit(100)->toString(),
                ];

                if ($cursorId !== null) {
                    $queries[] = Query::cursorAfter(new Document(['$id' => $cursorId]))->toString();
                }

                $response = $this->client->call(Client::METHOD_GET, '/project/variables', $destinationHeaders, [
                    'queries' => $queries,
                    'total' => false,
                ]);
                $this->assertEquals(200, $response['headers']['status-code']);

                $batch = $response['body']['variables'];
                array_push($variables, ...$batch);
                $cursorId = !empty($batch) ? $batch[array_key_last($batch)]['$id'] : null;
            } while (\count($batch) === 100);

            return $variables;
        };

        $existingDestinationVariableIds = \array_flip(\array_column($listDestinationVariables(), '$id'));

        // Source-side variable IDs and keys are uniquified so re-runs and parallel suites
        // can't trip the source-side findOne('variables', [key=...]) skip path.
        $plainKey = 'TEST_PLAIN_' . \strtoupper(ID::unique());
        $secretKey = 'TEST_SECRET_' . \strtoupper(ID::unique());

        // Non-secret variable: value should round-trip exactly.
        $plainResp = $this->client->call(Client::METHOD_POST, '/project/variables', $sourceHeaders, [
            'variableId' => ID::unique(),
            'key' => $plainKey,
            'value' => 'plain-value',
            'secret' => false,
        ]);
        $this->assertEquals(201, $plainResp['headers']['status-code']);
        $plainVariable = $plainResp['body'];

        // Secret variable: SDK strips `value` on subsequent reads, so the migration
        // source sees empty and the destination writes empty. Test asserts that.
        $secretResp = $this->client->call(Client::METHOD_POST, '/project/variables', $sourceHeaders, [
            'variableId' => ID::unique(),
            'key' => $secretKey,
            'value' => 'real-secret-value',
            'secret' => true,
        ]);
        $this->assertEquals(201, $secretResp['headers']['status-code']);
        $secretVariable = $secretResp['body'];

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_PROJECT_VARIABLE,
            ],
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_PROJECT_VARIABLE], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_PROJECT_VARIABLE, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROJECT_VARIABLE]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROJECT_VARIABLE]['pending']);
        $this->assertGreaterThanOrEqual(2, $result['statusCounters'][Resource::TYPE_PROJECT_VARIABLE]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROJECT_VARIABLE]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROJECT_VARIABLE]['warning']);

        $destinationVariables = $listDestinationVariables();

        $foundPlain = null;
        $foundSecret = null;
        foreach ($destinationVariables as $v) {
            if ($v['key'] === $plainKey) {
                $foundPlain = $v;
            } elseif ($v['key'] === $secretKey) {
                $foundSecret = $v;
            }
        }

        $this->assertNotNull($foundPlain, 'Plain variable not found on destination');
        $this->assertEquals($plainKey, $foundPlain['key']);
        $this->assertEquals('plain-value', $foundPlain['value']);
        $this->assertFalse($foundPlain['secret']);

        $this->assertNotNull($foundSecret, 'Secret variable not found on destination');
        $this->assertEquals($secretKey, $foundSecret['key']);
        // Secret variables: source SDK never returned the real value, so the destination
        // also stores empty. The original 'real-secret-value' must not have leaked.
        $this->assertEmpty($foundSecret['value']);
        $this->assertTrue($foundSecret['secret']);

        $this->assertMigrationSkipAndOverwrite(
            [Resource::TYPE_PROJECT_VARIABLE],
            fn () => $this->client->call(Client::METHOD_PUT, '/project/variables/' . $foundPlain['$id'], $destinationHeaders, [
                'key' => $plainKey,
                'value' => 'destination-only',
                'secret' => false,
            ]),
            function (array $skip) use ($destinationHeaders, $plainKey): void {
                $this->assertGreaterThanOrEqual(1, $skip['statusCounters'][Resource::TYPE_PROJECT_VARIABLE]['skip']);
                $variable = $this->getProjectVariableByKey($destinationHeaders, $plainKey);
                $this->assertNotNull($variable);
                $this->assertSame('destination-only', $variable['value']);
            },
            fn () => $this->client->call(Client::METHOD_PUT, '/project/variables/' . $plainVariable['$id'], $sourceHeaders, [
                'key' => $plainKey,
                'value' => 'source-overwrite',
                'secret' => false,
            ]),
            function (array $overwrite) use ($destinationHeaders, $plainKey): void {
                $this->assertGreaterThanOrEqual(1, $overwrite['statusCounters'][Resource::TYPE_PROJECT_VARIABLE]['success']);
                $variable = $this->getProjectVariableByKey($destinationHeaders, $plainKey);
                $this->assertNotNull($variable);
                $this->assertSame('source-overwrite', $variable['value']);
            },
        );

        // Cleanup every destination variable this migration added, including any
        // unrelated source variables copied by the resource-level migration.
        foreach ($destinationVariables as $variable) {
            if (!isset($existingDestinationVariableIds[$variable['$id']])) {
                $this->client->call(Client::METHOD_DELETE, '/project/variables/' . $variable['$id'], $destinationHeaders);
            }
        }

        // Cleanup on source
        $this->client->call(Client::METHOD_DELETE, '/project/variables/' . $plainVariable['$id'], $sourceHeaders);
        $this->client->call(Client::METHOD_DELETE, '/project/variables/' . $secretVariable['$id'], $sourceHeaders);
    }

    public function testAppwriteMigrationAuthMethods(): void
    {
        $sourceProjectId = $this->getProject()['$id'];
        $destinationProjectId = $this->getDestinationProject()['$id'];

        $sourceKeyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProjectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destinationKeyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $destinationProjectId,
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        // Flip a couple of auth methods on the source so the round-trip is
        // observable. Settling on email-password OFF and JWT OFF — the
        // remaining flags stay on their server defaults.
        $this->client->call(Client::METHOD_PATCH, '/project/auth-methods/email-password', $sourceKeyHeaders, [
            'enabled' => false,
        ]);
        $this->client->call(Client::METHOD_PATCH, '/project/auth-methods/jwt', $sourceKeyHeaders, [
            'enabled' => false,
        ]);

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_AUTH_METHODS,
            ],
            'endpoint' => $this->webEndpoint,
            'projectId' => $sourceProjectId,
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_AUTH_METHODS], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_AUTH_METHODS, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_AUTH_METHODS]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_AUTH_METHODS]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_AUTH_METHODS]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_AUTH_METHODS]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_AUTH_METHODS]['warning']);

        $response = $this->client->call(Client::METHOD_GET, '/project', $destinationKeyHeaders);

        $this->assertEquals(200, $response['headers']['status-code']);
        $authMethods = \array_column($response['body']['authMethods'] ?? [], 'enabled', '$id');
        $this->assertFalse($authMethods['email-password'] ?? null, 'email-password auth method should be migrated as false');
        $this->assertFalse($authMethods['jwt'] ?? null, 'jwt auth method should be migrated as false');

        $this->assertMigrationDuplicateModesComplete([Resource::TYPE_AUTH_METHODS]);

        // Restore source so the test is idempotent.
        $this->client->call(Client::METHOD_PATCH, '/project/auth-methods/email-password', $sourceKeyHeaders, ['enabled' => true]);
        $this->client->call(Client::METHOD_PATCH, '/project/auth-methods/jwt', $sourceKeyHeaders, ['enabled' => true]);
        // Restore destination too.
        $this->client->call(Client::METHOD_PATCH, '/project/auth-methods/email-password', $destinationKeyHeaders, ['enabled' => true]);
        $this->client->call(Client::METHOD_PATCH, '/project/auth-methods/jwt', $destinationKeyHeaders, ['enabled' => true]);
    }

    public function testAppwriteMigrationProtocols(): void
    {
        $sourceProjectId = $this->getProject()['$id'];
        $destinationProjectId = $this->getDestinationProject()['$id'];

        $sourceKeyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProjectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destinationKeyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $destinationProjectId,
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        // Flip graphql + websocket off on source to make the round-trip observable.
        $this->client->call(Client::METHOD_PATCH, '/project/protocols/graphql', $sourceKeyHeaders, [
            'enabled' => false,
        ]);
        $this->client->call(Client::METHOD_PATCH, '/project/protocols/websocket', $sourceKeyHeaders, [
            'enabled' => false,
        ]);

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_PROJECT_PROTOCOLS,
            ],
            'endpoint' => $this->webEndpoint,
            'projectId' => $sourceProjectId,
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_PROJECT_PROTOCOLS], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_PROJECT_PROTOCOLS, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROJECT_PROTOCOLS]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROJECT_PROTOCOLS]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_PROJECT_PROTOCOLS]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROJECT_PROTOCOLS]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROJECT_PROTOCOLS]['warning']);

        $response = $this->client->call(Client::METHOD_GET, '/project', $destinationKeyHeaders);

        $this->assertEquals(200, $response['headers']['status-code']);
        $protocols = \array_column($response['body']['protocols'] ?? [], 'enabled', '$id');
        $this->assertFalse($protocols['graphql'] ?? null, 'GraphQL protocol should be migrated as disabled');
        $this->assertFalse($protocols['websocket'] ?? null, 'WebSocket protocol should be migrated as disabled');

        $this->assertMigrationDuplicateModesComplete([Resource::TYPE_PROJECT_PROTOCOLS]);

        // Restore both projects so the test is idempotent.
        $this->client->call(Client::METHOD_PATCH, '/project/protocols/graphql', $sourceKeyHeaders, ['enabled' => true]);
        $this->client->call(Client::METHOD_PATCH, '/project/protocols/websocket', $sourceKeyHeaders, ['enabled' => true]);
        $this->client->call(Client::METHOD_PATCH, '/project/protocols/graphql', $destinationKeyHeaders, ['enabled' => true]);
        $this->client->call(Client::METHOD_PATCH, '/project/protocols/websocket', $destinationKeyHeaders, ['enabled' => true]);
    }

    public function testAppwriteMigrationLabels(): void
    {
        $sourceProjectId = $this->getProject()['$id'];
        $destinationProjectId = $this->getDestinationProject()['$id'];

        $sourceKeyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProjectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destinationKeyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $destinationProjectId,
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        $labels = ['vip' . \substr(ID::unique(), 0, 8), 'beta' . \substr(ID::unique(), 0, 8)];

        // Set labels on source. The labels endpoint is PUT /project/labels — the
        // generic project update endpoint doesn't accept a labels param.
        $this->client->call(Client::METHOD_PUT, '/project/labels', $sourceKeyHeaders, [
            'labels' => $labels,
        ]);

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_PROJECT_LABELS,
            ],
            'endpoint' => $this->webEndpoint,
            'projectId' => $sourceProjectId,
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_PROJECT_LABELS], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_PROJECT_LABELS, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROJECT_LABELS]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROJECT_LABELS]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_PROJECT_LABELS]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROJECT_LABELS]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROJECT_LABELS]['warning']);

        $response = $this->client->call(Client::METHOD_GET, '/project', $destinationKeyHeaders);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEqualsCanonicalizing($labels, $response['body']['labels']);

        $this->assertMigrationDuplicateModesComplete([Resource::TYPE_PROJECT_LABELS]);

        // Restore both projects.
        $this->client->call(Client::METHOD_PUT, '/project/labels', $sourceKeyHeaders, ['labels' => []]);
        $this->client->call(Client::METHOD_PUT, '/project/labels', $destinationKeyHeaders, ['labels' => []]);
    }

    public function testAppwriteMigrationServices(): void
    {
        $sourceProjectId = $this->getProject()['$id'];
        $destinationProjectId = $this->getDestinationProject()['$id'];

        $sourceKeyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProjectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destinationKeyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $destinationProjectId,
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        // Disable functions + graphql on source as observable changes.
        $this->client->call(Client::METHOD_PATCH, '/project/services/functions', $sourceKeyHeaders, ['enabled' => false]);
        $this->client->call(Client::METHOD_PATCH, '/project/services/graphql', $sourceKeyHeaders, ['enabled' => false]);

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_PROJECT_SERVICES,
            ],
            'endpoint' => $this->webEndpoint,
            'projectId' => $sourceProjectId,
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_PROJECT_SERVICES], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_PROJECT_SERVICES, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROJECT_SERVICES]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROJECT_SERVICES]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_PROJECT_SERVICES]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROJECT_SERVICES]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROJECT_SERVICES]['warning']);

        $response = $this->client->call(Client::METHOD_GET, '/project', $destinationKeyHeaders);
        $this->assertEquals(200, $response['headers']['status-code']);
        $services = \array_column($response['body']['services'] ?? [], 'enabled', '$id');
        $this->assertFalse($services['functions'] ?? null, 'Functions service should be migrated as disabled');
        $this->assertFalse($services['graphql'] ?? null, 'GraphQL service should be migrated as disabled');

        $this->assertMigrationDuplicateModesComplete([Resource::TYPE_PROJECT_SERVICES]);

        // Restore both projects.
        $this->client->call(Client::METHOD_PATCH, '/project/services/functions', $sourceKeyHeaders, ['enabled' => true]);
        $this->client->call(Client::METHOD_PATCH, '/project/services/graphql', $sourceKeyHeaders, ['enabled' => true]);
        $this->client->call(Client::METHOD_PATCH, '/project/services/functions', $destinationKeyHeaders, ['enabled' => true]);
        $this->client->call(Client::METHOD_PATCH, '/project/services/graphql', $destinationKeyHeaders, ['enabled' => true]);
    }

    public function testAppwriteMigrationPolicies(): void
    {
        $sourceProjectId = $this->getProject()['$id'];
        $destinationProjectId = $this->getDestinationProject()['$id'];

        // Policies have no /projects/:projectId admin route — they're only
        // reachable via project-scoped /v1/project/policies/* with an API key.
        $sourceKeyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProjectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destinationKeyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $destinationProjectId,
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        // Pick three policies that span the field types: int, bool, and
        // bundled membership-privacy.
        $this->client->call(Client::METHOD_PATCH, '/project/policies/password-history', $sourceKeyHeaders, [
            'total' => 5,
        ]);
        $this->client->call(Client::METHOD_PATCH, '/project/policies/session-alert', $sourceKeyHeaders, [
            'enabled' => true,
        ]);
        $this->client->call(Client::METHOD_PATCH, '/project/policies/membership-privacy', $sourceKeyHeaders, [
            'userEmail' => false,
        ]);

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_POLICIES,
            ],
            'endpoint' => $this->webEndpoint,
            'projectId' => $sourceProjectId,
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_POLICIES], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_POLICIES, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_POLICIES]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_POLICIES]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_POLICIES]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_POLICIES]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_POLICIES]['warning']);

        $passwordHistory = $this->client->call(Client::METHOD_GET, '/project/policies/password-history', $destinationKeyHeaders);
        $this->assertSame(200, $passwordHistory['headers']['status-code']);
        $this->assertSame(5, $passwordHistory['body']['total'], 'passwordHistory should be migrated as 5');

        $sessionAlert = $this->client->call(Client::METHOD_GET, '/project/policies/session-alert', $destinationKeyHeaders);
        $this->assertSame(200, $sessionAlert['headers']['status-code']);
        $this->assertTrue($sessionAlert['body']['enabled'], 'session-alert policy should be migrated as enabled');

        $membershipPrivacy = $this->client->call(Client::METHOD_GET, '/project/policies/membership-privacy', $destinationKeyHeaders);
        $this->assertSame(200, $membershipPrivacy['headers']['status-code']);
        $this->assertFalse($membershipPrivacy['body']['userEmail'], 'membership-privacy userEmail should be migrated as false');

        $this->assertMigrationDuplicateModesComplete([Resource::TYPE_POLICIES]);

        // Restore both projects to defaults.
        $this->client->call(Client::METHOD_PATCH, '/project/policies/password-history', $sourceKeyHeaders, ['total' => 0]);
        $this->client->call(Client::METHOD_PATCH, '/project/policies/session-alert', $sourceKeyHeaders, ['enabled' => false]);
        $this->client->call(Client::METHOD_PATCH, '/project/policies/membership-privacy', $sourceKeyHeaders, ['userEmail' => true]);
        $this->client->call(Client::METHOD_PATCH, '/project/policies/password-history', $destinationKeyHeaders, ['total' => 0]);
        $this->client->call(Client::METHOD_PATCH, '/project/policies/session-alert', $destinationKeyHeaders, ['enabled' => false]);
        $this->client->call(Client::METHOD_PATCH, '/project/policies/membership-privacy', $destinationKeyHeaders, ['userEmail' => true]);
    }

    public function testAppwriteMigrationSMTP(): void
    {
        $sourceProjectId = $this->getProject()['$id'];
        $destinationProjectId = $this->getDestinationProject()['$id'];

        $sourceKeyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProjectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destinationKeyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $destinationProjectId,
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        // Point at the in-cluster maildev container so the endpoint's SMTP
        // connection validation passes. Password is not migrated — source
        // API never exposes it.
        $sourceSmtpUpdate = $this->client->call(Client::METHOD_PATCH, '/project/smtp', $sourceKeyHeaders, [
            'enabled' => true,
            'senderName' => 'Migration Sender',
            'senderEmail' => 'sender@appwrite.io',
            'replyToName' => 'Migration Reply',
            'replyToEmail' => 'reply@appwrite.io',
            'host' => 'maildev',
            'port' => 1025,
        ]);
        $this->assertEquals(200, $sourceSmtpUpdate['headers']['status-code']);

        // Cross-check the PATCH actually landed on the SOURCE project, not on
        // a sibling scope. If this fails we've targeted the wrong project.
        $sourceProjectAfter = $this->client->call(Client::METHOD_GET, '/project', $sourceKeyHeaders);
        $this->assertSame('Migration Sender', $sourceProjectAfter['body']['smtpSenderName']);

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_SMTP,
            ],
            'endpoint' => $this->webEndpoint,
            'projectId' => $sourceProjectId,
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_SMTP], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_SMTP, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_SMTP]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_SMTP]['pending']);
        $this->assertEquals(1, $result['statusCounters'][Resource::TYPE_SMTP]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_SMTP]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_SMTP]['warning']);

        $response = $this->client->call(Client::METHOD_GET, '/project', $destinationKeyHeaders);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertTrue($response['body']['smtpEnabled'], 'smtpEnabled should be migrated as true');
        $this->assertSame('Migration Sender', $response['body']['smtpSenderName']);
        $this->assertSame('sender@appwrite.io', $response['body']['smtpSenderEmail']);
        $this->assertSame('Migration Reply', $response['body']['smtpReplyToName']);
        $this->assertSame('reply@appwrite.io', $response['body']['smtpReplyToEmail']);
        $this->assertSame('maildev', $response['body']['smtpHost']);
        $this->assertSame(1025, $response['body']['smtpPort']);
        $this->assertSame('', $response['body']['smtpSecure']);

        $this->assertMigrationDuplicateModesComplete([Resource::TYPE_SMTP]);

        // Reset both projects so the test is idempotent.
        $this->client->call(Client::METHOD_PATCH, '/project/smtp', $sourceKeyHeaders, ['enabled' => false]);
        $this->client->call(Client::METHOD_PATCH, '/project/smtp', $destinationKeyHeaders, ['enabled' => false]);
    }

    public function testAppwriteMigrationCustomDomains(): void
    {
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $destinationHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        // Domains are globally unique across projects; orphans from prior failed runs
        // poison both source list+report (returns extra rules) and destination create
        // (409 conflict). Sweep both before creating the new rule.
        foreach ([$sourceHeaders, $destinationHeaders] as $headers) {
            $existing = $this->client->call(Client::METHOD_GET, '/proxy/rules', $headers);
            if ($existing['headers']['status-code'] === 200) {
                foreach ($existing['body']['rules'] ?? [] as $r) {
                    if (\str_ends_with($r['domain'] ?? '', '-migration-api.myapp.com')) {
                        $this->client->call(Client::METHOD_DELETE, '/proxy/rules/' . $r['$id'], $headers);
                    }
                }
            }
        }

        // Unique domain so re-runs and parallel suites can't collide on the
        // global domain uniqueness check.
        $domain = \uniqid() . '-migration-api.myapp.com';

        $createResp = $this->client->call(Client::METHOD_POST, '/proxy/rules/api', $sourceHeaders, [
            'domain' => $domain,
        ]);
        $this->assertEquals(201, $createResp['headers']['status-code']);
        $sourceRule = $createResp['body'];

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_RULE,
            ],
            'endpoint' => $this->webEndpoint,
            'projectId' => $this->getProject()['$id'],
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_RULE], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_RULE, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_RULE]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_RULE]['pending']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_RULE]['processing']);
        // Domain uniqueness is enforced globally across projects. In this single-server
        // E2E setup the source project still owns the domain when the migration runs,
        // so the destination create hits the cross-project 409 — the destination must
        // surface a WARNING, not a hard error, so the rest of the migration continues.
        // (In a real self-hosted-to-cloud migration the source domain is on a separate
        // server, so this conflict does not occur and we'd see `success` instead.)
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_RULE]['success']);
        $this->assertGreaterThanOrEqual(1, $result['statusCounters'][Resource::TYPE_RULE]['warning']);

        // Cleanup on source
        $this->client->call(Client::METHOD_DELETE, '/proxy/rules/' . $sourceRule['$id'], $sourceHeaders);
    }

    public function testAppwriteMigrationEmailTemplate(): void
    {
        $sourceProjectId = $this->getProject()['$id'];
        $destinationProjectId = $this->getDestinationProject()['$id'];

        $sourceKeyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProjectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destinationKeyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $destinationProjectId,
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        // The source SDK path requires custom SMTP enabled before a template can be
        // saved — and the enable call validates the SMTP connection. `maildev` is the
        // dev mailcatcher in the test cluster's docker-compose; it accepts unauthenticated
        // connections on port 1025, so it's the only host that lets us pass validation.
        $smtpUpdate = $this->client->call(Client::METHOD_PATCH, '/project/smtp', $sourceKeyHeaders, [
            'enabled' => true,
            'senderName' => 'Test Sender',
            'senderEmail' => 'sender@example.com',
            'host' => 'maildev',
            'port' => 1025,
        ]);
        $this->assertEquals(200, $smtpUpdate['headers']['status-code'], 'SMTP enable on source failed: ' . \json_encode($smtpUpdate['body']));

        $templateId = 'verification';
        $locale = 'en';
        $subject = 'Verify your account ' . ID::unique();
        $message = '<p>Hello {{user}}, verify your account at {{redirect}}</p>';

        $update = $this->client->call(
            Client::METHOD_PATCH,
            '/project/templates/email',
            $sourceKeyHeaders,
            [
                'templateId' => $templateId,
                'locale' => $locale,
                'subject' => $subject,
                'message' => $message,
                'senderName' => 'Template Sender',
                'senderEmail' => 'tpl-sender@example.com',
                'replyToEmail' => 'reply@example.com',
                'replyToName' => 'Reply Team',
            ]
        );
        $this->assertEquals(200, $update['headers']['status-code']);

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_PROJECT_EMAIL_TEMPLATE,
            ],
            'endpoint' => $this->webEndpoint,
            'projectId' => $sourceProjectId,
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_PROJECT_EMAIL_TEMPLATE], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_PROJECT_EMAIL_TEMPLATE, $result['statusCounters']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROJECT_EMAIL_TEMPLATE]['error']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROJECT_EMAIL_TEMPLATE]['pending']);
        $this->assertGreaterThanOrEqual(1, $result['statusCounters'][Resource::TYPE_PROJECT_EMAIL_TEMPLATE]['success']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROJECT_EMAIL_TEMPLATE]['processing']);
        $this->assertEquals(0, $result['statusCounters'][Resource::TYPE_PROJECT_EMAIL_TEMPLATE]['warning']);

        // Read-back via the SDK requires the destination to have SMTP enabled too.
        $this->client->call(Client::METHOD_PATCH, '/project/smtp', $destinationKeyHeaders, [
            'enabled' => true,
            'senderName' => 'Dest Sender',
            'senderEmail' => 'dest@example.com',
            'host' => 'maildev',
            'port' => 1025,
        ]);

        $fetched = $this->client->call(
            Client::METHOD_GET,
            '/project/templates/email/' . $templateId,
            $destinationKeyHeaders,
            ['locale' => $locale]
        );
        $this->assertEquals(200, $fetched['headers']['status-code']);
        $this->assertSame($subject, $fetched['body']['subject']);
        $this->assertSame($message, $fetched['body']['message']);
        $this->assertSame('Template Sender', $fetched['body']['senderName']);
        $this->assertSame('tpl-sender@example.com', $fetched['body']['senderEmail']);
        $this->assertSame('reply@example.com', $fetched['body']['replyToEmail']);
        $this->assertSame('Reply Team', $fetched['body']['replyToName']);

        $this->assertMigrationDuplicateModesComplete([Resource::TYPE_PROJECT_EMAIL_TEMPLATE]);

        // Reset both projects so the test is idempotent.
        $this->client->call(Client::METHOD_PATCH, '/project/smtp', $sourceKeyHeaders, ['enabled' => false]);
        $this->client->call(Client::METHOD_PATCH, '/project/smtp', $destinationKeyHeaders, ['enabled' => false]);
    }

    public function testAppwriteMigrationOAuth(): void
    {
        $sourceProjectId = $this->getProject()['$id'];
        $destinationProjectId = $this->getDestinationProject()['$id'];

        $sourceKeyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sourceProjectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destinationKeyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $destinationProjectId,
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

        $clientId = 'gh-client-' . ID::unique();
        $configure = $this->client->call(Client::METHOD_PATCH, '/project/oauth2/github', $sourceKeyHeaders, [
            'clientId' => $clientId,
            'clientSecret' => 'gh-secret-not-migrated',
            'enabled' => false,
        ]);
        $this->assertEquals(200, $configure['headers']['status-code']);
        $this->assertSame($clientId, $configure['body']['clientId']);

        $keycloak = $this->client->call(Client::METHOD_PATCH, '/project/oauth2/keycloak', $sourceKeyHeaders, [
            'clientId' => 'keycloak-client-' . ID::unique(),
            'clientSecret' => 'keycloak-secret-not-migrated',
            'endpoint' => 'keycloak.example.com',
            'realmName' => 'appwrite',
            'enabled' => false,
        ]);
        $this->assertEquals(200, $keycloak['headers']['status-code']);

        $oidc = $this->client->call(Client::METHOD_PATCH, '/project/oauth2/oidc', $sourceKeyHeaders, [
            'clientId' => 'oidc-client-' . ID::unique(),
            'clientSecret' => 'oidc-secret-not-migrated',
            'wellKnownURL' => 'https://idp.example.com/.well-known/openid-configuration',
            'authorizationURL' => 'https://idp.example.com/oauth2/authorize',
            'tokenURL' => 'https://idp.example.com/oauth2/token',
            'userInfoURL' => 'https://idp.example.com/oauth2/userinfo',
            'enabled' => false,
        ]);
        $this->assertEquals(200, $oidc['headers']['status-code']);

        $okta = $this->client->call(Client::METHOD_PATCH, '/project/oauth2/okta', $sourceKeyHeaders, [
            'clientId' => 'okta-client-' . ID::unique(),
            'clientSecret' => 'okta-secret-not-migrated',
            'domain' => 'trial-6400025.okta.com',
            'authorizationServerId' => 'aus000000000000000h7z',
            'enabled' => false,
        ]);
        $this->assertEquals(200, $okta['headers']['status-code']);

        $result = $this->performMigrationSync([
            'resources' => [
                Resource::TYPE_OAUTH2_PROVIDER,
            ],
            'endpoint' => $this->webEndpoint,
            'projectId' => $sourceProjectId,
            'apiKey' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals([Resource::TYPE_OAUTH2_PROVIDER], $result['resources']);
        $this->assertArrayHasKey(Resource::TYPE_OAUTH2_PROVIDER, $result['statusCounters']);
        $oauthCounters = $result['statusCounters'][Resource::TYPE_OAUTH2_PROVIDER];
        $this->assertEquals(0, $oauthCounters['error']);
        $this->assertEquals(0, $oauthCounters['pending']);
        $this->assertEquals(0, $oauthCounters['processing']);
        $this->assertEquals(0, $oauthCounters['warning']);
        $this->assertEquals(4, $oauthCounters['success']);

        $fetched = $this->client->call(Client::METHOD_GET, '/project/oauth2/github', $destinationKeyHeaders);
        $this->assertEquals(200, $fetched['headers']['status-code']);
        $this->assertSame($clientId, $fetched['body']['clientId']);
        $this->assertFalse($fetched['body']['enabled']);
        $this->assertSame('', $fetched['body']['clientSecret']);

        $fetched = $this->client->call(Client::METHOD_GET, '/project/oauth2/keycloak', $destinationKeyHeaders);
        $this->assertEquals(200, $fetched['headers']['status-code']);
        $this->assertSame($keycloak['body']['clientId'], $fetched['body']['clientId']);
        $this->assertSame('keycloak.example.com', $fetched['body']['endpoint']);
        $this->assertSame('appwrite', $fetched['body']['realmName']);
        $this->assertFalse($fetched['body']['enabled']);
        $this->assertSame('', $fetched['body']['clientSecret']);

        $fetched = $this->client->call(Client::METHOD_GET, '/project/oauth2/oidc', $destinationKeyHeaders);
        $this->assertEquals(200, $fetched['headers']['status-code']);
        $this->assertSame($oidc['body']['clientId'], $fetched['body']['clientId']);
        $this->assertSame('https://idp.example.com/.well-known/openid-configuration', $fetched['body']['wellKnownURL']);
        $this->assertSame('https://idp.example.com/oauth2/authorize', $fetched['body']['authorizationURL']);
        $this->assertSame('https://idp.example.com/oauth2/token', $fetched['body']['tokenURL']);
        $this->assertSame('https://idp.example.com/oauth2/userinfo', $fetched['body']['userInfoURL']);
        $this->assertFalse($fetched['body']['enabled']);
        $this->assertSame('', $fetched['body']['clientSecret']);

        $fetched = $this->client->call(Client::METHOD_GET, '/project/oauth2/okta', $destinationKeyHeaders);
        $this->assertEquals(200, $fetched['headers']['status-code']);
        $this->assertSame($okta['body']['clientId'], $fetched['body']['clientId']);
        $this->assertSame('trial-6400025.okta.com', $fetched['body']['domain']);
        $this->assertSame('aus000000000000000h7z', $fetched['body']['authorizationServerId']);
        $this->assertFalse($fetched['body']['enabled']);
        $this->assertSame('', $fetched['body']['clientSecret']);

        $this->assertMigrationDuplicateModesComplete([Resource::TYPE_OAUTH2_PROVIDER]);

        $this->client->call(Client::METHOD_PATCH, '/project/oauth2/github', $sourceKeyHeaders, [
            'clientId' => '',
            'clientSecret' => '',
            'enabled' => false,
        ]);
        $this->client->call(Client::METHOD_PATCH, '/project/oauth2/github', $destinationKeyHeaders, [
            'clientId' => '',
            'clientSecret' => '',
            'enabled' => false,
        ]);
        foreach ([$sourceKeyHeaders, $destinationKeyHeaders] as $headers) {
            $this->client->call(Client::METHOD_PATCH, '/project/oauth2/keycloak', $headers, [
                'clientId' => '',
                'clientSecret' => '',
                'endpoint' => '',
                'realmName' => '',
                'enabled' => false,
            ]);
            $this->client->call(Client::METHOD_PATCH, '/project/oauth2/oidc', $headers, [
                'clientId' => '',
                'clientSecret' => '',
                'wellKnownURL' => '',
                'authorizationURL' => '',
                'tokenURL' => '',
                'userInfoURL' => '',
                'enabled' => false,
            ]);
            $this->client->call(Client::METHOD_PATCH, '/project/oauth2/okta', $headers, [
                'clientId' => '',
                'clientSecret' => '',
                'domain' => '',
                'authorizationServerId' => '',
                'enabled' => false,
            ]);
        }
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
                'missing-column.csv',
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

    /**
     * Set up a database + table + bucket + uploaded CSV for the skip/overwrite tests.
     * Returns [$databaseId, $tableId, $bucketId, $fileId, $firstRowId, $firstRowName, $firstRowAge].
     *
     * @return array{string,string,string,string,string,string,int}
     */
    private function prepareCsvImportFixture(string $testLabel): array
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ];

        // database
        $response = $this->client->call(Client::METHOD_POST, '/databases', $headers, [
            'databaseId' => ID::unique(),
            'name' => 'Test DB ' . $testLabel,
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $databaseId = $response['body']['$id'];

        // table
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', $headers, [
            'name' => 'Test table ' . $testLabel,
            'tableId' => ID::unique(),
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $tableId = $response['body']['$id'];

        // columns: name, age (match documents.csv fixture)
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', $headers, [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', $headers, [
            'key' => 'age',
            'min' => 18,
            'max' => 65,
            'required' => true,
        ]);
        $this->assertEquals(202, $response['headers']['status-code']);

        // Columns are created async (202). Wait for both to be `available`
        // before proceeding so the migration worker doesn't race the schema.
        foreach (['name', 'age'] as $column) {
            $this->assertEventually(function () use ($databaseId, $tableId, $column, $headers) {
                $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/' . $column, $headers);
                $this->assertEquals(200, $response['headers']['status-code']);
                $this->assertEquals('available', $response['body']['status']);
            }, 5000, 500);
        }

        // bucket
        $response = $this->client->call(Client::METHOD_POST, '/storage/buckets', $headers, [
            'bucketId' => ID::unique(),
            'name' => 'Bucket ' . $testLabel,
            'maximumFileSize' => 2000000,
            'allowedFileExtensions' => ['csv'],
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $bucketId = $response['body']['$id'];

        // upload documents.csv (100 rows with $id, name, age columns)
        $response = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/csv/documents.csv'), 'text/csv', 'documents.csv'),
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $fileId = $response['body']['$id'];

        // first row in documents.csv: hxfcwpcas5xokpwe,Diamond Mendez,56
        return [$databaseId, $tableId, $bucketId, $fileId, 'hxfcwpcas5xokpwe', 'Diamond Mendez', 56];
    }

    /**
     * onDuplicate=skip on re-import: duplicates are silently no-op'd, existing rows preserved unchanged.
     */
    public function testCreateCSVImportSkipDuplicates(): void
    {
        [$databaseId, $tableId, $bucketId, $fileId, $rowId, $originalName, $originalAge] = $this->prepareCsvImportFixture('skip');

        // First import: 100 rows created
        $first = $this->performCsvMigration([
            'fileId' => $fileId,
            'bucketId' => $bucketId,
            'resourceId' => $databaseId . ':' . $tableId,
        ]);
        $this->assertEventually(function () use ($first) {
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/' . $first['body']['$id'], array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertEquals('completed', $migration['body']['status']);
            $this->assertEquals(100, $migration['body']['statusCounters'][Resource::TYPE_ROW]['success']);
        }, 10_000, 500);

        // Mutate one row so we can prove skip does NOT overwrite it
        $mutate = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'data' => ['age' => 22],
        ]);
        $this->assertEquals(200, $mutate['headers']['status-code']);
        $this->assertEquals(22, $mutate['body']['age']);

        // Second import with onDuplicate=skip: no errors, mutated row preserved
        $second = $this->performCsvMigration([
            'fileId' => $fileId,
            'bucketId' => $bucketId,
            'resourceId' => $databaseId . ':' . $tableId,
            'onDuplicate' => 'skip',
        ]);
        $this->assertEventually(function () use ($second) {
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/' . $second['body']['$id'], array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertEquals('completed', $migration['body']['status']);
        }, 10_000, 500);

        // Mutated row kept its mutated value (not overwritten by CSV's original age)
        $row = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $row['headers']['status-code']);
        $this->assertEquals($originalName, $row['body']['name']);
        $this->assertEquals(22, $row['body']['age'], 'onDuplicate=skip must not overwrite mutated row');

        // Row count still 100 (no duplicates created)
        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::limit(150)->toString()],
        ]);
        $this->assertEquals(100, $rows['body']['total']);
    }

    /**
     * onDuplicate=overwrite on re-import: existing rows are replaced with imported values.
     */
    public function testCreateCSVImportOverwrite(): void
    {
        [$databaseId, $tableId, $bucketId, $fileId, $rowId, $originalName, $originalAge] = $this->prepareCsvImportFixture('overwrite');

        // First import: 100 rows created
        $first = $this->performCsvMigration([
            'fileId' => $fileId,
            'bucketId' => $bucketId,
            'resourceId' => $databaseId . ':' . $tableId,
        ]);
        $this->assertEventually(function () use ($first) {
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/' . $first['body']['$id'], array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertEquals('completed', $migration['body']['status']);
            $this->assertEquals(100, $migration['body']['statusCounters'][Resource::TYPE_ROW]['success']);
        }, 10_000, 500);

        // Mutate one row so we can prove overwrite restores it to the CSV's original value
        $mutate = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'data' => ['age' => 22],
        ]);
        $this->assertEquals(200, $mutate['headers']['status-code']);
        $this->assertEquals(22, $mutate['body']['age']);

        // Second import with onDuplicate=overwrite: mutated row restored to CSV value
        $second = $this->performCsvMigration([
            'fileId' => $fileId,
            'bucketId' => $bucketId,
            'resourceId' => $databaseId . ':' . $tableId,
            'onDuplicate' => 'overwrite',
        ]);
        $this->assertEventually(function () use ($second) {
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/' . $second['body']['$id'], array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertEquals('completed', $migration['body']['status']);
        }, 10_000, 500);

        // Mutated row is back to CSV's original age (proving overwrite actually replaced the row)
        $row = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $row['headers']['status-code']);
        $this->assertEquals($originalName, $row['body']['name']);
        $this->assertEquals($originalAge, $row['body']['age'], 'onDuplicate=overwrite must restore row to imported value');

        // Row count still 100 (no duplicates created)
        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::limit(150)->toString()],
        ]);
        $this->assertEquals(100, $rows['body']['total']);
    }

    /**
     * Default behavior (neither flag): re-import of duplicate ids fails with DuplicateException.
     * Regression guard so the skip/overwrite additions don't silently change the default.
     */
    public function testCreateCSVImportDefaultFailsOnDuplicate(): void
    {
        [$databaseId, $tableId, $bucketId, $fileId] = $this->prepareCsvImportFixture('default');

        // First import: succeeds
        $first = $this->performCsvMigration([
            'fileId' => $fileId,
            'bucketId' => $bucketId,
            'resourceId' => $databaseId . ':' . $tableId,
        ]);
        $this->assertEventually(function () use ($first) {
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/' . $first['body']['$id'], array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertEquals('completed', $migration['body']['status']);
        }, 10_000, 500);

        // Second import with no flags: should fail on duplicate ids
        $second = $this->performCsvMigration([
            'fileId' => $fileId,
            'bucketId' => $bucketId,
            'resourceId' => $databaseId . ':' . $tableId,
        ]);
        $this->assertEventually(function () use ($second) {
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/' . $second['body']['$id'], array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertEquals('finished', $migration['body']['stage']);
            $this->assertEquals('failed', $migration['body']['status']);
            $this->assertNotEmpty($migration['body']['errors']);
        }, 60_000, 500);
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
     * Set up a database + table + bucket + uploaded JSON for the skip/overwrite tests.
     * Mirrors prepareCsvImportFixture but uploads documents.json instead.
     *
     * @return array{string,string,string,string,string,string,int}
     */
    private function prepareJsonImportFixture(string $testLabel): array
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ];

        // database
        $response = $this->client->call(Client::METHOD_POST, '/databases', $headers, [
            'databaseId' => ID::unique(),
            'name' => 'Test JSON DB ' . $testLabel,
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $databaseId = $response['body']['$id'];

        // table
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', $headers, [
            'name' => 'Test JSON table ' . $testLabel,
            'tableId' => ID::unique(),
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $tableId = $response['body']['$id'];

        // columns: name, age (match documents.json fixture)
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', $headers, [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', $headers, [
            'key' => 'age',
            'min' => 18,
            'max' => 65,
            'required' => true,
        ]);
        $this->assertEquals(202, $response['headers']['status-code']);

        foreach (['name', 'age'] as $column) {
            $this->assertEventually(function () use ($databaseId, $tableId, $column, $headers) {
                $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/' . $column, $headers);
                $this->assertEquals(200, $response['headers']['status-code']);
                $this->assertEquals('available', $response['body']['status']);
            }, 5000, 500);
        }

        // bucket
        $response = $this->client->call(Client::METHOD_POST, '/storage/buckets', $headers, [
            'bucketId' => ID::unique(),
            'name' => 'JSON Bucket ' . $testLabel,
            'maximumFileSize' => 2000000,
            'allowedFileExtensions' => ['json'],
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $bucketId = $response['body']['$id'];

        // upload documents.json (same row shape as documents.csv)
        $response = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/json/documents.json'), 'application/json', 'documents.json'),
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $fileId = $response['body']['$id'];

        // first row in documents.json: hxfcwpcas5xokpwe, Diamond Mendez, 56
        return [$databaseId, $tableId, $bucketId, $fileId, 'hxfcwpcas5xokpwe', 'Diamond Mendez', 56];
    }

    /**
     * onDuplicate=skip on JSON re-import: duplicates silently no-op, existing rows preserved unchanged.
     */
    public function testCreateJSONImportSkipDuplicates(): void
    {
        [$databaseId, $tableId, $bucketId, $fileId, $rowId, $originalName, $originalAge] = $this->prepareJsonImportFixture('skip');

        $first = $this->performJsonMigration([
            'fileId' => $fileId,
            'bucketId' => $bucketId,
            'resourceId' => $databaseId . ':' . $tableId,
        ]);
        $this->assertEventually(function () use ($first) {
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/' . $first['body']['$id'], array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertEquals('completed', $migration['body']['status']);
            $this->assertEquals(100, $migration['body']['statusCounters'][Resource::TYPE_ROW]['success']);
        }, 10_000, 500);

        // Mutate one row so we can prove skip does NOT overwrite it
        $mutate = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'data' => ['age' => 22],
        ]);
        $this->assertEquals(200, $mutate['headers']['status-code']);
        $this->assertEquals(22, $mutate['body']['age']);

        $second = $this->performJsonMigration([
            'fileId' => $fileId,
            'bucketId' => $bucketId,
            'resourceId' => $databaseId . ':' . $tableId,
            'onDuplicate' => 'skip',
        ]);
        $this->assertEventually(function () use ($second) {
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/' . $second['body']['$id'], array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertEquals('completed', $migration['body']['status']);
        }, 10_000, 500);

        $row = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $row['headers']['status-code']);
        $this->assertEquals($originalName, $row['body']['name']);
        $this->assertEquals(22, $row['body']['age'], 'onDuplicate=skip must not overwrite mutated row');

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::limit(150)->toString()],
        ]);
        $this->assertEquals(100, $rows['body']['total']);
    }

    /**
     * onDuplicate=overwrite on JSON re-import: existing rows replaced with imported values.
     */
    public function testCreateJSONImportOverwrite(): void
    {
        [$databaseId, $tableId, $bucketId, $fileId, $rowId, $originalName, $originalAge] = $this->prepareJsonImportFixture('overwrite');

        $first = $this->performJsonMigration([
            'fileId' => $fileId,
            'bucketId' => $bucketId,
            'resourceId' => $databaseId . ':' . $tableId,
        ]);
        $this->assertEventually(function () use ($first) {
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/' . $first['body']['$id'], array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertEquals('completed', $migration['body']['status']);
            $this->assertEquals(100, $migration['body']['statusCounters'][Resource::TYPE_ROW]['success']);
        }, 10_000, 500);

        $mutate = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'data' => ['age' => 22],
        ]);
        $this->assertEquals(200, $mutate['headers']['status-code']);
        $this->assertEquals(22, $mutate['body']['age']);

        $second = $this->performJsonMigration([
            'fileId' => $fileId,
            'bucketId' => $bucketId,
            'resourceId' => $databaseId . ':' . $tableId,
            'onDuplicate' => 'overwrite',
        ]);
        $this->assertEventually(function () use ($second) {
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/' . $second['body']['$id'], array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertEquals('completed', $migration['body']['status']);
        }, 10_000, 500);

        $row = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $row['headers']['status-code']);
        $this->assertEquals($originalName, $row['body']['name']);
        $this->assertEquals($originalAge, $row['body']['age'], 'onDuplicate=overwrite must restore row to imported value');

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::limit(150)->toString()],
        ]);
        $this->assertEquals(100, $rows['body']['total']);
    }

    /**
     * Default (no onDuplicate) on JSON re-import: regression guard, must fail on duplicate ids.
     */
    public function testCreateJSONImportDefaultFailsOnDuplicate(): void
    {
        [$databaseId, $tableId, $bucketId, $fileId] = $this->prepareJsonImportFixture('default');

        $first = $this->performJsonMigration([
            'fileId' => $fileId,
            'bucketId' => $bucketId,
            'resourceId' => $databaseId . ':' . $tableId,
        ]);
        $this->assertEventually(function () use ($first) {
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/' . $first['body']['$id'], array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertEquals('completed', $migration['body']['status']);
        }, 10_000, 500);

        $second = $this->performJsonMigration([
            'fileId' => $fileId,
            'bucketId' => $bucketId,
            'resourceId' => $databaseId . ':' . $tableId,
        ]);
        $this->assertEventually(function () use ($second) {
            $migration = $this->client->call(Client::METHOD_GET, '/migrations/' . $second['body']['$id'], array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertEquals('finished', $migration['body']['stage']);
            $this->assertEquals('failed', $migration['body']['status']);
            $this->assertNotEmpty($migration['body']['errors']);
        }, 60_000, 500);
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

        $bigint = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/bigint', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'bigint',
            'min' => 2147483648,
            'max' => 9223372036854775807,
            'required' => false,
        ]);

        $this->assertEquals(202, $bigint['headers']['status-code']);

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
                    'bigint' => 2147483648 + $i,
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
        $this->assertStringContainsString('bigint', $csvData, 'CSV should contain the bigint column header');
        $this->assertStringContainsString('2147483649', $csvData, 'CSV should contain bigint test data');

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
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destinationHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

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

        $this->assertMigrationSkipAndOverwrite(
            [Resource::TYPE_PROVIDER],
            function () use ($destinationHeaders, $providerId): void {
                $response = $this->client->call(Client::METHOD_PATCH, '/messaging/providers/sendgrid/' . $providerId, $destinationHeaders, [
                    'name' => 'Destination Sendgrid',
                    'apiKey' => 'destination-apikey',
                    'fromEmail' => 'destination-provider@test.com',
                    'enabled' => false,
                ]);
                $this->assertEquals(200, $response['headers']['status-code']);
            },
            function (array $migration) use ($destinationHeaders, $providerId): void {
                $this->assertGreaterThanOrEqual(1, $migration['statusCounters'][Resource::TYPE_PROVIDER]['skip']);

                $response = $this->client->call(Client::METHOD_GET, '/messaging/providers/' . $providerId, $destinationHeaders);
                $this->assertEquals(200, $response['headers']['status-code']);
                $this->assertSame('Destination Sendgrid', $response['body']['name']);
            },
            function () use ($sourceHeaders, $providerId): void {
                $response = $this->client->call(Client::METHOD_PATCH, '/messaging/providers/sendgrid/' . $providerId, $sourceHeaders, [
                    'name' => 'Source Sendgrid Overwrite',
                    'apiKey' => 'source-overwrite-apikey',
                    'fromEmail' => 'source-overwrite-provider@test.com',
                    'enabled' => false,
                ]);
                $this->assertEquals(200, $response['headers']['status-code']);
            },
            function (array $migration) use ($destinationHeaders, $providerId): void {
                $this->assertGreaterThanOrEqual(1, $migration['statusCounters'][Resource::TYPE_PROVIDER]['success']);

                $response = $this->client->call(Client::METHOD_GET, '/messaging/providers/' . $providerId, $destinationHeaders);
                $this->assertEquals(200, $response['headers']['status-code']);
                $this->assertSame('Source Sendgrid Overwrite', $response['body']['name']);
            },
        );

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
        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destinationHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

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

        $this->assertMigrationSkipAndOverwrite(
            [Resource::TYPE_PROVIDER, Resource::TYPE_TOPIC],
            function () use ($destinationHeaders, $topicId): void {
                $response = $this->client->call(Client::METHOD_PATCH, '/messaging/topics/' . $topicId, $destinationHeaders, [
                    'name' => 'Destination Topic',
                ]);
                $this->assertEquals(200, $response['headers']['status-code']);
            },
            function (array $migration) use ($destinationHeaders, $topicId): void {
                $this->assertGreaterThanOrEqual(1, $migration['statusCounters'][Resource::TYPE_TOPIC]['skip']);

                $response = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $topicId, $destinationHeaders);
                $this->assertEquals(200, $response['headers']['status-code']);
                $this->assertSame('Destination Topic', $response['body']['name']);
            },
            function () use ($sourceHeaders, $topicId): void {
                $response = $this->client->call(Client::METHOD_PATCH, '/messaging/topics/' . $topicId, $sourceHeaders, [
                    'name' => 'Source Topic Overwrite',
                ]);
                $this->assertEquals(200, $response['headers']['status-code']);
            },
            function (array $migration) use ($destinationHeaders, $topicId): void {
                $this->assertGreaterThanOrEqual(1, $migration['statusCounters'][Resource::TYPE_TOPIC]['success']);

                $response = $this->client->call(Client::METHOD_GET, '/messaging/topics/' . $topicId, $destinationHeaders);
                $this->assertEquals(200, $response['headers']['status-code']);
                $this->assertSame('Source Topic Overwrite', $response['body']['name']);
            },
        );

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

        $this->assertMigrationDuplicateModesComplete([
            Resource::TYPE_USER,
            Resource::TYPE_PROVIDER,
            Resource::TYPE_TOPIC,
            Resource::TYPE_SUBSCRIBER,
        ]);

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

        $sourceHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $destinationHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getDestinationProject()['$id'],
            'x-appwrite-key' => $this->getDestinationProject()['apiKey'],
        ];

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

        $this->assertMigrationSkipAndOverwrite(
            [
                Resource::TYPE_USER,
                Resource::TYPE_PROVIDER,
                Resource::TYPE_TOPIC,
                Resource::TYPE_SUBSCRIBER,
                Resource::TYPE_MESSAGE,
            ],
            function () use ($destinationHeaders, $messageId, $topicId): void {
                $response = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/email/' . $messageId, $destinationHeaders, [
                    'topics' => [$topicId],
                    'subject' => 'Destination Draft Email',
                    'content' => 'Destination draft content',
                    'draft' => true,
                ]);
                $this->assertEquals(200, $response['headers']['status-code']);
            },
            function (array $migration) use ($destinationHeaders, $messageId): void {
                $this->assertGreaterThanOrEqual(1, $migration['statusCounters'][Resource::TYPE_MESSAGE]['skip']);

                $response = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $messageId, $destinationHeaders);
                $this->assertEquals(200, $response['headers']['status-code']);
                $this->assertSame('Destination Draft Email', $response['body']['data']['subject']);
                $this->assertSame('Destination draft content', $response['body']['data']['content']);
            },
            function () use ($sourceHeaders, $messageId, $topicId): void {
                $response = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/email/' . $messageId, $sourceHeaders, [
                    'topics' => [$topicId],
                    'subject' => 'Source Draft Email Overwrite',
                    'content' => 'Source draft overwrite content',
                    'draft' => true,
                ]);
                $this->assertEquals(200, $response['headers']['status-code']);
            },
            function (array $migration) use ($destinationHeaders, $messageId): void {
                $this->assertGreaterThanOrEqual(1, $migration['statusCounters'][Resource::TYPE_MESSAGE]['success']);

                $response = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $messageId, $destinationHeaders);
                $this->assertEquals(200, $response['headers']['status-code']);
                $this->assertSame('Source Draft Email Overwrite', $response['body']['data']['subject']);
                $this->assertSame('Source draft overwrite content', $response['body']['data']['content']);
            },
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
        $lastEmail = $this->getLastEmail(probe: function ($email) {
            $this->assertEquals('Your JSON export is ready', $email['subject']);
        });
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
