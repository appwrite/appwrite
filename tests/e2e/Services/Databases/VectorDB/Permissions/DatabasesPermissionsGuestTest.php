<?php

namespace Tests\E2E\Services\Databases\VectorDB\Permissions;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;

class DatabasesPermissionsGuestTest extends Scope
{
    use ProjectCustom;
    use SideClient;
    use DatabasesPermissionsScope;

    private $authorization;

    public function getAuthorization(): Authorization
    {
        if (isset($this->authorization)) {
            return $this->authorization;
        }

        $this->authorization = new Authorization();

        return $this->authorization;
    }

    public function createCollection(): array
    {
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'VectorGuestDB',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('VectorGuestDB', $database['body']['name']);

        $databaseId = $database['body']['$id'];
        $publicMovies = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', $this->getServerHeader(), [
            'collectionId' => ID::unique(),
            'name' => 'Movies',
            'dimension' => 3,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $privateMovies = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', $this->getServerHeader(), [
            'collectionId' => ID::unique(),
            'name' => 'Movies',
            'dimension' => 3,
            'permissions' => [],
            'documentSecurity' => true,
        ]);

        $publicCollection = ['id' => $publicMovies['body']['$id']];
        $privateCollection = ['id' => $privateMovies['body']['$id']];

        return [
            'databaseId' => $databaseId,
            'publicCollectionId' => $publicCollection['id'],
            'privateCollectionId' => $privateCollection['id'],
        ];
    }

    public function permissionsProvider(): array
    {
        return [
            [[Permission::read(Role::any())]],
            [[Permission::read(Role::users())]],
            [[Permission::update(Role::any()), Permission::delete(Role::any())]],
            [[Permission::read(Role::any()), Permission::update(Role::any()), Permission::delete(Role::any())]],
            [[Permission::read(Role::users()), Permission::update(Role::users()), Permission::delete(Role::users())]],
            [[Permission::read(Role::any()), Permission::update(Role::users()), Permission::delete(Role::users())]],
        ];
    }

    /**
     * @dataProvider permissionsProvider
     */
    public function testReadDocuments($permissions)
    {
        $data = $this->createCollection();
        $publicCollectionId = $data['publicCollectionId'];
        $privateCollectionId = $data['privateCollectionId'];
        $databaseId = $data['databaseId'];

        $publicResponse = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections/' . $publicCollectionId . '/documents', $this->getServerHeader(), [
            'documentId' => ID::unique(),
            'data' => [
                'embeddings' => [1.0, 0.0, 0.0],
                'metadata' => ['title' => 'Lorem'],
            ],
            'permissions' => $permissions,
        ]);
        $privateResponse = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections/' . $privateCollectionId . '/documents', $this->getServerHeader(), [
            'documentId' => ID::unique(),
            'data' => [
                'embeddings' => [0.0, 1.0, 0.0],
                'metadata' => ['title' => 'Lorem'],
            ],
            'permissions' => $permissions,
        ]);

        $this->assertEquals(201, $publicResponse['headers']['status-code']);
        $this->assertEquals(201, $privateResponse['headers']['status-code']);

        $roles = $this->getAuthorization()->getRoles();
        $this->getAuthorization()->cleanRoles();

        $publicDocuments = $this->client->call(Client::METHOD_GET, '/vectordb/' . $databaseId . '/collections/' . $publicCollectionId  . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);
        $privateDocuments = $this->client->call(Client::METHOD_GET, '/vectordb/' . $databaseId . '/collections/' . $privateCollectionId  . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(1, $publicDocuments['body']['total']);
        $this->assertEquals($permissions, $publicDocuments['body']['documents'][0]['$permissions']);

        if (\in_array(Permission::read(Role::any()), $permissions)) {
            $this->assertEquals(1, $privateDocuments['body']['total']);
            $this->assertEquals($permissions, $privateDocuments['body']['documents'][0]['$permissions']);
        } else {
            $this->assertEquals(0, $privateDocuments['body']['total']);
        }

        foreach ($roles as $role) {
            $this->getAuthorization()->addRole($role);
        }
    }

    public function testWriteDocument()
    {
        $data = $this->createCollection();
        $publicCollectionId = $data['publicCollectionId'];
        $privateCollectionId = $data['privateCollectionId'];
        $databaseId = $data['databaseId'];

        $roles = $this->getAuthorization()->getRoles();
        $this->getAuthorization()->cleanRoles();

        $publicResponse = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections/' . $publicCollectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'embeddings' => [1.0, 0.0, 0.0],
                'metadata' => ['title' => 'Lorem'],
            ]
        ]);

        $publicDocumentId = $publicResponse['body']['$id'];
        $this->assertEquals(201, $publicResponse['headers']['status-code']);

        $privateResponse = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections/' . $privateCollectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'embeddings' => [0.0, 1.0, 0.0],
                'metadata' => ['title' => 'Lorem'],
            ],
        ]);

        $this->assertEquals(401, $privateResponse['headers']['status-code']);

        // Create a document in private collection with API key so we can test that update and delete are also not allowed
        $privateResponse = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections/' . $privateCollectionId . '/documents', $this->getServerHeader(), [
            'documentId' => ID::unique(),
            'data' => [
                'embeddings' => [0.0, 0.0, 1.0],
                'metadata' => ['title' => 'Lorem'],
            ],
        ]);

        $this->assertEquals(201, $privateResponse['headers']['status-code']);
        $privateDocumentId = $privateResponse['body']['$id'];

        $publicDocument = $this->client->call(Client::METHOD_PUT, '/vectordb/' . $databaseId . '/collections/' . $publicCollectionId . '/documents/' . $publicDocumentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'data' => [
                'embeddings' => [0.5, 0.5, 0.0],
                'metadata' => ['title' => 'Thor: Ragnarok'],
            ],
        ]);

        $this->assertEquals(200, $publicDocument['headers']['status-code']);
        $this->assertEquals('Thor: Ragnarok', $publicDocument['body']['metadata']['title']);

        $privateDocument = $this->client->call(Client::METHOD_PUT, '/vectordb/' . $databaseId . '/collections/' . $privateCollectionId . '/documents/' . $privateDocumentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'data' => [
                'embeddings' => [0.2, 0.3, 0.5],
                'metadata' => ['title' => 'Thor: Ragnarok'],
            ],
        ]);

        $this->assertEquals(401, $privateDocument['headers']['status-code']);

        $publicDocument = $this->client->call(Client::METHOD_DELETE, '/vectordb/' . $databaseId . '/collections/' . $publicCollectionId . '/documents/' . $publicDocumentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(204, $publicDocument['headers']['status-code']);

        $privateDocument = $this->client->call(Client::METHOD_DELETE, '/vectordb/' . $databaseId . '/collections/' . $privateCollectionId . '/documents/' . $privateDocumentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(401, $privateDocument['headers']['status-code']);

        foreach ($roles as $role) {
            $this->getAuthorization()->addRole($role);
        }
    }

    public function testWriteDocumentWithPermissions()
    {
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'VectorGuestPermsWrite',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('VectorGuestPermsWrite', $database['body']['name']);

        $databaseId = $database['body']['$id'];
        $movies = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', $this->getServerHeader(), [
            'collectionId' => ID::unique(),
            'name' => 'Movies',
            'dimension' => 3,
            'permissions' => [
                Permission::create(Role::any()),
            ],
            'documentSecurity' => true
        ]);

        $moviesId = $movies['body']['$id'];

        $document = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections/' . $moviesId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'embeddings' => [1.0, 0.0, 0.0],
                'metadata' => ['title' => 'Thor: Ragnarok'],
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ]
        ]);

        $this->assertEquals(201, $document['headers']['status-code']);
        $this->assertEquals('Thor: Ragnarok', $document['body']['metadata']['title']);
    }
}
