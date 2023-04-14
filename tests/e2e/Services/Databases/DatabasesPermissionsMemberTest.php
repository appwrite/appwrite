<?php

namespace Tests\E2E\Services\Databases;

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

    public array $collections = [];

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
                'any' => 1,
                'users' => 2,
                'doconly' => 2,
            ],
            [
                'permissions' => [Permission::read(Role::user(ID::custom('random')))],
                'any' => 1,
                'users' => 2,
                'doconly' => 2,
            ],
            [
                'permissions' => [Permission::read(Role::user(ID::custom('lorem'))), Permission::update(Role::user('lorem')), Permission::delete(Role::user('lorem'))],
                'any' => 1,
                'users' => 2,
                'doconly' => 2,
            ],
            [
                'permissions' => [Permission::read(Role::user(ID::custom('dolor'))), Permission::update(Role::user('dolor')), Permission::delete(Role::user('dolor'))],
                'any' => 1,
                'users' => 2,
                'doconly' => 2,
            ],
            [
                'permissions' => [Permission::read(Role::user(ID::custom('dolor'))), Permission::read(Role::user('lorem')), Permission::update(Role::user('dolor')), Permission::delete(Role::user('dolor'))],
                'any' => 1,
                'users' => 2,
                'doconly' => 2,
            ],
            [
                'permissions' => [Permission::update(Role::any()), Permission::delete(Role::any())],
                'any' => 1,
                'users' => 2,
                'doconly' => 2,
            ],
            [
                'permissions' => [Permission::read(Role::any()), Permission::update(Role::any()), Permission::delete(Role::any())],
                'any' => 2,
                'users' => 3,
                'doconly' => 3,
            ],
            [
                'permissions' => [Permission::read(Role::any()), Permission::update(Role::users()), Permission::delete(Role::users())],
                'any' => 3,
                'users' => 4,
                'doconly' => 4,
            ],
            [
                'permissions' => [Permission::read(Role::user(ID::custom('user1')))],
                'any' => 3,
                'users' => 5,
                'doconly' => 5,
            ],
            [
                'permissions' => [Permission::read(Role::user(ID::custom('user1'))), Permission::read(Role::user(ID::custom('user1')))],
                'any' => 3,
                'users' => 6,
                'doconly' => 6,
            ],
            [
                'permissions' => [Permission::read(Role::users()), Permission::update(Role::users()), Permission::delete(Role::users())],
                'any' => 3,
                'users' => 7,
                'doconly' => 7,
            ],
        ];
    }

    /**
     * Setup database
     *
     * Data providers lose object state so explicitly pass [$users, $collections] to each iteration
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

        $public = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', $this->getServerHeader(), [
            'collectionId' => ID::unique(),
            'name' => 'Movies',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'documentSecurity' => true,
        ]);
        $this->assertEquals(201, $public['headers']['status-code']);
        $this->collections = ['public' => $public['body']['$id']];

        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $this->collections['public'] . '/attributes/string', $this->getServerHeader(), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $response['headers']['status-code']);

        $private = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', $this->getServerHeader(), [
            'collectionId' => ID::unique(),
            'name' => 'Private Movies',
            'permissions' => [
                Permission::read(Role::users()),
                Permission::create(Role::users()),
                Permission::update(Role::users()),
                Permission::delete(Role::users()),
            ],
            'documentSecurity' => true,
        ]);
        $this->assertEquals(201, $private['headers']['status-code']);
        $this->collections['private'] = $private['body']['$id'];

        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $this->collections['private'] . '/attributes/string', $this->getServerHeader(), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $response['headers']['status-code']);

        $doconly = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', $this->getServerHeader(), [
            'collectionId' => ID::unique(),
            'name' => 'Document Only Movies',
            'permissions' => [],
            'documentSecurity' => true,
        ]);
        $this->assertEquals(201, $private['headers']['status-code']);
        $this->collections['doconly'] = $doconly['body']['$id'];

        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $this->collections['doconly'] . '/attributes/string', $this->getServerHeader(), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $response['headers']['status-code']);

        sleep(2);

        return [
            'users' => $this->users,
            'collections' => $this->collections,
            'databaseId' => $databaseId
        ];
    }

    /**
     * Data provider params are passed before test dependencies
     * @dataProvider permissionsProvider
     * @depends      testSetupDatabase
     */
    public function testReadDocuments($permissions, $anyCount, $usersCount, $docOnlyCount, $data)
    {
        $users = $data['users'];
        $collections = $data['collections'];
        $databaseId = $data['databaseId'];

        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collections['public'] . '/documents', $this->getServerHeader(), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Lorem',
            ],
            'permissions' => $permissions
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collections['private'] . '/documents', $this->getServerHeader(), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Lorem',
            ],
            'permissions' => $permissions
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collections['doconly'] . '/documents', $this->getServerHeader(), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Lorem',
            ],
            'permissions' => $permissions
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        /**
         * Check "any" permission collection
         */
        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collections['public'] . '/documents', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ]);
        var_dump($documents);
        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals($anyCount, $documents['body']['total']);

        /**
         * Check "users" permission collection
         */
        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collections['private'] . '/documents', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users['user1']['session'],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals($usersCount, $documents['body']['total']);

        /**
         * Check "user:user1" document only permission collection
         */
        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collections['doconly'] . '/documents', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users['user1']['session'],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals($docOnlyCount, $documents['body']['total']);
    }
}
