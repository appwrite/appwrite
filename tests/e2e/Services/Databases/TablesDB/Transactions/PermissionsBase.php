<?php

namespace Tests\E2E\Services\Databases\TablesDB\Transactions;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

trait PermissionsBase
{
    protected string $permissionsDatabase;

    /**
     * Set up database for permission tests
     */
    public function setUp(): void
    {
        parent::setUp();

        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'PermissionsTestDB'
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $this->permissionsDatabase = $database['body']['$id'];
    }

    /**
     * Test collection-level create permission check on staging
     */
    public function testCollectionCreatePermissionDenied(): void
    {
        // Create a collection with no create permission for current user
        $collection = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => 'permTest1',
            'name' => 'Permission Test 1',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rowSecurity' => false,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $attribute = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables/' . $collection['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'size' => 255,
            'required' => true,
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code']);
        sleep(2);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);

        // Try to stage a create operation without permission, should fail
        $staged = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions/' . $transaction['body']['$id'] . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'create',
                'databaseId' => $this->permissionsDatabase,
                'tableId' => $collection['body']['$id'],
                'rowId' => 'testDoc1',
                'data' => ['title' => 'Test Document'],
            ]]
        ]);

        // This should fail with 401 Unauthorized
        if ($staged['headers']['status-code'] !== 401) {
            echo "\nDEBUG - Actual response code: " . $staged['headers']['status-code'] . "\n";
            echo "DEBUG - Response body: " . json_encode($staged['body'], JSON_PRETTY_PRINT) . "\n";
        }
        $this->assertEquals(401, $staged['headers']['status-code']);
    }

    /**
     * Test collection-level update permission check on staging
     */
    public function testCollectionUpdatePermissionDenied(): void
    {
        // Create a collection with create but no update permission
        $collection = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => 'permTest2',
            'name' => 'Permission Test 2',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rowSecurity' => false,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $attribute = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables/' . $collection['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'size' => 255,
            'required' => true,
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code']);
        sleep(2);

        // Create a document first with API key
        $doc = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables/' . $collection['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'rowId' => 'testDoc2',
            'data' => ['title' => 'Original Title'],
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);

        // Try to stage an update operation without permission, should fail
        $staged = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions/' . $transaction['body']['$id'] . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'update',
                'databaseId' => $this->permissionsDatabase,
                'tableId' => $collection['body']['$id'],
                'rowId' => 'testDoc2',
                'data' => ['title' => 'Updated Title'],
            ]]
        ]);

        // This should fail with 401 Unauthorized
        $this->assertEquals(401, $staged['headers']['status-code']);
    }

    /**
     * Test collection-level delete permission check on staging
     */
    public function testCollectionDeletePermissionDenied(): void
    {
        // Create a collection with create, read but no delete permission
        $collection = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => 'permTest3',
            'name' => 'Permission Test 3',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'rowSecurity' => false,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $attribute = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables/' . $collection['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'size' => 255,
            'required' => true,
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code']);
        sleep(2);

        $doc = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables/' . $collection['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'rowId' => 'testDoc3',
            'data' => ['title' => 'To Be Deleted'],
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);

        // Try to stage a delete operation without permission, should fail
        $staged = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions/' . $transaction['body']['$id'] . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'delete',
                'databaseId' => $this->permissionsDatabase,
                'tableId' => $collection['body']['$id'],
                'rowId' => 'testDoc3',
                'data' => [],
            ]]
        ]);

        // This should fail with 401 Unauthorized
        $this->assertEquals(401, $staged['headers']['status-code']);
    }

    /**
     * Test document-level update permission grants access when rowSecurity is enabled
     * Collection has no update permission, but document does, should succeed
     */
    public function testDocumentLevelUpdatePermissionGranted(): void
    {
        // Create collection with rowSecurity enabled but no update permission at collection level
        $collection = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => 'permTest4',
            'name' => 'Permission Test 4',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
            'rowSecurity' => true,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $attribute = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables/' . $collection['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'size' => 255,
            'required' => true,
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code']);
        sleep(2);

        // Create a document with update permission at document level
        $doc = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables/' . $collection['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'rowId' => 'testDoc4',
            'data' => ['title' => 'Protected Document'],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);

        // Stage an update, should succeed because document has update permission
        $staged = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions/' . $transaction['body']['$id'] . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'update',
                'databaseId' => $this->permissionsDatabase,
                'tableId' => $collection['body']['$id'],
                'rowId' => 'testDoc4',
                'data' => ['title' => 'Trying to Update'],
            ]]
        ]);

        // This should succeed with 201 because document has update permission
        $this->assertEquals(201, $staged['headers']['status-code']);
    }

    /**
     * Test document-level delete permission grants access when rowSecurity is enabled
     * Collection has no delete permission, but document does, should succeed
     */
    public function testDocumentLevelDeletePermissionGranted(): void
    {
        // Create collection with rowSecurity enabled but no delete permission at collection level
        $collection = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => 'permTest5',
            'name' => 'Permission Test 5',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'rowSecurity' => true,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $attribute = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables/' . $collection['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'size' => 255,
            'required' => true,
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code']);
        sleep(2);

        // Create a document with delete permission at document level
        $doc = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables/' . $collection['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'rowId' => 'testDoc5',
            'data' => ['title' => 'Can Delete Me'],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);

        // Stage a delete should succeed because document has delete permission
        $staged = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions/' . $transaction['body']['$id'] . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'delete',
                'databaseId' => $this->permissionsDatabase,
                'tableId' => $collection['body']['$id'],
                'rowId' => 'testDoc5',
                'data' => [],
            ]]
        ]);

        // This should succeed with 201 because document has DELETE permission
        $this->assertEquals(201, $staged['headers']['status-code']);
    }

    /**
     * Test that users cannot set permissions for roles they don't have
     */
    public function testCannotSetUnauthorizedRolePermissions(): void
    {
        // Create a collection
        $collection = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => 'permTest6',
            'name' => 'Permission Test 6',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rowSecurity' => true,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        // Add attribute
        $attribute = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables/' . $collection['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'size' => 255,
            'required' => true,
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code']);
        sleep(2);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);

        // Try to stage a create with team permissions, current user is not in team
        $staged = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions/' . $transaction['body']['$id'] . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'create',
                'databaseId' => $this->permissionsDatabase,
                'tableId' => $collection['body']['$id'],
                'rowId' => 'testDoc6',
                'data' => [
                    'title' => 'Admin Only Doc',
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::team('adminTeam')),
                    ],
                ],
            ]]
        ]);

        // This should fail with 401 Unauthorized, cannot set permissions for roles you don't have
        $this->assertEquals(401, $staged['headers']['status-code']);
        $this->assertStringContainsString('Permissions must be one of', $staged['body']['message']);
    }

    /**
     * Test successful staging when user has the required permissions
     */
    public function testSuccessfulStagingWithProperPermissions(): void
    {
        $collection = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => 'permTest7',
            'name' => 'Permission Test 7',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rowSecurity' => true,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $attribute = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables/' . $collection['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'size' => 255,
            'required' => true,
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code']);
        sleep(2);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);

        // Stage a create with permissions for current user's roles, should succeed
        $staged = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions/' . $transaction['body']['$id'] . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'create',
                'databaseId' => $this->permissionsDatabase,
                'tableId' => $collection['body']['$id'],
                'rowId' => 'testDoc7',
                'data' => [
                    'title' => 'Valid Document',
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::user($this->getUser()['$id'])),
                    ],
                ],
            ]]
        ]);

        // This should succeed
        $this->assertEquals(201, $staged['headers']['status-code']);
        $this->assertEquals(1, $staged['body']['operations']);
    }

    /**
     * Test that non-existent documents cannot be updated in transactions
     */
    public function testCannotUpdateNonExistentDocument(): void
    {
        // Create a collection
        $collection = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => 'permTest8',
            'name' => 'Permission Test 8',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rowSecurity' => false,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $attribute = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables/' . $collection['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'size' => 255,
            'required' => true,
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code']);
        sleep(2);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);

        // Try to update a document that doesn't exist - should fail
        $staged = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions/' . $transaction['body']['$id'] . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'update',
                'databaseId' => $this->permissionsDatabase,
                'tableId' => $collection['body']['$id'],
                'rowId' => 'nonExistentDoc',
                'data' => ['title' => 'Trying to Update'],
            ]]
        ]);

        // This should fail with 404 Not Found
        $this->assertEquals(404, $staged['headers']['status-code']);
    }

    /**
     * Test that non-existent documents cannot be deleted in transactions
     */
    public function testCannotDeleteNonExistentDocument(): void
    {
        // Create a collection
        $collection = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => 'permTest9',
            'name' => 'Permission Test 9',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rowSecurity' => false,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $attribute = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables/' . $collection['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'size' => 255,
            'required' => true,
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code']);
        sleep(2);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);

        // Try to delete a document that doesn't exist, should fail
        $staged = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions/' . $transaction['body']['$id'] . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'delete',
                'databaseId' => $this->permissionsDatabase,
                'tableId' => $collection['body']['$id'],
                'rowId' => 'nonExistentDoc',
                'data' => [],
            ]]
        ]);

        // This should fail with 404 Not Found
        $this->assertEquals(404, $staged['headers']['status-code']);
    }
}
