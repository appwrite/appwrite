<?php

namespace Tests\E2E\Services\Migrations;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Utopia\Database\Helpers\ID;
use Utopia\Migration\Resource;
use Utopia\Migration\Sources\Appwrite;

trait MigrationsBase
{
    use ProjectCustom;

    /**
     * @var array
     */
    protected static $sideProject = [];

    /**
     * @param bool $fresh
     * @return array
     */
    public function getSideProject(bool $fresh = false): array
    {
        if (!empty(self::$sideProject) && !$fresh) {
            return self::$sideProject;
        }

        $projectBackup = self::$project;

        self::$sideProject = $this->getProject(true);
        self::$project = $projectBackup;

        return self::$sideProject;
    }

    public function performMigrationSync(
        callable $migrationRequest,
    ): array {
        $migration = $migrationRequest();

        $this->assertEquals(202, $migration['headers']['status-code']);
        $this->assertNotEmpty($migration['body']);
        $this->assertNotEmpty($migration['body']['$id']);
        
        $attempts = 0;
        while ($attempts < 5) {
            $response = $this->client->call(Client::METHOD_GET, '/migrations/' . $migration['body']['$id'], [

                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']);
            $this->assertNotEmpty($response['body']['$id']);

            if ($response['body']['status'] === 'failed') {
                var_dump($response['body']);
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
        $response = $this->client->call(Client::METHOD_POST, '/migrations/appwrite', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'resources' => Appwrite::getSupportedResources(),
            'endpoint' => 'http://localhost/v1',
            'projectId' => $this->getSideProject()['$id'],
            'apiKey' => $this->getSideProject()['apiKey'],
        ]);

        $this->assertEquals(202, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Appwrite', $response['body']['source']);

        // Check empty migration completes without issues
        sleep(5);

        $attempts = 0;
        $maxAttempts = 10;
        while ($attempts < $maxAttempts) {
            $response = $this->client->call(Client::METHOD_GET, '/migrations/' . $response['body']['$id'], [

                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']);
            $this->assertNotEmpty($response['body']['$id']);
            $this->assertEquals('Appwrite', $response['body']['source']);

            $this->assertNotEquals('failed', $response['body']['status']);

            if ($response['body']['status'] === 'completed') {
                break;
            }

            if ($attempts === $maxAttempts - 1) {
                $this->assertEquals('completed', $response['body']['status']);
            }

            $attempts++;
            sleep(2);
        }
    }

    /**
     * Auth
     */
    public function testAppwriteMigrationAuthUserPassword()
    {
        $sideProject = $this->getSideProject();

        $response = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sideProject['$id'],
            'x-appwrite-key' => $sideProject['apiKey'],
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

        $result = $this->performMigrationSync(function () {
            return $this->client->call(Client::METHOD_POST, '/migrations/appwrite', [

                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], [
                'resources' => [
                    Resource::TYPE_USER,
                ],
                'endpoint' => 'http://localhost/v1',
                'projectId' => $this->getSideProject()['$id'],
                'apiKey' => $this->getSideProject()['apiKey'],
            ]);
        });

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
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($user['email'], $response['body']['email']);
        $this->assertEquals($user['password'], $response['body']['password']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/users/' . $user['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/users/' . $user['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sideProject['$id'],
            'x-appwrite-key' => $sideProject['apiKey'],
        ]);
    }

    public function testAppwriteMigrationAuthUserPhone()
    {
        $sideProject = $this->getSideProject();

        $response = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sideProject['$id'],
            'x-appwrite-key' => $sideProject['apiKey'],
        ], [
            'userId' => ID::unique(),
            'phone' => '+12065550100',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('+12065550100', $response['body']['phone']);

        $user = $response['body'];

        $result = $this->performMigrationSync(function () {
            return $this->client->call(Client::METHOD_POST, '/migrations/appwrite', [

                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], [
                'resources' => [
                    Resource::TYPE_USER,
                ],
                'endpoint' => 'http://localhost/v1',
                'projectId' => $this->getSideProject()['$id'],
                'apiKey' => $this->getSideProject()['apiKey'],
            ]);
        });

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
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
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
            'x-appwrite-project' => $sideProject['$id'],
            'x-appwrite-key' => $sideProject['apiKey'],
        ]);
    }

    public function testAppwriteMigrationAuthTeam()
    {
        $sideProject = $this->getSideProject();

        $user = $this->client->call(Client::METHOD_POST, '/users', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $sideProject['$id'],
            'x-appwrite-key' => $sideProject['apiKey'],
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
            'x-appwrite-project' => $sideProject['$id'],
            'x-appwrite-key' => $sideProject['apiKey'],
        ], [
            'teamId' => ID::unique(),
            'name' => 'Test Team',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertNotEmpty($team['body']);
        $this->assertNotEmpty($team['body']['$id']);

        $membership = $this->client->call(Client::METHOD_POST, '/teams/' . $team['body']['$id'] . '/memberships', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sideProject['$id'],
            'x-appwrite-key' => $sideProject['apiKey'],
        ], [
            'teamId' => $team['body']['$id'],
            'userId' => $user['body']['$id'],
            'roles' => ['owner'],
        ]);

        $this->assertEquals(201, $membership['headers']['status-code']);
        $this->assertNotEmpty($membership['body']);
        $this->assertNotEmpty($membership['body']['$id']);

        $result = $this->performMigrationSync(function () {
            return $this->client->call(Client::METHOD_POST, '/migrations/appwrite', [

                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], [
                'resources' => [
                    Resource::TYPE_USER,
                    Resource::TYPE_TEAM,
                    Resource::TYPE_MEMBERSHIP,
                ],
                'endpoint' => 'http://localhost/v1',
                'projectId' => $this->getSideProject()['$id'],
                'apiKey' => $this->getSideProject()['apiKey'],
            ]);
        });

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
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($team['body']['name'], $response['body']['name']);

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $team['body']['$id'] . '/memberships', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
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
            'x-appwrite-project' => $sideProject['$id'],
            'x-appwrite-key' => $sideProject['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/users/' . $user['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/users/' . $user['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sideProject['$id'],
            'x-appwrite-key' => $sideProject['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/teams/' . $team['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->client->call(Client::METHOD_DELETE, '/teams/' . $team['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $sideProject['$id'],
            'x-appwrite-key' => $sideProject['apiKey'],
        ]);
    }

    /**
     * Databases
     */

     /**
      * Storage
      */

    /**
     * Functions
     */
}