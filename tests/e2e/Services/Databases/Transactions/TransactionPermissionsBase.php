<?php

namespace Tests\E2E\Services\Databases\Transactions;

use Tests\E2E\Client;
use Tests\E2E\Scopes\SchemaPolling;
use Tests\E2E\Traits\DatabasesUrlHelpers;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

trait TransactionPermissionsBase
{
    use DatabasesUrlHelpers;
    use SchemaPolling;

    protected static string $permissionsDatabase = '';

    /**
     * Set up database once for all permission tests in this class
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
    }

    /**
     * Initialize the permissions database if not already done
     */
    protected function ensurePermissionsDatabase(): void
    {
        if (!empty(self::$permissionsDatabase)) {
            return;
        }

        $database = $this->client->call(Client::METHOD_POST, $this->getApiBasePath(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'PermissionsTestDB'
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        self::$permissionsDatabase = $database['body']['$id'];
    }

    /**
     * Get the permissions database ID, creating it if needed
     */
    protected function getPermissionsDatabase(): string
    {
        $this->ensurePermissionsDatabase();
        return self::$permissionsDatabase;
    }

    /**
     * Clean up database after all tests in this class
     */
    public static function tearDownAfterClass(): void
    {
        // Database cleanup is handled by the test framework
        self::$permissionsDatabase = '';
        parent::tearDownAfterClass();
    }

    /**
     * Test collection-level create permission check on staging
     */
    public function testCollectionCreatePermissionDenied(): void
    {
        // Create a collection with no create permission for current user
        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($this->getPermissionsDatabase()), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            $this->getContainerIdParam() => 'permTest1',
            'name' => 'Permission Test 1',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            $this->getSecurityParam() => false,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        if ($this->getSupportForAttributes()) {
            $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($this->getPermissionsDatabase(), $collection['body']['$id'], 'string'), array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'key' => 'title',
                'size' => 255,
                'required' => true,
            ]);

            $this->assertEquals(202, $attribute['headers']['status-code']);
            $this->waitForAllAttributes($this->getPermissionsDatabase(), $collection['body']['$id']);
        }

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);

        // Try to stage a create operation without permission, should fail
        $staged = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transaction['body']['$id']) . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'create',
                'databaseId' => $this->getPermissionsDatabase(),
                $this->getContainerIdParam() => $collection['body']['$id'],
                $this->getRecordIdParam() => 'testDoc1',
                'data' => ['title' => 'Test Document'],
            ]]
        ]);

        // This should fail with 401 Unauthorized
        $this->assertEquals(401, $staged['headers']['status-code']);
    }

    /**
     * Test collection-level update permission check on staging
     */
    public function testCollectionUpdatePermissionDenied(): void
    {
        // Create a collection with create but no update permission
        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($this->getPermissionsDatabase()), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            $this->getContainerIdParam() => 'permTest2',
            'name' => 'Permission Test 2',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
            ],
            $this->getSecurityParam() => false,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        if ($this->getSupportForAttributes()) {
            $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($this->getPermissionsDatabase(), $collection['body']['$id'], 'string'), array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'key' => 'title',
                'size' => 255,
                'required' => true,
            ]);

            $this->assertEquals(202, $attribute['headers']['status-code']);
            $this->waitForAllAttributes($this->getPermissionsDatabase(), $collection['body']['$id']);
        }

        // Create a document first with API key
        $doc = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($this->getPermissionsDatabase(), $collection['body']['$id']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            $this->getRecordIdParam() => 'testDoc2',
            'data' => ['title' => 'Original Title'],
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);

        // Try to stage an update operation without permission, should fail
        $staged = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transaction['body']['$id']) . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'update',
                'databaseId' => $this->getPermissionsDatabase(),
                $this->getContainerIdParam() => $collection['body']['$id'],
                $this->getRecordIdParam() => 'testDoc2',
                'data' => ['title' => 'Updated Title'],
            ]]
        ]);

        // This should fail with 401 Unauthorized
        $this->assertEquals(401, $staged['headers']['status-code']);
    }

    public function testStagedNumericUpdatesWithRowPermissions(): void
    {
        $databaseId = $this->getPermissionsDatabase();
        $userId = $this->getUser()['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
        $admin = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $admin, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'Numeric row permissions',
            'permissions' => [],
            $this->getSecurityParam() => true,
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];
        if ($this->getSupportForAttributes()) {
            $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($databaseId, $collectionId, 'integer'), $admin, [
                'key' => 'balance',
                'required' => true,
            ]);
            $this->assertEquals(202, $attribute['headers']['status-code']);
            $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($databaseId, $collectionId, 'integer'), $admin, [
                'key' => 'bonus',
                'required' => false,
            ]);
            $this->assertEquals(202, $attribute['headers']['status-code']);
            foreach (['name' => false, 'tags' => true] as $key => $array) {
                $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($databaseId, $collectionId, 'string'), $admin, [
                    'key' => $key,
                    'size' => 255,
                    'required' => false,
                    'array' => $array,
                ]);
                $this->assertEquals(202, $attribute['headers']['status-code']);
            }
            $this->waitForAllAttributes($databaseId, $collectionId);
        }
        $permissions = [Permission::update(Role::user($userId))];
        $writable = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $collectionId), $admin, [
            $this->getRecordIdParam() => ID::unique(),
            'data' => ['balance' => 50, 'name' => 'Account', 'tags' => ['account']],
            'permissions' => $permissions,
        ]);
        $readonly = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $collectionId), $admin, [
            $this->getRecordIdParam() => ID::unique(),
            'data' => ['balance' => 50],
            'permissions' => [Permission::read(Role::user($userId))],
        ]);
        $this->assertEquals(201, $writable['headers']['status-code']);
        $this->assertEquals(201, $readonly['headers']['status-code']);
        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), $headers);
        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        // Test for SUCCESS: update-only row permission is sufficient, without read permission.
        $read = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $collectionId, $writable['body']['$id']), $headers);
        $this->assertEquals(404, $read['headers']['status-code']);
        foreach (['increment' => 55, 'decrement' => 50] as $operation => $expected) {
            $response = $this->client->call(Client::METHOD_PATCH, $this->getRecordUrl($databaseId, $collectionId, $writable['body']['$id']) . '/balance/' . $operation, $headers, [
                'value' => 5,
                'transactionId' => $transactionId,
            ]);
            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals($expected, $response['body']['balance']);
            $this->assertEquals($permissions, $response['body']['$permissions']);
        }

        // Test for FAILURE: readable rows without update permission cannot stage either operation.
        foreach (['increment', 'decrement'] as $operation) {
            $response = $this->client->call(Client::METHOD_PATCH, $this->getRecordUrl($databaseId, $collectionId, $readonly['body']['$id']) . '/balance/' . $operation, $headers, [
                'value' => 5,
                'transactionId' => $transactionId,
            ]);
            $this->assertEquals(401, $response['headers']['status-code']);
        }

        // Test for SUCCESS: a null optional numeric column counts from zero.
        $response = $this->client->call(Client::METHOD_PATCH, $this->getRecordUrl($databaseId, $collectionId, $writable['body']['$id']) . '/bonus/increment', $headers, [
            'value' => 5,
            'transactionId' => $transactionId,
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(5, $response['body']['bonus']);

        // Test for FAILURE: nonnumeric strings and arrays fail before another operation can be staged.
        foreach (['name', 'tags'] as $attribute) {
            foreach (['increment', 'decrement'] as $operation) {
                $response = $this->client->call(Client::METHOD_PATCH, $this->getRecordUrl($databaseId, $collectionId, $writable['body']['$id']) . '/' . $attribute . '/' . $operation, $headers, [
                    'value' => 5,
                    'transactionId' => $transactionId,
                ]);
                $this->assertEquals(400, $response['headers']['status-code']);
                $this->assertEquals('attribute_type_invalid', $response['body']['type']);

                $response = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transactionId) . '/operations', $headers, [
                    'operations' => [[
                        'action' => $operation,
                        'databaseId' => $databaseId,
                        $this->getContainerIdParam() => $collectionId,
                        $this->getRecordIdParam() => $writable['body']['$id'],
                        'data' => [$this->getSchemaParam() => $attribute, 'value' => 5],
                    ]],
                ]);
                $this->assertEquals(400, $response['headers']['status-code']);
                $this->assertEquals('attribute_type_invalid', $response['body']['type']);
            }
        }
        $status = $this->client->call(Client::METHOD_GET, $this->getTransactionUrl($transactionId), $headers);
        $this->assertEquals(200, $status['headers']['status-code']);
        $this->assertEquals(3, $status['body']['operations']);
        $staged = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $collectionId, $writable['body']['$id']), $admin, [
            'transactionId' => $transactionId,
        ]);
        $this->assertEquals(200, $staged['headers']['status-code']);
        $this->assertEquals(50, $staged['body']['balance']);
        $this->assertEquals(5, $staged['body']['bonus']);
        $committed = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $collectionId, $writable['body']['$id']), $admin);
        $this->assertEquals(200, $committed['headers']['status-code']);
        $this->assertEquals(50, $committed['body']['balance']);
        $this->assertEquals('Account', $committed['body']['name']);
        $this->assertEquals(['account'], $committed['body']['tags']);
    }

    public function testStagedNumericUpdatesFollowEarlierBatchOperations(): void
    {
        if ($this->getSupportForAttributes()) {
            $this->markTestSkipped('Schemaful adapters validate numeric operations against the column type.');
        }

        $databaseId = $this->getPermissionsDatabase();
        $admin = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $admin, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'Numeric batch',
            'permissions' => [],
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];
        $record = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $collectionId), $admin, [
            $this->getRecordIdParam() => ID::unique(),
            'data' => ['label' => 'text'],
        ]);
        $this->assertEquals(201, $record['headers']['status-code']);
        $operation = fn (string $action, string $recordId, array $data) => [
            'action' => $action,
            'databaseId' => $databaseId,
            $this->getContainerIdParam() => $collectionId,
            $this->getRecordIdParam() => $recordId,
            'data' => $data,
        ];

        // Test for SUCCESS: a field updated to a number earlier in the batch can be incremented.
        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), $admin);
        $this->assertEquals(201, $transaction['headers']['status-code']);
        $response = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transaction['body']['$id']) . '/operations', $admin, [
            'operations' => [
                $operation('update', $record['body']['$id'], ['label' => 5]),
                $operation('increment', $record['body']['$id'], [$this->getSchemaParam() => 'label', 'value' => 1]),
            ],
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals(2, $response['body']['operations']);

        // Test for FAILURE: a field created as a string earlier in the batch cannot be incremented.
        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), $admin);
        $this->assertEquals(201, $transaction['headers']['status-code']);
        $recordId = ID::unique();
        $response = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transaction['body']['$id']) . '/operations', $admin, [
            'operations' => [
                $operation('create', $recordId, ['label' => 'text']),
                $operation('increment', $recordId, [$this->getSchemaParam() => 'label', 'value' => 1]),
            ],
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('attribute_type_invalid', $response['body']['type']);
        $status = $this->client->call(Client::METHOD_GET, $this->getTransactionUrl($transaction['body']['$id']), $admin);
        $this->assertEquals(0, $status['body']['operations']);
    }

    /**
     * Regression: a commit whose write fails authorization at commit time must leave
     * the transaction in the terminal `failed` state, never stuck in `committing`.
     * A staged update is authorized when staged, then the row permission is revoked
     * before commit, so the commit's write is rejected with 401.
     */
    public function testCommitAuthorizationFailureResetsStatus(): void
    {
        $userId = $this->getUser()['$id'];

        // Document security on, and no collection-level update permission, so update
        // is only ever granted at the row level.
        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($this->getPermissionsDatabase()), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'Commit Authorization Failure',
            'permissions' => [
                Permission::read(Role::users()),
            ],
            $this->getSecurityParam() => true,
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        if ($this->getSupportForAttributes()) {
            $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($this->getPermissionsDatabase(), $collectionId, 'string'), array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'key' => 'title',
                'size' => 255,
                'required' => true,
            ]);
            $this->assertEquals(202, $attribute['headers']['status-code']);
            $this->waitForAllAttributes($this->getPermissionsDatabase(), $collectionId);
        }

        // API key creates a row the user is allowed to update at the row level.
        $row = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($this->getPermissionsDatabase(), $collectionId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            $this->getRecordIdParam() => ID::unique(),
            'data' => ['title' => 'Original Title'],
            'permissions' => [
                Permission::read(Role::user($userId)),
                Permission::update(Role::user($userId)),
            ],
        ]);
        $this->assertEquals(201, $row['headers']['status-code']);
        $rowId = $row['body']['$id'];

        // User opens a transaction and stages an update they are currently allowed to make.
        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        $staged = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transactionId) . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'update',
                'databaseId' => $this->getPermissionsDatabase(),
                $this->getContainerIdParam() => $collectionId,
                $this->getRecordIdParam() => $rowId,
                'data' => ['title' => 'Updated Title'],
            ]]
        ]);
        $this->assertEquals(201, $staged['headers']['status-code']);

        // Revoke the user's update permission before they commit.
        $revoke = $this->client->call(Client::METHOD_PATCH, $this->getRecordUrl($this->getPermissionsDatabase(), $collectionId, $rowId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'permissions' => [
                Permission::read(Role::user($userId)),
            ],
        ]);
        $this->assertEquals(200, $revoke['headers']['status-code']);

        // Commit now fails the authorization check at write time.
        $commit = $this->client->call(Client::METHOD_PATCH, $this->getTransactionUrl($transactionId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), ['commit' => true]);
        $this->assertEquals(401, $commit['headers']['status-code']);

        // The transaction must be terminal `failed`, never left stuck in `committing`.
        $status = $this->client->call(Client::METHOD_GET, $this->getTransactionUrl($transactionId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $status['headers']['status-code']);
        $this->assertEquals('failed', $status['body']['status']);

        // The staged write must have rolled back; the row is unchanged.
        $read = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($this->getPermissionsDatabase(), $collectionId, $rowId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
        $this->assertEquals(200, $read['headers']['status-code']);
        $this->assertEquals('Original Title', $read['body']['title']);
    }

    /**
     * Test collection-level delete permission check on staging
     */
    public function testCollectionDeletePermissionDenied(): void
    {
        // Create a collection with create, read but no delete permission
        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($this->getPermissionsDatabase()), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            $this->getContainerIdParam() => 'permTest3',
            'name' => 'Permission Test 3',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            $this->getSecurityParam() => false,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        if ($this->getSupportForAttributes()) {
            $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($this->getPermissionsDatabase(), $collection['body']['$id'], 'string'), array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'key' => 'title',
                'size' => 255,
                'required' => true,
            ]);

            $this->assertEquals(202, $attribute['headers']['status-code']);
            $this->waitForAllAttributes($this->getPermissionsDatabase(), $collection['body']['$id']);
        }

        $doc = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($this->getPermissionsDatabase(), $collection['body']['$id']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            $this->getRecordIdParam() => 'testDoc3',
            'data' => ['title' => 'To Be Deleted'],
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);

        // Try to stage a delete operation without permission, should fail
        $staged = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transaction['body']['$id']) . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'delete',
                'databaseId' => $this->getPermissionsDatabase(),
                $this->getContainerIdParam() => $collection['body']['$id'],
                $this->getRecordIdParam() => 'testDoc3',
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
        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($this->getPermissionsDatabase()), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            $this->getContainerIdParam() => 'permTest4',
            'name' => 'Permission Test 4',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
            $this->getSecurityParam() => true,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        if ($this->getSupportForAttributes()) {
            $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($this->getPermissionsDatabase(), $collection['body']['$id'], 'string'), array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'key' => 'title',
                'size' => 255,
                'required' => true,
            ]);

            $this->assertEquals(202, $attribute['headers']['status-code']);
            $this->waitForAllAttributes($this->getPermissionsDatabase(), $collection['body']['$id']);
        }

        // Create a document with update permission at document level
        $doc = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($this->getPermissionsDatabase(), $collection['body']['$id']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            $this->getRecordIdParam() => 'testDoc4',
            'data' => ['title' => 'Protected Document'],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);

        // Stage an update, should succeed because document has update permission
        $staged = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transaction['body']['$id']) . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'update',
                'databaseId' => $this->getPermissionsDatabase(),
                $this->getContainerIdParam() => $collection['body']['$id'],
                $this->getRecordIdParam() => 'testDoc4',
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
        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($this->getPermissionsDatabase()), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            $this->getContainerIdParam() => 'permTest5',
            'name' => 'Permission Test 5',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            $this->getSecurityParam() => true,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        if ($this->getSupportForAttributes()) {
            $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($this->getPermissionsDatabase(), $collection['body']['$id'], 'string'), array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'key' => 'title',
                'size' => 255,
                'required' => true,
            ]);

            $this->assertEquals(202, $attribute['headers']['status-code']);
            $this->waitForAllAttributes($this->getPermissionsDatabase(), $collection['body']['$id']);
        }

        // Create a document with delete permission at document level
        $doc = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($this->getPermissionsDatabase(), $collection['body']['$id']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            $this->getRecordIdParam() => 'testDoc5',
            'data' => ['title' => 'Can Delete Me'],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);

        // Stage a delete should succeed because document has delete permission
        $staged = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transaction['body']['$id']) . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'delete',
                'databaseId' => $this->getPermissionsDatabase(),
                $this->getContainerIdParam() => $collection['body']['$id'],
                $this->getRecordIdParam() => 'testDoc5',
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
        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($this->getPermissionsDatabase()), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            $this->getContainerIdParam() => 'permTest6',
            'name' => 'Permission Test 6',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            $this->getSecurityParam() => true,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        // Add attribute
        if ($this->getSupportForAttributes()) {
            $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($this->getPermissionsDatabase(), $collection['body']['$id'], 'string'), array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'key' => 'title',
                'size' => 255,
                'required' => true,
            ]);

            $this->assertEquals(202, $attribute['headers']['status-code']);
            $this->waitForAllAttributes($this->getPermissionsDatabase(), $collection['body']['$id']);
        }

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);

        // Try to stage a create with team permissions, current user is not in team
        $staged = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transaction['body']['$id']) . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'create',
                'databaseId' => $this->getPermissionsDatabase(),
                $this->getContainerIdParam() => $collection['body']['$id'],
                $this->getRecordIdParam() => 'testDoc6',
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
        $this->assertArrayHasKey('message', $staged['body']);
        $this->assertStringContainsString('Permissions must be one of', $staged['body']['message']);
    }

    /**
     * Test that staging cannot grant roles the user lacks on a related document
     */
    public function testCannotSetUnauthorizedRelatedPermissions(): void
    {
        if (!$this->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $keyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ];
        $containerPermissions = [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
        ];

        $parent = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($this->getPermissionsDatabase()), $keyHeaders, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'Related Permissions Parent',
            'permissions' => $containerPermissions,
            $this->getSecurityParam() => true,
        ]);
        $this->assertEquals(201, $parent['headers']['status-code']);
        $parentId = $parent['body']['$id'];

        $child = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($this->getPermissionsDatabase()), $keyHeaders, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'Related Permissions Child',
            'permissions' => $containerPermissions,
            $this->getSecurityParam() => true,
        ]);
        $this->assertEquals(201, $child['headers']['status-code']);
        $childId = $child['body']['$id'];

        $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($this->getPermissionsDatabase(), $childId, 'string'), $keyHeaders, [
            'key' => 'title',
            'size' => 255,
            'required' => false,
        ]);
        $this->assertEquals(202, $attribute['headers']['status-code']);
        $this->waitForAttribute($this->getPermissionsDatabase(), $childId, 'title');

        $relationship = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($this->getPermissionsDatabase(), $parentId, 'relationship'), $keyHeaders, [
            $this->getRelatedIdParam() => $childId,
            'type' => 'oneToOne',
            'key' => 'child',
        ]);
        $this->assertEquals(202, $relationship['headers']['status-code']);
        $this->waitForAttribute($this->getPermissionsDatabase(), $parentId, 'child');

        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(201, $transaction['headers']['status-code']);

        $stage = fn (array $permissions) => $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transaction['body']['$id']) . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'create',
                'databaseId' => $this->getPermissionsDatabase(),
                $this->getContainerIdParam() => $parentId,
                $this->getRecordIdParam() => ID::unique(),
                'data' => [
                    'child' => [
                        '$id' => ID::unique(),
                        '$permissions' => $permissions,
                        'title' => 'Child',
                    ],
                ],
            ]]
        ]);

        /**
         * Test for SUCCESS
         */
        $staged = $stage([Permission::read(Role::user($this->getUser()['$id']))]);
        $this->assertEquals(201, $staged['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $staged = $stage([Permission::update(Role::team('adminTeam'))]);
        $this->assertEquals(401, $staged['headers']['status-code']);
        $this->assertStringContainsString('Permissions must be one of', $staged['body']['message']);
    }

    /**
     * Test that a related document deleted in the transaction cannot be recreated with its previous permissions
     */
    public function testCannotRecreateRelatedWithUnauthorizedPermissions(): void
    {
        if (!$this->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $keyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ];
        $userHeaders = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());

        $parent = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($this->getPermissionsDatabase()), $keyHeaders, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'Recreated Related Parent',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
            $this->getSecurityParam() => true,
        ]);
        $this->assertEquals(201, $parent['headers']['status-code']);
        $parentId = $parent['body']['$id'];

        $child = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($this->getPermissionsDatabase()), $keyHeaders, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'Recreated Related Child',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::delete(Role::any()),
            ],
            $this->getSecurityParam() => true,
        ]);
        $this->assertEquals(201, $child['headers']['status-code']);
        $childId = $child['body']['$id'];

        $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($this->getPermissionsDatabase(), $childId, 'string'), $keyHeaders, [
            'key' => 'title',
            'size' => 255,
            'required' => false,
        ]);
        $this->assertEquals(202, $attribute['headers']['status-code']);
        $this->waitForAttribute($this->getPermissionsDatabase(), $childId, 'title');

        $relationship = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($this->getPermissionsDatabase(), $parentId, 'relationship'), $keyHeaders, [
            $this->getRelatedIdParam() => $childId,
            'type' => 'oneToOne',
            'key' => 'child',
        ]);
        $this->assertEquals(202, $relationship['headers']['status-code']);
        $this->waitForAttribute($this->getPermissionsDatabase(), $parentId, 'child');

        $foreign = [Permission::update(Role::team('adminTeam'))];
        $recordId = ID::unique();
        $record = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($this->getPermissionsDatabase(), $childId), $keyHeaders, [
            $this->getRecordIdParam() => $recordId,
            'data' => ['title' => 'Original'],
            'permissions' => $foreign,
        ]);
        $this->assertEquals(201, $record['headers']['status-code']);

        $delete = [
            'action' => 'delete',
            'databaseId' => $this->getPermissionsDatabase(),
            $this->getContainerIdParam() => $childId,
            $this->getRecordIdParam() => $recordId,
        ];
        $recreate = [
            'action' => 'create',
            'databaseId' => $this->getPermissionsDatabase(),
            $this->getContainerIdParam() => $parentId,
            $this->getRecordIdParam() => ID::unique(),
            'data' => [
                'child' => [
                    '$id' => $recordId,
                    '$permissions' => $foreign,
                    'title' => 'Injected',
                ],
            ],
        ];

        /**
         * Test for FAILURE
         */
        // Delete staged in an earlier request is visible while staging.
        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), $userHeaders);
        $this->assertEquals(201, $transaction['headers']['status-code']);

        $staged = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transaction['body']['$id']) . '/operations', $userHeaders, [
            'operations' => [$delete],
        ]);
        $this->assertEquals(201, $staged['headers']['status-code']);

        $staged = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transaction['body']['$id']) . '/operations', $userHeaders, [
            'operations' => [$recreate],
        ]);
        $this->assertEquals(401, $staged['headers']['status-code']);
        $this->assertStringContainsString('Permissions must be one of', $staged['body']['message']);

        // Delete staged in the same request is caught when the commit applies it.
        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), $userHeaders);
        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        $staged = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transactionId) . '/operations', $userHeaders, [
            'operations' => [$delete, $recreate],
        ]);
        $this->assertEquals(201, $staged['headers']['status-code']);

        $commit = $this->client->call(Client::METHOD_PATCH, $this->getTransactionUrl($transactionId), $userHeaders, ['commit' => true]);
        $this->assertEquals(401, $commit['headers']['status-code']);

        $status = $this->client->call(Client::METHOD_GET, $this->getTransactionUrl($transactionId), $userHeaders);
        $this->assertEquals(200, $status['headers']['status-code']);
        $this->assertEquals('failed', $status['body']['status']);

        $read = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($this->getPermissionsDatabase(), $childId, $recordId), $keyHeaders);
        $this->assertEquals(200, $read['headers']['status-code']);
        $this->assertEquals('Original', $read['body']['title']);

        /**
         * Test for SUCCESS
         */
        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), $userHeaders);
        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        $recreate['data']['child']['$permissions'] = [Permission::read(Role::user($this->getUser()['$id']))];
        $staged = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transactionId) . '/operations', $userHeaders, [
            'operations' => [$delete, $recreate],
        ]);
        $this->assertEquals(201, $staged['headers']['status-code']);

        $commit = $this->client->call(Client::METHOD_PATCH, $this->getTransactionUrl($transactionId), $userHeaders, ['commit' => true]);
        $this->assertEquals(200, $commit['headers']['status-code']);

        $read = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($this->getPermissionsDatabase(), $childId, $recordId), $keyHeaders);
        $this->assertEquals(200, $read['headers']['status-code']);
        $this->assertEquals('Injected', $read['body']['title']);
        $this->assertEquals([Permission::read(Role::user($this->getUser()['$id']))], $read['body']['$permissions']);
    }

    /**
     * Test successful staging when user has the required permissions
     */
    public function testSuccessfulStagingWithProperPermissions(): void
    {
        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($this->getPermissionsDatabase()), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            $this->getContainerIdParam() => 'permTest7',
            'name' => 'Permission Test 7',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            $this->getSecurityParam() => true,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        if ($this->getSupportForAttributes()) {
            $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($this->getPermissionsDatabase(), $collection['body']['$id'], 'string'), array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'key' => 'title',
                'size' => 255,
                'required' => true,
            ]);

            $this->assertEquals(202, $attribute['headers']['status-code']);
            $this->waitForAllAttributes($this->getPermissionsDatabase(), $collection['body']['$id']);
        }

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);

        // Stage a create with permissions for current user's roles, should succeed
        $staged = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transaction['body']['$id']) . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'create',
                'databaseId' => $this->getPermissionsDatabase(),
                $this->getContainerIdParam() => $collection['body']['$id'],
                $this->getRecordIdParam() => 'testDoc7',
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
        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($this->getPermissionsDatabase()), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            $this->getContainerIdParam() => 'permTest8',
            'name' => 'Permission Test 8',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            $this->getSecurityParam() => false,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        if ($this->getSupportForAttributes()) {
            $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($this->getPermissionsDatabase(), $collection['body']['$id'], 'string'), array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'key' => 'title',
                'size' => 255,
                'required' => true,
            ]);

            $this->assertEquals(202, $attribute['headers']['status-code']);
            $this->waitForAllAttributes($this->getPermissionsDatabase(), $collection['body']['$id']);
        }

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);

        // Try to update a document that doesn't exist - should fail
        $staged = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transaction['body']['$id']) . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'update',
                'databaseId' => $this->getPermissionsDatabase(),
                $this->getContainerIdParam() => $collection['body']['$id'],
                $this->getRecordIdParam() => 'nonExistentDoc',
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
        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($this->getPermissionsDatabase()), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            $this->getContainerIdParam() => 'permTest9',
            'name' => 'Permission Test 9',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            $this->getSecurityParam() => false,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        if ($this->getSupportForAttributes()) {
            $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($this->getPermissionsDatabase(), $collection['body']['$id'], 'string'), array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'key' => 'title',
                'size' => 255,
                'required' => true,
            ]);

            $this->assertEquals(202, $attribute['headers']['status-code']);
            $this->waitForAllAttributes($this->getPermissionsDatabase(), $collection['body']['$id']);
        }

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);

        // Try to delete a document that doesn't exist, should fail
        $staged = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transaction['body']['$id']) . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'delete',
                'databaseId' => $this->getPermissionsDatabase(),
                $this->getContainerIdParam() => $collection['body']['$id'],
                $this->getRecordIdParam() => 'nonExistentDoc',
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
        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($this->getPermissionsDatabase()), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            $this->getContainerIdParam() => 'permTest10',
            'name' => 'Permission Test 10',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            $this->getSecurityParam() => false,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        if ($this->getSupportForAttributes()) {
            $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($this->getPermissionsDatabase(), $collection['body']['$id'], 'string'), array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'key' => 'title',
                'size' => 255,
                'required' => true,
            ]);

            $this->assertEquals(202, $attribute['headers']['status-code']);
            $this->waitForAllAttributes($this->getPermissionsDatabase(), $collection['body']['$id']);
        }

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);

        // Batch 1: Create a document
        $batch1 = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transaction['body']['$id']) . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'create',
                'databaseId' => $this->getPermissionsDatabase(),
                $this->getContainerIdParam() => $collection['body']['$id'],
                $this->getRecordIdParam() => 'crossBatchDoc',
                'data' => [
                    'title' => 'Initial Title',
                ],
            ]]
        ]);

        $this->assertEquals(201, $batch1['headers']['status-code']);
        $this->assertEquals(1, $batch1['body']['operations']);

        // Batch 2: Update the document created in batch 1
        $batch2 = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transaction['body']['$id']) . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'update',
                'databaseId' => $this->getPermissionsDatabase(),
                $this->getContainerIdParam() => $collection['body']['$id'],
                $this->getRecordIdParam() => 'crossBatchDoc',
                'data' => [
                    'title' => 'Updated Title',
                ],
            ]]
        ]);

        // This should succeed with 201 because transactionState finds the staged document from batch 1
        $this->assertEquals(201, $batch2['headers']['status-code']);
        $this->assertEquals(2, $batch2['body']['operations']);

        // Batch 3: Delete the same document
        $batch3 = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transaction['body']['$id']) . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [[
                'action' => 'delete',
                'databaseId' => $this->getPermissionsDatabase(),
                $this->getContainerIdParam() => $collection['body']['$id'],
                $this->getRecordIdParam() => 'crossBatchDoc',
                'data' => [],
            ]]
        ]);

        // This should also succeed with 201
        $this->assertEquals(201, $batch3['headers']['status-code']);
        $this->assertEquals(3, $batch3['body']['operations']);

        // Rollback to clean up
        $rollback = $this->client->call(Client::METHOD_PATCH, $this->getTransactionUrl($transaction['body']['$id']), array_merge([
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

        $transaction1 = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
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
        $readAttempt = $this->client->call(Client::METHOD_GET, $this->getTransactionUrl($transactionId1), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user2Headers));

        // This should fail with 404 Not Found (transaction doesn't exist for this user)
        $this->assertEquals(404, $readAttempt['headers']['status-code']);

        // Verify User 1 can still read their own transaction
        $readOwn = $this->client->call(Client::METHOD_GET, $this->getTransactionUrl($transactionId1), array_merge([
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

        $transaction1 = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user1Headers));

        $this->assertEquals(201, $transaction1['headers']['status-code']);

        $transaction2 = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
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

        $transaction3 = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user2Headers));

        $this->assertEquals(201, $transaction3['headers']['status-code']);

        // User 2 lists transactions - should only see their own
        $listUser2 = $this->client->call(Client::METHOD_GET, $this->getTransactionUrl(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user2Headers));

        $this->assertEquals(200, $listUser2['headers']['status-code']);
        $this->assertEquals(1, $listUser2['body']['total']);
        $this->assertEquals($transaction3['body']['$id'], $listUser2['body']['transactions'][0]['$id']);

        // User 1 lists transactions - should only see their own (2 transactions)
        $listUser1 = $this->client->call(Client::METHOD_GET, $this->getTransactionUrl(), array_merge([
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

        $transaction1 = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
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
        $commitAttempt = $this->client->call(Client::METHOD_PATCH, $this->getTransactionUrl($transactionId1), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user2Headers), [
            'commit' => true,
        ]);

        // This should fail with 404 Not Found
        $this->assertEquals(404, $commitAttempt['headers']['status-code']);

        // User 2 tries to rollback User 1's transaction - should also fail
        $rollbackAttempt = $this->client->call(Client::METHOD_PATCH, $this->getTransactionUrl($transactionId1), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user2Headers), [
            'rollback' => true,
        ]);

        // This should also fail with 404 Not Found
        $this->assertEquals(404, $rollbackAttempt['headers']['status-code']);

        // Verify User 1 can still commit their own transaction
        $commitOwn = $this->client->call(Client::METHOD_PATCH, $this->getTransactionUrl($transactionId1), array_merge([
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

        $transaction1 = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
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
        $deleteAttempt = $this->client->call(Client::METHOD_DELETE, $this->getTransactionUrl($transactionId1), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user2Headers));

        // This should fail with 404 Not Found
        $this->assertEquals(404, $deleteAttempt['headers']['status-code']);

        // Verify User 1 can still access their transaction
        $readOwn = $this->client->call(Client::METHOD_GET, $this->getTransactionUrl($transactionId1), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user1Headers));

        $this->assertEquals(200, $readOwn['headers']['status-code']);

        // User 1 can delete their own transaction
        $deleteOwn = $this->client->call(Client::METHOD_DELETE, $this->getTransactionUrl($transactionId1), array_merge([
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
        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($this->getPermissionsDatabase()), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            $this->getContainerIdParam() => 'permTest11',
            'name' => 'Permission Test 11',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            $this->getSecurityParam() => false,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        if ($this->getSupportForAttributes()) {
            $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($this->getPermissionsDatabase(), $collection['body']['$id'], 'string'), array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'key' => 'title',
                'size' => 255,
                'required' => true,
            ]);

            $this->assertEquals(202, $attribute['headers']['status-code']);
            $this->waitForAllAttributes($this->getPermissionsDatabase(), $collection['body']['$id']);
        }

        // Create user 1 (fresh) and their transaction
        $user1 = $this->getUser(true);
        $user1Headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ];

        $transaction1 = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
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
        $operationAttempt = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transactionId1) . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user2Headers), [
            'operations' => [[
                'action' => 'create',
                'databaseId' => $this->getPermissionsDatabase(),
                $this->getContainerIdParam() => $collection['body']['$id'],
                $this->getRecordIdParam() => 'maliciousDoc',
                'data' => ['title' => 'Malicious Document'],
            ]]
        ]);

        // This should fail with 404 Not Found
        $this->assertEquals(404, $operationAttempt['headers']['status-code']);

        // Verify User 1 can still add operations to their own transaction
        $operationOwn = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transactionId1) . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user1Headers), [
            'operations' => [[
                'action' => 'create',
                'databaseId' => $this->getPermissionsDatabase(),
                $this->getContainerIdParam() => $collection['body']['$id'],
                $this->getRecordIdParam() => 'legitimateDoc',
                'data' => ['title' => 'Legitimate Document'],
            ]]
        ]);

        $this->assertEquals(201, $operationOwn['headers']['status-code']);
        $this->assertEquals(1, $operationOwn['body']['operations']);
    }

    /**
     * Test that a leaked transaction ID does not expose another user's staged documents
     */
    public function testUserCannotReadAnotherUsersStagedDocuments(): void
    {
        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($this->getPermissionsDatabase()), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'Staged Read Test',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            $this->getSecurityParam() => false,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        if ($this->getSupportForAttributes()) {
            $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($this->getPermissionsDatabase(), $collectionId, 'string'), array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'key' => 'title',
                'size' => 255,
                'required' => true,
            ]);

            $this->assertEquals(202, $attribute['headers']['status-code']);
            $this->waitForAllAttributes($this->getPermissionsDatabase(), $collectionId);
        }

        $user1 = $this->getUser(true);
        $user1Headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ];

        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user1Headers));

        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        $documentId = ID::unique();
        $staged = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transactionId) . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user1Headers), [
            'operations' => [[
                'action' => 'create',
                'databaseId' => $this->getPermissionsDatabase(),
                $this->getContainerIdParam() => $collectionId,
                $this->getRecordIdParam() => $documentId,
                'data' => ['title' => 'Staged secret'],
            ]]
        ]);

        $this->assertEquals(201, $staged['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $ownRead = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($this->getPermissionsDatabase(), $collectionId, $documentId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user1Headers), [
            'transactionId' => $transactionId,
        ]);

        $this->assertEquals(200, $ownRead['headers']['status-code']);
        $this->assertEquals('Staged secret', $ownRead['body']['title']);

        /**
         * Test for FAILURE
         */
        $user2 = $this->getUser(true);
        $user2Headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ];

        $foreignRead = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($this->getPermissionsDatabase(), $collectionId, $documentId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user2Headers), [
            'transactionId' => $transactionId,
        ]);

        $this->assertEquals(404, $foreignRead['headers']['status-code']);

        $foreignList = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($this->getPermissionsDatabase(), $collectionId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $user2Headers), [
            'transactionId' => $transactionId,
        ]);

        $this->assertEquals(200, $foreignList['headers']['status-code']);
        $this->assertEquals(0, $foreignList['body']['total']);
        $this->assertNotContains($documentId, \array_column($foreignList['body'][$this->getRecordResource()], '$id'));
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
            $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $userHeaders));

            $this->assertEquals(201, $transaction['headers']['status-code']);
            $this->assertNotEmpty($transaction['body']['$id']);
            $transactionIds[] = $transaction['body']['$id'];
        }

        // List transactions
        $list = $this->client->call(Client::METHOD_GET, $this->getTransactionUrl(), array_merge([
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
        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $userHeaders));

        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        // Verify transaction exists by reading it
        $read = $this->client->call(Client::METHOD_GET, $this->getTransactionUrl($transactionId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $userHeaders));

        $this->assertEquals(200, $read['headers']['status-code']);
        $this->assertEquals($transactionId, $read['body']['$id']);

        // Delete the transaction
        $delete = $this->client->call(Client::METHOD_DELETE, $this->getTransactionUrl($transactionId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $userHeaders));

        $this->assertEquals(204, $delete['headers']['status-code']);

        // Verify transaction is deleted by trying to read it again
        $readAfterDelete = $this->client->call(Client::METHOD_GET, $this->getTransactionUrl($transactionId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $userHeaders));

        $this->assertEquals(404, $readAfterDelete['headers']['status-code']);

        // Create another transaction and verify it can also be deleted
        $transaction2 = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $userHeaders));

        $this->assertEquals(201, $transaction2['headers']['status-code']);
        $transactionId2 = $transaction2['body']['$id'];

        $delete2 = $this->client->call(Client::METHOD_DELETE, $this->getTransactionUrl($transactionId2), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $userHeaders));

        $this->assertEquals(204, $delete2['headers']['status-code']);
    }
}
