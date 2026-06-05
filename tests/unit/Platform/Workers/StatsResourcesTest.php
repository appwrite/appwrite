<?php

namespace Tests\Unit\Platform\Workers;

use Appwrite\Platform\Workers\StatsResources;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;

class StatsResourcesTest extends TestCase
{
    private StatsResources $worker;
    private \ReflectionMethod $countMethod;

    protected function setUp(): void
    {
        $this->worker = new StatsResources();
        $this->countMethod = new \ReflectionMethod($this->worker, 'countForProject');
        $this->countMethod->setAccessible(true);

        $logErrorProp = new \ReflectionProperty($this->worker, 'logError');
        $logErrorProp->setAccessible(true);
        $logErrorProp->setValue($this->worker, function () {});
    }

    private function makeProject(): Document
    {
        return new Document([
            '$id' => 'test-project',
            '$sequence' => 1,
            'region' => 'fra',
            'database' => 'test-db',
        ]);
    }

    private function makeDbMock(): Database
    {
        $mock = $this->createMock(Database::class);
        $mock->method('count')->willReturn(0);
        $mock->method('sum')->willReturn(0);
        $mock->method('find')->willReturn([]);
        $mock->method('upsertDocuments')->willReturn(0);
        return $mock;
    }

    /**
     * Test that countForProject retries on transient PDOException
     * and succeeds on subsequent attempt.
     */
    public function testRetriesOnPDOExceptionDuringInit(): void
    {
        $callCount = 0;
        $dbForPlatform = $this->makeDbMock();
        $mockDbForLogs = $this->makeDbMock();
        $mockDbForProject = $this->makeDbMock();

        $getLogsDB = fn() => $mockDbForLogs;
        $getProjectDB = function () use (&$callCount, $mockDbForProject) {
            $callCount++;
            if ($callCount === 1) {
                throw new \PDOException(
                    'SQLSTATE[HY000]: General error: 9001 Max connect timeout reached while reaching hostgroup 10 after 10000ms'
                );
            }
            return $mockDbForProject;
        };

        $this->countMethod->invoke($this->worker, $dbForPlatform, $getLogsDB, $getProjectDB, $this->makeProject());

        $this->assertEquals(2, $callCount, 'getProjectDB should be called twice (first fails, second succeeds)');
    }

    /**
     * Test that countForProject gives up after max retries and logs error.
     */
    public function testGivesUpAfterMaxRetries(): void
    {
        $callCount = 0;
        $dbForPlatform = $this->makeDbMock();
        $mockDbForLogs = $this->makeDbMock();

        $getLogsDB = fn() => $mockDbForLogs;
        $getProjectDB = function () use (&$callCount) {
            $callCount++;
            throw new \PDOException(
                'SQLSTATE[HY000]: General error: 9001 Max connect timeout reached while reaching hostgroup 10 after 10000ms'
            );
        };

        $errors = [];
        $logErrorProp = new \ReflectionProperty($this->worker, 'logError');
        $logErrorProp->setAccessible(true);
        $logErrorProp->setValue($this->worker, function ($error, $context, $action) use (&$errors) {
            $errors[] = $action;
        });

        $this->countMethod->invoke($this->worker, $dbForPlatform, $getLogsDB, $getProjectDB, $this->makeProject());

        $this->assertEquals(3, $callCount, 'Should retry exactly 3 times');
        $this->assertNotEmpty($errors, 'Should log error after exhausting retries');
        $this->assertStringContainsString('count_for_project', $errors[0]);
    }

    /**
     * Test that non-PDOException errors are not retried.
     */
    public function testDoesNotRetryNonPDOExceptions(): void
    {
        $callCount = 0;
        $dbForPlatform = $this->makeDbMock();
        $mockDbForLogs = $this->makeDbMock();

        $getLogsDB = fn() => $mockDbForLogs;
        $getProjectDB = function () use (&$callCount) {
            $callCount++;
            throw new \RuntimeException('Some permanent error');
        };

        $errors = [];
        $logErrorProp = new \ReflectionProperty($this->worker, 'logError');
        $logErrorProp->setAccessible(true);
        $logErrorProp->setValue($this->worker, function ($error, $context, $action) use (&$errors) {
            $errors[] = $action;
        });

        $this->countMethod->invoke($this->worker, $dbForPlatform, $getLogsDB, $getProjectDB, $this->makeProject());

        $this->assertEquals(1, $callCount, 'Non-PDO errors should not be retried');
        $this->assertNotEmpty($errors, 'Should log the non-transient error');
    }

    /**
     * Test that PDOException during count operations triggers retry of entire attempt.
     */
    public function testRetriesPDOExceptionDuringCounting(): void
    {
        $platformCountCalls = 0;
        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->method('count')
            ->willReturnCallback(function () use (&$platformCountCalls) {
                $platformCountCalls++;
                if ($platformCountCalls === 1) { // First call in first attempt fails
                    throw new \PDOException(
                        'SQLSTATE[HY000]: General error: 9001 Max connect timeout reached'
                    );
                }
                return 0;
            });

        $mockDbForLogs = $this->makeDbMock();
        $mockDbForProject = $this->makeDbMock();

        $getLogsDB = fn() => $mockDbForLogs;
        $getProjectDB = fn() => $mockDbForProject;

        $this->countMethod->invoke($this->worker, $dbForPlatform, $getLogsDB, $getProjectDB, $this->makeProject());

        // First attempt fails on call 1, second attempt succeeds with 4 platform counts
        $this->assertGreaterThan(1, $platformCountCalls, 'Should have retried after PDOException during counting');
    }
}
