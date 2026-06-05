<?php

namespace Tests\Unit\Platform;

use Appwrite\Platform\Action;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;

class ConcreteAction extends Action
{
    public static function getName(): string
    {
        return 'test-action';
    }

    public function __construct()
    {
        $this->logError = function () {};
    }

    public function publicForeachDocument(Database $database, string $collection, array $queries = [], callable $callback = null, int $limit = 1000): void
    {
        $this->foreachDocument($database, $collection, $queries, $callback, $limit);
    }

    public function publicRetryOnFailure(callable $callback, int $maxRetries = 3): mixed
    {
        return $this->retryOnFailure($callback, $maxRetries);
    }
}

class ActionTest extends TestCase
{
    public function testRetryOnFailureSucceedsFirstTry(): void
    {
        $action = new ConcreteAction();
        $callCount = 0;

        $result = $action->publicRetryOnFailure(function () use (&$callCount) {
            $callCount++;
            return 'success';
        });

        $this->assertEquals('success', $result);
        $this->assertEquals(1, $callCount);
    }

    public function testRetryOnFailureRetriesOnTransientPDOException(): void
    {
        $action = new ConcreteAction();
        $callCount = 0;

        $result = $action->publicRetryOnFailure(function () use (&$callCount) {
            $callCount++;
            if ($callCount < 3) {
                throw new \PDOException('SQLSTATE[HY000]: General error: 9001 Max connect timeout reached while reaching hostgroup 10 after 10000ms');
            }
            return 'recovered';
        });

        $this->assertEquals('recovered', $result);
        $this->assertEquals(3, $callCount);
    }

    public function testRetryOnFailureRetriesOnServerGoneAway(): void
    {
        $action = new ConcreteAction();
        $callCount = 0;

        $result = $action->publicRetryOnFailure(function () use (&$callCount) {
            $callCount++;
            if ($callCount < 2) {
                throw new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
            }
            return 'recovered';
        });

        $this->assertEquals('recovered', $result);
        $this->assertEquals(2, $callCount);
    }

    public function testRetryOnFailureRetriesOnLostConnection(): void
    {
        $action = new ConcreteAction();
        $callCount = 0;

        $result = $action->publicRetryOnFailure(function () use (&$callCount) {
            $callCount++;
            if ($callCount < 2) {
                throw new \PDOException('SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server during query');
            }
            return 'recovered';
        });

        $this->assertEquals('recovered', $result);
        $this->assertEquals(2, $callCount);
    }

    public function testRetryOnFailureThrowsAfterMaxRetries(): void
    {
        $action = new ConcreteAction();
        $callCount = 0;

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('9001');

        $action->publicRetryOnFailure(function () use (&$callCount) {
            $callCount++;
            throw new \PDOException('SQLSTATE[HY000]: General error: 9001 Max connect timeout reached while reaching hostgroup 10 after 10000ms');
        }, 3);

        $this->assertEquals(3, $callCount);
    }

    public function testRetryOnFailureDoesNotRetryNonTransientErrors(): void
    {
        $action = new ConcreteAction();
        $callCount = 0;

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('Duplicate entry');

        $action->publicRetryOnFailure(function () use (&$callCount) {
            $callCount++;
            throw new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        });

        $this->assertEquals(1, $callCount);
    }

    public function testRetryOnFailureDoesNotRetryNonPDOExceptions(): void
    {
        $action = new ConcreteAction();
        $callCount = 0;

        $this->expectException(\RuntimeException::class);

        $action->publicRetryOnFailure(function () use (&$callCount) {
            $callCount++;
            throw new \RuntimeException('Some other error');
        });

        $this->assertEquals(1, $callCount);
    }
}
