<?php

namespace Tests\Unit\Databases;

use Appwrite\Databases\PDOConnectionFactory;
use PHPUnit\Framework\TestCase;

class PDOConnectionFactoryTest extends TestCase
{
    public function testThrowsAfterMaxRetries(): void
    {
        $this->expectException(\PDOException::class);

        // Use an invalid DSN that will always fail, simulating a connection error.
        // We use maxRetries=2 and a very short delay to keep the test fast.
        PDOConnectionFactory::create(
            dsn: 'mysql:host=invalid_host_that_does_not_exist;port=9999;dbname=test;charset=utf8mb4',
            username: 'test',
            password: 'test',
            options: [
                \PDO::ATTR_TIMEOUT => 1,
            ],
            maxRetries: 2,
            retryDelayMs: 10,
        );
    }

    public function testRetriesBeforeThrowing(): void
    {
        $startTime = hrtime(true);

        try {
            PDOConnectionFactory::create(
                dsn: 'mysql:host=invalid_host_that_does_not_exist;port=9999;dbname=test;charset=utf8mb4',
                username: 'test',
                password: 'test',
                options: [
                    \PDO::ATTR_TIMEOUT => 1,
                ],
                maxRetries: 2,
                retryDelayMs: 50,
            );
        } catch (\PDOException) {
            // Expected
        }

        $elapsedMs = (hrtime(true) - $startTime) / 1_000_000;

        // With 2 retries at 50ms * attempt (50ms + 100ms = 150ms min delay),
        // elapsed time should be at least 100ms to prove retries happened
        $this->assertGreaterThan(100, $elapsedMs, 'Should have waited for retry delays');
    }

    public function testZeroRetriesThrowsImmediately(): void
    {
        $this->expectException(\PDOException::class);

        $startTime = hrtime(true);

        try {
            PDOConnectionFactory::create(
                dsn: 'mysql:host=invalid_host_that_does_not_exist;port=9999;dbname=test;charset=utf8mb4',
                username: 'test',
                password: 'test',
                options: [
                    \PDO::ATTR_TIMEOUT => 1,
                ],
                maxRetries: 0,
                retryDelayMs: 1000,
            );
        } catch (\PDOException $e) {
            $elapsedMs = (hrtime(true) - $startTime) / 1_000_000;
            // Should not have waited for any retry delay
            $this->assertLessThan(500, $elapsedMs, 'Should not have retried');
            throw $e;
        }
    }
}
