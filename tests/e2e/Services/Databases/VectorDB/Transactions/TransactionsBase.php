<?php

namespace Tests\E2E\Services\Databases\VectorDB\Transactions;

use Tests\E2E\Client;
use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

trait TransactionsBase
{
    /**
     * Helper method to generate embeddings vector
     */
    private function generateEmbeddings(int $dimensions = 3, float $value = 0.1): array
    {
        $vector = array_fill(0, $dimensions, $value);
        $vector[0] = 1.0; // Set first element to 1.0 for uniqueness
        return $vector;
    }
    /**
     * Test creating a transaction
     */
    public function testCreate(): void
    {
        // Create database first
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
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
        $response = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertArrayHasKey('$id', $response['body']);
        $this->assertArrayHasKey('status', $response['body']);
        $this->assertArrayHasKey('operations', $response['body']);
        $this->assertArrayHasKey('expiresAt', $response['body']);
        $this->assertEquals('pending', $response['body']['status']);
        $this->assertEquals(0, $response['body']['operations']);

        $transactionId1 = $response['body']['$id'];

        // Test creating a transaction with custom TTL
        $response = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
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
        $response = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'ttl' => 30 // Below minimum
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'ttl' => 4000 // Above maximum
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    /**
     * Test adding operations to a transaction
     */
    public function testCreateOperations(): void
    {
        // Create database first
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
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
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        // Create a collection for testing
        $collection = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TransactionOperationsTest',
            'dimensions' => 3,
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

        // Add valid operations
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
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
                        'embeddings' => $this->generateEmbeddings(3),
                        'metadata' => ['name' => 'Test Document 1']
                    ]
                ],
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => 'doc2',
                    'data' => [
                        'embeddings' => $this->generateEmbeddings(3, 0.2),
                        'metadata' => ['name' => 'Test Document 2']
                    ]
                ]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals(2, $response['body']['operations']);

        // Test adding more operations
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
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
                        'metadata' => ['name' => 'Updated Document 1']
                    ]
                ]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals(3, $response['body']['operations']);

        // Test invalid database ID
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
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
                    'data' => [
                        'embeddings' => $this->generateEmbeddings(3),
                        'metadata' => ['name' => 'Test']
                    ]
                ]
            ]
        ]);

        $this->assertEquals(404, $response['headers']['status-code'], 'Invalid database should return 404. Got: ' . json_encode($response['body']));

        // Test invalid collection ID
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
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
                    'data' => [
                        'embeddings' => $this->generateEmbeddings(3),
                        'metadata' => ['name' => 'Test']
                    ]
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
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
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
        $collection = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TransactionCommitTest',
            'dimensions' => 3,
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

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        // Add operations
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
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
                        'embeddings' => $this->generateEmbeddings(3),
                        'metadata' => ['name' => 'Test Document 1']
                    ]
                ],
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => 'doc2',
                    'data' => [
                        'embeddings' => $this->generateEmbeddings(3, 0.2),
                        'metadata' => ['name' => 'Test Document 2']
                    ]
                ],
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'update',
                    'documentId' => 'doc1',
                    'data' => [
                        'metadata' => ['name' => 'Updated Document 1']
                    ]
                ]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals(3, $response['body']['operations']);

        // Commit the transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('committed', $response['body']['status']);

        // Verify documents were created
        $documents = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(2, $documents['body']['total']);

        // Verify the update was applied
        $doc1Found = false;
        foreach ($documents['body']['documents'] as $doc) {
            if ($doc['$id'] === 'doc1') {
                $this->assertEquals('Updated Document 1', $doc['metadata']['name']);
                $doc1Found = true;
            }
        }
        $this->assertTrue($doc1Found, 'Document doc1 should exist with updated name');

        // Test committing already committed transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
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
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
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
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        // Create a collection for rollback test
        $collection = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TransactionRollbackTest',
            'dimensions' => 3,
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Add operations
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
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
                        'embeddings' => $this->generateEmbeddings(3),
                        'metadata' => ['value' => 'Should not exist']
                    ]
                ]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Rollback the transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'rollback' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('failed', $response['body']['status']);

        // Verify no documents were created
        $documents = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
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
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'ExpirationTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'dimensions' => 3,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create transaction with minimum TTL (60 seconds)
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'ttl' => 60
        ]);

        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        // Add operation
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
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
                    'data' => [
                        'embeddings' => $this->generateEmbeddings(3),
                        'metadata' => ['data' => 'Should expire']
                    ]
                ]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Verify transaction was created with correct expiration
        $txnDetails = $this->client->call(Client::METHOD_GET, "/vectordb/transactions/{$transactionId}", array_merge([
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
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'SizeLimitTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'dimensions' => 3,
            'permissions' => [Permission::create(Role::any())],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

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
                'data' => [
                    'embeddings' => $this->generateEmbeddings(3, 0.1 + ($i * 0.001)),
                    'metadata' => ['value' => 'Test ' . $i]
                ]
            ];
        }

        // First batch should succeed
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
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
                'data' => [
                    'embeddings' => $this->generateEmbeddings(3, 0.1 + ($i * 0.001)),
                    'metadata' => ['value' => 'Test ' . $i]
                ]
            ];
        }

        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => $operations
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals(100, $response['body']['operations']);

        // Try to add one more operation - should fail
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
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
                    'data' => [
                        'embeddings' => $this->generateEmbeddings(3),
                        'metadata' => ['value' => 'This should fail']
                    ]
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
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'ConflictTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'dimensions' => 3,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create initial document
        $doc = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'shared_doc',
            'data' => [
                'embeddings' => $this->generateEmbeddings(3),
                'metadata' => ['counter' => 100]
            ]
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);

        // Create two transactions
        $txn1 = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $txn2 = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $transactionId1 = $txn1['body']['$id'];
        $transactionId2 = $txn2['body']['$id'];

        // Both transactions try to update the same document
        $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId1}/operations", array_merge([
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
                    'data' => [
                        'metadata' => ['counter' => 200]
                    ]
                ]
            ]
        ]);

        $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId2}/operations", array_merge([
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
                    'data' => [
                        'metadata' => ['counter' => 300]
                    ]
                ]
            ]
        ]);

        // Commit first transaction
        $response1 = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId1}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response1['headers']['status-code']);

        // Commit second transaction - should fail with conflict
        $response2 = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId2}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(409, $response2['headers']['status-code']); // Conflict

        // Verify the document has the value from first transaction
        $doc = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/shared_doc", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $doc['body']['metadata']['counter']);
    }

    /**
     * Test deleting a document that's being updated in a transaction
     */
    public function testDeleteDocumentDuringTransaction(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'DeleteConflictDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'dimensions' => 3,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create document
        $doc = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'target_doc',
            'data' => [
                'embeddings' => $this->generateEmbeddings(3),
                'metadata' => ['data' => 'Original']
            ]
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $transactionId = $transaction['body']['$id'];

        // Add update operation to transaction
        $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
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
                    'data' => [
                        'metadata' => ['data' => 'Updated in transaction']
                    ]
                ]
            ]
        ]);

        // Delete the document outside of transaction
        $response = $this->client->call(Client::METHOD_DELETE, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/target_doc", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $response['headers']['status-code']);

        // Try to commit transaction - should fail because document no longer exists
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(404, $response['headers']['status-code']); // Conflict
    }

    /**
     * Test bulk operations in transactions
     */
    public function testBulkOperations(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'BulkOpsDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'dimensions' => 3,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create some initial documents
        for ($i = 1; $i <= 5; $i++) {
            $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'documentId' => 'existing_' . $i,
                'data' => [
                    'embeddings' => $this->generateEmbeddings(3, 0.1 + ($i * 0.01)),
                    'metadata' => [
                        'name' => 'Existing ' . $i,
                        'category' => 'old'
                    ]
                ]
            ]);
        }

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $transactionId = $transaction['body']['$id'];

        // Add bulk operations
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
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
                        [
                            '$id' => 'bulk_1',
                            'embeddings' => $this->generateEmbeddings(3, 0.2),
                            'metadata' => ['name' => 'Bulk 1', 'category' => 'new']
                        ],
                        [
                            '$id' => 'bulk_2',
                            'embeddings' => $this->generateEmbeddings(3, 0.3),
                            'metadata' => ['name' => 'Bulk 2', 'category' => 'new']
                        ],
                        [
                            '$id' => 'bulk_3',
                            'embeddings' => $this->generateEmbeddings(3, 0.4),
                            'metadata' => ['name' => 'Bulk 3', 'category' => 'new']
                        ],
                    ]
                ],
                // Bulk update
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'bulkUpdate',
                    'data' => [
                        'queries' => [Query::equal('metadata', [['category' => 'old']])->toString()],
                        'data' => ['metadata' => ['category' => 'updated']]
                    ]
                ],
                // Bulk delete
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'bulkDelete',
                    'data' => [
                        'queries' => [Query::equal('$id', ['existing_5'])->toString()]
                    ]
                ]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Verify results
        $documents = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
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
            $category = $doc['metadata']['category'] ?? null;
            switch ($category) {
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
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'PartialFailureDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'dimensions' => 3,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create HNSW index on embeddings
        $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/indexes", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'embeddings_index',
            'type' => Database::INDEX_HNSW_EUCLIDEAN,
            'attributes' => ['embeddings'],
        ]);

        sleep(2);

        // Create an existing document
        $duplicateId = ID::unique();
        $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => $duplicateId,
            'data' => [
                'embeddings' => $this->generateEmbeddings(3),
                'metadata' => ['email' => 'existing@example.com']
            ]
        ]);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $transactionId = $transaction['body']['$id'];

        // Add operations - mix of valid and invalid (duplicate id)
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
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
                    'data' => [
                        'embeddings' => $this->generateEmbeddings(3, 0.2),
                        'metadata' => ['email' => 'valid1@example.com']
                    ]
                ],
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => ID::unique(),
                    'data' => [
                        'embeddings' => $this->generateEmbeddings(3, 0.3),
                        'metadata' => ['email' => 'valid2@example.com']
                    ]
                ],
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => $duplicateId,
                    'data' => [
                        'embeddings' => $this->generateEmbeddings(3, 0.4),
                        'metadata' => ['email' => 'existing@example.com']
                    ]
                ],
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => ID::unique(),
                    'data' => [
                        'embeddings' => $this->generateEmbeddings(3, 0.5),
                        'metadata' => ['email' => 'valid3@example.com']
                    ]
                ],
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Try to commit - should fail and rollback all operations
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(409, $response['headers']['status-code']); // Conflict due to duplicate

        // Verify NO new documents were created (atomicity)
        $documents = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(1, $documents['body']['total']); // Only the original document
        $this->assertEquals('existing@example.com', $documents['body']['documents'][0]['metadata']['email']);
    }

    /**
     * Test double commit/rollback attempts
     */
    public function testDoubleCommitRollback(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'DoubleCommitDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'dimensions' => 3,
            'permissions' => [Permission::create(Role::any())],
        ]);

        $collectionId = $collection['body']['$id'];

        // Test double commit
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $transactionId = $transaction['body']['$id'];

        // Add operation
        $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
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
                    'data' => [
                        'embeddings' => $this->generateEmbeddings(3),
                        'metadata' => ['data' => 'Test']
                    ]
                ]
            ]
        ]);

        // First commit
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Second commit attempt - should fail
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(400, $response['headers']['status-code']); // Bad request - already committed

        // Test double rollback
        $transaction2 = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $transactionId2 = $transaction2['body']['$id'];

        // First rollback
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId2}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'rollback' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Second rollback attempt - should fail
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId2}", array_merge([
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
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'NonExistentDocDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'dimensions' => 3,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $transactionId = $transaction['body']['$id'];

        // Try to update non-existent document - should fail at staging time with early validation
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
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
                    'data' => [
                        'metadata' => ['data' => 'Should fail']
                    ]
                ]
            ]
        ]);

        $this->assertEquals(404, $response['headers']['status-code']); // Document not found at staging time

        // Test delete non-existent document - should also fail at staging time with early validation
        $transaction2 = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $transactionId2 = $transaction2['body']['$id'];

        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId2}/operations", array_merge([
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

        $this->assertEquals(404, $response['headers']['status-code']); // Document not found at staging time
    }

    /**
     * Test createDocument with transactionId via normal route
     */
    public function testCreateDocument(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'WriteRoutesTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'dimensions' => 3,
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        // Create document via normal route with transactionId
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'doc_from_route',
            'data' => [
                'embeddings' => $this->generateEmbeddings(3),
                'metadata' => [
                    'name' => 'Created via normal route',
                    'counter' => 100,
                    'category' => 'test'
                ]
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Document should not exist outside transaction yet
        $response = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/doc_from_route", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Document should now exist
        $response = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/doc_from_route", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Created via normal route', $response['body']['metadata']['name']);
    }

    /**
     * Test updateDocument with transactionId via normal route
     */
    public function testUpdateDocument(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'UpdateRouteTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'dimensions' => 3,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create document outside transaction
        $doc = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'doc_to_update',
            'data' => [
                'embeddings' => $this->generateEmbeddings(3),
                'metadata' => [
                    'name' => 'Original name',
                    'counter' => 50,
                    'category' => 'original'
                ]
            ]
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $transactionId = $transaction['body']['$id'];

        // Update document via normal route with transactionId
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/doc_to_update", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'metadata' => [
                    'name' => 'Updated via normal route',
                    'counter' => 150,
                    'category' => 'updated'
                ]
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Document should still have original values outside transaction
        $response = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/doc_to_update", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals('Original name', $response['body']['metadata']['name']);
        $this->assertEquals(50, $response['body']['metadata']['counter']);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Document should now have updated values
        $response = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/doc_to_update", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals('Updated via normal route', $response['body']['metadata']['name']);
        $this->assertEquals(150, $response['body']['metadata']['counter']);
    }

    /**
     * Test upsertDocument with transactionId via normal route
     */
    public function testUpsertDocument(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'UpsertRouteTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'dimensions' => 3,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $transactionId = $transaction['body']['$id'];

        // Upsert document (create) via normal route with transactionId
        $response = $this->client->call(Client::METHOD_PUT, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/doc_upsert", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'doc_upsert',
            'data' => [
                'embeddings' => $this->generateEmbeddings(3),
                'metadata' => [
                    'name' => 'Created by upsert',
                    'counter' => 25
                ]
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Document should not exist outside transaction yet
        $response = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/doc_upsert", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        // Upsert same document (update) in same transaction
        $response = $this->client->call(Client::METHOD_PUT, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/doc_upsert", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'doc_upsert',
            'data' => [
                'metadata' => [
                    'name' => 'Updated by upsert',
                    'counter' => 75
                ]
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(201, $response['headers']['status-code']); // Upsert in transaction returns 201

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Document should now exist with updated values
        $response = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/doc_upsert", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Updated by upsert', $response['body']['metadata']['name']);
        $this->assertEquals(75, $response['body']['metadata']['counter']);
    }

    /**
     * Test deleteDocument with transactionId via normal route
     */
    public function testDeleteDocument(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'DeleteRouteTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'dimensions' => 3,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create document outside transaction
        $doc = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'doc_to_delete',
            'data' => [
                'embeddings' => $this->generateEmbeddings(3),
                'metadata' => ['name' => 'Will be deleted']
            ]
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $transactionId = $transaction['body']['$id'];

        // Delete document via normal route with transactionId
        $response = $this->client->call(Client::METHOD_DELETE, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/doc_to_delete", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(204, $response['headers']['status-code']);

        // Document should still exist outside transaction
        $response = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/doc_to_delete", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Document should no longer exist
        $response = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/doc_to_delete", array_merge([
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
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'BulkCreateTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'dimensions' => 3,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $transactionId = $transaction['body']['$id'];

        // Bulk create via normal route with transactionId
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documents' => [
                [
                    '$id' => 'bulk_create_1',
                    'embeddings' => $this->generateEmbeddings(3),
                    'metadata' => [
                        'name' => 'Bulk created 1',
                        'category' => 'bulk_created'
                    ]
                ],
                [
                    '$id' => 'bulk_create_2',
                    'embeddings' => $this->generateEmbeddings(3, 0.2),
                    'metadata' => [
                        'name' => 'Bulk created 2',
                        'category' => 'bulk_created'
                    ]
                ],
                [
                    '$id' => 'bulk_create_3',
                    'embeddings' => $this->generateEmbeddings(3, 0.3),
                    'metadata' => [
                        'name' => 'Bulk created 3',
                        'category' => 'bulk_created'
                    ]
                ]
            ],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(200, $response['headers']['status-code']); // Bulk operations return 200

        // Documents should not exist outside transaction yet
        $response = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('metadata', [['metadata' => ['category' => 'bulk_created']]])->toString()]
        ]);

        $this->assertEquals(0, $response['body']['total']);

        // Individual document check
        $response = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/bulk_create_1", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Documents should now exist
        $response = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('metadata', ['metadata' => ['category' => 'bulk_created']])->toString()]
        ]);

        $this->assertEquals(3, $response['body']['total']);

        // Verify individual documents
        for ($i = 1; $i <= 3; $i++) {
            $response = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/bulk_create_{$i}", array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals("Bulk created {$i}", $response['body']['metadata']['name']);
            $this->assertEquals('bulk_created', $response['body']['metadata']['category']);
        }
    }

    /**
     * Test bulkUpdate with transactionId via normal route
     */
    public function testBulkUpdate(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'BulkUpdateTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'dimensions' => 3,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create documents for bulk testing
        for ($i = 1; $i <= 3; $i++) {
            $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'documentId' => 'bulk_update_' . $i,
                'data' => [
                    'embeddings' => $this->generateEmbeddings(3, 0.1 * $i),
                    'metadata' => [
                        'name' => 'Bulk doc ' . $i,
                        'category' => 'bulk_test'
                    ]
                ]
            ]);
        }

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $transactionId = $transaction['body']['$id'];

        // Bulk update via normal route with transactionId
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'queries' => [Query::equal('metadata', ['metadata' => ['category' => 'bulk_test']])->toString()],
            'data' => ['metadata' => ['category' => 'bulk_updated']],
            'transactionId' => $transactionId
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Documents should still have original category outside transaction
        $response = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('metadata', ['metadata' => ['category' => 'bulk_test']])->toString()]
        ]);

        $this->assertEquals(3, $response['body']['total']);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Documents should now have updated category
        $response = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('metadata', ['metadata' => ['category' => 'bulk_updated']])->toString()]
        ]);

        $this->assertEquals(3, $response['body']['total']);
    }

    /**
     * Test bulkUpsert with transactionId via normal route
     */
    public function testBulkUpsert(): void
    {
        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'BulkUpsertTestDB'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCollection',
            'dimensions' => 3,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        // Test 1: Invalid action type
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'action' => 'invalidAction',
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'documentId' => ID::unique(),
                    'data' => ['name' => 'Test']
                ]
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Test 2: Missing required action field
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'documentId' => ID::unique(),
                    'data' => ['name' => 'Test']
                ]
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Test 3: Missing required databaseId field
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'action' => 'create',
                    'collectionId' => $collectionId,
                    'documentId' => ID::unique(),
                    'data' => ['name' => 'Test']
                ]
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Test 4: Missing documentId for create operation
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'action' => 'create',
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'data' => ['name' => 'Test']
                ]
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Test 5: Missing data for create operation
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'action' => 'create',
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'documentId' => ID::unique()
                ]
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Test 6: BulkCreate with non-array data
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'action' => 'bulkCreate',
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'data' => 'not an array'
                ]
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Test 7: BulkUpdate with missing queries
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'action' => 'bulkUpdate',
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'data' => [
                        'data' => ['name' => 'Updated']
                    ]
                ]
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Test 8: Empty operations array
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => []
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Test 9: Operations not an array
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => 'not an array'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    /**
     * Test validation for committing/rolling back transactions
     */
    public function testCommitRollbackValidation(): void
    {
        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        // Test 1: Missing both commit and rollback
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), []);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Test 2: Both commit and rollback set to true
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true,
            'rollback' => true
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Test 3: Invalid transaction ID
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/invalid_id", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        // Commit the transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Test 4: Attempt to commit already committed transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    /**
     * Test validation for non-existent resources
     */
    public function testNonExistentResources(): void
    {
        // Create database and transaction
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'ResourceTestDatabase'
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        // Test 1: Non-existent database
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'action' => 'create',
                    'databaseId' => 'nonExistentDatabase',
                    'collectionId' => 'someCollection',
                    'documentId' => ID::unique(),
                    'data' => ['name' => 'Test']
                ]
            ]
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        // Test 2: Non-existent collection
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'action' => 'create',
                    'databaseId' => $databaseId,
                    'collectionId' => 'nonExistentCollection',
                    'documentId' => ID::unique(),
                    'data' => ['name' => 'Test']
                ]
            ]
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
    }
}
