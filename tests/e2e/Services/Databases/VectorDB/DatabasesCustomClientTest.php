<?php

namespace Tests\E2E\Services\Databases\VectorDB;

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

        $database = $this->client->call(Client::METHOD_POST, '/vectordb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        // Collection aliases write to create, update, delete
        $movies = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Movies',
            'dimension' => 3,
            'documentSecurity' => true,
            'permissions' => [
                Permission::write(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $moviesId = $movies['body']['$id'];

        $this->assertContains(Permission::create(Role::user($this->getUser()['$id'])), $movies['body']['$permissions']);
        $this->assertContains(Permission::update(Role::user($this->getUser()['$id'])), $movies['body']['$permissions']);
        $this->assertContains(Permission::delete(Role::user($this->getUser()['$id'])), $movies['body']['$permissions']);

        // VectorDB uses fixed schema (embeddings, metadata). No attribute creation needed.

        // Document aliases write to update, delete
        $document1 = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections/' . $moviesId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'embeddings' => [1.0, 0.0, 0.0],
                'metadata' => ['k' => 'v'],
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
        $document2 = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections/' . $moviesId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'embeddings' => [0.0, 1.0, 0.0],
                'metadata' => ['k' => 'v'],
            ],
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals(400, $document2['headers']['status-code']);
    }

    public function testUpdateWithoutPermission(): array
    {
        // As a part of preparation, we get ID of currently logged-in user
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $response['headers']['status-code']);

        $userId = $response['body']['$id'];

        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
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
        $response = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::custom('permissionCheck'),
            'name' => 'permissionCheck',
            'dimension' => 3,
            'permissions' => [],
            'documentSecurity' => true,
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        // Creating document by server, give read permission to our user + some other user
        $response = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections/permissionCheck/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => ID::custom('permissionCheckDocument'),
            'data' => [
                'embeddings' => [1.0, 0.0, 0.0],
                'metadata' => ['name' => 'AppwriteBeginner'],
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
        $response = $this->client->call(Client::METHOD_PATCH, '/vectordb/' . $databaseId . '/collections/permissionCheck/documents/permissionCheckDocument', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'data' => [
                'embeddings' => [0.0, 1.0, 0.0],
                'metadata' => ['name' => 'AppwriteExpert'],
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Get name of the document, should be the new one
        $response = $this->client->call(Client::METHOD_GET, '/vectordb/' . $databaseId . '/collections/permissionCheck/documents/permissionCheckDocument', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("AppwriteExpert", $response['body']['metadata']['name']);

        // Cleanup to prevent collision with other tests
        // Delete collection
        $response = $this->client->call(Client::METHOD_DELETE, '/vectordb/' . $databaseId . '/collections/permissionCheck', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $response['headers']['status-code']);


        // Wait for database worker to finish deleting collection
        sleep(2);

        // Make sure collection has been deleted
        $response = $this->client->call(Client::METHOD_GET, '/vectordb/' . $databaseId . '/collections/permissionCheck', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
        $this->assertEquals(404, $response['headers']['status-code']);

        return [];
    }
}
