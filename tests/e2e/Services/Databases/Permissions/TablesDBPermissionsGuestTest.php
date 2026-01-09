<?php

namespace Tests\E2E\Services\Databases\Permissions;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ApiTablesDB;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class TablesDBPermissionsGuestTest extends Scope
{
    use DatabasesPermissionsBase;
    use ProjectCustom;
    use SideClient;
    use ApiTablesDB;

    public function createCollection(): array
    {
        $database = $this->client->call(Client::METHOD_POST, $this->getDatabaseUrl(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'InvalidDocumentDatabase',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('InvalidDocumentDatabase', $database['body']['name']);

        $databaseId = $database['body']['$id'];
        $publicMovies = $this->client->call(
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
            ]
        );
        $privateMovies = $this->client->call(
            Client::METHOD_POST,
            $this->getContainerUrl($databaseId),
            $this->getServerHeader(),
            [
                $this->getContainerIdParam() => ID::unique(),
                'name' => 'Movies',
                'permissions' => [],
                $this->getSecurityParam() => true,
            ]
        );

        $publicCollection = ['id' => $publicMovies['body']['$id']];
        $privateCollection = ['id' => $privateMovies['body']['$id']];

        $this->client->call(
            Client::METHOD_POST,
            $this->getSchemaUrl($databaseId, $publicCollection['id'], 'string'),
            $this->getServerHeader(),
            [
                'key' => 'title',
                'size' => 256,
                'required' => true,
            ]
        );
        $this->client->call(
            Client::METHOD_POST,
            $this->getSchemaUrl($databaseId, $privateCollection['id'], 'string'),
            $this->getServerHeader(),
            [
                'key' => 'title',
                'size' => 256,
                'required' => true,
            ]
        );

        sleep(2);

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

        $publicResponse = $this->client->call(
            Client::METHOD_POST,
            $this->getRecordUrl($databaseId, $publicCollectionId),
            $this->getServerHeader(),
            [
                $this->getRecordIdParam() => ID::unique(),
                'data' => [
                    'title' => 'Lorem',
                ],
                'permissions' => $permissions,
            ]
        );
        $privateResponse = $this->client->call(
            Client::METHOD_POST,
            $this->getRecordUrl($databaseId, $privateCollectionId),
            $this->getServerHeader(),
            [
                $this->getRecordIdParam() => ID::unique(),
                'data' => [
                    'title' => 'Lorem',
                ],
                'permissions' => $permissions,
            ]
        );

        $this->assertEquals(201, $publicResponse['headers']['status-code']);
        $this->assertEquals(201, $privateResponse['headers']['status-code']);

        $publicDocuments = $this->client->call(
            Client::METHOD_GET,
            $this->getRecordUrl($databaseId, $publicCollectionId),
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ]
        );
        $privateDocuments = $this->client->call(
            Client::METHOD_GET,
            $this->getRecordUrl($databaseId, $privateCollectionId),
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ]
        );

        $recordKey = $this->getRecordResource();
        $this->assertEquals(1, $publicDocuments['body']['total']);
        $this->assertEquals($permissions, $publicDocuments['body'][$recordKey][0]['$permissions']);

        if (\in_array(Permission::read(Role::any()), $permissions)) {
            $this->assertEquals(1, $privateDocuments['body']['total']);
            $this->assertEquals($permissions, $privateDocuments['body'][$recordKey][0]['$permissions']);
        } else {
            $this->assertEquals(0, $privateDocuments['body']['total']);
        }
    }

    public function testWriteDocument()
    {
        $data = $this->createCollection();
        $publicCollectionId = $data['publicCollectionId'];
        $privateCollectionId = $data['privateCollectionId'];
        $databaseId = $data['databaseId'];

        $publicResponse = $this->client->call(
            Client::METHOD_POST,
            $this->getRecordUrl($databaseId, $publicCollectionId),
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ],
            [
                $this->getRecordIdParam() => ID::unique(),
                'data' => [
                    'title' => 'Lorem',
                ]
            ]
        );

        $publicDocumentId = $publicResponse['body']['$id'];
        $this->assertEquals(201, $publicResponse['headers']['status-code']);

        $privateResponse = $this->client->call(
            Client::METHOD_POST,
            $this->getRecordUrl($databaseId, $privateCollectionId),
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ],
            [
                $this->getRecordIdParam() => ID::unique(),
                'data' => [
                    'title' => 'Lorem',
                ],
            ]
        );

        $this->assertEquals(401, $privateResponse['headers']['status-code']);

        // Create a document in private collection with API key so we can test that update and delete are also not allowed
        $privateResponse = $this->client->call(
            Client::METHOD_POST,
            $this->getRecordUrl($databaseId, $privateCollectionId),
            $this->getServerHeader(),
            [
                $this->getRecordIdParam() => ID::unique(),
                'data' => [
                    'title' => 'Lorem',
                ],
            ]
        );

        $this->assertEquals(201, $privateResponse['headers']['status-code']);
        $privateDocumentId = $privateResponse['body']['$id'];

        $publicDocument = $this->client->call(
            Client::METHOD_PATCH,
            $this->getRecordUrl($databaseId, $publicCollectionId, $publicDocumentId),
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ],
            [
                'data' => [
                    'title' => 'Thor: Ragnarok',
                ],
            ]
        );

        $this->assertEquals(200, $publicDocument['headers']['status-code']);
        $this->assertEquals('Thor: Ragnarok', $publicDocument['body']['title']);

        $privateDocument = $this->client->call(
            Client::METHOD_PATCH,
            $this->getRecordUrl($databaseId, $privateCollectionId, $privateDocumentId),
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ],
            [
                'data' => [
                    'title' => 'Thor: Ragnarok',
                ],
            ]
        );

        $this->assertEquals(401, $privateDocument['headers']['status-code']);

        $publicDocument = $this->client->call(
            Client::METHOD_DELETE,
            $this->getRecordUrl($databaseId, $publicCollectionId, $publicDocumentId),
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ]
        );

        $this->assertEquals(204, $publicDocument['headers']['status-code']);

        $privateDocument = $this->client->call(
            Client::METHOD_DELETE,
            $this->getRecordUrl($databaseId, $privateCollectionId, $privateDocumentId),
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ]
        );

        $this->assertEquals(401, $privateDocument['headers']['status-code']);
    }

    public function testWriteDocumentWithPermissions()
    {
        $database = $this->client->call(
            Client::METHOD_POST,
            $this->getDatabaseUrl(),
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]),
            [
                'databaseId' => ID::unique(),
                'name' => 'GuestPermissionsWrite',
            ]
        );
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('GuestPermissionsWrite', $database['body']['name']);

        $databaseId = $database['body']['$id'];
        $movies = $this->client->call(
            Client::METHOD_POST,
            $this->getContainerUrl($databaseId),
            $this->getServerHeader(),
            [
                $this->getContainerIdParam() => ID::unique(),
                'name' => 'Movies',
                'permissions' => [
                    Permission::create(Role::any()),
                ],
                $this->getSecurityParam() => true
            ]
        );

        $moviesId = $movies['body']['$id'];

        $this->client->call(
            Client::METHOD_POST,
            $this->getSchemaUrl($databaseId, $moviesId, 'string'),
            $this->getServerHeader(),
            [
                'key' => 'title',
                'size' => 256,
                'required' => true,
            ]
        );

        sleep(1);

        $document = $this->client->call(
            Client::METHOD_POST,
            $this->getRecordUrl($databaseId, $moviesId),
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ],
            [
                $this->getRecordIdParam() => ID::unique(),
                'data' => [
                    'title' => 'Thor: Ragnarok',
                ],
                'permissions' => [
                    Permission::read(Role::any()),
                ]
            ]
        );

        $this->assertEquals(201, $document['headers']['status-code']);
        $this->assertEquals('Thor: Ragnarok', $document['body']['title']);
    }
}
