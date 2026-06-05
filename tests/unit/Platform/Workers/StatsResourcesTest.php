<?php

namespace Tests\Unit\Platform\Workers;

use Appwrite\Platform\Workers\StatsResources;
use PHPUnit\Framework\TestCase;
use PDOException;

class StatsResourcesTest extends TestCase
{
    public function testRetryOnTransientErrorSucceedsAfterRetry(): void
    {
        $worker = new StatsResources();
        $attempts = 0;

        $result = $worker->retry(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new PDOException(
                    'SQLSTATE[HY000]: General error: 9001 Max connect timeout reached while reaching hostgroup 10 after 10000ms'
                );
            }
            return 'success';
        }, 3, 0);

        $this->assertEquals('success', $result);
        $this->assertEquals(3, $attempts);
    }

    public function testRetryThrowsAfterMaxAttempts(): void
    {
        $worker = new StatsResources();

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Max connect timeout');

        $worker->retry(function () {
            throw new PDOException(
                'SQLSTATE[HY000]: General error: 9001 Max connect timeout reached while reaching hostgroup 10 after 10000ms'
            );
        }, 3, 0);
    }

    public function testRetryDoesNotRetryNonTransientErrors(): void
    {
        $worker = new StatsResources();
        $attempts = 0;

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Some other error');

        $worker->retry(function () use (&$attempts) {
            $attempts++;
            throw new PDOException('Some other error');
        }, 3, 0);

        $this->assertEquals(1, $attempts);
    }

    public function testRetrySucceedsOnFirstAttempt(): void
    {
        $worker = new StatsResources();

        $result = $worker->retry(function () {
            return 42;
        }, 3, 0);

        $this->assertEquals(42, $result);
    }

    public function testRetryHandlesConnectionGoneAway(): void
    {
        $worker = new StatsResources();
        $attempts = 0;

        $result = $worker->retry(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 2) {
                throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
            }
            return 'recovered';
        }, 3, 0);

        $this->assertEquals('recovered', $result);
        $this->assertEquals(2, $attempts);
    }
}
