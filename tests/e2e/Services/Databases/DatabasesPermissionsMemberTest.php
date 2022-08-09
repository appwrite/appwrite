<?php

namespace Tests\E2E\Services\Databases;

use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;

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
           [['read(any)']],
           [['read(users)']],
           [['read(user:random)']],
           [['read(user:lorem)', 'create(user:lorem)', 'update(user:lorem)', 'delete(user:lorem)',]],
           [['read(user:dolor)', 'create(user:dolor)', 'update(user:dolor)', 'delete(user:dolor)',]],
           [['read(user:dolor, user:lorem)', 'create(user:dolor)', 'update(user:dolor)', 'delete(user:dolor)',]],
           [['create(any)', 'update(any)', 'delete(any)']],
           [['read(any)', 'create(any)', 'update(any)', 'delete(any)']],
           [['read(users)', 'create(users)', 'update(users)', 'delete(users)']],
           [['read(any)', 'create(users)', 'update(users)', 'delete(users)']],
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
            'databaseId' => 'unique()',
            'name' => 'Test Database',
        ]);
        $this->assertEquals(201, $db['headers']['status-code']);

        $databaseId = $db['body']['$id'];

        $public = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', $this->getServerHeader(), [
            'collectionId' => 'unique()',
            'name' => 'Movies',
            'permissions' => [
                'read(any)',
                'create(any)',
                'update(any)',
                'delete(any)',
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
        $this->assertEquals(201, $response['headers']['status-code']);

        $private = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', $this->getServerHeader(), [
            'collectionId' => 'unique()',
            'name' => 'Private Movies',
            'permissions' => [
                'read(users)',
                'create(users)',
                'update(users)',
                'delete(users)',
            ],
            'documentSecurity' => true,
        ]);
        $this->assertEquals(201, $private['headers']['status-code']);

        $this->collections['private'] = $private['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $this->collections['private'] . '/attributes/string', $this->getServerHeader(), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

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
     * @depends testSetupDatabase
     */
    public function testReadDocuments($permissions, $data)
    {
        $users = $data['users'];
        $collections = $data['collections'];
        $databaseId = $data['databaseId'];

        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collections['public'] . '/documents', $this->getServerHeader(), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Lorem',
            ],
            'permissions' => $permissions
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collections['private'] . '/documents', $this->getServerHeader(), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Lorem',
            ],
            'permissions' => $permissions
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        /**
         * Check role:all collection
         */
        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collections['public']  . '/documents', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users['user1']['session'],
        ]);

        foreach ($documents['body']['documents'] as $document) {
            $hasPermissions = \array_reduce(['any', 'users', 'user:' . $users['user1']['$id']], function (bool $carry, string $role) use ($document) {
                if ($carry) {
                    return true;
                }
                foreach ($document['$permissions'] as $permission) {
                    if (\str_starts_with($permission, 'read') && \str_contains($permission, $role)) {
                        return true;
                    }
                }
                return false;
            }, false);

            $this->assertTrue($hasPermissions);
        }

        /**
         * Check role:member collection
         */
        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collections['private']  . '/documents', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users['user1']['session'],
        ]);

        foreach ($documents['body']['documents'] as $document) {
            $hasPermissions = \array_reduce(['any', 'users', 'user:' . $users['user1']['$id']], function (bool $carry, string $role) use ($document) {
                if ($carry) {
                    return true;
                }
                foreach ($document['$permissions'] as $permission) {
                    if (\str_starts_with($permission, 'read') && \str_contains($permission, $role)) {
                        return true;
                    }
                }
                return false;
            }, false);

            $this->assertTrue($hasPermissions);
        }
    }
}
