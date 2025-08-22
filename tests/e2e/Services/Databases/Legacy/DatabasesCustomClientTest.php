<?php

namespace Tests\E2E\Services\Databases\Legacy;

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

        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        // Collection aliases write to create, update, delete
        $movies = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
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

        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $moviesId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        sleep(1);

        $this->assertEquals(202, $response['headers']['status-code']);

        // Document aliases write to update, delete
        $document1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $moviesId . '/documents', array_merge([
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
        $document2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $moviesId . '/documents', array_merge([
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

        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
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
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
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

        // Add attribute to collection
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/permissionCheck/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 255,
            'required' => true,
        ]);
        $this->assertEquals(202, $response['headers']['status-code']);

        // Wait for database worker to finish creating attributes
        sleep(2);

        // Creating document by server, give read permission to our user + some other user
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/permissionCheck/documents', array_merge([
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
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/permissionCheck/documents/permissionCheckDocument', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'name' => 'AppwriteExpert',
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Get name of the document, should be the new one
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/permissionCheck/documents/permissionCheckDocument', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("AppwriteExpert", $response['body']['name']);

        // Cleanup to prevent collision with other tests
        // Delete collection
        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/permissionCheck', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $response['headers']['status-code']);


        // Wait for database worker to finish deleting collection
        sleep(2);

        // Make sure collection has been deleted
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/permissionCheck', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
        $this->assertEquals(404, $response['headers']['status-code']);

        return [];
    }

    public function testUpdateTwoWayRelationship(): void
    {

        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Test Database'
        ]);

        $databaseId = $database['body']['$id'];


        // Creating collection 1
        $collection1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'level1',
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        // Creating collection 2
        $collection2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'level2',
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        \sleep(2);

        // Creating two way relationship between collection 1 and collection 2 from collection 1
        $relation = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1['body']['$id'] . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $collection2['body']['$id'],
            'type' => 'oneToMany',
            'twoWay' => true,
            'onDelete' => 'cascade',
            'key' => $collection2['body']['$id'],
            'twoWayKey' => $collection1['body']['$id']
        ]);

        \sleep(3);

        // Update relation from collection 2 to on delete restrict
        $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collection2['body']['$id'] . '/attributes/' . $collection1['body']['$id'] . '/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'onDelete' => 'restrict',
        ]);

        // Fetching attributes after updating relation to compare
        $collection1Attributes =  $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection1['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $collection1RelationAttribute = $collection1Attributes['body']['attributes'][0];

        $this->assertEquals($relation['body']['side'], $collection1RelationAttribute['side']);
        $this->assertEquals($relation['body']['twoWayKey'], $collection1RelationAttribute['twoWayKey']);
        $this->assertEquals($relation['body']['relatedCollection'], $collection1RelationAttribute['relatedCollection']);
        $this->assertEquals('restrict', $collection1RelationAttribute['onDelete']);
    }

    public function testRelationshipSameTwoWayKey(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Same two way key'
        ]);

        $databaseId = $database['body']['$id'];

        $collection1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'c1',
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $collection2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'c2',
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        \sleep(2);

        $relation = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1['body']['$id'] . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $collection2['body']['$id'],
            'type' => Database::RELATION_ONE_TO_ONE,
            'twoWay' => false,
            'onDelete' => 'cascade',
            'key' => 'attr1',
            'twoWayKey' => 'same_key'
        ]);

        \sleep(2);

        $this->assertEquals(202, $relation['headers']['status-code']);
        $this->assertEquals('same_key', $relation['body']['twoWayKey']);

        $relation = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1['body']['$id'] . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $collection2['body']['$id'],
            'type' => Database::RELATION_ONE_TO_MANY,
            'twoWay' => false,
            'onDelete' => 'cascade',
            'key' => 'attr2',
            'twoWayKey' => 'same_key'
        ]);

        \sleep(2);

        $this->assertEquals(409, $relation['body']['code']);
        $this->assertEquals('Attribute with the requested key already exists. Attribute keys must be unique, try again with a different key.', $relation['body']['message']);

        // twoWayKey is null TwoWayKey is default
        $relation = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1['body']['$id'] . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $collection2['body']['$id'],
            'type' => Database::RELATION_ONE_TO_MANY,
            'twoWay' => false,
            'onDelete' => 'cascade',
            'key' => 'attr3',
        ]);

        \sleep(2);

        $this->assertEquals(202, $relation['headers']['status-code']);
        $this->assertArrayHasKey('twoWayKey', $relation['body']);

        // twoWayKey is null,   TwoWayKey is default, second POST
        $relation = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1['body']['$id'] . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $collection2['body']['$id'],
            'type' => Database::RELATION_ONE_TO_MANY,
            'twoWay' => false,
            'onDelete' => 'cascade',
            'key' => 'attr4',
        ]);

        \sleep(2);

        $this->assertEquals('Attribute with the requested key already exists. Attribute keys must be unique, try again with a different key.', $relation['body']['message']);
        $this->assertEquals(409, $relation['body']['code']);

        // RelationshipManyToMany
        $relation = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1['body']['$id'] . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $collection2['body']['$id'],
            'type' => Database::RELATION_MANY_TO_MANY,
            'twoWay' => true,
            'onDelete' => 'setNull',
            'key' => 'songs',
            'twoWayKey' => 'playlist',
        ]);

        \sleep(2);

        $this->assertEquals(202, $relation['headers']['status-code']);
        $this->assertArrayHasKey('twoWayKey', $relation['body']);

        // Second RelationshipManyToMany on Same collections
        $relation = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1['body']['$id'] . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $collection2['body']['$id'],
            'type' => Database::RELATION_MANY_TO_MANY,
            'twoWay' => true,
            'onDelete' => 'setNull',
            'key' => 'songs2',
            'twoWayKey' => 'playlist2',
        ]);

        \sleep(2);

        $this->assertEquals(409, $relation['body']['code']);
        $this->assertEquals('Creating more than one "manyToMany" relationship on the same collection is currently not permitted.', $relation['body']['message']);
    }

    public function testUpdateWithoutRelationPermission(): void
    {
        $userId = $this->getUser()['$id'];
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => ID::unique(),
        ]);

        $databaseId = $database['body']['$id'];

        // Creating collection 1
        $collection1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::custom('collection1'),
            'name' => ID::custom('collection1'),
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($userId)),
                Permission::read(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ]
        ]);

        // Creating collection 2
        $collection2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::custom('collection2'),
            'name' => ID::custom('collection2'),
            'documentSecurity' => false,
            'permissions' => [
                Permission::read(Role::user($userId)),
            ]
        ]);

        $collection3 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::custom('collection3'),
            'name' => ID::custom('collection3'),
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($userId)),
                Permission::read(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ]
        ]);

        $collection4 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::custom('collection4'),
            'name' => ID::custom('collection4'),
            'documentSecurity' => false,
            'permissions' => [
                Permission::read(Role::user($userId)),
            ]
        ]);

        $collection5 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::custom('collection5'),
            'name' => ID::custom('collection5'),
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($userId)),
                Permission::read(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ]
        ]);

        // Creating one to one relationship from collection 1 to colletion 2
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1['body']['$id'] . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $collection2['body']['$id'],
            'type' => 'oneToOne',
            'twoWay' => false,
            'onDelete' => 'setNull',
            'key' => $collection2['body']['$id']
        ]);

        // Creating one to one relationship from collection 2 to colletion 3
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection2['body']['$id'] . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $collection3['body']['$id'],
            'type' => 'oneToOne',
            'twoWay' => false,
            'onDelete' => 'setNull',
            'key' => $collection3['body']['$id']
        ]);

        // Creating one to one relationship from collection 3 to colletion 4
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection3['body']['$id'] . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $collection4['body']['$id'],
            'type' => 'oneToOne',
            'twoWay' => false,
            'onDelete' => 'setNull',
            'key' => $collection4['body']['$id']
        ]);

        // Creating one to one relationship from collection 4 to colletion 5
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection4['body']['$id'] . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $collection5['body']['$id'],
            'type' => 'oneToOne',
            'twoWay' => false,
            'onDelete' => 'setNull',
            'key' => $collection5['body']['$id']
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => "Title",
            'size' => 100,
            'required' => false,
            'array' => false,
            'default' => null,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection2['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => "Rating",
            'size' => 100,
            'required' => false,
            'array' => false,
            'default' => null,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection3['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => "Rating",
            'size' => 100,
            'required' => false,
            'array' => false,
            'default' => null,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection4['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => "Rating",
            'size' => 100,
            'required' => false,
            'array' => false,
            'default' => null,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection5['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => "Rating",
            'size' => 100,
            'required' => false,
            'array' => false,
            'default' => null,
        ]);

        \sleep(2);
        // Creating parent document with a child reference to test the permissions
        $parentDocument = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => ID::custom($collection1['body']['$id']),
            'data' => [
                'Title' => 'Captain America',
                $collection2['body']['$id'] => [
                    '$id' => ID::custom($collection2['body']['$id']),
                    'Rating' => '10',
                    $collection3['body']['$id'] => [
                        '$id' => ID::custom($collection3['body']['$id']),
                        'Rating' => '10',
                        $collection4['body']['$id'] => [
                            '$id' => ID::custom($collection4['body']['$id']),
                            'Rating' => '10',
                            $collection5['body']['$id'] => [
                                '$id' => ID::custom($collection5['body']['$id']),
                                'Rating' => '10'
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $this->assertEquals(201, $parentDocument['headers']['status-code']);
        // This is the point of the test. We should not need any authorization permission to update the document with same data.
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collection1['body']['$id'] . '/documents/' . $collection1['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::custom($collection1['body']['$id']),
            'data' => [
                'Title' => 'Captain America',
                $collection2['body']['$id'] => [
                    '$id' => $collection2['body']['$id'],
                    'Rating' => '10',
                    $collection3['body']['$id'] => [
                        '$id' => $collection3['body']['$id'],
                        'Rating' => '10',
                        $collection4['body']['$id'] => [
                            '$id' => $collection4['body']['$id'],
                            'Rating' => '10',
                            $collection5['body']['$id'] => [
                                '$id' => $collection5['body']['$id'],
                                'Rating' => '10'
                            ]
                        ]
                    ]
                ]
            ]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($parentDocument['body'], $response['body']);

        // Giving update permission of collection 3 to user.
        $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/collection3', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::custom('collection3'),
            'name' => ID::custom('collection3'),
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($userId)),
                Permission::read(Role::user($userId)),
                Permission::update(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ]
        ]);

        // This is the point of this test. We should be allowed to do this action, and it should not fail on permission check
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collection1['body']['$id'] . '/documents/' . $collection1['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'Title' => 'Captain America',
                $collection2['body']['$id'] => [
                    '$id' => ID::custom($collection2['body']['$id']),
                    'Rating' => '10',
                    $collection3['body']['$id'] => [
                        '$id' => ID::custom($collection3['body']['$id']),
                        'Rating' => '11',
                        $collection4['body']['$id'] => [
                            '$id' => ID::custom($collection4['body']['$id']),
                            'Rating' => '10',
                            $collection5['body']['$id'] => [
                                '$id' => ID::custom($collection5['body']['$id']),
                                'Rating' => '11'
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(11, $response['body'][$collection2['body']['$id']]['collection3']['Rating']);

        // We should not be allowed to update the document as we do not have permission for collection 2.
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collection1['body']['$id'] . '/documents/' . $collection1['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'Title' => 'Captain America',
                $collection2['body']['$id'] => [
                    '$id' => ID::custom($collection2['body']['$id']),
                    'Rating' => '11',
                    $collection3['body']['$id'] => null,
                ]
            ]
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // We should not be allowed to update the document as we do not have permission for collection 2.
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collection2['body']['$id'] . '/documents/' . $collection2['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'Rating' => '11',
            ]
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // Removing update permission from collection 3.
        $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/collection3', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::custom('collection3'),
            'name' => ID::custom('collection3'),
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($userId)),
                Permission::read(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ]
        ]);

        // Giving update permission to collection 2.
        $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/collection2', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::custom('collection2'),
            'name' => ID::custom('collection2'),
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($userId)),
                Permission::update(Role::user($userId)),
                Permission::read(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ]
        ]);

        // Creating collection 3 new document
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection3['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => ID::custom('collection3Doc1'),
            'data' => [
                'Rating' => '20'
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // We should be allowed to link a new document from collection 3 to collection 2.
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collection1['body']['$id'] . '/documents/' . $collection1['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'Title' => 'Captain America',
                $collection2['body']['$id'] => [
                    '$id' => ID::custom($collection2['body']['$id']),
                    $collection3['body']['$id'] => 'collection3Doc1',
                ]
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // We should be allowed to link and create a new document from collection 3 to collection 2.
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collection1['body']['$id'] . '/documents/' . $collection1['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'Title' => 'Captain America',
                $collection2['body']['$id'] => [
                    '$id' => ID::custom($collection2['body']['$id']),
                    $collection3['body']['$id'] => [
                        '$id' => ID::custom('collection3Doc2')
                    ],
                ]
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
    }
    public function testModifyCreatedAtUpdatedAtSingleDocument(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        $table = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Test Table',
            'documentsecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);
        $collectionId = $table['body']['$id'];

        // Create string column
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        sleep(1);

        // Test 1: Try to create document with $createdAt - should return 400
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Test Movie',
                '$createdAt' => '2000-01-01T10:00:00.000+00:00'
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Test 2: Try to create document with $updatedAt - should return 400
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Test Movie',
                '$updatedAt' => '2000-01-01T10:00:00.000+00:00'
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Test 3: Try to create document with both $createdAt and $updatedAt - should return 400
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Test Movie',
                '$createdAt' => '2000-01-01T10:00:00.000+00:00',
                '$updatedAt' => '2000-01-01T10:00:00.000+00:00'
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Test 4: Create a valid document first
        $validRow = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Valid Movie'
            ]
        ]);

        $this->assertEquals(201, $validRow['headers']['status-code']);
        $documentId = $validRow['body']['$id'];

        // Test 5: Try to update document with $createdAt - should return 400
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Updated Movie',
                '$createdAt' => '2000-01-01T10:00:00.000+00:00'
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Test 6: Try to update document with $updatedAt - should return 400
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Updated Movie',
                '$updatedAt' => '2000-01-01T10:00:00.000+00:00'
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Test 7: Try to update document with both $createdAt and $updatedAt - should return 400
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Updated Movie',
                '$createdAt' => '2000-01-01T10:00:00.000+00:00',
                '$updatedAt' => '2000-01-01T10:00:00.000+00:00'
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }
    public function testSpatialPointAttributes(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial Point Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        // Create collection with spatial and non-spatial attributes
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Spatial Point Collection',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create string attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        // Create point attribute - handle both 201 (created) and 200 (already exists)
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'location',
            'required' => true,
        ]);

        $this->assertEquals(202, $response['headers']['status-code']);

        sleep(2);

        // Test 1: Create document with point attribute
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Test Location',
                'location' => [40.7128, -74.0060] // New York coordinates
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals([40.7128, -74.0060], $response['body']['location']);
        $documentId = $response['body']['$id'];

        // Test 2: Read document with point attribute
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([40.7128, -74.0060], $response['body']['location']);

        // Test 3: Update document with new point coordinates
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'location' => [40.7589, -73.9851] // Times Square coordinates
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([40.7589, -73.9851], $response['body']['location']);

        // Test 4: Upsert document with point attribute
        $response = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . ID::unique(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Upserted Location',
                'location' => [34.0522, -118.2437] // Los Angeles coordinates
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([34.0522, -118.2437], $response['body']['location']);

        // Test 5: Create document without permissions (should fail)
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Unauthorized Location',
                'location' => [0, 0]
            ]
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialLineAttributes(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial Line Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        // Create collection with spatial and non-spatial attributes
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Spatial Line Collection',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create integer attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'distance',
            'required' => true,
        ]);

        // Create line attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/line', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'route',
            'required' => true,
        ]);

        sleep(2);

        // Test 1: Create document with line attribute
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'distance' => 100,
                'route' => [[40.7128, -74.0060], [40.7589, -73.9851]] // Line from Downtown to Times Square
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals([[40.7128, -74.0060], [40.7589, -73.9851]], $response['body']['route']);
        $documentId = $response['body']['$id'];

        // Test 2: Read document with line attribute
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([[40.7128, -74.0060], [40.7589, -73.9851]], $response['body']['route']);

        // Test 3: Update document with new line coordinates
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'route' => [[40.7128, -74.0060], [40.7589, -73.9851], [40.7505, -73.9934]] // Extended route
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([[40.7128, -74.0060], [40.7589, -73.9851], [40.7505, -73.9934]], $response['body']['route']);

        // Test 4: Upsert document with line attribute
        $response = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . ID::unique(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'distance' => 200,
                'route' => [[34.0522, -118.2437], [34.0736, -118.2400]] // LA route
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([[34.0522, -118.2437], [34.0736, -118.2400]], $response['body']['route']);

        // Test 5: Delete document
        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);

        // Test 6: Verify document is deleted
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialPolygonAttributes(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial Polygon Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        // Create collection with spatial and non-spatial attributes
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Spatial Polygon Collection',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create boolean attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/boolean', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'active',
            'required' => true,
        ]);

        // Create polygon attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/polygon', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'area',
            'required' => true,
        ]);

        sleep(2);

        // Test 1: Create document with polygon attribute
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'active' => true,
                'area' => [[[40.7128, -74.0060], [40.7589, -74.0060], [40.7589, -73.9851], [40.7128, -73.9851], [40.7128, -74.0060]]] // Manhattan area
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals([[[40.7128, -74.0060], [40.7589, -74.0060], [40.7589, -73.9851], [40.7128, -73.9851], [40.7128, -74.0060]]], $response['body']['area']);
        $documentId = $response['body']['$id'];

        // Test 2: Read document with polygon attribute
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([[[40.7128, -74.0060], [40.7589, -74.0060], [40.7589, -73.9851], [40.7128, -73.9851], [40.7128, -74.0060]]], $response['body']['area']);

        // Test 3: Update document with new polygon coordinates
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'area' => [[[40.7128, -74.0060], [40.7589, -74.0060], [40.7589, -73.9851], [40.7128, -73.9851], [40.7505, -73.9934], [40.7128, -74.0060]]] // Extended area
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([[[40.7128, -74.0060], [40.7589, -74.0060], [40.7589, -73.9851], [40.7128, -73.9851], [40.7505, -73.9934], [40.7128, -74.0060]]], $response['body']['area']);

        // Test 4: Upsert document with polygon attribute
        $response = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . ID::unique(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'active' => false,
                'area' => [[[34.0522, -118.2437], [34.0736, -118.2437], [34.0736, -118.2400], [34.0522, -118.2400], [34.0522, -118.2437]]] // LA area
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([[[34.0522, -118.2437], [34.0736, -118.2437], [34.0736, -118.2400], [34.0522, -118.2400], [34.0522, -118.2437]]], $response['body']['area']);

        // Test 5: Create document without required polygon attribute (should fail)
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'active' => true
                // Missing required 'area' attribute
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialAttributesMixedCollection(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Mixed Spatial Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        // Create collection with multiple spatial and non-spatial attributes
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Mixed Spatial Collection',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create multiple attributes
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'center',
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/line', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'boundary',
            'required' => false,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/polygon', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'coverage',
            'required' => true,
        ]);

        sleep(3);

        // Test 1: Create document with all spatial attributes
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Central Park',
                'center' => [40.7829, -73.9654],
                'boundary' => [[40.7649, -73.9814], [40.8009, -73.9494]],
                'coverage' => [[[40.7649, -73.9814], [40.8009, -73.9814], [40.8009, -73.9494], [40.7649, -73.9494], [40.7649, -73.9814]]]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals([40.7829, -73.9654], $response['body']['center']);
        $this->assertEquals([[40.7649, -73.9814], [40.8009, -73.9494]], $response['body']['boundary']);
        $this->assertEquals([[[40.7649, -73.9814], [40.8009, -73.9814], [40.8009, -73.9494], [40.7649, -73.9494], [40.7649, -73.9814]]], $response['body']['coverage']);
        $documentId = $response['body']['$id'];

        // Test 2: Update document with new spatial data
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'center' => [40.7505, -73.9934],
                'boundary' => [[40.7305, -74.0134], [40.7705, -73.9734]],
                'coverage' => [[[40.7305, -74.0134], [40.7705, -74.0134], [40.7705, -73.9734], [40.7305, -73.9734], [40.7305, -74.0134]]]
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([40.7505, -73.9934], $response['body']['center']);
        $this->assertEquals([[40.7305, -74.0134], [40.7705, -73.9734]], $response['body']['boundary']);
        $this->assertEquals([[[40.7305, -74.0134], [40.7705, -74.0134], [40.7705, -73.9734], [40.7305, -73.9734], [40.7305, -74.0134]]], $response['body']['coverage']);

        // Test 3: Create document with minimal required attributes
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Minimal Location',
                'center' => [0, 0],
                'coverage' => [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals([0, 0], $response['body']['center']);

        // Test 4: Test permission validation - create without user context
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Unauthorized Location',
                'center' => [0, 0],
                'coverage' => [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]]
            ]
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }
}
