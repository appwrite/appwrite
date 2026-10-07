<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Mongo\Client;
use Utopia\Mongo\Exception;

class TransactionTest extends TestCase
{
    private static ?Client $client = null;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$client = new Client(
                database: 'testing',
                host: 'mongo',
                port: 27017,
                user: 'root',
                password: 'example',
                useCoroutine: false
            );
            self::$client->connect();
        } catch (Exception $e) {
            self::markTestSkipped('MongoDB connection failed: ' . $e->getMessage());
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$client?->close();
    }

    private function getClient(): Client
    {
        if (!self::$client) {
            self::markTestSkipped('MongoDB client not available');
        }
        return self::$client;
    }

    /**
     * Check if MongoDB instance supports transactions (requires replica set)
     */
    private function isReplicatSet(): bool
    {
        try {
            $client = $this->getClient();

            // Try to get server status to check if it's a replica set
            $result = $client->query(['isMaster' => 1], 'admin');

            // Check various fields that indicate replica set or sharding
            $isReplicaSet = isset($result->setName) ||
                isset($result->hosts) ||
                isset($result->ismaster) && isset($result->secondary) ||
                isset($result->isWritablePrimary) ||
                isset($result->msg) && $result->msg === 'isdbgrid';

            if (!$isReplicaSet) {
                return false;
            }
        } catch (\Exception $e) {
            try {
                $client = $this->getClient();
                $testSession = $client->startSession();
                $client->startTransaction($testSession);

                // Try a simple insert with transaction params
                $client->insert(
                    '_test_tx_check',
                    ['test' => true],
                    ['session' => $testSession]
                );

                // If we get here, transactions work - abort and clean up
                $client->abortTransaction($testSession);
                $client->endSessions([$testSession]);
                $client->delete('_test_tx_check', ['test' => true]);
            } catch (Exception $txError) {
                if (strpos($txError->getMessage(), 'Transaction numbers are only allowed') !== false ||
                    strpos($txError->getMessage(), 'IllegalOperation') !== false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Test basic session creation and cleanup
     */
    public function testSessionManagement()
    {
        $client = $this->getClient();

        // Create session with options
        $session = $client->startSession([
            'causalConsistency' => true,
            'defaultTransactionOptions' => [
                'readConcern' => ['level' => 'majority'],
                'writeConcern' => ['w' => 1, 'j' => true]
            ]
        ]);

        $this->assertArrayHasKey('id', $session);
        $this->assertArrayHasKey('sessionId', $session);

        // Get session state
        $state = $client->getSessionState($session);
        $this->assertEquals('none', $state['state']);
        $this->assertEquals(0, $state['txnNumber']);

        // End session
        $client->endSessions([$session]);

        // Verify session is cleaned up
        $stateAfterEnd = $client->getSessionState($session);
        $this->assertArrayHasKey('error', $stateAfterEnd);
    }

    /**
     * Test transaction with proper state management
     */
    public function testTransactionStateManagement()
    {
        if (!$this->isReplicatSet()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $client = $this->getClient();
        $session = $client->startSession();

        try {
            // Start transaction
            $result = $client->startTransaction($session, [
                'readConcern' => ['level' => 'majority'],
                'writeConcern' => ['w' => 1]
            ]);

            $this->assertTrue($result);

            // Verify transaction state
            $state = $client->getSessionState($session);
            $this->assertEquals('in_progress', $state['state']);
            $this->assertEquals(1, $state['txnNumber']);

            // Perform operations within transaction
            $client->insert(
                'test_collection',
                ['name' => 'test_doc', 'value' => 42],
                ['session' => $session]
            );

            // Commit transaction
            $commitResult = $client->commitTransaction($session);
            $this->assertNotNull($commitResult);

            // Verify final state
            $finalState = $client->getSessionState($session);
            $this->assertEquals('committed', $finalState['state']);
        } finally {
            $client->endSessions([$session]);
        }
    }

    /**
     * Test transaction abort functionality
     */
    public function testTransactionAbort()
    {
        if (!$this->isReplicatSet()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $client = $this->getClient();
        $session = $client->startSession();

        try {
            $client->startTransaction($session);

            // Insert a document
            $client->insert(
                'test_collection',
                ['name' => 'abort_test', 'value' => 999],
                ['session' => $session]
            );

            // Abort transaction
            $abortResult = $client->abortTransaction($session);
            $this->assertNotNull($abortResult);

            // Verify transaction was aborted
            $state = $client->getSessionState($session);
            $this->assertEquals('aborted', $state['state']);

            // Verify document was not inserted (transaction rolled back)
            $found = $client->find('test_collection', ['name' => 'abort_test']);
            $this->assertEmpty($found->cursor->firstBatch);
        } finally {
            $client->endSessions([$session]);
        }
    }

    /**
     * Test withTransaction helper with retry logic
     */
    public function testWithTransactionHelper()
    {
        if (!$this->isReplicatSet()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $client = $this->getClient();
        $session = $client->startSession();

        try {
            $result = $client->withTransaction($session, function ($session) use ($client) {
                // Insert multiple documents in transaction
                $client->insert(
                    'test_collection',
                    ['name' => 'with_transaction_1', 'counter' => 1],
                    ['session' => $session]
                );

                $client->insert(
                    'test_collection',
                    ['name' => 'with_transaction_2', 'counter' => 2],
                    ['session' => $session]
                );

                return 'transaction_completed';
            }, [
                'readConcern' => ['level' => 'majority'],
                'writeConcern' => ['w' => 1],
                'maxRetries' => 3
            ]);

            $this->assertEquals('transaction_completed', $result);

            // Verify both documents were inserted
            $found1 = $client->find('test_collection', ['name' => 'with_transaction_1']);
            $found2 = $client->find('test_collection', ['name' => 'with_transaction_2']);

            $this->assertNotEmpty($found1->cursor->firstBatch);
            $this->assertNotEmpty($found2->cursor->firstBatch);
        } finally {
            $client->endSessions([$session]);
        }
    }

    /**
     * Test read and write concerns validation
     */
    public function testReadWriteConcerns()
    {
        $client = $this->getClient();

        // Test valid write concern
        $writeConcern = $client->createWriteConcern(['w' => 'majority', 'j' => true, 'wtimeout' => 5000]);
        $this->assertEquals('majority', $writeConcern['w']);
        $this->assertTrue($writeConcern['j']);
        $this->assertEquals(5000, $writeConcern['wtimeout']);

        // Test valid read concern
        $readConcern = $client->createReadConcern(['level' => 'majority']);
        $this->assertEquals('majority', $readConcern['level']);

        // Test invalid read concern should throw exception
        $this->expectException(Exception::class);
        $client->createReadConcern(['level' => 'invalid']);
    }

    /**
     * Test CRUD operations with session and concerns
     */
    public function testCRUDWithSessionAndConcerns()
    {
        if (!$this->isReplicatSet()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $client = $this->getClient();
        $session = $client->startSession();

        try {
            $client->startTransaction($session);

            // Insert with session and write concern
            $insertedDoc = $client->insert(
                'test_collection',
                ['name' => 'crud_test', 'status' => 'active'],
                [
                    'session' => $session,
                    'writeConcern' => ['w' => 1, 'j' => true]
                ]
            );

            $this->assertArrayHasKey('_id', $insertedDoc);

            // Find with session and read concern
            $found = $client->find(
                'test_collection',
                ['name' => 'crud_test'],
                [
                    'session' => $session,
                    'readConcern' => ['level' => 'local']
                ]
            );

            $this->assertNotEmpty($found->cursor->firstBatch);

            // Update with session
            $client->update(
                'test_collection',
                ['name' => 'crud_test'],
                ['$set' => ['status' => 'updated']],
                [
                    'session' => $session,
                    'writeConcern' => ['w' => 1]
                ]
            );

            // Count with session
            $count = $client->count(
                'test_collection',
                ['name' => 'crud_test'],
                [
                    'session' => $session,
                    'readConcern' => ['level' => 'local']
                ]
            );

            $this->assertEquals(1, $count);

            $client->commitTransaction($session);
        } finally {
            $client->endSessions([$session]);
        }
    }

    /**
     * Test connection validation and health checks
     */
    public function testConnectionValidation()
    {
        $client = $this->getClient();

        // Get connection info
        $info = $client->getConnectionInfo();

        $this->assertTrue($info['connected']);
        $this->assertEquals('mongo', $info['host']);
        $this->assertEquals(27017, $info['port']);
        $this->assertEquals('testing', $info['database']);
        $this->assertIsInt($info['activeSessions']);
    }

    /**
     * Test causal consistency tracking
     */
    public function testCausalConsistency()
    {
        $client = $this->getClient();

        // Perform an operation to get operation time
        $client->find('test_collection', []);

        // Check if operation time is tracked
        $operationTime = $client->getOperationTime();
        $clusterTime = $client->getClusterTime();

        // These may be null if not in a replica set, which is fine for testing
        $this->assertTrue($operationTime === null || is_object($operationTime));
        $this->assertTrue($clusterTime === null || is_object($clusterTime));
    }

    /**
     * Test session cleanup
     */
    public function testSessionCleanup()
    {
        $client = $this->getClient();

        // Create multiple sessions
        $sessions = [];
        for ($i = 0; $i < 3; $i++) {
            $sessions[] = $client->startSession();
        }

        // Verify sessions are active
        $info = $client->getConnectionInfo();
        $this->assertGreaterThanOrEqual(3, $info['activeSessions']);

        // End all sessions
        $client->endSessions($sessions);

        // Verify sessions are cleaned up
        $infoAfter = $client->getConnectionInfo();
        $this->assertLessThan($info['activeSessions'], $infoAfter['activeSessions']);
    }

    protected function tearDown(): void
    {
        // Clean up test data
        if (self::$client) {
            try {
                self::$client->dropCollection('test_collection');
            } catch (Exception $e) {
                // Ignore cleanup errors
            }
        }
    }
}
