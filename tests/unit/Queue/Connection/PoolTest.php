<?php

declare(strict_types=1);

namespace Tests\Unit\Queue\Connection;

use Appwrite\Queue\Connection\Pool;
use Appwrite\Tests\Queue\InMemoryConnection;
use PHPUnit\Framework\TestCase;
use Utopia\Pools\Adapter\Stack as StackPool;
use Utopia\Pools\Pool as UtopiaPool;
use Utopia\Queue\Connection;

/**
 * Command connections are pooled so combined workers can ack in parallel.
 * A size-1 pool still serializes; size-N allows N overlapping commands.
 */
final class PoolTest extends TestCase
{
    protected function tearDown(): void
    {
        ReenteringConnection::$commands = null;
        ReenteringConnection::$created = 0;
    }

    public function testReusesIdleConnectionAcrossSequentialCommands(): void
    {
        $created = 0;
        $commands = $this->commands(1, function () use (&$created): Connection {
            $created++;

            return new InMemoryConnection();
        });

        $this->assertTrue($commands->set('k', 'v'));
        $this->assertSame('v', $commands->get('k'));
        $this->assertSame(1, $commands->increment('n'));
        $this->assertSame(1, $created);
    }

    public function testNestedCommandBorrowsASecondConnection(): void
    {
        $commands = $this->commands(2, static fn (): Connection => new ReenteringConnection());
        ReenteringConnection::$commands = $commands;

        $this->assertSame(1, $commands->increment('outer'));
        $this->assertSame(2, ReenteringConnection::$created);
    }

    public function testSingleConnectionPoolCannotOverlapCommands(): void
    {
        $commands = $this->commands(1, static fn (): Connection => new ReenteringConnection());
        ReenteringConnection::$commands = $commands;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('could not provide a connection');

        $commands->increment('outer');
    }

    /**
     * @param \Closure(): Connection $init
     */
    private function commands(int $size, \Closure $init): Pool
    {
        return new Pool(new UtopiaPool(
            new StackPool(),
            'worker-commands',
            $size,
            $init,
            0.1,
        ));
    }
}

final class ReenteringConnection extends InMemoryConnection
{
    public static ?Connection $commands = null;

    public static int $created = 0;

    public function __construct()
    {
        self::$created++;
    }

    public function increment(string $key): int
    {
        if ($key === 'outer') {
            if (self::$commands === null) {
                throw new \RuntimeException('commands wrapper not set');
            }

            return self::$commands->increment('inner');
        }

        return parent::increment($key);
    }
}
