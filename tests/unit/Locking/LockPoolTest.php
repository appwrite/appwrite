<?php

declare(strict_types=1);

namespace Tests\Unit\Locking;

use PHPUnit\Framework\TestCase;
use Utopia\DI\Container;
use Utopia\Lock\Exception\Contention as LockContention;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;

require_once __DIR__ . '/../../../app/init.php';

final class LockPoolTest extends TestCase
{
    public function testLockPoolIsNotSizedOffTheDatabaseBudget(): void
    {
        global $register;

        /** @var Group $pools */
        $pools = $register->get('pools');

        $lock = $pools->get('lock')->size;

        $this->assertGreaterThan(
            1,
            $lock,
            'A lock lease is held for as long as the work it guards, so a second concurrent taker needs its own connection to wait on the lock with. The shared size is derived from the MySQL connection budget and floored by a worker-only setting, which leaves this server with one; the taker then cannot get a connection to queue with and 500s instead.'
        );
    }

    public function testExhaustedLockPoolIsReportedAsContention(): void
    {
        $pool = $this->lockPool();
        $pool->pop();

        $called = false;

        try {
            $this->locks($pool)('test', 10, function () use (&$called): void {
                $called = true;
            });
            $this->fail('Expected lock contention');
        } catch (LockContention $error) {
            $this->assertInstanceOf(\Exception::class, $error->getPrevious());
            $this->assertStringContainsString("Pool 'lock' could not provide a connection", $error->getMessage());
        }

        $this->assertFalse($called);
    }

    public function testCallbackPoolExhaustionIsNotReportedAsContention(): void
    {
        $pool = $this->lockPool();

        try {
            $this->locks($pool)('test', 10, function (): void {
                throw new \Exception("Pool 'database' could not provide a connection");
            });
            $this->fail('Expected the callback exception');
        } catch (LockContention) {
            $this->fail('A pool exhausted inside the callback must not be reported as lock contention');
        } catch (\Exception $error) {
            $this->assertStringContainsString("'database'", $error->getMessage());
        }

        $this->assertSame(1, $pool->count());
    }

    private function lockPool(): Pool
    {
        return new Pool(new Stack(), 'lock', 1, fn (): \Redis => new FakeLockRedis(), timeout: 0.0);
    }

    private function locks(Pool $pool): callable
    {
        global $container;

        /** @var array<string, callable> $factories */
        $factories = (new \ReflectionProperty(Container::class, 'factories'))->getValue($container);

        $locks = (new Container())
            ->set('pools', fn (): Group => (new Group())->add($pool))
            ->set('locks', $factories['locks'], ['pools'])
            ->get('locks');

        $this->assertIsCallable($locks);

        return $locks;
    }
}

class FakeLockRedis extends \Redis
{
    public function __construct()
    {
    }

    public function set($key, $value, $options = null): \Redis|string|bool
    {
        return true;
    }

    public function eval($script, $args = [], $num_keys = 0): mixed
    {
        return 1;
    }
}
