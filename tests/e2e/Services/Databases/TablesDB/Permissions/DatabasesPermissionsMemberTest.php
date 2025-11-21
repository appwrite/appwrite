<?php

namespace Tests\E2E\Services\Databases\TablesDB\Permissions;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class DatabasesPermissionsMemberTest extends Scope
{
    use ProjectCustom;
    use SideClient;
    use DatabasesPermissionsScope;

    public array $tables = [];

    public function createUsers(): array
    {
        return [
            'user1' => $this->createUser('user1', 'lorem@ipsum.com'),
            'user2' => $this->createUser('user2', 'dolor@ipsum.com'),
        ];
    }

    public function permissionsProvider(): array
    {
        return [
            [
                'permissions' => [Permission::read(Role::any())],
                'any' => 1,
                'users' => 1,
                'doconly' => 1,
            ],
            [
                'permissions' => [Permission::read(Role::users())],
                'any' => 2,
                'users' => 2,
                'doconly' => 2,
            ],
            [
                'permissions' => [Permission::read(Role::user(ID::custom('random')))],
                'any' => 3,
                'users' => 3,
                'doconly' => 2,
            ],
            [
                'permissions' => [Permission::read(Role::user(ID::custom('lorem'))), Permission::update(Role::user('lorem')), Permission::delete(Role::user('lorem'))],
                'any' => 4,
                'users' => 4,
                'doconly' => 2,
            ],
            [
                'permissions' => [Permission::read(Role::user(ID::custom('dolor'))), Permission::update(Role::user('dolor')), Permission::delete(Role::user('dolor'))],
                'any' => 5,
                'users' => 5,
                'doconly' => 2,
            ],
            [
                'permissions' => [Permission::read(Role::user(ID::custom('dolor'))), Permission::read(Role::user('lorem')), Permission::update(Role::user('dolor')), Permission::delete(Role::user('dolor'))],
                'any' => 6,
                'users' => 6,
                'doconly' => 2,
            ],
            [
                'permissions' => [Permission::update(Role::any()), Permission::delete(Role::any())],
                'any' => 7,
                'users' => 7,
                'doconly' => 2,
            ],
            [
                'permissions' => [Permission::read(Role::any()), Permission::update(Role::any()), Permission::delete(Role::any())],
                'any' => 8,
                'users' => 8,
                'doconly' => 3,
            ],
            [
                'permissions' => [Permission::read(Role::any()), Permission::update(Role::users()), Permission::delete(Role::users())],
                'any' => 9,
                'users' => 9,
                'doconly' => 4,
            ],
            [
                'permissions' => [Permission::read(Role::user(ID::custom('user1')))],
                'any' => 10,
                'users' => 10,
                'doconly' => 5,
            ],
            [
                'permissions' => [Permission::read(Role::user(ID::custom('user1'))), Permission::read(Role::user(ID::custom('user1')))],
                'any' => 11,
                'users' => 11,
                'doconly' => 6,
            ],
            [
                'permissions' => [Permission::read(Role::users()), Permission::update(Role::users()), Permission::delete(Role::users())],
                'any' => 12,
                'users' => 12,
                'doconly' => 7,
            ],
        ];
    }

    /**
     * Setup database
     *
     * Data providers lose object state so explicitly pass [$users, $tables] to each iteration
     *
     * @return array
     * @throws \Exception
     */
    public function testSetupDatabase(): array
    {
        $this->createUsers();

        $db = $this->client->call(Client::METHOD_POST, '/databases', $this->getServerHeader(), [
            'databaseId' => ID::unique(),
            'name' => 'Test Database',
        ]);
        $this->assertEquals(201, $db['headers']['status-code']);

        $databaseId = $db['body']['$id'];

        $public = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', $this->getServerHeader(), [
            'tableId' => ID::unique(),
            'name' => 'Movies',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rowSecurity' => true,
        ]);
        $this->assertEquals(201, $public['headers']['status-code']);
        $this->tables = ['public' => $public['body']['$id']];

        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $this->tables['public'] . '/columns/string', $this->getServerHeader(), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $response['headers']['status-code']);

        $private = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', $this->getServerHeader(), [
            'tableId' => ID::unique(),
            'name' => 'Private Movies',
            'permissions' => [
                Permission::read(Role::users()),
                Permission::create(Role::users()),
                Permission::update(Role::users()),
                Permission::delete(Role::users()),
            ],
            'rowSecurity' => true,
        ]);
        $this->assertEquals(201, $private['headers']['status-code']);
        $this->tables['private'] = $private['body']['$id'];

        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $this->tables['private'] . '/columns/string', $this->getServerHeader(), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $response['headers']['status-code']);

        $doconly = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', $this->getServerHeader(), [
            'tableId' => ID::unique(),
            'name' => 'Row Only Movies',
            'permissions' => [],
            'rowSecurity' => true,
        ]);
        $this->assertEquals(201, $private['headers']['status-code']);
        $this->tables['doconly'] = $doconly['body']['$id'];

        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $this->tables['doconly'] . '/columns/string', $this->getServerHeader(), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $response['headers']['status-code']);

        sleep(2);

        return [
            'users' => $this->users,
            'tables' => $this->tables,
            'databaseId' => $databaseId
        ];
    }

    /**
     * Data provider params are passed before test dependencies
     * @dataProvider permissionsProvider
     * @depends      testSetupDatabase
     */
    public function testReadRows($permissions, $anyCount, $usersCount, $docOnlyCount, $data)
    {
        $users = $data['users'];
        $tables = $data['tables'];
        $databaseId = $data['databaseId'];

        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tables['public'] . '/rows', $this->getServerHeader(), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Lorem',
            ],
            'permissions' => $permissions
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tables['private'] . '/rows', $this->getServerHeader(), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Lorem',
            ],
            'permissions' => $permissions
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tables['doconly'] . '/rows', $this->getServerHeader(), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Lorem',
            ],
            'permissions' => $permissions
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        /**
         * Check "any" permission table
         */
        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tables['public'] . '/rows', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users['user1']['session'],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals($anyCount, $rows['body']['total']);

        /**
         * Check "users" permission table
         */
        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tables['private'] . '/rows', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users['user1']['session'],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals($usersCount, $rows['body']['total']);

        /**
         * Check "user:user1" row only permission table
         */
        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tables['doconly'] . '/rows', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users['user1']['session'],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals($docOnlyCount, $rows['body']['total']);
    }
}
