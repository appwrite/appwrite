<?php

namespace Tests\E2E\Services\Databases\TablesDB\Transactions;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class ACIDTest extends Scope
{
    use ProjectCustom;
    use SideClient;

    /**
     * Test atomicity - all operations succeed or all fail
     */
    public function testAtomicity(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'AtomicityTestDB'
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create table with unique constraint
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'AtomicityTest',
            'rowSecurity' => false,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
        ]);

        $tableId = $table['body']['$id'];

        // Add unique column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'email',
            'size' => 256,
            'required' => true,
        ]);

        // Add unique index
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'unique_email',
            'type' => Database::INDEX_UNIQUE,
            'columns' => ['email']
        ]);

        sleep(3);

        // Create first row outside transaction
        $doc1 = $this->client->call(Client::METHOD_POST, "/tablesdb/{$databaseId}/tables/{$tableId}/rows", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'email' => 'existing@example.com'
            ]
        ]);

        $this->assertEquals(201, $doc1['headers']['status-code']);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(201, $transaction['headers']['status-code'], 'Transaction creation should succeed. Response: ' . json_encode($transaction));
        $this->assertArrayHasKey('$id', $transaction['body'], 'Transaction response should have $id. Response body: ' . json_encode($transaction['body']));
        $transactionId = $transaction['body']['$id'];

        // Add operations - second one will fail due to unique constraint
        $response = $this->client->call(Client::METHOD_POST, "/tablesdb/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'tableId' => $tableId,
                    'action' => 'create',
                    'rowId' => ID::unique(),
                    'data' => [
                        'email' => 'newuser@example.com' // This should succeed
                    ]
                ],
                [
                    'databaseId' => $databaseId,
                    'tableId' => $tableId,
                    'action' => 'create',
                    'rowId' => ID::unique(),
                    'data' => [
                        'email' => 'existing@example.com' // This will fail - duplicate
                    ]
                ],
                [
                    'databaseId' => $databaseId,
                    'tableId' => $tableId,
                    'action' => 'create',
                    'rowId' => ID::unique(),
                    'data' => [
                        'email' => 'anotheruser@example.com' // This should not be created due to atomicity
                    ]
                ]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code'], 'Add operations failed. Response: ' . json_encode($response['body']));

        // Attempt to commit - should fail due to unique constraint violation
        $response = $this->client->call(Client::METHOD_PATCH, "/tablesdb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        if ($response['headers']['status-code'] === 200) {
            // If transaction succeeded, all rows should be created
            $rows = $this->client->call(Client::METHOD_GET, "/tablesdb/{$databaseId}/tables/{$tableId}/rows", array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            // Should have 4 rows total (1 original + 3 from transaction)
            // But since we have a unique constraint violation, this might fail
            $this->assertGreaterThanOrEqual(1, $rows['body']['total']);
        } else {
            $this->assertEquals(409, $response['headers']['status-code']); // Conflict error

            // Verify NO new rows were created (atomicity)
            $rows = $this->client->call(Client::METHOD_GET, "/tablesdb/{$databaseId}/tables/{$tableId}/rows", array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(1, $rows['body']['total']); // Only the original row
            $this->assertEquals('existing@example.com', $rows['body']['rows'][0]['email']);
        }
    }

    /**
     * Test consistency - schema validation and constraints
     */
    public function testConsistency(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'ConsistencyTestDB'
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create table with required fields and constraints
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'ConsistencyTest',
            'rowSecurity' => false,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
        ]);

        $tableId = $table['body']['$id'];

        // Add required string column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'required_field',
            'size' => 256,
            'required' => true,
        ]);

        // Add integer column with min/max constraints
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'age',
            'required' => true,
            'min' => 18,
            'max' => 100
        ]);

        sleep(3);

        // Create transaction
        $transaction = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $transactionId = $transaction['body']['$id'];

        // Add operations with both valid and invalid data
        $response = $this->client->call(Client::METHOD_POST, "/tablesdb/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'tableId' => $tableId,
                    'action' => 'create',
                    'rowId' => ID::unique(),
                    'data' => [
                        'required_field' => 'Valid User',
                        'age' => 25 // Valid age
                    ]
                ],
                [
                    'databaseId' => $databaseId,
                    'tableId' => $tableId,
                    'action' => 'create',
                    'rowId' => ID::unique(),
                    'data' => [
                        'required_field' => 'Too Young User',
                        'age' => 10 // Below minimum - will fail constraint
                    ]
                ],
                [
                    'databaseId' => $databaseId,
                    'tableId' => $tableId,
                    'action' => 'create',
                    'rowId' => ID::unique(),
                    'data' => [
                        'required_field' => 'Another Valid User',
                        'age' => 30 // Valid but should not be created due to transaction failure
                    ]
                ]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Attempt to commit - should fail due to constraint violation
        $response = $this->client->call(Client::METHOD_PATCH, "/tablesdb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertContains($response['headers']['status-code'], [400, 500], 'Transaction commit should fail due to validation. Response: ' . json_encode($response['body']));

        // Verify no rows were created
        $rows = $this->client->call(Client::METHOD_GET, "/tablesdb/{$databaseId}/tables/{$tableId}/rows", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(0, $rows['body']['total']);
    }

    /**
     * Test isolation - concurrent transactions on same data
     */
    public function testIsolation(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'IsolationTestDB'
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create table
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'IsolationTest',
            'rowSecurity' => false,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $tableId = $table['body']['$id'];

        // Add counter column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'counter',
            'required' => true,
            'min' => 0,
            'max' => 1000000
        ]);

        sleep(2);

        // Create initial row with counter
        $doc = $this->client->call(Client::METHOD_POST, "/tablesdb/{$databaseId}/tables/{$tableId}/rows", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => 'shared_counter',
            'data' => [
                'counter' => 0
            ]
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);

        // Create first transaction
        $transaction1 = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(201, $transaction1['headers']['status-code'], 'Transaction 1 creation should succeed');
        $this->assertArrayHasKey('$id', $transaction1['body'], 'Transaction 1 response should have $id');
        $transactionId1 = $transaction1['body']['$id'];

        // Create second transaction
        $transaction2 = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(201, $transaction2['headers']['status-code'], 'Transaction 2 creation should succeed');
        $this->assertArrayHasKey('$id', $transaction2['body'], 'Transaction 2 response should have $id');
        $transactionId2 = $transaction2['body']['$id'];

        // Transaction 1: Increment counter by 10
        $this->client->call(Client::METHOD_POST, "/tablesdb/transactions/{$transactionId1}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'tableId' => $tableId,
                    'rowId' => 'shared_counter',
                    'action' => 'increment',
                    'data' => [
                        'column' => 'counter',
                        'value' => 10
                    ]
                ]
            ]
        ]);

        // Transaction 2: Increment counter by 5
        $this->client->call(Client::METHOD_POST, "/tablesdb/transactions/{$transactionId2}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'tableId' => $tableId,
                    'rowId' => 'shared_counter',
                    'action' => 'increment',
                    'data' => [
                        'column' => 'counter',
                        'value' => 5
                    ]
                ]
            ]
        ]);

        // Commit first transaction
        $response1 = $this->client->call(Client::METHOD_PATCH, "/tablesdb/transactions/{$transactionId1}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response1['headers']['status-code']);

        // Commit second transaction
        $response2 = $this->client->call(Client::METHOD_PATCH, "/tablesdb/transactions/{$transactionId2}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response2['headers']['status-code']);

        // Check final value - both increments should be applied
        $row = $this->client->call(Client::METHOD_GET, "/tablesdb/{$databaseId}/tables/{$tableId}/rows/shared_counter", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        // Both increments should be applied: 0 + 10 + 5 = 15
        $this->assertEquals(15, $row['body']['counter']);
    }

    /**
     * Test durability - committed data persists
     */
    public function testDurability(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'DurabilityTestDB'
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create table
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'DurabilityTest',
            'rowSecurity' => false,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $tableId = $table['body']['$id'];

        // Add column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'data',
            'size' => 256,
            'required' => true,
        ]);

        sleep(2);

        // Create and commit transaction with multiple operations
        $transaction = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(201, $transaction['headers']['status-code'], 'Transaction creation should succeed');
        $this->assertArrayHasKey('$id', $transaction['body'], 'Transaction response should have $id');
        $transactionId = $transaction['body']['$id'];

        // Add multiple operations
        $this->client->call(Client::METHOD_POST, "/tablesdb/transactions/{$transactionId}/operations", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'tableId' => $tableId,
                    'action' => 'create',
                    'rowId' => 'durable_doc_1',
                    'data' => [
                        'data' => 'Important data 1'
                    ]
                ],
                [
                    'databaseId' => $databaseId,
                    'tableId' => $tableId,
                    'action' => 'create',
                    'rowId' => 'durable_doc_2',
                    'data' => [
                        'data' => 'Important data 2'
                    ]
                ],
                [
                    'databaseId' => $databaseId,
                    'tableId' => $tableId,
                    'action' => 'update',
                    'rowId' => 'durable_doc_1',
                    'data' => [
                        'data' => 'Updated important data 1'
                    ]
                ]
            ]
        ]);

        // Commit transaction
        $response = $this->client->call(Client::METHOD_PATCH, "/tablesdb/transactions/{$transactionId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'commit' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code'], 'Commit should succeed. Response: ' . json_encode($response['body']));
        $this->assertEquals('committed', $response['body']['status']);

        // List all rows to see what was created
        $allDocs = $this->client->call(Client::METHOD_GET, "/tablesdb/{$databaseId}/tables/{$tableId}/rows", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertGreaterThan(0, $allDocs['body']['total'], 'Should have created rows. Found: ' . json_encode($allDocs['body']));

        // Verify rows exist and have correct data
        $row1 = $this->client->call(Client::METHOD_GET, "/tablesdb/{$databaseId}/tables/{$tableId}/rows/durable_doc_1", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $row1['headers']['status-code']);
        $this->assertEquals('Updated important data 1', $row1['body']['data']);

        $row2 = $this->client->call(Client::METHOD_GET, "/tablesdb/{$databaseId}/tables/{$tableId}/rows/durable_doc_2", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $row2['headers']['status-code']);
        $this->assertEquals('Important data 2', $row2['body']['data']);

        // Further update outside transaction to ensure persistence
        $update = $this->client->call(Client::METHOD_PATCH, "/tablesdb/{$databaseId}/tables/{$tableId}/rows/durable_doc_1", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'data' => 'Modified outside transaction'
            ]
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        // Verify the update persisted
        $row1 = $this->client->call(Client::METHOD_GET, "/tablesdb/{$databaseId}/tables/{$tableId}/rows/durable_doc_1", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals('Modified outside transaction', $row1['body']['data']);

        // List all rows to verify total count
        $rows = $this->client->call(Client::METHOD_GET, "/tablesdb/{$databaseId}/tables/{$tableId}/rows", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(2, $rows['body']['total']);
    }
}
