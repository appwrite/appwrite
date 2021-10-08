<?php

namespace Tests\E2E\Services\Database;

use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;

class DatabasePermissionsGuestTest extends Scope
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

    public function createCollection(): array
    {
        $movies = $this->client->call(Client::METHOD_POST, '/database/collections', $this->getServerHeader(), [
            'collectionId' => 'unique()',
            'name' => 'Movies',
            'read' => ['role:all'],
            'write' => ['role:all'],
            'permission' => 'document',
        ]);

        $collection = ['id' => $movies['body']['$id']];

        $this->client->call(Client::METHOD_POST, '/database/collections/' . $collection['id'] . '/attributes/string', $this->getServerHeader(), [
            'attributeId' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        sleep(2);

        return $collection;
    }

    public function testReadDocuments()
    {
        $collection = $this->createCollection();

        foreach ($this->mockPermissions as $permissions) {
            $response = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collection['id'] . '/documents', $this->getServerHeader(), [
                'documentId' => 'unique()',
                'data' => [
                    'title' => 'Lorem',
                ],
                'read' => $permissions['read'],
                'write' => $permissions['write'],
            ]);
            $this->assertEquals(201, $response['headers']['status-code']);
        }

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collection['id']  . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        foreach ($documents['body']['documents'] as $document) {
            $this->assertContains('role:all', $document['$read']);
        }
    }
}
