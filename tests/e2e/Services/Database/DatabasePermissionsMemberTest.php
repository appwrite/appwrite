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

    public array $mockPermissions = [
        [
            'read' => ['role:all'],
            'write' => []
        ],
        [
            'read' => ['role:member'],
            'write' => []
        ],
        [
            'read' => ['user:random'],
            'write' => []
        ],
        [
            'read' => ['user:lorem'],
            'write' => ['user:lorem']
        ],
        [
            'read' => ['user:dolor'],
            'write' => ['user:dolor']
        ],
        [
            'read' => ['user:dolor', 'user:lorem'],
            'write' => ['user:dolor']
        ],
        [
            'read' => [],
            'write' => ['role:all']
        ],
        [
            'read' => ['role:all'],
            'write' => ['role:all']
        ],
        [
            'read' => ['role:member'],
            'write' => ['role:member']
        ],
        [
            'read' => ['role:all'],
            'write' => ['role:member']
        ]
    ];

    public function createCollections(): array
    {
        $movies = $this->client->call(Client::METHOD_POST, '/database/collections', $this->getServerHeader(), [
            'collectionId' => 'unique()',
            'name' => 'Movies',
            'read' => ['role:all'],
            'write' => ['role:all'],
            'permission' => 'document',
        ]);

        $collections = ['public' => $movies['body']['$id']];

        $this->client->call(Client::METHOD_POST, '/database/collections/' . $collections['public'] . '/attributes/string', $this->getServerHeader(), [
            'attributeId' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        $private = $this->client->call(Client::METHOD_POST, '/database/collections', $this->getServerHeader(), [
            'collectionId' => 'unique()',
            'name' => 'Private Movies',
            'read' => ['role:member'],
            'write' => ['role:member'],
            'permission' => 'document',
        ]);

        $collections['private'] = $private['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/database/collections/' . $collections['private'] . '/attributes/string', $this->getServerHeader(), [
            'attributeId' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        sleep(2);

        return $collections;
    }

    public function testReadDocuments()
    {
        $user1 = $this->createUser('lorem', 'lorem@ipsum.com');
        $user2 = $this->createUser('dolor', 'dolor@ipsum.com');

        $collections = $this->createCollections();

        foreach ($this->mockPermissions as $permissions) {
            $response = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collections['public'] . '/documents', $this->getServerHeader(), [
                'documentId' => 'unique()',
                'data' => [
                    'title' => 'Lorem',
                ],
                'read' => $permissions['read'],
                'write' => $permissions['write'],
            ]);
            $this->assertEquals(201, $response['headers']['status-code']);
        }

        foreach ($this->mockPermissions as $permissions) {
            $response = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collections['private'] . '/documents', $this->getServerHeader(), [
                'documentId' => 'unique()',
                'data' => [
                    'title' => 'Lorem',
                ],
                'read' => $permissions['read'],
                'write' => $permissions['write'],
            ]);
            $this->assertEquals(201, $response['headers']['status-code']);
        }

        /**
         * Check role:all collection
         */
        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collections['public']  . '/documents', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ]);

        foreach ($documents['body']['documents'] as $document) {
            $hasPermissions = \array_reduce(['role:all', 'role:member', 'user:' . $user1['$id']], function ($carry, $item) use ($document) {
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
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ]);

        foreach ($documents['body']['documents'] as $document) {
            $hasPermissions = \array_reduce(['role:all', 'role:member', 'user:' . $user1['$id']], function ($carry, $item) use ($document) {
                return $carry ? true : \in_array($item, $document['$read']);
            }, false);
            $this->assertTrue($hasPermissions);
        }

        
    }
}
