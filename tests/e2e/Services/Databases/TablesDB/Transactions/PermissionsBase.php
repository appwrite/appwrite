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

    /**
     * Test that a document created in one batch can be updated in a subsequent batch within the same transaction
     * This validates the transactionState->getDocument() fix for cross-batch dependencies
     */
    public function testCanUpdateDocumentCreatedInPreviousBatch(): void
    {
        $collection = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => 'permTest10',
            'name' => 'Permission Test 10',
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

        // Batch 1: Create a document
        $batch1 = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions/' . $transaction['body']['$id'] . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'create',
                'databaseId' => $this->permissionsDatabase,
                'tableId' => $collection['body']['$id'],
                'rowId' => 'crossBatchDoc',
                'data' => [
                    'title' => 'Initial Title',
                ],
            ]]
        ]);

        $this->assertEquals(201, $batch1['headers']['status-code']);
        $this->assertEquals(1, $batch1['body']['operations']);

        // Batch 2: Update the document created in batch 1
        $batch2 = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions/' . $transaction['body']['$id'] . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'update',
                'databaseId' => $this->permissionsDatabase,
                'tableId' => $collection['body']['$id'],
                'rowId' => 'crossBatchDoc',
                'data' => [
                    'title' => 'Updated Title',
                ],
            ]]
        ]);

        // This should succeed with 201 because transactionState finds the staged document from batch 1
        $this->assertEquals(201, $batch2['headers']['status-code']);
        $this->assertEquals(2, $batch2['body']['operations']);

        // Batch 3: Delete the same document
        $batch3 = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions/' . $transaction['body']['$id'] . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'delete',
                'databaseId' => $this->permissionsDatabase,
                'tableId' => $collection['body']['$id'],
                'rowId' => 'crossBatchDoc',
                'data' => [],
            ]]
        ]);

        // This should also succeed with 201
        $this->assertEquals(201, $batch3['headers']['status-code']);
        $this->assertEquals(3, $batch3['body']['operations']);

        // Rollback to clean up
        $rollback = $this->client->call(Client::METHOD_PATCH, '/tablesdb/transactions/' . $transaction['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rollback' => true,
        ]);

        $this->assertEquals(200, $rollback['headers']['status-code']);
    }

    /**
     * Test that one user cannot read another user's transaction
     */
    public function testUserCannotReadAnotherUsersTransaction(): void
    {
        // Create user 1 (fresh) and their transaction
        $user1 = $this->getUser(true);
        $user1Headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ];

        $transaction1 = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user1Headers));

        $this->assertEquals(201, $transaction1['headers']['status-code']);
        $transactionId1 = $transaction1['body']['$id'];

        // Create user 2 (fresh)
        $user2 = $this->getUser(true); // Fresh user
        $user2Headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ];

        // User 2 tries to read User 1's transaction - should fail
        $readAttempt = $this->client->call(Client::METHOD_GET, '/tablesdb/transactions/' . $transactionId1, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user2Headers));

        // This should fail with 404 Not Found (transaction doesn't exist for this user)
        $this->assertEquals(404, $readAttempt['headers']['status-code']);

        // Verify User 1 can still read their own transaction
        $readOwn = $this->client->call(Client::METHOD_GET, '/tablesdb/transactions/' . $transactionId1, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user1Headers));

        $this->assertEquals(200, $readOwn['headers']['status-code']);
        $this->assertEquals($transactionId1, $readOwn['body']['$id']);
    }

    /**
     * Test that one user cannot list another user's transactions
     */
    public function testUserCannotListAnotherUsersTransactions(): void
    {
        // Create user 1 (fresh) with transactions
        $user1 = $this->getUser(true);
        $user1Headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ];

        $transaction1 = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user1Headers));

        $this->assertEquals(201, $transaction1['headers']['status-code']);

        $transaction2 = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user1Headers));

        $this->assertEquals(201, $transaction2['headers']['status-code']);

        // Create user 2 (fresh) with their own transaction
        $user2 = $this->getUser(true); // Fresh user
        $user2Headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ];

        $transaction3 = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user2Headers));

        $this->assertEquals(201, $transaction3['headers']['status-code']);

        // User 2 lists transactions - should only see their own
        $listUser2 = $this->client->call(Client::METHOD_GET, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user2Headers));

        $this->assertEquals(200, $listUser2['headers']['status-code']);
        $this->assertEquals(1, $listUser2['body']['total']);
        $this->assertEquals($transaction3['body']['$id'], $listUser2['body']['transactions'][0]['$id']);

        // User 1 lists transactions - should only see their own (2 transactions)
        $listUser1 = $this->client->call(Client::METHOD_GET, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user1Headers));

        $this->assertEquals(200, $listUser1['headers']['status-code']);
        $this->assertEquals(2, $listUser1['body']['total']);

        // Verify neither of user1's transactions appear in user2's list
        $user2TransactionIds = array_column($listUser2['body']['transactions'], '$id');
        $this->assertNotContains($transaction1['body']['$id'], $user2TransactionIds);
        $this->assertNotContains($transaction2['body']['$id'], $user2TransactionIds);
    }

    /**
     * Test that one user cannot update another user's transaction
     */
    public function testUserCannotUpdateAnotherUsersTransaction(): void
    {
        // Create user 1 (fresh) and their transaction
        $user1 = $this->getUser(true);
        $user1Headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ];

        $transaction1 = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user1Headers));

        $this->assertEquals(201, $transaction1['headers']['status-code']);
        $transactionId1 = $transaction1['body']['$id'];

        // Create user 2 (fresh)
        $user2 = $this->getUser(true); // Fresh user
        $user2Headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ];

        // User 2 tries to commit User 1's transaction - should fail
        $commitAttempt = $this->client->call(Client::METHOD_PATCH, '/tablesdb/transactions/' . $transactionId1, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user2Headers), [
            'commit' => true,
        ]);

        // This should fail with 404 Not Found
        $this->assertEquals(404, $commitAttempt['headers']['status-code']);

        // User 2 tries to rollback User 1's transaction - should also fail
        $rollbackAttempt = $this->client->call(Client::METHOD_PATCH, '/tablesdb/transactions/' . $transactionId1, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user2Headers), [
            'rollback' => true,
        ]);

        // This should also fail with 404 Not Found
        $this->assertEquals(404, $rollbackAttempt['headers']['status-code']);

        // Verify User 1 can still commit their own transaction
        $commitOwn = $this->client->call(Client::METHOD_PATCH, '/tablesdb/transactions/' . $transactionId1, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user1Headers), [
            'commit' => true,
        ]);

        $this->assertEquals(200, $commitOwn['headers']['status-code']);
    }

    /**
     * Test that one user cannot delete another user's transaction
     */
    public function testUserCannotDeleteAnotherUsersTransaction(): void
    {
        // Create user 1 (fresh) and their transaction
        $user1 = $this->getUser(true);
        $user1Headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ];

        $transaction1 = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user1Headers));

        $this->assertEquals(201, $transaction1['headers']['status-code']);
        $transactionId1 = $transaction1['body']['$id'];

        // Create user 2 (fresh)
        $user2 = $this->getUser(true); // Fresh user
        $user2Headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ];

        // User 2 tries to delete User 1's transaction - should fail
        $deleteAttempt = $this->client->call(Client::METHOD_DELETE, '/tablesdb/transactions/' . $transactionId1, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user2Headers));

        // This should fail with 404 Not Found
        $this->assertEquals(404, $deleteAttempt['headers']['status-code']);

        // Verify User 1 can still access their transaction
        $readOwn = $this->client->call(Client::METHOD_GET, '/tablesdb/transactions/' . $transactionId1, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user1Headers));

        $this->assertEquals(200, $readOwn['headers']['status-code']);

        // User 1 can delete their own transaction
        $deleteOwn = $this->client->call(Client::METHOD_DELETE, '/tablesdb/transactions/' . $transactionId1, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user1Headers));

        $this->assertEquals(204, $deleteOwn['headers']['status-code']);
    }

    /**
     * Test that one user cannot add operations to another user's transaction
     */
    public function testUserCannotAddOperationsToAnotherUsersTransaction(): void
    {
        // Create a collection for testing
        $collection = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $this->permissionsDatabase . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => 'permTest11',
            'name' => 'Permission Test 11',
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

        // Create user 1 (fresh) and their transaction
        $user1 = $this->getUser(true);
        $user1Headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ];

        $transaction1 = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user1Headers));

        $this->assertEquals(201, $transaction1['headers']['status-code']);
        $transactionId1 = $transaction1['body']['$id'];

        // Create user 2 (fresh)
        $user2 = $this->getUser(true); // Fresh user
        $user2Headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ];

        // User 2 tries to add operations to User 1's transaction - should fail
        $operationAttempt = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions/' . $transactionId1 . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user2Headers), [
            'operations' => [[
                'action' => 'create',
                'databaseId' => $this->permissionsDatabase,
                'tableId' => $collection['body']['$id'],
                'rowId' => 'maliciousDoc',
                'data' => ['title' => 'Malicious Document'],
            ]]
        ]);

        // This should fail with 404 Not Found
        $this->assertEquals(404, $operationAttempt['headers']['status-code']);

        // Verify User 1 can still add operations to their own transaction
        $operationOwn = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions/' . $transactionId1 . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user1Headers), [
            'operations' => [[
                'action' => 'create',
                'databaseId' => $this->permissionsDatabase,
                'tableId' => $collection['body']['$id'],
                'rowId' => 'legitimateDoc',
                'data' => ['title' => 'Legitimate Document'],
            ]]
        ]);

        $this->assertEquals(201, $operationOwn['headers']['status-code']);
        $this->assertEquals(1, $operationOwn['body']['operations']);
    }

    /**
     * Test that an authenticated user can successfully list their own transactions
     */
    public function testAuthenticatedUserCanListTheirOwnTransactions(): void
    {
        // Create an authenticated user
        $user = $this->getUser();
        $userHeaders = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user['session'],
        ];

        // Create multiple transactions for this user
        $transactionIds = [];
        for ($i = 0; $i < 3; $i++) {
            $transaction = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $userHeaders));

            $this->assertEquals(201, $transaction['headers']['status-code']);
            $this->assertNotEmpty($transaction['body']['$id']);
            $transactionIds[] = $transaction['body']['$id'];
        }

        // List transactions
        $list = $this->client->call(Client::METHOD_GET, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $userHeaders));

        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(3, $list['body']['total']);
        $this->assertIsArray($list['body']['transactions']);
        $this->assertGreaterThanOrEqual(3, count($list['body']['transactions']));

        // Verify all created transactions are in the list
        $listedIds = array_column($list['body']['transactions'], '$id');
        foreach ($transactionIds as $transactionId) {
            $this->assertContains($transactionId, $listedIds);
        }

        // Verify transaction structure
        foreach ($list['body']['transactions'] as $transaction) {
            $this->assertArrayHasKey('$id', $transaction);
            $this->assertArrayHasKey('$createdAt', $transaction);
            $this->assertArrayHasKey('$updatedAt', $transaction);
            $this->assertArrayHasKey('status', $transaction);
            $this->assertArrayHasKey('operations', $transaction);
        }
    }

    /**
     * Test that an authenticated user can successfully delete their own transaction
     */
    public function testAuthenticatedUserCanDeleteTheirOwnTransaction(): void
    {
        // Create an authenticated user
        $user = $this->getUser();
        $userHeaders = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user['session'],
        ];

        // Create a transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $userHeaders));

        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        // Verify transaction exists by reading it
        $read = $this->client->call(Client::METHOD_GET, '/tablesdb/transactions/' . $transactionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $userHeaders));

        $this->assertEquals(200, $read['headers']['status-code']);
        $this->assertEquals($transactionId, $read['body']['$id']);

        // Delete the transaction
        $delete = $this->client->call(Client::METHOD_DELETE, '/tablesdb/transactions/' . $transactionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $userHeaders));

        $this->assertEquals(204, $delete['headers']['status-code']);

        // Verify transaction is deleted by trying to read it again
        $readAfterDelete = $this->client->call(Client::METHOD_GET, '/tablesdb/transactions/' . $transactionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $userHeaders));

        $this->assertEquals(404, $readAfterDelete['headers']['status-code']);

        // Create another transaction and verify it can also be deleted
        $transaction2 = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $userHeaders));

        $this->assertEquals(201, $transaction2['headers']['status-code']);
        $transactionId2 = $transaction2['body']['$id'];

        $delete2 = $this->client->call(Client::METHOD_DELETE, '/tablesdb/transactions/' . $transactionId2, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $userHeaders));

        $this->assertEquals(204, $delete2['headers']['status-code']);
    }
}
