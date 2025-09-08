<?php

namespace Tests\E2E\Services\Databases\Transactions;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

class TransactionsTest extends Scope
{
    use ProjectCustom;
    use SideClient;

    /**
     * Test creating a transaction
     */
    public function testCreate(): void
    {
        // Create database first
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'TransactionTestDatabase'
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Test creating a transaction with default TTL
        $response = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertArrayHasKey('$id', $response['body']);
        $this->assertArrayHasKey('status', $response['body']);
        $this->assertArrayHasKey('operations', $response['body']);
        $this->assertArrayHasKey('expiresAt', $response['body']);
        $this->assertEquals('pending', $response['body']['status']);
        $this->assertEquals(0, $response['body']['operations']);

        $transactionId1 = $response['body']['$id'];

        // Test creating a transaction with custom TTL
        $response = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'ttl' => 900
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('pending', $response['body']['status']);

        $expiresAt = new \DateTime($response['body']['expiresAt']);
        $now = new \DateTime();
        $diff = $expiresAt->getTimestamp() - $now->getTimestamp();
        $this->assertGreaterThan(800, $diff);
        $this->assertLessThan(1000, $diff);

        $transactionId2 = $response['body']['$id'];

        // Test invalid TTL values
        $response = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'ttl' => 30 // Below minimum
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'ttl' => 4000 // Above maximum
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    /**
     * Test adding operations to a transaction
     */
    public function testAddOperations(): void
    {
        // Create database first
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'TransactionOperationsTestDB'
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        // Create a collection for testing
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TransactionOperationsTest',
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Add attributes
        $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code']);

        // Wait for attribute to be created
        sleep(2);

        // Add valid operations
        $response = $this->client->call(Client::METHOD_POST, "/databases/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => 'doc1',
                    'data' => [
                        'name' => 'Test Document 1'
                    ]
                ],
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => 'doc2',
                    'data' => [
                        'name' => 'Test Document 2'
                    ]
                ]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals(2, $response['body']['operations']);

        // Test adding more operations
        $response = $this->client->call(Client::METHOD_POST, "/databases/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'update',
                    'documentId' => 'doc1',
                    'data' => [
                        'name' => 'Updated Document 1'
                    ]
                ]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals(3, $response['body']['operations']);

        // Test invalid database ID
        $response = $this->client->call(Client::METHOD_POST, "/databases/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => 'invalid_database',
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => ID::unique(),
                    'data' => ['name' => 'Test']
                ]
            ]
        ]);

        $this->assertEquals(404, $response['headers']['status-code'], 'Invalid database should return 404. Got: ' . json_encode($response['body']));

        // Test invalid collection ID
        $response = $this->client->call(Client::METHOD_POST, "/databases/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'collectionId' => 'invalid_collection',
                    'action' => 'create',
                    'documentId' => ID::unique(),
                    'data' => ['name' => 'Test']
                ]
            ]
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    /**
     * Test committing a transaction
     */
    public function testCommit(): void
    {
        // Create database first
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'TransactionCommitTestDB'
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create collection
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TransactionCommitTest',
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Add attributes
        $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code']);
        sleep(2);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        // Add operations
        $response = $this->client->call(Client::METHOD_POST, "/databases/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => 'doc1',
                    'data' => [
                        'name' => 'Test Document 1'
                    ]
                ],
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => 'doc2',
                    'data' => [
                        'name' => 'Test Document 2'
                    ]
                ],
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'update',
                    'documentId' => 'doc1',
                    'data' => [
                        'name' => 'Updated Document 1'
                    ]
                ]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals(3, $response['body']['operations']);

        // Commit the transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('committed', $response['body']['status']);

        // Verify documents were created
        $documents = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(2, $documents['body']['total']);

        // Verify the update was applied
        $doc1Found = false;
        foreach ($documents['body']['documents'] as $doc) {
            if ($doc['$id'] === 'doc1') {
                $this->assertEquals('Updated Document 1', $doc['name']);
                $doc1Found = true;
            }
        }
        $this->assertTrue($doc1Found, 'Document doc1 should exist with updated name');

        // Test committing already committed transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    /**
     * Test rolling back a transaction
     */
    public function testRollback(): void
    {
        // Create database first
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'TransactionRollbackTestDB'
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        // Create a collection for rollback test
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TransactionRollbackTest',
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Add attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'value',
            'size' => 256,
            'required' => true,
        ]);

        sleep(2);

        // Add operations
        $response = $this->client->call(Client::METHOD_POST, "/databases/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => 'rollback_doc',
                    'data' => [
                        'value' => 'Should not exist'
                    ]
                ]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Rollback the transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'rollback' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('rolledBack', $response['body']['status']);

        // Verify no documents were created
        $documents = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(0, $documents['body']['total']);
    }

    /**
     * Test transaction expiration
     */
    public function testTransactionExpiration(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'ExpirationTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attribute
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'data',
            'size' => 256,
            'required' => false,
        ]);

        sleep(2);

        // Create transaction with minimum TTL (60 seconds)
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'ttl' => 60
        ]);

        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        // Add operation
        $response = $this->client->call(Client::METHOD_POST, "/databases/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => ID::unique(),
                    'data' => ['data' => 'Should expire']
                ]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Verify transaction was created with correct expiration
        $txnDetails = $this->client->call(Client::METHOD_GET, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $txnDetails['headers']['status-code']);
        $this->assertEquals('pending', $txnDetails['body']['status']);

        // Verify expiration time is approximately 60 seconds from now
        $expiresAt = new \DateTime($txnDetails['body']['expiresAt']);
        $now = new \DateTime();
        $diff = $expiresAt->getTimestamp() - $now->getTimestamp();
        $this->assertGreaterThan(55, $diff);
        $this->assertLessThan(65, $diff);
    }

    /**
     * Test maximum operations per transaction
     */
    public function testTransactionSizeLimit(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'SizeLimitTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [Permission::create(Role::any())],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attribute
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'value',
            'size' => 256,
            'required' => false,
        ]);

        sleep(2);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];

        // Try to add operations exceeding the limit (assuming limit is 100)
        // We'll add 50 operations twice to test incremental limit
        $operations = [];
        for ($i = 0; $i < 50; $i++) {
            $operations[] = [
                'databaseId' => $databaseId,
                'collectionId' => $collectionId,
                'action' => 'create',
                'documentId' => 'doc_' . $i,
                'data' => ['value' => 'Test ' . $i]
            ];
        }

        // First batch should succeed
        $response = $this->client->call(Client::METHOD_POST, "/databases/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => $operations
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals(50, $response['body']['operations']);

        // Second batch of 50 more operations
        $operations = [];
        for ($i = 50; $i < 100; $i++) {
            $operations[] = [
                'databaseId' => $databaseId,
                'collectionId' => $collectionId,
                'documentId' => 'doc_' . $i,
                'action' => 'create',
                'data' => ['value' => 'Test ' . $i]
            ];
        }

        $response = $this->client->call(Client::METHOD_POST, "/databases/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => $operations
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals(100, $response['body']['operations']);

        // Try to add one more operation - should fail
        $response = $this->client->call(Client::METHOD_POST, "/databases/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => 'doc_overflow',
                    'data' => ['value' => 'This should fail']
                ]
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    /**
     * Test concurrent transactions with conflicting operations
     */
    public function testConcurrentTransactionConflicts(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'ConflictTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attribute
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/integer", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'counter',
            'required' => true,
            'min' => 0,
            'max' => 1000000,
        ]);

        sleep(2);

        // Create initial document
        $doc = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'shared_doc',
            'data' => ['counter' => 100]
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);

        // Create two transactions
        $txn1 = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $txn2 = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId1 = $txn1['body']['$id'];
        $transactionId2 = $txn2['body']['$id'];

        // Both transactions try to update the same document
        $this->client->call(Client::METHOD_POST, "/databases/transactions/{$transactionId1}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'update',
                    'documentId' => 'shared_doc',
                    'data' => ['counter' => 200]
                ]
            ]
        ]);

        $this->client->call(Client::METHOD_POST, "/databases/transactions/{$transactionId2}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'update',
                    'documentId' => 'shared_doc',
                    'data' => ['counter' => 300]
                ]
            ]
        ]);

        // Commit first transaction
        $response1 = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId1}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response1['headers']['status-code']);

        // Commit second transaction - should fail with conflict
        $response2 = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId2}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(409, $response2['headers']['status-code']); // Conflict

        // Verify the document has the value from first transaction
        $doc = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/shared_doc", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $doc['body']['counter']);
    }

    /**
     * Test deleting a document that's being updated in a transaction
     */
    public function testDeleteDocumentDuringTransaction(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'DeleteConflictDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attribute
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'data',
            'size' => 256,
            'required' => false,
        ]);

        sleep(2);

        // Create document
        $doc = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'target_doc',
            'data' => ['data' => 'Original']
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];

        // Add update operation to transaction
        $this->client->call(Client::METHOD_POST, "/databases/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'update',
                    'documentId' => 'target_doc',
                    'data' => ['data' => 'Updated in transaction']
                ]
            ]
        ]);

        // Delete the document outside of transaction
        $response = $this->client->call(Client::METHOD_DELETE, "/databases/{$databaseId}/collections/{$collectionId}/documents/target_doc", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $response['headers']['status-code']);

        // Try to commit transaction - should fail because document no longer exists
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(409, $response['headers']['status-code']); // Conflict
    }

    /**
     * Test bulk operations in transactions
     */
    public function testBulkOperations(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'BulkOpsDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attributes
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'category',
            'size' => 256,
            'required' => true,
        ]);

        sleep(3);

        // Create some initial documents
        for ($i = 1; $i <= 5; $i++) {
            $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'documentId' => 'existing_' . $i,
                'data' => [
                    'name' => 'Existing ' . $i,
                    'category' => 'old'
                ]
            ]);
        }

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];

        // Add bulk operations
        $response = $this->client->call(Client::METHOD_POST, "/databases/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                // Bulk create
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'bulkCreate',
                    'data' => [
                        ['$id' => 'bulk_1', 'name' => 'Bulk 1', 'category' => 'new'],
                        ['$id' => 'bulk_2', 'name' => 'Bulk 2', 'category' => 'new'],
                        ['$id' => 'bulk_3', 'name' => 'Bulk 3', 'category' => 'new'],
                    ]
                ],
                // Bulk update
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'bulkUpdate',
                    'data' => [
                        'queries' => [Query::equal('category', ['old'])->toString()],
                        'data' => ['category' => 'updated']
                    ]
                ],
                // Bulk delete
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'bulkDelete',
                    'data' => [
                        'queries' => [Query::equal('name', ['Existing 5'])->toString()]
                    ]
                ]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Verify results
        $documents = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        // Should have 7 documents (5 existing - 1 deleted + 3 new)
        $this->assertEquals(7, $documents['body']['total']);

        // Check categories were updated
        $oldCategoryCount = 0;
        $updatedCategoryCount = 0;
        $newCategoryCount = 0;

        foreach ($documents['body']['documents'] as $doc) {
            switch ($doc['category']) {
                case 'old':
                    $oldCategoryCount++;
                    break;
                case 'updated':
                    $updatedCategoryCount++;
                    break;
                case 'new':
                    $newCategoryCount++;
                    break;
            }
        }

        $this->assertEquals(0, $oldCategoryCount);
        $this->assertEquals(4, $updatedCategoryCount); // 4 existing docs updated
        $this->assertEquals(3, $newCategoryCount); // 3 new docs
    }

    /**
     * Test transaction with mixed success and failure operations
     */
    public function testPartialFailureRollback(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'PartialFailureDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attributes with constraints
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'email',
            'size' => 256,
            'required' => true,
        ]);

        sleep(2);

        // Create unique index on email
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/indexes", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'unique_email',
            'type' => 'unique',
            'attributes' => ['email'],
        ]);

        sleep(2);

        // Create an existing document
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => ID::unique(),
            'data' => ['email' => 'existing@example.com']
        ]);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];

        // Add operations - mix of valid and invalid
        $response = $this->client->call(Client::METHOD_POST, "/databases/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => ID::unique(),
                    'data' => ['email' => 'valid1@example.com'] // Valid
                ],
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => ID::unique(),
                    'data' => ['email' => 'valid2@example.com'] // Valid
                ],
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => ID::unique(),
                    'data' => ['email' => 'existing@example.com'] // Will fail - duplicate
                ],
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => ID::unique(),
                    'data' => ['email' => 'valid3@example.com'] // Would be valid but should rollback
                ],
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Try to commit - should fail and rollback all operations
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(409, $response['headers']['status-code']); // Conflict due to duplicate

        // Verify NO new documents were created (atomicity)
        $documents = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(1, $documents['body']['total']); // Only the original document
        $this->assertEquals('existing@example.com', $documents['body']['documents'][0]['email']);
    }

    /**
     * Test double commit/rollback attempts
     */
    public function testDoubleCommitRollback(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'DoubleCommitDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [Permission::create(Role::any())],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attribute
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'data',
            'size' => 256,
            'required' => false,
        ]);

        sleep(2);

        // Test double commit
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];

        // Add operation
        $this->client->call(Client::METHOD_POST, "/databases/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => ID::unique(),
                    'data' => ['data' => 'Test']
                ]
            ]
        ]);

        // First commit
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Second commit attempt - should fail
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(400, $response['headers']['status-code']); // Bad request - already committed

        // Test double rollback
        $transaction2 = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId2 = $transaction2['body']['$id'];

        // First rollback
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId2}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'rollback' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Second rollback attempt - should fail
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId2}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'rollback' => true
        ]);

        $this->assertEquals(400, $response['headers']['status-code']); // Bad request - already rolled back
    }

    /**
     * Test operations on non-existent documents
     */
    public function testOperationsOnNonExistentDocuments(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'NonExistentDocDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attribute
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'data',
            'size' => 256,
            'required' => false,
        ]);

        sleep(2);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];

        // Try to update non-existent document
        $response = $this->client->call(Client::METHOD_POST, "/databases/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'update',
                    'documentId' => 'non_existent_doc',
                    'data' => ['data' => 'Should fail']
                ]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']); // Operation added

        // Commit should fail
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(404, $response['headers']['status-code']); // Document not found

        // Test delete non-existent document
        $transaction2 = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId2 = $transaction2['body']['$id'];

        $response = $this->client->call(Client::METHOD_POST, "/databases/transactions/{$transactionId2}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'delete',
                    'documentId' => 'non_existent_doc',
                    'data' => []
                ]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Commit should fail
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId2}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(404, $response['headers']['status-code']); // Document not found
    }

    /**
     * Test createDocument with transactionId via normal route
     */
    public function testCreateDocument(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'WriteRoutesTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attributes
        $attributes = [
            ['key' => 'name', 'type' => 'string', 'size' => 256, 'required' => true],
            ['key' => 'counter', 'type' => 'integer', 'required' => false, 'min' => 0, 'max' => 10000],
            ['key' => 'category', 'type' => 'string', 'size' => 256, 'required' => false],
            ['key' => 'data', 'type' => 'string', 'size' => 256, 'required' => false],
        ];

        foreach ($attributes as $attr) {
            $type = $attr['type'];
            unset($attr['type']);

            $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/{$type}", array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), $attr);

            $this->assertEquals(202, $response['headers']['status-code']);
        }

        sleep(3);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        // Create document via normal route with transactionId
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'doc_from_route',
            'data' => [
                'name' => 'Created via normal route',
                'counter' => 100,
                'category' => 'test'
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Document should not exist outside transaction yet
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_from_route", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Document should now exist
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_from_route", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Created via normal route', $response['body']['name']);
    }

    /**
     * Test updateDocument with transactionId via normal route
     */
    public function testUpdateDocument(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'UpdateRouteTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attributes
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/integer", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'counter',
            'required' => false,
            'min' => 0,
            'max' => 10000,
        ]);

        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'category',
            'size' => 256,
            'required' => false,
        ]);

        sleep(3);

        // Create document outside transaction
        $doc = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'doc_to_update',
            'data' => [
                'name' => 'Original name',
                'counter' => 50,
                'category' => 'original'
            ]
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];

        // Update document via normal route with transactionId
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_to_update", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'name' => 'Updated via normal route',
                'counter' => 150,
                'category' => 'updated'
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Document should still have original values outside transaction
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_to_update", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals('Original name', $response['body']['name']);
        $this->assertEquals(50, $response['body']['counter']);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Document should now have updated values
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_to_update", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals('Updated via normal route', $response['body']['name']);
        $this->assertEquals(150, $response['body']['counter']);
    }

    /**
     * Test upsertDocument with transactionId via normal route
     */
    public function testUpsertDocument(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'UpsertRouteTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attributes
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/integer", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'counter',
            'required' => false,
            'min' => 0,
            'max' => 10000,
        ]);

        sleep(3);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];

        // Upsert document (create) via normal route with transactionId
        $response = $this->client->call(Client::METHOD_PUT, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_upsert", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'doc_upsert',
            'data' => [
                'name' => 'Created by upsert',
                'counter' => 25
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Document should not exist outside transaction yet
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_upsert", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        // Upsert same document (update) in same transaction
        $response = $this->client->call(Client::METHOD_PUT, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_upsert", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'doc_upsert',
            'data' => [
                'name' => 'Updated by upsert',
                'counter' => 75
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(201, $response['headers']['status-code']); // Upsert in transaction returns 201

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Document should now exist with updated values
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_upsert", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Updated by upsert', $response['body']['name']);
        $this->assertEquals(75, $response['body']['counter']);
    }

    /**
     * Test deleteDocument with transactionId via normal route
     */
    public function testDeleteDocument(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'DeleteRouteTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attribute
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        sleep(2);

        // Create document outside transaction
        $doc = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'doc_to_delete',
            'data' => ['name' => 'Will be deleted']
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];

        // Delete document via normal route with transactionId
        $response = $this->client->call(Client::METHOD_DELETE, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_to_delete", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(204, $response['headers']['status-code']);

        // Document should still exist outside transaction
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_to_delete", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Document should no longer exist
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_to_delete", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    /**
     * Test bulkCreate with transactionId via normal route
     */
    public function testBulkCreate(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'BulkCreateTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attributes
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'category',
            'size' => 256,
            'required' => false,
        ]);

        sleep(3);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];

        // Bulk create via normal route with transactionId
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documents' => [
                [
                    '$id' => 'bulk_create_1',
                    'name' => 'Bulk created 1',
                    'category' => 'bulk_created'
                ],
                [
                    '$id' => 'bulk_create_2',
                    'name' => 'Bulk created 2',
                    'category' => 'bulk_created'
                ],
                [
                    '$id' => 'bulk_create_3',
                    'name' => 'Bulk created 3',
                    'category' => 'bulk_created'
                ]
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(200, $response['headers']['status-code']); // Bulk operations return 200

        // Documents should not exist outside transaction yet
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('category', ['bulk_created'])->toString()]
        ]);

        $this->assertEquals(0, $response['body']['total']);

        // Individual document check
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/bulk_create_1", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Documents should now exist
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('category', ['bulk_created'])->toString()]
        ]);

        $this->assertEquals(3, $response['body']['total']);

        // Verify individual documents
        for ($i = 1; $i <= 3; $i++) {
            $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/bulk_create_{$i}", array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals("Bulk created {$i}", $response['body']['name']);
            $this->assertEquals('bulk_created', $response['body']['category']);
        }
    }

    /**
     * Test bulkUpdate with transactionId via normal route
     */
    public function testBulkUpdate(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'BulkUpdateTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attributes
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'category',
            'size' => 256,
            'required' => false,
        ]);

        sleep(3);

        // Create documents for bulk testing
        for ($i = 1; $i <= 3; $i++) {
            $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'documentId' => 'bulk_update_' . $i,
                'data' => [
                    'name' => 'Bulk doc ' . $i,
                    'category' => 'bulk_test'
                ]
            ]);
        }

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];

        // Bulk update via normal route with transactionId
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'queries' => [Query::equal('category', ['bulk_test'])->toString()],
            'data' => ['category' => 'bulk_updated'],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Documents should still have original category outside transaction
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('category', ['bulk_test'])->toString()]
        ]);

        $this->assertEquals(3, $response['body']['total']);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Documents should now have updated category
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('category', ['bulk_updated'])->toString()]
        ]);

        $this->assertEquals(3, $response['body']['total']);
    }

    /**
     * Test bulkUpsert with transactionId via normal route
     */
    public function testBulkUpsert(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'BulkUpsertTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attributes
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/integer", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'counter',
            'required' => false,
            'min' => 0,
            'max' => 10000,
        ]);

        sleep(3);

        // Create one document outside transaction
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'bulk_upsert_existing',
            'data' => [
                'name' => 'Existing doc',
                'counter' => 10
            ]
        ]);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];

        // Bulk upsert via normal route with transactionId (updates existing, creates new)
        $response = $this->client->call(Client::METHOD_PUT, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documents' => [
                [
                    '$id' => 'bulk_upsert_existing',
                    'name' => 'Updated existing',
                    'counter' => 20
                ],
                [
                    '$id' => 'bulk_upsert_new',
                    'name' => 'New doc',
                    'counter' => 30
                ]
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Original document should be unchanged, new document shouldn't exist outside transaction
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/bulk_upsert_existing", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals('Existing doc', $response['body']['name']);
        $this->assertEquals(10, $response['body']['counter']);

        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/bulk_upsert_new", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Check both documents exist with updated values
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/bulk_upsert_existing", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals('Updated existing', $response['body']['name']);
        $this->assertEquals(20, $response['body']['counter']);

        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/bulk_upsert_new", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals('New doc', $response['body']['name']);
        $this->assertEquals(30, $response['body']['counter']);
    }

    /**
     * Test bulkDelete with transactionId via normal route
     */
    public function testBulkDelete(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'BulkDeleteTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attributes
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'category',
            'size' => 256,
            'required' => false,
        ]);

        sleep(3);

        // Create documents for bulk testing
        for ($i = 1; $i <= 3; $i++) {
            $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'documentId' => 'bulk_delete_' . $i,
                'data' => [
                    'name' => 'Delete doc ' . $i,
                    'category' => 'bulk_delete_test'
                ]
            ]);
        }

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];

        // Bulk delete via normal route with transactionId
        $response = $this->client->call(Client::METHOD_DELETE, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'queries' => [Query::equal('category', ['bulk_delete_test'])->toString()],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(200, $response['headers']['status-code']); // Bulk delete with transaction returns 200

        // Documents should still exist outside transaction
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('category', ['bulk_delete_test'])->toString()]
        ]);

        $this->assertEquals(3, $response['body']['total']);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Documents should now be deleted
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('category', ['bulk_delete_test'])->toString()]
        ]);

        $this->assertEquals(0, $response['body']['total']);
    }

    /**
     * Test multiple single route operations in one transaction
     */
    public function testMixedSingleOperations(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'MultipleSingleRoutesDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attributes
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'status',
            'size' => 256,
            'required' => false,
        ]);

        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/integer", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'priority',
            'required' => false,
            'min' => 1,
            'max' => 10,
        ]);

        sleep(3);

        // Create an existing document outside transaction for testing
        $existingDoc = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'existing_doc',
            'data' => [
                'name' => 'Existing Document',
                'status' => 'active',
                'priority' => 5
            ]
        ]);

        $this->assertEquals(201, $existingDoc['headers']['status-code']);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];
        $this->assertEquals(201, $transaction['headers']['status-code']);

        // 1. Create new document via normal route with transactionId
        $response1 = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'new_doc_1',
            'data' => [
                'name' => 'New Document 1',
                'status' => 'pending',
                'priority' => 1
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(201, $response1['headers']['status-code']);

        // 2. Create another document via normal route with transactionId
        $response2 = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'new_doc_2',
            'data' => [
                'name' => 'New Document 2',
                'status' => 'pending',
                'priority' => 2
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(201, $response2['headers']['status-code']);

        // 3. Update existing document via normal route with transactionId
        $response3 = $this->client->call(Client::METHOD_PATCH, "/databases/{$databaseId}/collections/{$collectionId}/documents/existing_doc", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'status' => 'updated',
                'priority' => 10
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(200, $response3['headers']['status-code']);

        // 4. Update the first new document (created in same transaction)
        $response4 = $this->client->call(Client::METHOD_PATCH, "/databases/{$databaseId}/collections/{$collectionId}/documents/new_doc_1", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'status' => 'active',
                'priority' => 8
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(200, $response4['headers']['status-code']);

        // 5. Delete the second new document (created in same transaction)
        $response5 = $this->client->call(Client::METHOD_DELETE, "/databases/{$databaseId}/collections/{$collectionId}/documents/new_doc_2", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(204, $response5['headers']['status-code']);

        // 6. Upsert a new document via normal route with transactionId
        $response6 = $this->client->call(Client::METHOD_PUT, "/databases/{$databaseId}/collections/{$collectionId}/documents/upserted_doc", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'upserted_doc',
            'data' => [
                'name' => 'Upserted Document',
                'status' => 'new',
                'priority' => 3
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(201, $response6['headers']['status-code']);

        // Check transaction has correct number of operations
        $txnDetails = $this->client->call(Client::METHOD_GET, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $txnDetails['headers']['status-code']);
        $this->assertEquals(6, $txnDetails['body']['operations']); // 6 operations total

        // Verify nothing exists outside transaction yet
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/new_doc_1", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/upserted_doc", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        // Existing doc should still have original values
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/existing_doc", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals('active', $response['body']['status']);
        $this->assertEquals(5, $response['body']['priority']);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('committed', $response['body']['status']);

        // Verify final state after commit
        // new_doc_1 should exist with updated values
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/new_doc_1", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('New Document 1', $response['body']['name']);
        $this->assertEquals('active', $response['body']['status']);
        $this->assertEquals(8, $response['body']['priority']);

        // new_doc_2 should not exist (was deleted in transaction)
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/new_doc_2", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        // existing_doc should have updated values
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/existing_doc", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals('updated', $response['body']['status']);
        $this->assertEquals(10, $response['body']['priority']);

        // upserted_doc should exist
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/upserted_doc", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Upserted Document', $response['body']['name']);
        $this->assertEquals('new', $response['body']['status']);
        $this->assertEquals(3, $response['body']['priority']);

        // Verify total document count
        $documents = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(3, $documents['body']['total']); // existing_doc, new_doc_1, upserted_doc
    }

    /**
     * Test mixed operations with transactions
     */
    public function testMixedOperations(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'MixedOpsTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attribute
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        sleep(2);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];

        // Add operation via Operations\Add endpoint
        $response = $this->client->call(Client::METHOD_POST, "/databases/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => 'mixed_doc1',
                    'data' => ['name' => 'Via Operations Add']
                ]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['operations']);

        // Add operation via normal route with transactionId
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'mixed_doc2',
            'data' => ['name' => 'Via normal route'],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Check transaction now has 2 operations
        $txnDetails = $this->client->call(Client::METHOD_GET, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(2, $txnDetails['body']['operations']);

        // Both documents shouldn't exist yet
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/mixed_doc1", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/mixed_doc2", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Both documents should now exist
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/mixed_doc1", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Via Operations Add', $response['body']['name']);

        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/mixed_doc2", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Via normal route', $response['body']['name']);
    }

    /**
     * Test bulk update with queries that should match documents created in the same transaction
     */
    public function testBulkUpdateWithTransactionAwareQueries(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'BulkTxnAwareDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attributes
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/integer", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'age',
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'status',
            'size' => 256,
            'required' => true,
        ]);

        sleep(3); // Wait for attributes to be created

        // Create some existing documents
        for ($i = 1; $i <= 3; $i++) {
            $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'documentId' => 'existing_' . $i,
                'data' => [
                    'name' => 'Existing ' . $i,
                    'age' => 20 + $i,
                    'status' => 'inactive'
                ]
            ]);
        }

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];

        // Step 1: Create new documents with age > 25 in transaction
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'txn_doc_1',
            'data' => [
                'name' => 'Transaction Doc 1',
                'age' => 30,
                'status' => 'inactive'
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'txn_doc_2',
            'data' => [
                'name' => 'Transaction Doc 2',
                'age' => 35,
                'status' => 'inactive'
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Step 2: Bulk update all documents with age > 25 to have status 'active'
        // This should match both existing_3 (age=23 doesn't match, age=24 doesn't match, but existing documents have age 21,22,23)
        // Wait, let me fix the ages - existing docs have ages 21, 22, 23, so only txn docs should match
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'status' => 'active'
            ],
            'queries' => [Query::greaterThan('age', 25)->toString()],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Verify that documents created in the transaction were updated by the bulk update
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/txn_doc_1", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('active', $response['body']['status'], 'Document created in transaction should be updated by bulk update query');

        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/txn_doc_2", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('active', $response['body']['status'], 'Document created in transaction should be updated by bulk update query');

        // Verify existing documents were not affected
        for ($i = 1; $i <= 3; $i++) {
            $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/existing_{$i}", array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals('inactive', $response['body']['status'], "Existing document {$i} should remain inactive (age <= 25)");
        }
    }

    /**
     * Test bulk update with queries that should match documents updated in the same transaction
     */
    public function testBulkUpdateMatchingUpdatedDocuments(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'BulkUpdateTxnDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attributes
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'category',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'priority',
            'size' => 256,
            'required' => true,
        ]);

        sleep(3); // Wait for attributes to be created

        // Create existing documents
        for ($i = 1; $i <= 4; $i++) {
            $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'documentId' => 'doc_' . $i,
                'data' => [
                    'name' => 'Document ' . $i,
                    'category' => 'normal',
                    'priority' => 'low'
                ]
            ]);
        }

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];

        // Step 1: Update some documents to have category 'special' in transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_1", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'category' => 'special'
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_2", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'category' => 'special'
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Step 2: Bulk update all documents with category 'special' to have priority 'high'
        // This should match the documents we just updated in the transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'priority' => 'high'
            ],
            'queries' => [Query::equal('category', ['special'])->toString()],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Verify that the updated documents were matched by bulk update
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_1", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('special', $response['body']['category']);
        $this->assertEquals('high', $response['body']['priority'], 'Document updated in transaction should be matched by bulk update query');

        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_2", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('special', $response['body']['category']);
        $this->assertEquals('high', $response['body']['priority'], 'Document updated in transaction should be matched by bulk update query');

        // Verify other documents were not affected
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_3", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('normal', $response['body']['category']);
        $this->assertEquals('low', $response['body']['priority']);
    }

    /**
     * Test bulk delete with queries that should match documents created in the same transaction
     */
    public function testBulkDeleteMatchingCreatedDocuments(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'BulkDeleteTxnDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attributes
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'type',
            'size' => 256,
            'required' => true,
        ]);

        sleep(3); // Wait for attributes to be created

        // Create existing documents
        for ($i = 1; $i <= 3; $i++) {
            $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'documentId' => 'existing_' . $i,
                'data' => [
                    'name' => 'Existing ' . $i,
                    'type' => 'permanent'
                ]
            ]);
        }

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];

        // Step 1: Create temporary documents in transaction
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'temp_1',
            'data' => [
                'name' => 'Temporary 1',
                'type' => 'temporary'
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'temp_2',
            'data' => [
                'name' => 'Temporary 2',
                'type' => 'temporary'
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Step 2: Bulk delete all documents with type 'temporary'
        // This should delete the documents we just created in the transaction
        $response = $this->client->call(Client::METHOD_DELETE, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'queries' => [Query::equal('type', ['temporary'])->toString()],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Verify temporary documents were deleted (should not exist)
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/temp_1", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code'], 'Temporary document created and deleted in transaction should not exist');

        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/temp_2", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code'], 'Temporary document created and deleted in transaction should not exist');

        // Verify existing documents were not affected
        for ($i = 1; $i <= 3; $i++) {
            $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/existing_{$i}", array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $response['headers']['status-code'], "Permanent document {$i} should still exist");
            $this->assertEquals('permanent', $response['body']['type']);
        }
    }

    /**
     * Test bulk delete with queries that should match documents updated in the same transaction
     */
    public function testBulkDeleteMatchingUpdatedDocuments(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'BulkDeleteUpdateTxnDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create attributes
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'status',
            'size' => 256,
            'required' => true,
        ]);

        sleep(3); // Wait for attributes to be created

        // Create existing documents
        for ($i = 1; $i <= 5; $i++) {
            $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'documentId' => 'doc_' . $i,
                'data' => [
                    'name' => 'Document ' . $i,
                    'status' => 'active'
                ]
            ]);
        }

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/databases/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];

        // Step 1: Mark some documents for deletion by updating their status
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_2", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'status' => 'marked_for_deletion'
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_4", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'status' => 'marked_for_deletion'
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Step 2: Bulk delete all documents with status 'marked_for_deletion'
        // This should delete the documents we just updated in the transaction
        $response = $this->client->call(Client::METHOD_DELETE, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'queries' => [Query::equal('status', ['marked_for_deletion'])->toString()],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Verify marked documents were deleted
        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_2", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code'], 'Document marked for deletion should have been deleted');

        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_4", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code'], 'Document marked for deletion should have been deleted');

        // Verify other documents still exist
        foreach ([1, 3, 5] as $i) {
            $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/documents/doc_{$i}", array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $response['headers']['status-code'], "Document {$i} should still exist");
            $this->assertEquals('active', $response['body']['status']);
        }
    }
}
