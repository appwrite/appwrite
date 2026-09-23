<?php

declare(strict_types=1);

namespace Utopia\Tests\e2e;

use PHPUnit\Framework\TestCase;
use Utopia\CircuitBreaker\Adapter\AdapterException;
use Utopia\CircuitBreaker\Adapter\SwooleTable;
use Utopia\CircuitBreaker\CircuitBreaker;

final class SwooleTableAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Swoole\Table::class)) {
            self::markTestSkipped('The swoole extension is not installed.');
        }
    }

    public function testSwooleTableAdapterSharesValuesAcrossInstances(): void
    {
        $table = SwooleTable::createTable(32);
        $first = new SwooleTable($table, 'test:');
        $second = new SwooleTable($table, 'test:');

        $first->set('state', 'half_open');
        $first->set('failures', 2);

        $this->assertSame('half_open', $second->get('state'));
        $this->assertSame(2, $second->get('failures'));
        $this->assertSame(3, $second->increment('failures'));
        $this->assertSame(3, $first->get('failures'));
        $this->assertSame(1, $first->increment('missing-counter'));
        $this->assertSame(1, $second->get('missing-counter'));
        $first->set('empty-string', '');
        $this->assertSame('', $second->get('empty-string'));

        $first->delete('failures');

        $this->assertNull($second->get('failures'));
    }

    public function testSwooleTableAdapterRejectsIncrementingStrings(): void
    {
        $adapter = new SwooleTable(SwooleTable::createTable(32), 'test:');
        $adapter->set('state', 'open');

        $this->expectException(AdapterException::class);

        $adapter->increment('state');
    }

    public function testSwooleTableAdapterHashesLongKeysToFitTableLimits(): void
    {
        $adapter = new SwooleTable(SwooleTable::createTable(32), 'test:');
        $key = str_repeat('service-', 20) . 'state';

        $adapter->set($key, 'open');

        $this->assertSame('open', $adapter->get($key));
    }

    public function testCircuitBreakerSharesStateThroughSwooleTable(): void
    {
        $cache = new SwooleTable(SwooleTable::createTable(32), 'breaker-test:');
        $first = new CircuitBreaker(timeout: 0, successThreshold: 2, cache: $cache, key: 'billing-api', minimumThroughput: 1);
        $second = new CircuitBreaker(timeout: 0, successThreshold: 2, cache: $cache, key: 'billing-api', minimumThroughput: 1);

        $first->call(
            open: static fn(): string => 'fallback',
            close: static function (): never {
                throw new \RuntimeException('failed');
            },
        );

        $this->assertTrue($second->isHalfOpen());

        $this->assertSame('probe-1', $second->call(
            open: static fn(): string => 'fallback',
            close: static fn(): string => 'closed',
            halfOpen: static fn(): string => 'probe-1',
        ));
        $this->assertSame(1, $first->getSuccessCount());

        $this->assertSame('probe-2', $first->call(
            open: static fn(): string => 'fallback',
            close: static fn(): string => 'closed',
            halfOpen: static fn(): string => 'probe-2',
        ));

        $this->assertTrue($second->isClosed());
        $this->assertSame(0, $second->getFailureCount());
        $this->assertSame(0, $second->getSuccessCount());
    }
}
