<?php

namespace Tests\E2E\Services\Databases\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ApiLegacy;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class LegacyPermissionsMemberTest extends Scope
{
    use DatabasesPermissionsBase;
    use ProjectCustom;
    use SideClient;
    use ApiLegacy;

    public array $collections = [];

    public function createUsers(): array
    {
        return [
            'user1' => $this->createUser('user1', 'lorem@ipsum.com'),
            'user2' => $this->createUser('user2', 'dolor@ipsum.com'),
        ];
    }

    public static function permissionsProvider(): array
    {
        return [
            [[Permission::read(Role::any())], 1, 1, 1],
            [[Permission::read(Role::users())], 2, 2, 2],
            [[Permission::read(Role::user(ID::custom('random')))], 3, 3, 2],
            [[Permission::read(Role::user(ID::custom('lorem'))), Permission::update(Role::user('lorem')), Permission::delete(Role::user('lorem'))], 4, 4, 2],
            [[Permission::read(Role::user(ID::custom('dolor'))), Permission::update(Role::user('dolor')), Permission::delete(Role::user('dolor'))], 5, 5, 2],
            [[Permission::read(Role::user(ID::custom('dolor'))), Permission::read(Role::user('lorem')), Permission::update(Role::user('dolor')), Permission::delete(Role::user('dolor'))], 6, 6, 2],
            [[Permission::update(Role::any()), Permission::delete(Role::any())], 7, 7, 2],
            [[Permission::read(Role::any()), Permission::update(Role::any()), Permission::delete(Role::any())], 8, 8, 3],
            [[Permission::read(Role::any()), Permission::update(Role::users()), Permission::delete(Role::users())], 9, 9, 4],
            [[Permission::read(Role::user(ID::custom('user1')))], 10, 10, 5],
            [[Permission::read(Role::user(ID::custom('user1'))), Permission::read(Role::user(ID::custom('user1')))], 11, 11, 6],
            [[Permission::read(Role::users()), Permission::update(Role::users()), Permission::delete(Role::users())], 12, 12, 7],
        ];
    }

    /**
     * Setup database helper with caching
     */
    protected function setupDatabase(): array
    {
        $cacheKey = $this->getProject()['$id'] . '_' . static::class;

        if (!empty(self::$setupDatabaseCache[$cacheKey])) {
            return self::$setupDatabaseCache[$cacheKey];
        }

        $this->createUsers();

        $db = $this->client->call(
            Client::METHOD_POST,
            $this->getDatabaseUrl(),
            $this->getServerHeader(),
            [
                'databaseId' => ID::unique(),
                'name' => 'Test Database',
            ]
        );
        $this->assertEquals(201, $db['headers']['status-code']);

        $databaseId = $db['body']['$id'];

        $public = $this->client->call(
            Client::METHOD_POST,
            $this->getContainerUrl($databaseId),
            $this->getServerHeader(),
            [
                $this->getContainerIdParam() => ID::unique(),
                'name' => 'Movies',
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                $this->getSecurityParam() => true,
            ]
        );
        $this->assertEquals(201, $public['headers']['status-code']);
        $this->collections = ['public' => $public['body']['$id']];

        $response = $this->client->call(
            Client::METHOD_POST,
            $this->getSchemaUrl($databaseId, $this->collections['public'], 'string'),
            $this->getServerHeader(),
            [
                'key' => 'title',
                'size' => 256,
                'required' => true,
            ]
        );
        $this->assertEquals(202, $response['headers']['status-code']);

        $private = $this->client->call(
            Client::METHOD_POST,
            $this->getContainerUrl($databaseId),
            $this->getServerHeader(),
            [
                $this->getContainerIdParam() => ID::unique(),
                'name' => 'Private Movies',
                'permissions' => [
                    Permission::read(Role::users()),
                    Permission::create(Role::users()),
                    Permission::update(Role::users()),
                    Permission::delete(Role::users()),
                ],
                $this->getSecurityParam() => true,
            ]
        );
        $this->assertEquals(201, $private['headers']['status-code']);
        $this->collections['private'] = $private['body']['$id'];

        $response = $this->client->call(
            Client::METHOD_POST,
            $this->getSchemaUrl($databaseId, $this->collections['private'], 'string'),
            $this->getServerHeader(),
            [
                'key' => 'title',
                'size' => 256,
                'required' => true,
            ]
        );
        $this->assertEquals(202, $response['headers']['status-code']);

        $doconly = $this->client->call(
            Client::METHOD_POST,
            $this->getContainerUrl($databaseId),
            $this->getServerHeader(),
            [
                $this->getContainerIdParam() => ID::unique(),
                'name' => 'Document Only Movies',
                'permissions' => [],
                $this->getSecurityParam() => true,
            ]
        );
        $this->assertEquals(201, $doconly['headers']['status-code']);
        $this->collections['doconly'] = $doconly['body']['$id'];

        $response = $this->client->call(
            Client::METHOD_POST,
            $this->getSchemaUrl($databaseId, $this->collections['doconly'], 'string'),
            $this->getServerHeader(),
            [
                'key' => 'title',
                'size' => 256,
                'required' => true,
            ]
        );
        $this->assertEquals(202, $response['headers']['status-code']);

        sleep(2);

        self::$setupDatabaseCache[$cacheKey] = [
            'users' => $this->users,
            'collections' => $this->collections,
            'databaseId' => $databaseId
        ];

        return self::$setupDatabaseCache[$cacheKey];
    }

    /**
     * Setup database test
     */
    public function testSetupDatabase(): void
    {
        $data = $this->setupDatabase();
        $this->assertNotEmpty($data['databaseId']);
    }

    #[DataProvider('permissionsProvider')]
    public function testReadDocuments($permissions, $anyCount, $usersCount, $docOnlyCount)
    {
        $data = $this->setupDatabase();
        $users = $data['users'];
        $collections = $data['collections'];
        $databaseId = $data['databaseId'];

        $response = $this->client->call(
            Client::METHOD_POST,
            $this->getRecordUrl($databaseId, $collections['public']),
            $this->getServerHeader(),
            [
                $this->getRecordIdParam() => ID::unique(),
                'data' => [
                    'title' => 'Lorem',
                ],
                'permissions' => $permissions
            ]
        );
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(
            Client::METHOD_POST,
            $this->getRecordUrl($databaseId, $collections['private']),
            $this->getServerHeader(),
            [
                $this->getRecordIdParam() => ID::unique(),
                'data' => [
                    'title' => 'Lorem',
                ],
                'permissions' => $permissions
            ]
        );
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(
            Client::METHOD_POST,
            $this->getRecordUrl($databaseId, $collections['doconly']),
            $this->getServerHeader(),
            [
                $this->getRecordIdParam() => ID::unique(),
                'data' => [
                    'title' => 'Lorem',
                ],
                'permissions' => $permissions
            ]
        );
        $this->assertEquals(201, $response['headers']['status-code']);

        /**
         * Check "any" permission collection
         */
        $documents = $this->client->call(
            Client::METHOD_GET,
            $this->getRecordUrl($databaseId, $collections['public']),
            [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users['user1']['session'],
            ]
        );

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals($anyCount, $documents['body']['total']);

        /**
         * Check "users" permission collection
         */
        $documents = $this->client->call(
            Client::METHOD_GET,
            $this->getRecordUrl($databaseId, $collections['private']),
            [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users['user1']['session'],
            ]
        );

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals($usersCount, $documents['body']['total']);

        /**
         * Check "user:user1" document only permission collection
         */
        $documents = $this->client->call(
            Client::METHOD_GET,
            $this->getRecordUrl($databaseId, $collections['doconly']),
            [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users['user1']['session'],
            ]
        );

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals($docOnlyCount, $documents['body']['total']);
    }
}
