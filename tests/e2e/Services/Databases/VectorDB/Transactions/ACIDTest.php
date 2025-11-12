<?php

namespace Tests\E2E\Services\Databases\VectorDB\Transactions;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class ACIDTest extends Scope
{
    use ProjectCustom;
    use SideClient;

    private function generateEmbeddings(int $dimensions = 3, float $value = 0.1): array
    {
        $vector = array_fill(0, $dimensions, $value);
        $vector[0] = 1.0;
        return $vector;
    }

    /**
     * Test atomicity - all operations succeed or all fail
     */
    public function testAtomicity(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'AtomicityTestDB'
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create collection for the test
        $collection = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'AtomicityTest',
            'dimensions' => 3,
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Create a document outside the transaction
        $existingDocumentId = 'existing_doc';
        $doc1 = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => $existingDocumentId,
            'data' => [
                'embeddings' => $this->generateEmbeddings(3),
                'metadata' => ['email' => 'existing@example.com'],
            ],
        ]);

        $this->assertEquals(201, $doc1['headers']['status-code']);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(201, $transaction['headers']['status-code'], 'Transaction creation should succeed. Response: ' . json_encode($transaction));
        $this->assertArrayHasKey('$id', $transaction['body'], 'Transaction response should have $id. Response body: ' . json_encode($transaction['body']));
        $transactionId = $transaction['body']['$id'];

        // Add operations - second create reuses an existing documentId and should cause the commit to fail
        $response = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documents' => [
                [
                    '$id' => 'txn_doc_1',
                    'embeddings' => $this->generateEmbeddings(3, 0.2),
                    'metadata' => ['email' => 'newuser@example.com'],
                ],
                [
                    '$id' => $existingDocumentId,
                    'embeddings' => $this->generateEmbeddings(3, 0.3),
                    'metadata' => ['email' => 'duplicate@example.com'],
                ],
                [
                    '$id' => 'txn_doc_2',
                    'embeddings' => $this->generateEmbeddings(3, 0.4),
                    'metadata' => ['email' => 'should-not-exist@example.com'],
                ],
            ],
            'transactionId' => $transactionId,
        ]);

        $this->assertEquals(200, $response['headers']['status-code'], 'Adding documents via normal route should succeed. Response: ' . json_encode($response['body']));

        // Attempt to commit - should fail due to duplicate document ID
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(409, $response['headers']['status-code']);

        // Verify NO new documents were created (atomicity)
        $documents = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(1, $documents['body']['total']);
        $this->assertEquals('existing@example.com', $documents['body']['documents'][0]['metadata']['email']);
    }

    /**
     * Test consistency - schema validation and constraints
     */
    public function testConsistency(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'ConsistencyTestDB'
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
            'name' => 'ConsistencyTest',
            'dimensions' => 3,
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];

        // Stage operations with valid and invalid data (embedding length mismatch)
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
                        'metadata' => ['name' => 'Valid User'],
                    ],
                ],
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => ID::unique(),
                    'data' => [
                        'embeddings' => $this->generateEmbeddings(2, 0.5), // Invalid dimensions
                        'metadata' => ['name' => 'Invalid User'],
                    ],
                ],
                [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'action' => 'create',
                    'documentId' => ID::unique(),
                    'data' => [
                        'embeddings' => $this->generateEmbeddings(3, 0.6),
                        'metadata' => ['name' => 'Should Not Persist'],
                    ],
                ],
            ],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Attempt to commit - should fail due to invalid embeddings
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertContains($response['headers']['status-code'], [400, 409, 500], 'Transaction commit should fail due to validation. Response: ' . json_encode($response['body']));

        // Verify no documents were created
        $documents = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(0, $documents['body']['total']);
    }

    /**
     * Test isolation - concurrent transactions on same data
     */
    public function testIsolation(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'IsolationTestDB'
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
            'name' => 'IsolationTest',
            'dimensions' => 3,
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create initial document with status metadata
        $doc = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'shared_doc',
            'data' => [
                'embeddings' => $this->generateEmbeddings(3),
                'metadata' => ['status' => 'pending'],
            ],
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);

        // Create first transaction
        $transaction1 = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(201, $transaction1['headers']['status-code'], 'Transaction 1 creation should succeed');
        $this->assertArrayHasKey('$id', $transaction1['body'], 'Transaction 1 response should have $id');
        $transactionId1 = $transaction1['body']['$id'];

        // Transaction 1: update status to approved
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
                        'metadata' => ['status' => 'approved'],
                    ],
                ],
            ],
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

        // Document should reflect the first transaction's update
        $document = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/shared_doc", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals('approved', $document['body']['metadata']['status']);

        // Create second transaction after first commit
        $transaction2 = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(201, $transaction2['headers']['status-code'], 'Transaction 2 creation should succeed');
        $this->assertArrayHasKey('$id', $transaction2['body'], 'Transaction 2 response should have $id');
        $transactionId2 = $transaction2['body']['$id'];

        // Transaction 2: update status to declined
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
                        'metadata' => ['status' => 'declined'],
                    ],
                ],
            ],
        ]);

        // Commit second transaction and ensure isolation guarantees
        $response2 = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId2}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response2['headers']['status-code']);

        // Final document should reflect the second transaction's update
        $document = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/shared_doc", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals('declined', $document['body']['metadata']['status']);
    }

    /**
     * Test durability - committed data persists
     */
    public function testDurability(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'DurabilityTestDB'
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
            'name' => 'DurabilityTest',
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

        // Create transaction with multiple operations
        $transaction = $this->client->call(Client::METHOD_POST, '/vectordb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(201, $transaction['headers']['status-code'], 'Transaction creation should succeed');
        $this->assertArrayHasKey('$id', $transaction['body'], 'Transaction response should have $id');
        $transactionId = $transaction['body']['$id'];

        // Create two documents via normal route inside transaction
        $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documents' => [
                [
                    '$id' => 'durable_doc_1',
                    'embeddings' => $this->generateEmbeddings(3, 0.3),
                    'metadata' => ['data' => 'Important data 1'],
                ],
                [
                    '$id' => 'durable_doc_2',
                    'embeddings' => $this->generateEmbeddings(3, 0.5),
                    'metadata' => ['data' => 'Important data 2'],
                ],
            ],
            'transactionId' => $transactionId,
        ]);

        // Update first document inside the same transaction
        $this->client->call(Client::METHOD_PATCH, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/durable_doc_1", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'metadata' => ['data' => 'Updated important data 1'],
            ],
            'transactionId' => $transactionId,
        ]);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/vectordb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code'], 'Commit should succeed. Response: ' . json_encode($response['body']));
        $this->assertEquals('committed', $response['body']['status']);

        // Verify documents exist and have correct data
        $document1 = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/durable_doc_1", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $document1['headers']['status-code']);
        $this->assertEquals('Updated important data 1', $document1['body']['metadata']['data']);

        $document2 = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/durable_doc_2", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $document2['headers']['status-code']);
        $this->assertEquals('Important data 2', $document2['body']['metadata']['data']);

        // Further update outside transaction to ensure persistence
        $update = $this->client->call(Client::METHOD_PATCH, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/durable_doc_1", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'metadata' => ['data' => 'Modified outside transaction'],
            ],
        ]);
        $this->assertEquals(200, $update['headers']['status-code']);

        // Verify the update persisted
        $document1 = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/durable_doc_1", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals('Modified outside transaction', $document1['body']['metadata']['data']);

        // List all documents to verify total count
        $documents = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(2, $documents['body']['total']);
    }
}
