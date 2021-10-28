<?php

namespace Tests\E2E\Services\Database;

use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;

class DatabasePermissionsMemberTest extends Scope
{
    use ProjectCustom;
    use SideClient;
    use DatabasePermissionsScope;

    public array $collections = [];

    public function createUsers(): array
    {
        return [
            'user1' => $this->createUser('user1', 'lorem@ipsum.com'),
            'user2' => $this->createUser('user2', 'dolor@ipsum.com'),
        ];
    }

    /**
     * [string[] $read, string[] $write]
     */
    public function readDocumentsProvider()
    {
        return [
            [['role:all'], []],
            [['role:member'], []],
            [['user:random'], []],
            [['user:lorem'] ,['user:lorem']],
            [['user:dolor'] ,['user:dolor']],
            [['user:dolor', 'user:lorem'] ,['user:dolor']],
            [[], ['role:all']],
            [['role:all'], ['role:all']],
            [['role:member'], ['role:member']],
            [['role:all'], ['role:member']],
        ];
    }

    /**
     * Setup database
     *
     * Data providers lose object state
     * so explicitly pass [$users, $collections] to each iteration
     * @return array
     */
    public function testSetupDatabase(): array
    {
        $this->createUsers();

        $public = $this->client->call(Client::METHOD_POST, '/database/collections', $this->getServerHeader(), [
            'collectionId' => 'unique()',
            'name' => 'Movies',
            'read' => ['role:all'],
            'write' => ['role:all'],
            'permission' => 'document',
        ]);
        $this->assertEquals(201, $public['headers']['status-code']);

        $this->collections = ['public' => $public['body']['$id']];

        $response = $this->client->call(Client::METHOD_POST, '/database/collections/' . $this->collections['public'] . '/attributes/string', $this->getServerHeader(), [
            'attributeId' => 'title',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        $private = $this->client->call(Client::METHOD_POST, '/database/collections', $this->getServerHeader(), [
            'collectionId' => 'unique()',
            'name' => 'Private Movies',
            'read' => ['role:member'],
            'write' => ['role:member'],
            'permission' => 'document',
        ]);
        $this->assertEquals(201, $private['headers']['status-code']);

        $this->collections['private'] = $private['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/database/collections/' . $this->collections['private'] . '/attributes/string', $this->getServerHeader(), [
            'attributeId' => 'title',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        sleep(2);

        return [
            'users' => $this->users,
            'collections' => $this->collections
        ];
    }

    /**
     * Data provider params are passed before test dependencies
     * @dataProvider readDocumentsProvider
     * @depends testSetupDatabase
     */
    public function testReadDocuments($read, $write, $data)
    {
        $users = $data['users'];
        $collections = $data['collections'];

        $response = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collections['public'] . '/documents', $this->getServerHeader(), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Lorem',
            ],
            'read' => $read,
            'write' => $write,
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collections['private'] . '/documents', $this->getServerHeader(), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Lorem',
            ],
            'read' => $read,
            'write' => $write,
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        /**
         * Check role:all collection
         */
        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collections['public']  . '/documents', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users['user1']['session'],
        ]);

        foreach ($documents['body']['documents'] as $document) {
            $hasPermissions = \array_reduce(['role:all', 'role:member', 'user:' . $users['user1']['$id']], function ($carry, $item) use ($document) {
                return $carry ? true : \in_array($item, $document['$read']);
            }, false);
            $this->assertTrue($hasPermissions);
        }

        /**
         * Check role:member collection
         */
        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collections['private']  . '/documents', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users['user1']['session'],
        ]);

        foreach ($documents['body']['documents'] as $document) {
            $hasPermissions = \array_reduce(['role:all', 'role:member', 'user:' . $users['user1']['$id']], function ($carry, $item) use ($document) {
                return $carry ? true : \in_array($item, $document['$read']);
            }, false);
            $this->assertTrue($hasPermissions);
        }

    }
}
