<?php

namespace Tests\E2E\Services\Databases\VectorDB\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
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

        $db = $this->client->call(Client::METHOD_POST, '/vectordb', $this->getServerHeader(), [
            'databaseId' => ID::unique(),
            'name' => 'Test Database',
        ]);
        $this->assertEquals(201, $db['headers']['status-code']);

        $databaseId = $db['body']['$id'];

        $public = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', $this->getServerHeader(), [
            'collectionId' => ID::unique(),
            'name' => 'Movies',
            'dimension' => 3,
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

        $private = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', $this->getServerHeader(), [
            'collectionId' => ID::unique(),
            'name' => 'Private Movies',
            'dimension' => 3,
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

        $doconly = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', $this->getServerHeader(), [
            'collectionId' => ID::unique(),
            'name' => 'Document Only Movies',
            'dimension' => 3,
            'permissions' => [],
            'documentSecurity' => true,
        ]);
        $this->assertEquals(201, $private['headers']['status-code']);
        $this->collections['doconly'] = $doconly['body']['$id'];

        return [
            'users' => $this->users,
            'collections' => $this->collections,
            'databaseId' => $databaseId
        ];
    }

    /**
     * Data provider params are passed before test dependencies.
     */
    #[DataProvider('permissionsProvider')]
    #[Depends('testSetupDatabase')]
    public function testReadDocuments($permissions, $anyCount, $usersCount, $docOnlyCount, $data)
    {
        $users = $data['users'];
        $collections = $data['collections'];
        $databaseId = $data['databaseId'];

        $response = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections/' . $collections['public'] . '/documents', $this->getServerHeader(), [
            'documentId' => ID::unique(),
            'data' => [
                'embeddings' => [1.0, 0.0, 0.0],
                'metadata' => ['title' => 'Lorem'],
            ],
            'permissions' => $permissions
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections/' . $collections['private'] . '/documents', $this->getServerHeader(), [
            'documentId' => ID::unique(),
            'data' => [
                'embeddings' => [0.0, 1.0, 0.0],
                'metadata' => ['title' => 'Lorem'],
            ],
            'permissions' => $permissions
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections/' . $collections['doconly'] . '/documents', $this->getServerHeader(), [
            'documentId' => ID::unique(),
            'data' => [
                'embeddings' => [0.0, 0.0, 1.0],
                'metadata' => ['title' => 'Lorem'],
            ],
            'permissions' => $permissions
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        /**
         * Check "any" permission collection
         */
        $documents = $this->client->call(Client::METHOD_GET, '/vectordb/' . $databaseId . '/collections/' . $collections['public'] . '/documents', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users['user1']['session'],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals($anyCount, $documents['body']['total']);

        /**
         * Check "users" permission collection
         */
        $documents = $this->client->call(Client::METHOD_GET, '/vectordb/' . $databaseId . '/collections/' . $collections['private'] . '/documents', [
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
        $documents = $this->client->call(Client::METHOD_GET, '/vectordb/' . $databaseId . '/collections/' . $collections['doconly'] . '/documents', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users['user1']['session'],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals($docOnlyCount, $documents['body']['total']);
    }
}
