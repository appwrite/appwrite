<?php

namespace Utopia\Tests\Audit;

use Utopia\Audit\Audit;
use Utopia\Database\DateTime;

/**
 * Audit Test Trait
 *
 * This trait contains all the common test methods that should work
 * with any adapter (Database, ClickHouse, etc).
 *
 * Classes using this trait should implement initializeAudit() to initialize
 * the appropriate adapter and set $this->audit.
 */
trait AuditBase
{
    protected Audit $audit;

    /**
     * Classes using this trait must implement this to initialize the audit instance
     * with their specific adapter configuration
     */
    abstract protected function initializeAudit(): void;

    /**
     * Classes should override if they need custom setup
     */
    public function setUp(): void
    {
        $this->initializeAudit();
        $cleanup = new \DateTime();
        $cleanup->modify('+10 second');
        $this->audit->cleanup(new \DateTime());
        $this->createLogs();
    }

    /**
     * Classes should override if they need custom teardown
     */
    public function tearDown(): void
    {
        $cleanup = new \DateTime();
        $cleanup->modify('+10 second');
        $this->audit->cleanup(new \DateTime());
    }

    public function createLogs(): void
    {
        $userId = 'userId';
        $userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.88 Safari/537.36';
        $ip = '127.0.0.1';
        $data = ['key1' => 'value1', 'key2' => 'value2'];

        $requiredAttributes = $this->getRequiredAttributes();
        $dataWithAttributes = array_merge($data, $requiredAttributes);

        $this->assertInstanceOf(\Utopia\Audit\Log::class, $this->audit->log($userId, 'update', 'database/document/1', $userAgent, $ip, $dataWithAttributes));
        $this->assertInstanceOf(\Utopia\Audit\Log::class, $this->audit->log($userId, 'update', 'database/document/2', $userAgent, $ip, $dataWithAttributes));
        $this->assertInstanceOf(\Utopia\Audit\Log::class, $this->audit->log($userId, 'delete', 'database/document/2', $userAgent, $ip, $dataWithAttributes));
        $this->assertInstanceOf(\Utopia\Audit\Log::class, $this->audit->log(null, 'insert', 'user/null', $userAgent, $ip, $dataWithAttributes));
    }

    public function testPing(): void
    {
        $this->assertTrue($this->audit->ping());
    }

    public function testGetLogsByUser(): void
    {
        $logs = $this->audit->getLogsByUser('userId');
        $this->assertEquals(3, \count($logs));

        $logsCount = $this->audit->countLogsByUser('userId');
        $this->assertEquals(3, $logsCount);

        $logs1 = $this->audit->getLogsByUser('userId', limit: 1, offset: 1);
        $this->assertEquals(1, \count($logs1));
        $this->assertEquals($logs1[0]->getId(), $logs[1]->getId());

        $logs2 = $this->audit->getLogsByUser('userId', limit: 1, offset: 1);
        $this->assertEquals(1, \count($logs2));
        $this->assertEquals($logs2[0]->getId(), $logs[1]->getId());
    }

    public function testGetLogsByUserAndEvents(): void
    {
        $logs1 = $this->audit->getLogsByUserAndEvents('userId', ['update']);
        $logs2 = $this->audit->getLogsByUserAndEvents('userId', ['update', 'delete']);

        $this->assertEquals(2, \count($logs1));
        $this->assertEquals(3, \count($logs2));

        $logsCount1 = $this->audit->countLogsByUserAndEvents('userId', ['update']);
        $logsCount2 = $this->audit->countLogsByUserAndEvents('userId', ['update', 'delete']);

        $this->assertEquals(2, $logsCount1);
        $this->assertEquals(3, $logsCount2);

        $logs3 = $this->audit->getLogsByUserAndEvents('userId', ['update', 'delete'], limit: 1, offset: 1);

        $this->assertEquals(1, \count($logs3));
        $this->assertEquals($logs3[0]->getId(), $logs2[1]->getId());

        $logs4 = $this->audit->getLogsByUserAndEvents('userId', ['update', 'delete'], limit: 1, offset: 1);

        $this->assertEquals(1, \count($logs4));
        $this->assertEquals($logs4[0]->getId(), $logs2[1]->getId());
    }

    public function testGetLogsByResourceAndEvents(): void
    {
        $logs1 = $this->audit->getLogsByResourceAndEvents('database/document/1', ['update']);
        $logs2 = $this->audit->getLogsByResourceAndEvents('database/document/2', ['update', 'delete']);

        $this->assertEquals(1, \count($logs1));
        $this->assertEquals(2, \count($logs2));

        $logsCount1 = $this->audit->countLogsByResourceAndEvents('database/document/1', ['update']);
        $logsCount2 = $this->audit->countLogsByResourceAndEvents('database/document/2', ['update', 'delete']);

        $this->assertEquals(1, $logsCount1);
        $this->assertEquals(2, $logsCount2);

        $logs3 = $this->audit->getLogsByResourceAndEvents('database/document/2', ['update', 'delete'], limit: 1, offset: 1);

        $this->assertEquals(1, \count($logs3));
        $this->assertEquals($logs3[0]->getId(), $logs2[1]->getId());

        $logs4 = $this->audit->getLogsByResourceAndEvents('database/document/2', ['update', 'delete'], limit: 1, offset: 1);

        $this->assertEquals(1, \count($logs4));
        $this->assertEquals($logs4[0]->getId(), $logs2[1]->getId());
    }

    public function testGetLogsByResource(): void
    {
        $logs1 = $this->audit->getLogsByResource('database/document/1');
        $logs2 = $this->audit->getLogsByResource('database/document/2');

        $this->assertEquals(1, \count($logs1));
        $this->assertEquals(2, \count($logs2));

        $logsCount1 = $this->audit->countLogsByResource('database/document/1');
        $logsCount2 = $this->audit->countLogsByResource('database/document/2');

        $this->assertEquals(1, $logsCount1);
        $this->assertEquals(2, $logsCount2);

        $logs3 = $this->audit->getLogsByResource('database/document/2', limit: 1, offset: 1);
        $this->assertEquals(1, \count($logs3));
        $this->assertEquals($logs3[0]->getId(), $logs2[1]->getId());

        $logs4 = $this->audit->getLogsByResource('database/document/2', limit: 1, offset: 1);
        $this->assertEquals(1, \count($logs4));
        $this->assertEquals($logs4[0]->getId(), $logs2[1]->getId());

        $logs5 = $this->audit->getLogsByResource('user/null');
        $this->assertEquals(1, \count($logs5));
        $this->assertNull($logs5[0]['userId']);
        $this->assertEquals('127.0.0.1', $logs5[0]['ip']);
    }

    public function testGetLogById(): void
    {
        // Create a test log
        $userId = 'testGetByIdUser';
        $userAgent = 'Mozilla/5.0 Test';
        $ip = '192.168.1.100';
        $data = ['test' => 'getById'];

        $requiredAttributes = $this->getRequiredAttributes();
        $dataWithAttributes = array_merge($data, $requiredAttributes);
        $log = $this->audit->log($userId, 'create', 'test/resource/123', $userAgent, $ip, $dataWithAttributes);
        $logId = $log->getId();

        // Retrieve the log by ID
        $retrievedLog = $this->audit->getLogById($logId);

        $this->assertNotNull($retrievedLog);
        $this->assertEquals($logId, $retrievedLog->getId());
        $this->assertEquals($userId, $retrievedLog->getAttribute('userId'));
        $this->assertEquals('create', $retrievedLog->getAttribute('event'));
        $this->assertEquals('test/resource/123', $retrievedLog->getAttribute('resource'));
        $this->assertEquals($userAgent, $retrievedLog->getAttribute('userAgent'));
        $this->assertEquals($ip, $retrievedLog->getAttribute('ip'));
        $this->assertEquals($data, $retrievedLog->getAttribute('data'));

        // Test with non-existent ID
        $nonExistentLog = $this->audit->getLogById('non-existent-id-12345');
        $this->assertNull($nonExistentLog);
    }

    public function testLogByBatch(): void
    {
        // First cleanup existing logs
        $this->audit->cleanup(new \DateTime());

        $userId = 'batchUserId';
        $userAgent = 'Mozilla/5.0 (Test User Agent)';
        $ip = '192.168.1.1';

        // Create timestamps 1 minute apart
        $timestamp1 = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -120)) ?? '';
        $timestamp2 = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -60)) ?? '';
        $timestamp3 = DateTime::formatTz(DateTime::now()) ?? '';

        $batchEvents = [
            [
                'userId' => $userId,
                'event' => 'create',
                'resource' => 'database/document/batch1',
                'userAgent' => $userAgent,
                'ip' => $ip,
                'data' => ['key' => 'value1'],
                'time' => $timestamp1,
            ],
            [
                'userId' => $userId,
                'event' => 'update',
                'resource' => 'database/document/batch2',
                'userAgent' => $userAgent,
                'ip' => $ip,
                'data' => ['key' => 'value2'],
                'time' => $timestamp2,
            ],
            [
                'userId' => $userId,
                'event' => 'delete',
                'resource' => 'database/document/batch3',
                'userAgent' => $userAgent,
                'ip' => $ip,
                'data' => ['key' => 'value3'],
                'time' => $timestamp3,
            ],
            [
                'userId' => null,
                'event' => 'insert',
                'resource' => 'user1/null',
                'userAgent' => $userAgent,
                'ip' => $ip,
                'data' => ['key' => 'value4'],
                'time' => $timestamp3,
            ],
        ];

        // Test batch insertion
        $batchEvents = $this->applyRequiredAttributesToBatch($batchEvents);

        $result = $this->audit->logBatch($batchEvents);
        $this->assertTrue($result);

        // Verify the number of logs inserted
        $logs = $this->audit->getLogsByUser($userId);
        $this->assertEquals(3, \count($logs));

        // Verify chronological order (newest first due to orderDesc)
        $this->assertEquals('delete', $logs[0]->getAttribute('event'));
        $this->assertEquals('update', $logs[1]->getAttribute('event'));
        $this->assertEquals('create', $logs[2]->getAttribute('event'));

        // Verify timestamps were preserved
        $this->assertEquals($timestamp3, $logs[0]->getAttribute('time'));
        $this->assertEquals($timestamp2, $logs[1]->getAttribute('time'));
        $this->assertEquals($timestamp1, $logs[2]->getAttribute('time'));

        // Test resource-based retrieval
        $resourceLogs = $this->audit->getLogsByResource('database/document/batch2');
        $this->assertEquals(1, \count($resourceLogs));
        $this->assertEquals('update', $resourceLogs[0]->getAttribute('event'));

        // Test resource with userId null
        $resourceLogs = $this->audit->getLogsByResource('user1/null');
        $this->assertEquals(1, \count($resourceLogs));
        foreach ($resourceLogs as $log) {
            $this->assertEquals('insert', $log->getAttribute('event'));
            $this->assertNull($log['userId']);
        }

        // Test event-based retrieval
        $eventLogs = $this->audit->getLogsByUserAndEvents($userId, ['create', 'delete']);
        $this->assertEquals(2, \count($eventLogs));
    }

    public function testGetLogsCustomFilters(): void
    {
        $threshold = new \DateTime();
        $threshold->modify('-10 seconds');
        $logs = $this->audit->getLogsByUser('userId', after: $threshold);

        $this->assertEquals(3, \count($logs));
    }

    public function testAscendingOrderRetrieval(): void
    {
        // Test ascending order retrieval
        $logsDesc = $this->audit->getLogsByUser('userId', ascending: false);
        $logsAsc = $this->audit->getLogsByUser('userId', ascending: true);

        // Both should have same count
        $this->assertEquals(\count($logsDesc), \count($logsAsc));

        // Events should be in opposite order
        if (\count($logsDesc) > 1) {
            $descEvents = array_map(fn($log) => $log->getAttribute('event'), $logsDesc);
            $ascEvents = array_map(fn($log) => $log->getAttribute('event'), $logsAsc);
            $this->assertEquals($descEvents, array_reverse($ascEvents));
        }
    }

    public function testLargeBatchInsert(): void
    {
        // Create a large batch (50 events)
        $batchEvents = [];
        $baseTime = DateTime::now();
        for ($i = 0; $i < 50; $i++) {
            $batchEvents[] = [
                'userId' => 'largebatchuser',
                'event' => 'event_' . $i,
                'resource' => 'doc/' . $i,
                'userAgent' => 'Mozilla',
                'ip' => '127.0.0.1',
                'data' => ['index' => $i],
                'time' => DateTime::formatTz($baseTime) ?? '',
            ];
        }

        // Insert batch
        $batchEvents = $this->applyRequiredAttributesToBatch($batchEvents);
        $result = $this->audit->logBatch($batchEvents);
        $this->assertTrue($result);

        // Verify all were inserted
        $count = $this->audit->countLogsByUser('largebatchuser');
        $this->assertEquals(50, $count);

        // Test pagination
        $page1 = $this->audit->getLogsByUser('largebatchuser', limit: 10, offset: 0);
        $this->assertEquals(10, \count($page1));

        $page2 = $this->audit->getLogsByUser('largebatchuser', limit: 10, offset: 10);
        $this->assertEquals(10, \count($page2));
    }

    public function testTimeRangeFilters(): void
    {
        // Create logs with different timestamps
        $old = DateTime::format(new \DateTime('2024-01-01 10:00:00'));
        $recent = DateTime::now();

        $batchEvents = [
            [
                'userId' => 'timerangeuser',
                'event' => 'old_event',
                'resource' => 'doc/1',
                'userAgent' => 'Mozilla',
                'ip' => '127.0.0.1',
                'data' => [],
                'time' => $old,
            ],
            [
                'userId' => 'timerangeuser',
                'event' => 'recent_event',
                'resource' => 'doc/2',
                'userAgent' => 'Mozilla',
                'ip' => '127.0.0.1',
                'data' => [],
                'time' => $recent,
            ],
        ];

        $batchEvents = $this->applyRequiredAttributesToBatch($batchEvents);
        $this->audit->logBatch($batchEvents);

        // Test getting all logs
        $all = $this->audit->getLogsByUser('timerangeuser');
        $this->assertGreaterThanOrEqual(2, \count($all));

        // Test with before filter - should get both since they're both in the past relative to future
        $beforeFuture = new \DateTime('2099-12-31 23:59:59');
        $beforeLogs = $this->audit->getLogsByUser('timerangeuser', before: $beforeFuture);
        $this->assertGreaterThanOrEqual(2, \count($beforeLogs));
    }

    public function testCleanup(): void
    {
        $status = $this->audit->cleanup(new \DateTime());
        $this->assertEquals($status, true);

        // Check that all logs have been deleted
        $logs = $this->audit->getLogsByUser('userId');
        $this->assertEquals(0, \count($logs));

        // Add three sample logs
        $userId = 'userId';
        $userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.88 Safari/537.36';
        $ip = '127.0.0.1';
        $data = ['key1' => 'value1', 'key2' => 'value2'];

        $requiredAttributes = $this->getRequiredAttributes();
        $dataWithAttributes = array_merge($data, $requiredAttributes);

        $this->assertInstanceOf(\Utopia\Audit\Log::class, $this->audit->log($userId, 'update', 'database/document/1', $userAgent, $ip, $dataWithAttributes));
        sleep(5);
        $this->assertInstanceOf(\Utopia\Audit\Log::class, $this->audit->log($userId, 'update', 'database/document/2', $userAgent, $ip, $dataWithAttributes));
        sleep(5);
        $this->assertInstanceOf(\Utopia\Audit\Log::class, $this->audit->log($userId, 'delete', 'database/document/2', $userAgent, $ip, $dataWithAttributes));
        sleep(5);

        // DELETE logs older than 11 seconds and check that status is true
        $datetime = new \DateTime();
        $datetime->modify('-11 seconds');
        $status = $this->audit->cleanup($datetime);
        $this->assertEquals($status, true);

        // Check if 1 log has been deleted
        $logs = $this->audit->getLogsByUser('userId');
        $this->assertEquals(2, \count($logs));
    }

    /**
     * Test all additional retrieval parameters: limit, offset, ascending, after, before
     */
    public function testRetrievalParameters(): void
    {
        // Setup: Create logs with specific timestamps for testing
        $this->audit->cleanup(new \DateTime());

        $userId = 'paramtestuser';
        $userAgent = 'Mozilla/5.0';
        $ip = '192.168.1.1';

        // Create 5 logs with different timestamps
        new \DateTime('2024-06-15 12:00:00');
        $batchEvents = [];
        for ($i = 0; $i < 5; $i++) {
            $offset = $i * 60;
            $logTime = new \DateTime('2024-06-15 12:00:00');
            $logTime->modify("+{$offset} seconds");
            $timestamp = DateTime::format($logTime);
            $batchEvents[] = [
                'userId' => $userId,
                'event' => 'event_' . $i,
                'resource' => 'doc/' . $i,
                'userAgent' => $userAgent,
                'ip' => $ip,
                'data' => ['sequence' => $i],
                'time' => $timestamp,
            ];
        }

        $batchEvents = $this->applyRequiredAttributesToBatch($batchEvents);

        $this->audit->logBatch($batchEvents);

        // Test 1: limit parameter
        $logsLimit2 = $this->audit->getLogsByUser($userId, limit: 2);
        $this->assertEquals(2, \count($logsLimit2));

        $logsLimit3 = $this->audit->getLogsByUser($userId, limit: 3);
        $this->assertEquals(3, \count($logsLimit3));

        // Test 2: offset parameter
        $logsOffset0 = $this->audit->getLogsByUser($userId, limit: 10, offset: 0);
        $logsOffset2 = $this->audit->getLogsByUser($userId, limit: 10, offset: 2);
        $logsOffset4 = $this->audit->getLogsByUser($userId, limit: 10, offset: 4);

        $this->assertEquals(5, \count($logsOffset0));
        $this->assertEquals(3, \count($logsOffset2));
        $this->assertEquals(1, \count($logsOffset4));

        // Verify offset returns different logs
        $this->assertNotEquals($logsOffset0[0]->getId(), $logsOffset2[0]->getId());
        $this->assertNotEquals($logsOffset2[0]->getId(), $logsOffset4[0]->getId());

        // Test 3: ascending parameter
        $logsDesc = $this->audit->getLogsByUser($userId, ascending: false);
        $logsAsc = $this->audit->getLogsByUser($userId, ascending: true);

        $this->assertEquals(5, \count($logsDesc));
        $this->assertEquals(5, \count($logsAsc));

        // Verify order is reversed
        if (\count($logsDesc) === \count($logsAsc)) {
            $counter = \count($logsDesc);
            for ($i = 0; $i < $counter; $i++) {
                $this->assertEquals(
                    $logsDesc[$i]->getId(),
                    $logsAsc[\count($logsAsc) - 1 - $i]->getId(),
                );
            }
        }

        // Test 4: after parameter (logs after a certain timestamp)
        $afterTimeObj = new \DateTime('2024-06-15 12:03:00'); // After 3rd log
        $logsAfter = $this->audit->getLogsByUser($userId, after: $afterTimeObj);
        // Should get logs at positions 3 and 4 (2 logs)
        $this->assertGreaterThanOrEqual(1, \count($logsAfter));

        // Test 5: before parameter (logs before a certain timestamp)
        $beforeTimeObj = new \DateTime('2024-06-15 12:02:00'); // Before 3rd log
        $logsBefore = $this->audit->getLogsByUser($userId, before: $beforeTimeObj);
        // Should get logs at positions 0, 1, 2 (3 logs)
        $this->assertGreaterThanOrEqual(1, \count($logsBefore));

        // Test 6: Combination of limit + offset
        $logsPage1 = $this->audit->getLogsByUser($userId, limit: 2, offset: 0);
        $logsPage2 = $this->audit->getLogsByUser($userId, limit: 2, offset: 2);
        $logsPage3 = $this->audit->getLogsByUser($userId, limit: 2, offset: 4);

        $this->assertEquals(2, \count($logsPage1));
        $this->assertEquals(2, \count($logsPage2));
        $this->assertEquals(1, \count($logsPage3));

        // Verify pages don't overlap
        $this->assertNotEquals($logsPage1[0]->getId(), $logsPage2[0]->getId());
        $this->assertNotEquals($logsPage2[0]->getId(), $logsPage3[0]->getId());

        // Test 7: Combination of ascending + limit
        $ascLimit2 = $this->audit->getLogsByUser($userId, limit: 2, ascending: true);
        $this->assertEquals(2, \count($ascLimit2));
        // First log should be oldest in ascending order
        $this->assertEquals('event_0', $ascLimit2[0]->getAttribute('event'));

        // Test 8: Combination of after + before (time range)
        $afterTimeObj2 = new \DateTime('2024-06-15 12:01:00');  // After 1st log
        $beforeTimeObj2 = new \DateTime('2024-06-15 12:04:00'); // Before 4th log
        $logsRange = $this->audit->getLogsByUser($userId, after: $afterTimeObj2, before: $beforeTimeObj2);
        $this->assertGreaterThanOrEqual(1, \count($logsRange));

        // Test 9: Test with getLogsByResource using parameters
        $logsRes = $this->audit->getLogsByResource('doc/0', limit: 1, offset: 0);
        $this->assertEquals(1, \count($logsRes));

        // Test 10: Test with getLogsByUserAndEvents using parameters
        $logsEvt = $this->audit->getLogsByUserAndEvents(
            $userId,
            ['event_1', 'event_2'],
            limit: 1,
            offset: 0,
            ascending: false,
        );
        $this->assertGreaterThanOrEqual(0, \count($logsEvt));

        // Test 11: Test count methods with after/before filters
        $countAll = $this->audit->countLogsByUser($userId);
        $this->assertEquals(5, $countAll);

        $countAfter = $this->audit->countLogsByUser($userId, after: $afterTimeObj);
        $this->assertGreaterThanOrEqual(0, $countAfter);

        $countBefore = $this->audit->countLogsByUser($userId, before: $beforeTimeObj);
        $this->assertGreaterThanOrEqual(0, $countBefore);

        // Test 12: Test countLogsByResource with filters
        $countResAll = $this->audit->countLogsByResource('doc/0');
        $this->assertEquals(1, $countResAll);

        $countResAfter = $this->audit->countLogsByResource('doc/0', after: $afterTimeObj);
        $this->assertGreaterThanOrEqual(0, $countResAfter);

        // Test 13: Test countLogsByUserAndEvents with filters
        $countEvtAll = $this->audit->countLogsByUserAndEvents($userId, ['event_1', 'event_2']);
        $this->assertGreaterThanOrEqual(0, $countEvtAll);

        $countEvtAfter = $this->audit->countLogsByUserAndEvents(
            $userId,
            ['event_1', 'event_2'],
            after: $afterTimeObj,
        );
        $this->assertGreaterThanOrEqual(0, $countEvtAfter);

        // Test 14: Test countLogsByResourceAndEvents with filters
        $countResEvtAll = $this->audit->countLogsByResourceAndEvents('doc/0', ['event_0']);
        $this->assertEquals(1, $countResEvtAll);

        $countResEvtAfter = $this->audit->countLogsByResourceAndEvents(
            'doc/0',
            ['event_0'],
            after: $afterTimeObj,
        );
        $this->assertGreaterThanOrEqual(0, $countResEvtAfter);

        // Test 15: Test getLogsByResourceAndEvents with all parameters
        $logsResEvt = $this->audit->getLogsByResourceAndEvents(
            'doc/1',
            ['event_1'],
            limit: 1,
            offset: 0,
            ascending: true,
        );
        $this->assertGreaterThanOrEqual(0, \count($logsResEvt));
    }

    public function testFind(): void
    {
        // Setup: Create specific test data for find queries
        $this->audit->cleanup(new \DateTime());

        $userId = 'userId';
        $userAgent = 'Mozilla/5.0';
        $ip = '192.168.1.1';

        // Create test logs with specific attributes
        new \DateTime('2024-06-15 12:00:00');
        $batchEvents = [];
        for ($i = 0; $i < 3; $i++) {
            $offset = $i * 60;
            $logTime = new \DateTime('2024-06-15 12:00:00');
            $logTime->modify("+{$offset} seconds");
            $timestamp = DateTime::format($logTime);
            $batchEvents[] = [
                'userId' => $userId,
                'event' => 'event_' . $i,
                'resource' => 'doc/' . $i,
                'userAgent' => $userAgent,
                'ip' => $ip,
                'data' => ['sequence' => $i],
                'time' => $timestamp,
            ];
        }
        $batchEvents = $this->applyRequiredAttributesToBatch($batchEvents);

        $this->audit->logBatch($batchEvents);

        // Test 1: Find with equal filter
        $logs = $this->audit->find([
            \Utopia\Audit\Query::equal('userId', $userId),
        ]);
        $this->assertEquals(3, \count($logs));

        // Test 2: Find with equal and limit
        $logs = $this->audit->find([
            \Utopia\Audit\Query::equal('userId', $userId),
            \Utopia\Audit\Query::limit(2),
        ]);
        $this->assertEquals(2, \count($logs));

        // Test 3: Find with equal, limit and offset
        $logs = $this->audit->find([
            \Utopia\Audit\Query::equal('userId', $userId),
            \Utopia\Audit\Query::limit(2),
            \Utopia\Audit\Query::offset(1),
        ]);
        $this->assertEquals(2, \count($logs));

        // Test 4: Find with multiple filters
        $logs = $this->audit->find([
            \Utopia\Audit\Query::equal('userId', $userId),
            \Utopia\Audit\Query::equal('resource', 'doc/0'),
        ]);
        $this->assertEquals(1, \count($logs));

        // Test 5: Find with ordering
        $logsDesc = $this->audit->find([
            \Utopia\Audit\Query::equal('userId', $userId),
            \Utopia\Audit\Query::orderDesc('time'),
        ]);
        $logsAsc = $this->audit->find([
            \Utopia\Audit\Query::equal('userId', $userId),
            \Utopia\Audit\Query::orderAsc('time'),
        ]);
        $this->assertEquals(3, \count($logsDesc));
        $this->assertEquals(3, \count($logsAsc));

        // Verify order is reversed
        if (\count($logsDesc) === \count($logsAsc)) {
            $counter = \count($logsDesc);
            for ($i = 0; $i < $counter; $i++) {
                $this->assertEquals(
                    $logsDesc[$i]->getId(),
                    $logsAsc[\count($logsAsc) - 1 - $i]->getId(),
                );
            }
        }

        // Test 6: Find with contains filter (substring match, like utopia-php/database)
        $logs = $this->audit->find([
            \Utopia\Audit\Query::contains('event', ['event_0', 'event_1']),
        ]);
        $this->assertGreaterThanOrEqual(2, \count($logs));

        // Substring needle matches without an exact value
        $logs = $this->audit->find([
            \Utopia\Audit\Query::contains('event', ['vent_0']),
        ]);
        $this->assertGreaterThanOrEqual(1, \count($logs));
        foreach ($logs as $log) {
            $this->assertStringContainsString('vent_0', $log->getEvent());
        }

        // Test 7: Find with between query for time range
        $afterTime = new \DateTime('2024-06-15 12:01:00');
        $beforeTime = new \DateTime('2024-06-15 12:04:00');
        $logs = $this->audit->find([
            \Utopia\Audit\Query::equal('userId', $userId),
            \Utopia\Audit\Query::between('time', DateTime::format($afterTime), DateTime::format($beforeTime)),
        ]);
        $this->assertGreaterThanOrEqual(0, \count($logs));

        // Test 8: Find with greater than
        $afterTime = new \DateTime('2024-06-15 12:02:00');
        $logs = $this->audit->find([
            \Utopia\Audit\Query::equal('userId', $userId),
            \Utopia\Audit\Query::greaterThan('time', DateTime::format($afterTime)),
        ]);
        $this->assertGreaterThanOrEqual(0, \count($logs));

        // Test 9: Find with less than
        $beforeTime = new \DateTime('2024-06-15 12:03:00');
        $logs = $this->audit->find([
            \Utopia\Audit\Query::equal('userId', $userId),
            \Utopia\Audit\Query::lessThan('time', DateTime::format($beforeTime)),
        ]);
        $this->assertGreaterThanOrEqual(0, \count($logs));
    }

    public function testCount(): void
    {
        // Setup: Create specific test data for count queries
        $this->audit->cleanup(new \DateTime());

        $userId = 'userId';
        $userAgent = 'Mozilla/5.0';
        $ip = '192.168.1.1';

        // Create test logs with specific attributes
        new \DateTime('2024-06-15 12:00:00');
        $batchEvents = [];
        for ($i = 0; $i < 3; $i++) {
            $offset = $i * 60;
            $logTime = new \DateTime('2024-06-15 12:00:00');
            $logTime->modify("+{$offset} seconds");
            $timestamp = DateTime::format($logTime);
            $batchEvents[] = [
                'userId' => $userId,
                'event' => 'event_' . $i,
                'resource' => 'doc/' . $i,
                'userAgent' => $userAgent,
                'ip' => $ip,
                'data' => ['sequence' => $i],
                'time' => $timestamp,
            ];
        }
        $batchEvents = $this->applyRequiredAttributesToBatch($batchEvents);
        $this->audit->logBatch($batchEvents);

        // Test 1: Count with simple filter
        $count = $this->audit->count([
            \Utopia\Audit\Query::equal('userId', $userId),
        ]);
        $this->assertEquals(3, $count);

        // Test 2: Count with multiple filters
        $count = $this->audit->count([
            \Utopia\Audit\Query::equal('userId', $userId),
            \Utopia\Audit\Query::equal('resource', 'doc/0'),
        ]);
        $this->assertEquals(1, $count);

        // Test 3: Count with contains filter (substring match, like utopia-php/database)
        $count = $this->audit->count([
            \Utopia\Audit\Query::contains('event', ['event_0', 'event_1']),
        ]);
        $this->assertGreaterThanOrEqual(2, $count);

        // Test 4: Count ignores limit and offset
        $count = $this->audit->count([
            \Utopia\Audit\Query::equal('userId', $userId),
            \Utopia\Audit\Query::limit(2),
            \Utopia\Audit\Query::offset(1),
        ]);
        $this->assertEquals(3, $count); // Should count all 3, not affected by limit/offset

        // Test 5: Count with between query
        $afterTime = new \DateTime('2024-06-15 12:01:00');
        $beforeTime = new \DateTime('2024-06-15 12:04:00');
        $count = $this->audit->count([
            \Utopia\Audit\Query::equal('userId', $userId),
            \Utopia\Audit\Query::between('time', DateTime::format($afterTime), DateTime::format($beforeTime)),
        ]);
        $this->assertGreaterThanOrEqual(0, $count);

        // Test 6: Count with greater than
        $afterTime = new \DateTime('2024-06-15 12:02:00');
        $count = $this->audit->count([
            \Utopia\Audit\Query::equal('userId', $userId),
            \Utopia\Audit\Query::greaterThan('time', DateTime::format($afterTime)),
        ]);
        $this->assertGreaterThanOrEqual(0, $count);

        // Test 7: Count with less than
        $beforeTime = new \DateTime('2024-06-15 12:03:00');
        $count = $this->audit->count([
            \Utopia\Audit\Query::equal('userId', $userId),
            \Utopia\Audit\Query::lessThan('time', DateTime::format($beforeTime)),
        ]);
        $this->assertGreaterThanOrEqual(0, $count);

        // Test 8: Count returns zero for no matches
        $count = $this->audit->count([
            \Utopia\Audit\Query::equal('userId', 'nonExistentUser'),
        ]);
        $this->assertEquals(0, $count);
    }

    /**
     * Apply adapter-specific required attributes to batch events.
     *
     * @param array<int, array<string, mixed>> $batchEvents
     * @return array<array{userId: string|null, event: string, resource: string, userAgent: string, ip: string, time: string, data?: array<string, mixed>}>
     */
    protected function applyRequiredAttributesToBatch(array $batchEvents): array
    {
        $requiredAttributes = $this->getRequiredAttributes();
        if ($requiredAttributes === []) {
            return $batchEvents;
        }

        /** @var array<array{userId: string|null, event: string, resource: string, userAgent: string, ip: string, time: string, data?: array<string, mixed>}> */
        return array_map(static fn(array $event): array => array_merge($event, $requiredAttributes), $batchEvents);
    }

    /**
     * Override in adapter-specific tests to provide required attribute defaults.
     *
     * @return array<string, mixed>
     */
    protected function getRequiredAttributes(): array
    {
        return [];
    }
}
