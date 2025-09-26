<?php

namespace Tests\E2E\Services\Databases\DocumentsDB;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class DatabasesCustomClientTest extends Scope
{
    use DatabasesBase;
    use ProjectCustom;
    use SideClient;

    public function testAllowedPermissions(): void
    {
        /**
         * Test for SUCCESS
         */

        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        // Collection aliases write to create, update, delete
        $movies = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Movies',
            'documentSecurity' => true,
            'permissions' => [
                Permission::write(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $moviesId = $movies['body']['$id'];

        $this->assertContains(Permission::create(Role::user($this->getUser()['$id'])), $movies['body']['$permissions']);
        $this->assertContains(Permission::update(Role::user($this->getUser()['$id'])), $movies['body']['$permissions']);
        $this->assertContains(Permission::delete(Role::user($this->getUser()['$id'])), $movies['body']['$permissions']);

        // Document aliases write to update, delete
        $document1 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $moviesId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Captain America',
            ],
            'permissions' => [
                Permission::write(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertNotContains(Permission::create(Role::user($this->getUser()['$id'])), $document1['body']['$permissions']);
        $this->assertContains(Permission::update(Role::user($this->getUser()['$id'])), $document1['body']['$permissions']);
        $this->assertContains(Permission::delete(Role::user($this->getUser()['$id'])), $document1['body']['$permissions']);

        /**
         * Test for FAILURE
         */

        // Document does not allow create permission
        $document2 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $moviesId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Captain America',
            ],
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals(400, $document2['headers']['status-code']);
    }

    public function testUpdateWithoutPermission(): array
    {
        // If document has been created by server and client tried to update it without adjusting permissions, permission validation should be skipped

        // As a part of preparation, we get ID of currently logged-in user
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $response['headers']['status-code']);

        $userId = $response['body']['$id'];

        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::custom('permissionCheckDatabase'),
            'name' => 'Test Database',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('Test Database', $database['body']['name']);

        $databaseId = $database['body']['$id'];
        // Create collection
        $response = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::custom('permissionCheck'),
            'name' => 'permissionCheck',
            'permissions' => [],
            'documentSecurity' => true,
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        // Creating document by server, give read permission to our user + some other user
        $response = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/permissionCheck/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => ID::custom('permissionCheckDocument'),
            'data' => [
                'name' => 'AppwriteBeginner',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom('user2'))),
                Permission::read(Role::user($userId)),
                Permission::update(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Update document
        // This is the point of this test. We should be allowed to do this action, and it should not fail on permission check
        $response = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/permissionCheck/documents/permissionCheckDocument', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'name' => 'AppwriteExpert',
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Get name of the document, should be the new one
        $response = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/permissionCheck/documents/permissionCheckDocument', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("AppwriteExpert", $response['body']['name']);

        // Cleanup to prevent collision with other tests
        // Delete collection
        $response = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId . '/collections/permissionCheck', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $response['headers']['status-code']);


        // Wait for database worker to finish deleting collection
        sleep(2);

        // Make sure collection has been deleted
        $response = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/permissionCheck', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
        $this->assertEquals(404, $response['headers']['status-code']);

        return [];
    }
    // public function testCreateDocumentsDB(){
    //     // Create a database
    //     $database = $this->client->call(Client::METHOD_POST, '/documentsdb', [
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //         'x-appwrite-key' => $this->getProject()['apiKey']
    //     ], [
    //         'databaseId' => ID::unique(),
    //         'name' => 'Test Database'
    //     ]);

    //     $this->assertNotEmpty($database['body']['$id']);
    //     $this->assertEquals(201, $database['headers']['status-code']);
    //     $this->assertEquals('Test Database', $database['body']['name']);

    //     $dbId = $database['body']['$id'];

    //     // var_dump the database id
    //     var_dump('Database ID: ' . $dbId);

    //     // Get the database
    //     $getDatabase = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $dbId, [
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //         'x-appwrite-key' => $this->getProject()['apiKey']
    //     ]);
    //     $this->assertEquals(200, $getDatabase['headers']['status-code']);
    //     $this->assertEquals('Test Database', $getDatabase['body']['name']);

    //     // List databases
    //     $listDatabases = $this->client->call(Client::METHOD_GET, '/documentsdb', [
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //         'x-appwrite-key' => $this->getProject()['apiKey']
    //     ]);
    //     $this->assertEquals(200, $listDatabases['headers']['status-code']);
    //     $this->assertGreaterThan(0, $listDatabases['body']['total']);
    //     var_dump($listDatabases['body']['databases']);

    //     // Update the database
    //     $updateDatabase = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $dbId, [
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //         'x-appwrite-key' => $this->getProject()['apiKey']
    //     ], [
    //         'name' => 'Updated Test Database'
    //     ]);
    //     $this->assertEquals(200, $updateDatabase['headers']['status-code']);
    //     $this->assertEquals('Updated Test Database', $updateDatabase['body']['name']);

    //     // Create a collection
    //     $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $dbId . '/collections', [
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //         'x-appwrite-key' => $this->getProject()['apiKey']
    //     ], [
    //         'collectionId' => ID::unique(),
    //         'name' => 'Test Collection',
    //         'permissions' => [
    //             Permission::create(Role::user($this->getUser()['$id'])),
    //             Permission::read(Role::user($this->getUser()['$id'])),
    //             Permission::update(Role::user($this->getUser()['$id'])),
    //             Permission::delete(Role::user($this->getUser()['$id'])),
    //         ]
    //     ]);

    //     $collectionId = $collection['body']['$id'];
    //     $this->assertEquals(201, $collection['headers']['status-code']);

    //     // var_dump the collection id
    //     var_dump('Collection ID: ' . $collectionId);

    //     // Create collection1
    //     $collection1 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $dbId . '/collections', [
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //         'x-appwrite-key' => $this->getProject()['apiKey']
    //     ], [
    //         'collectionId' => ID::unique(),
    //         'name' => 'Collection 1',
    //         'permissions' => [
    //             Permission::create(Role::user($this->getUser()['$id'])),
    //             Permission::read(Role::user($this->getUser()['$id'])),
    //             Permission::update(Role::user($this->getUser()['$id'])),
    //             Permission::delete(Role::user($this->getUser()['$id'])),
    //         ]
    //     ]);

    //     $collection1Id = $collection1['body']['$id'];
    //     // var_dump the collection id
    //     var_dump('Collection1 ID: ' . $collection1Id);
    //     $this->assertEquals(201, $collection1['headers']['status-code']);

    //     // List collections
    //     $listDatabases = $this->client->call(Client::METHOD_GET, '/documentsdb/'.$dbId.'/collections', [
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //         'x-appwrite-key' => $this->getProject()['apiKey']
    //     ]);
    //     $this->assertEquals(200, $listDatabases['headers']['status-code']);
    //     $this->assertGreaterThan(0, $listDatabases['body']['total']);
    //     var_dump($listDatabases['body']['collections']);

    //     // Create documents for collection1
    //     var_dump("making req -> ".'/documentsdb/' . $dbId . '/collections/' . $collection1Id . '/documents');
    //     $document1 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $dbId . '/collections/' . $collection1Id . '/documents', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()), [
    //         'documentId' => ID::unique(),
    //         'data' => [
    //             'title' => 'First Document',
    //             'rating' => 5
    //         ]
    //     ]);
    //     $this->assertEquals(201, $document1['headers']['status-code']);

    //     $document2 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $dbId . '/collections/' . $collection1Id . '/documents', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()), [
    //         'documentId' => ID::unique(),
    //         'data' => [
    //             'title' => 'Second Document',
    //             'rating' => 8
    //         ]
    //     ]);
    //     $this->assertEquals(201, $document2['headers']['status-code']);

    //     // List documents from collection1
    //     $listDocuments1 = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $dbId . '/collections/' . $collection1Id . '/documents', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()));
    //     var_dump($listDocuments1['body']);
    //     $this->assertEquals(200, $listDocuments1['headers']['status-code']);
    //     $this->assertEquals(2, $listDocuments1['body']['total']);

    //     // Update document from collection1
    //     $updateDocument1 = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $dbId . '/collections/' . $collection1Id . '/documents/' . $document1['body']['$id'], array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()), [
    //         'data' => [
    //             'title' => 'Updated First Document',
    //             'rating' => 9
    //         ]
    //     ]);
    //     $this->assertEquals(200, $updateDocument1['headers']['status-code']);
    //     $this->assertEquals('Updated First Document', $updateDocument1['body']['title']);

    //     // Create collection2
    //     $collection2 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $dbId . '/collections', [
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //         'x-appwrite-key' => $this->getProject()['apiKey']
    //     ], [
    //         'collectionId' => ID::unique(),
    //         'name' => 'Collection 2',
    //         'permissions' => [
    //             Permission::create(Role::user($this->getUser()['$id'])),
    //             Permission::read(Role::user($this->getUser()['$id'])),
    //             Permission::update(Role::user($this->getUser()['$id'])),
    //             Permission::delete(Role::user($this->getUser()['$id'])),
    //         ]
    //     ]);

    //     $collection2Id = $collection2['body']['$id'];
    //     $this->assertEquals(201, $collection2['headers']['status-code']);

    //     // Wait for collection to be created
    //     sleep(1);

    //     // Directly create documents for collection2 (without explicit attributes)
    //     $document3 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $dbId . '/collections/' . $collection2Id . '/documents', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()), [
    //         'documentId' => ID::unique(),
    //         'data' => [
    //             'name' => 'Dynamic Document 1',
    //             'description' => 'This document was created without predefined attributes',
    //             'count' => 42
    //         ]
    //     ]);
    //     $this->assertEquals(201, $document3['headers']['status-code']);

    //     $document4 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $dbId . '/collections/' . $collection2Id . '/documents', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()), [
    //         'documentId' => ID::unique(),
    //         'data' => [
    //             'name' => 'Dynamic Document 2',
    //             'category' => 'Test',
    //             'active' => true
    //         ]
    //     ]);
    //     $this->assertEquals(201, $document4['headers']['status-code']);

    //     // List documents from collection2
    //     $listDocuments2 = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $dbId . '/collections/' . $collection2Id . '/documents', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()));
    //     $this->assertEquals(200, $listDocuments2['headers']['status-code']);
    //     $this->assertEquals(2, $listDocuments2['body']['total']);

    //     // Update document from collection2
    //     $updateDocument3 = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $dbId . '/collections/' . $collection2Id . '/documents/' . $document3['body']['$id'], array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()), [
    //         'data' => [
    //             'name' => 'Updated Dynamic Document 1',
    //             'description' => 'This document has been updated',
    //             'count' => 100,
    //             'updated' => true
    //         ]
    //     ]);
    //     $this->assertEquals(200, $updateDocument3['headers']['status-code']);
    //     $this->assertEquals('Updated Dynamic Document 1', $updateDocument3['body']['name']);

    //     // Delete the database (cleanup)
    //     $deleteDatabase = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $dbId, [
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //         'x-appwrite-key' => $this->getProject()['apiKey']
    //     ]);
    //     $this->assertEquals(204, $deleteDatabase['headers']['status-code']);
    // }
}
