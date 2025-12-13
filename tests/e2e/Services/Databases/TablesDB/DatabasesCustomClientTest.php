<?php

namespace Tests\E2E\Services\Databases\TablesDB;

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

        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        // table aliases write to create, update, delete
        $movies = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Movies',
            'rowSecurity' => true,
            'permissions' => [
                Permission::write(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $moviesId = $movies['body']['$id'];

        $this->assertContains(Permission::create(Role::user($this->getUser()['$id'])), $movies['body']['$permissions']);
        $this->assertContains(Permission::update(Role::user($this->getUser()['$id'])), $movies['body']['$permissions']);
        $this->assertContains(Permission::delete(Role::user($this->getUser()['$id'])), $movies['body']['$permissions']);

        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $moviesId . '/columns/string', array_merge([
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
        $row1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $moviesId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Captain America',
            ],
            'permissions' => [
                Permission::write(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertNotContains(Permission::create(Role::user($this->getUser()['$id'])), $row1['body']['$permissions']);
        $this->assertContains(Permission::update(Role::user($this->getUser()['$id'])), $row1['body']['$permissions']);
        $this->assertContains(Permission::delete(Role::user($this->getUser()['$id'])), $row1['body']['$permissions']);

        /**
         * Test for FAILURE
         */

        // Document does not allow create permission
        $row2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $moviesId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Captain America',
            ],
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals(400, $row2['headers']['status-code']);
    }

    public function testUpdateWithoutPermission(): array
    {
        // If row has been created by server and client tried to update it without adjusting permissions, permission validation should be skipped

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
        // Create table
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::custom('permissionCheck'),
            'name' => 'permissionCheck',
            'permissions' => [],
            'rowSecurity' => true,
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        // Add column to table
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/permissionCheck/columns/string', array_merge([
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

        // Creating row by server, give read permission to our user + some other user
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/permissionCheck/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'rowId' => ID::custom('permissionCheckDocument'),
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

        // Update row
        // This is the point of this test. We should be allowed to do this action, and it should not fail on permission check
        $response = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/permissionCheck/rows/permissionCheckDocument', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'name' => 'AppwriteExpert',
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Get name of the row, should be the new one
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/permissionCheck/rows/permissionCheckDocument', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("AppwriteExpert", $response['body']['name']);

        // Cleanup to prevent collision with other tests
        // Delete table
        $response = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/permissionCheck', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $response['headers']['status-code']);


        // Wait for database worker to finish deleting table
        sleep(2);

        // Make sure table has been deleted
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/permissionCheck', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
        $this->assertEquals(404, $response['headers']['status-code']);

        return [];
    }

    public function testUpdateTwoWayRelationship(): void
    {

        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Test Database'
        ]);

        $databaseId = $database['body']['$id'];


        // Creating table 1
        $table1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'level1',
            'rowSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        // Creating table 2
        $table2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'level2',
            'rowSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        \sleep(2);

        // Creating two way relationship between table 1 and table 2 from table 1
        $relation = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table1['body']['$id'] . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => $table2['body']['$id'],
            'type' => 'oneToMany',
            'twoWay' => true,
            'onDelete' => 'cascade',
            'key' => $table2['body']['$id'],
            'twoWayKey' => $table1['body']['$id']
        ]);

        \sleep(3);

        // Update relation from table 2 to on delete restrict
        $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $table2['body']['$id'] . '/columns/' . $table1['body']['$id'] . '/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'onDelete' => 'restrict',
        ]);

        // Fetching attributes after updating relation to compare
        $table1Attributes =  $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $table1['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $table1RelationAttribute = $table1Attributes['body']['columns'][0];

        $this->assertEquals($relation['body']['side'], $table1RelationAttribute['side']);
        $this->assertEquals($relation['body']['twoWayKey'], $table1RelationAttribute['twoWayKey']);
        $this->assertEquals($relation['body']['relatedTable'], $table1RelationAttribute['relatedTable']);
        $this->assertEquals('restrict', $table1RelationAttribute['onDelete']);
    }

    public function testRelationshipSameTwoWayKey(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Same two way key'
        ]);

        $databaseId = $database['body']['$id'];

        $table1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'c1',
            'rowSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $table2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'c2',
            'rowSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        \sleep(2);

        $relation = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table1['body']['$id'] . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => $table2['body']['$id'],
            'type' => Database::RELATION_ONE_TO_ONE,
            'twoWay' => false,
            'onDelete' => 'cascade',
            'key' => 'attr1',
            'twoWayKey' => 'same_key'
        ]);

        \sleep(2);

        $this->assertEquals(202, $relation['headers']['status-code']);
        $this->assertEquals('same_key', $relation['body']['twoWayKey']);

        $relation = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table1['body']['$id'] . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => $table2['body']['$id'],
            'type' => Database::RELATION_ONE_TO_MANY,
            'twoWay' => false,
            'onDelete' => 'cascade',
            'key' => 'attr2',
            'twoWayKey' => 'same_key'
        ]);

        \sleep(2);

        $this->assertEquals(409, $relation['body']['code']);
        $this->assertEquals('Column with the requested key \'same_key\' already exists. Column keys must be unique, try again with a different key.', $relation['body']['message']);

        // twoWayKey is null TwoWayKey is default
        $relation = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table1['body']['$id'] . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => $table2['body']['$id'],
            'type' => Database::RELATION_ONE_TO_MANY,
            'twoWay' => false,
            'onDelete' => 'cascade',
            'key' => 'attr3',
        ]);

        \sleep(2);

        $this->assertEquals(202, $relation['headers']['status-code']);
        $this->assertArrayHasKey('twoWayKey', $relation['body']);

        // twoWayKey is null,   TwoWayKey is default, second POST
        $relation = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table1['body']['$id'] . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => $table2['body']['$id'],
            'type' => Database::RELATION_ONE_TO_MANY,
            'twoWay' => false,
            'onDelete' => 'cascade',
            'key' => 'attr4',
        ]);

        \sleep(2);

        $this->assertEquals('Column with the requested key \'attr4\' already exists. Column keys must be unique, try again with a different key.', $relation['body']['message']);
        $this->assertEquals(409, $relation['body']['code']);

        // RelationshipManyToMany
        $relation = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table1['body']['$id'] . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => $table2['body']['$id'],
            'type' => Database::RELATION_MANY_TO_MANY,
            'twoWay' => true,
            'onDelete' => 'setNull',
            'key' => 'songs',
            'twoWayKey' => 'playlist',
        ]);

        \sleep(2);

        $this->assertEquals(202, $relation['headers']['status-code']);
        $this->assertArrayHasKey('twoWayKey', $relation['body']);

        // Second RelationshipManyToMany on Same tables
        $relation = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table1['body']['$id'] . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => $table2['body']['$id'],
            'type' => Database::RELATION_MANY_TO_MANY,
            'twoWay' => true,
            'onDelete' => 'setNull',
            'key' => 'songs2',
            'twoWayKey' => 'playlist2',
        ]);

        \sleep(2);

        $this->assertEquals(409, $relation['body']['code']);
        $this->assertEquals('Creating more than one "manyToMany" relationship on the same table is currently not permitted.', $relation['body']['message']);
    }

    public function testUpdateWithoutRelationPermission(): void
    {
        $userId = $this->getUser()['$id'];
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => ID::unique(),
        ]);

        $databaseId = $database['body']['$id'];

        // Creating table 1
        $table1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::custom('table1'),
            'name' => ID::custom('table1'),
            'rowSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($userId)),
                Permission::read(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ]
        ]);

        // Creating table 2
        $table2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::custom('table2'),
            'name' => ID::custom('table2'),
            'rowSecurity' => false,
            'permissions' => [
                Permission::read(Role::user($userId)),
            ]
        ]);

        $table3 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::custom('table3'),
            'name' => ID::custom('table3'),
            'rowSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($userId)),
                Permission::read(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ]
        ]);

        $table4 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::custom('table4'),
            'name' => ID::custom('table4'),
            'rowSecurity' => false,
            'permissions' => [
                Permission::read(Role::user($userId)),
            ]
        ]);

        $table5 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::custom('table5'),
            'name' => ID::custom('table5'),
            'rowSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($userId)),
                Permission::read(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ]
        ]);

        // Creating one to one relationship from table 1 to colletion 2
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table1['body']['$id'] . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => $table2['body']['$id'],
            'type' => 'oneToOne',
            'twoWay' => false,
            'onDelete' => 'setNull',
            'key' => $table2['body']['$id']
        ]);

        // Creating one to one relationship from table 2 to colletion 3
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table2['body']['$id'] . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => $table3['body']['$id'],
            'type' => 'oneToOne',
            'twoWay' => false,
            'onDelete' => 'setNull',
            'key' => $table3['body']['$id']
        ]);

        // Creating one to one relationship from table 3 to colletion 4
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table3['body']['$id'] . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => $table4['body']['$id'],
            'type' => 'oneToOne',
            'twoWay' => false,
            'onDelete' => 'setNull',
            'key' => $table4['body']['$id']
        ]);

        // Creating one to one relationship from table 4 to colletion 5
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table4['body']['$id'] . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => $table5['body']['$id'],
            'type' => 'oneToOne',
            'twoWay' => false,
            'onDelete' => 'setNull',
            'key' => $table5['body']['$id']
        ]);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table1['body']['$id'] . '/columns/string', array_merge([
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

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table2['body']['$id'] . '/columns/string', array_merge([
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

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table3['body']['$id'] . '/columns/string', array_merge([
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

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table4['body']['$id'] . '/columns/string', array_merge([
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

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table5['body']['$id'] . '/columns/string', array_merge([
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
        // Creating parent row with a child reference to test the permissions
        $parentDocument = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table1['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'rowId' => ID::custom($table1['body']['$id']),
            'data' => [
                'Title' => 'Captain America',
                $table2['body']['$id'] => [
                    '$id' => ID::custom($table2['body']['$id']),
                    'Rating' => '10',
                    $table3['body']['$id'] => [
                        '$id' => ID::custom($table3['body']['$id']),
                        'Rating' => '10',
                        $table4['body']['$id'] => [
                            '$id' => ID::custom($table4['body']['$id']),
                            'Rating' => '10',
                            $table5['body']['$id'] => [
                                '$id' => ID::custom($table5['body']['$id']),
                                'Rating' => '10'
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $this->assertEquals(201, $parentDocument['headers']['status-code']);
        // This is the point of the test. We should not need any authorization permission to update the row with same data.
        $response = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $table1['body']['$id'] . '/rows/' . $table1['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::custom($table1['body']['$id']),
            'data' => [
                'Title' => 'Captain America',
                $table2['body']['$id'] => [
                    '$id' => $table2['body']['$id'],
                    'Rating' => '10',
                    $table3['body']['$id'] => [
                        '$id' => $table3['body']['$id'],
                        'Rating' => '10',
                        $table4['body']['$id'] => [
                            '$id' => $table4['body']['$id'],
                            'Rating' => '10',
                            $table5['body']['$id'] => [
                                '$id' => $table5['body']['$id'],
                                'Rating' => '10'
                            ]
                        ]
                    ]
                ]
            ]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($parentDocument['body'], $response['body']);

        // Giving update permission of table 3 to user.
        $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/table3', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::custom('table3'),
            'name' => ID::custom('table3'),
            'rowSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($userId)),
                Permission::read(Role::user($userId)),
                Permission::update(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ]
        ]);

        // This is the point of this test. We should be allowed to do this action, and it should not fail on permission check
        $response = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $table1['body']['$id'] . '/rows/' . $table1['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'Title' => 'Captain America',
                $table2['body']['$id'] => [
                    '$id' => ID::custom($table2['body']['$id']),
                    'Rating' => '10',
                    $table3['body']['$id'] => [
                        '$id' => ID::custom($table3['body']['$id']),
                        'Rating' => '11',
                        $table4['body']['$id'] => [
                            '$id' => ID::custom($table4['body']['$id']),
                            'Rating' => '10',
                            $table5['body']['$id'] => [
                                '$id' => ID::custom($table5['body']['$id']),
                                'Rating' => '11'
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(11, $response['body'][$table2['body']['$id']]['table3']['Rating']);

        // We should not be allowed to update the row as we do not have permission for table 2.
        $response = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $table1['body']['$id'] . '/rows/' . $table1['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'Title' => 'Captain America',
                $table2['body']['$id'] => [
                    '$id' => ID::custom($table2['body']['$id']),
                    'Rating' => '11',
                    $table3['body']['$id'] => null,
                ]
            ]
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // We should not be allowed to update the row as we do not have permission for table 2.
        $response = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $table2['body']['$id'] . '/rows/' . $table2['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'Rating' => '11',
            ]
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // Removing update permission from table 3.
        $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/table3', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::custom('table3'),
            'name' => ID::custom('table3'),
            'rowSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($userId)),
                Permission::read(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ]
        ]);

        // Giving update permission to table 2.
        $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/table2', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::custom('table2'),
            'name' => ID::custom('table2'),
            'rowSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($userId)),
                Permission::update(Role::user($userId)),
                Permission::read(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ]
        ]);

        // Creating table 3 new row
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table3['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'rowId' => ID::custom('table3Doc1'),
            'data' => [
                'Rating' => '20'
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // We should be allowed to link a new row from table 3 to table 2.
        $response = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $table1['body']['$id'] . '/rows/' . $table1['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'Title' => 'Captain America',
                $table2['body']['$id'] => [
                    '$id' => ID::custom($table2['body']['$id']),
                    $table3['body']['$id'] => 'table3Doc1',
                ]
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);


        // We should be allowed to link and create a new row from table 3 to table 2.
        $response = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $table1['body']['$id'] . '/rows/' . $table1['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'Title' => 'Captain America',
                $table2['body']['$id'] => [
                    '$id' => ID::custom($table2['body']['$id']),
                    $table3['body']['$id'] => [
                        '$id' => ID::custom('table3Doc2')
                    ],
                ]
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
    }
}
