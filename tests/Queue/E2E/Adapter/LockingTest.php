<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Lock\Lock;
use Utopia\Lock\Mutex;
use Utopia\Queue\Connection;
use Utopia\Queue\Connection\Locking;

final class LockingTest extends TestCase
{
    /**
     * Every method must run its inner call exactly once, wrapped in a single
     * acquire/release pair, and return the inner connection's result verbatim.
     *
     * @param array<mixed> $args
     */
    #[DataProvider('operationProvider')]
    public function testOperationIsSynchronized(string $method, array $args, mixed $expected): void
    {
        $recorder = new Recorder();
        $locking = new Locking(
            new RecordingConnection($recorder),
            new RecordingLock($recorder),
        );

        $result = $locking->$method(...$args);

        $this->assertSame($expected, $result, "{$method}() should return the inner connection's value");
        $this->assertSame(['acquire', $method, 'release'], $recorder->events, "{$method}() must run inside the lock");
        $this->assertSame([$method => $args], $recorder->calls, "{$method}() must forward its arguments");
    }

    /**
     * The lock must be acquired with a wait-forever timeout, so a command is
     * queued rather than dropped when the connection is momentarily busy.
     */
    public function testLockIsAcquiredWithWaitForeverTimeout(): void
    {
        $recorder = new Recorder();
        $locking = new Locking(
            new RecordingConnection($recorder),
            new RecordingLock($recorder),
        );

        $locking->ping();

        $this->assertSame(-1.0, $recorder->lastTimeout);
    }

    /**
     * The lock must be released even when the inner command throws, otherwise
     * a single failure would deadlock every other command on the connection.
     */
    public function testLockIsReleasedWhenInnerCommandThrows(): void
    {
        $recorder = new Recorder();
        $locking = new Locking(
            new ThrowingConnection(),
            new RecordingLock($recorder),
        );

        try {
            $locking->ping();
            $this->fail('Expected the inner exception to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(['acquire', 'release'], $recorder->events);
    }

    /**
     * Defaults to a coroutine-aware Mutex when no lock is injected.
     */
    public function testDefaultLockIsAMutex(): void
    {
        $locking = new Locking(new RecordingConnection(new Recorder()));

        $lock = new \ReflectionProperty(Locking::class, 'lock')->getValue($locking);

        $this->assertInstanceOf(Mutex::class, $lock);
    }

    /**
     * Regression guard: every method on the Connection interface must be
     * exercised by operationProvider(), so newly added methods cannot ship
     * without verifying they are synchronized.
     */
    public function testEveryConnectionMethodIsCovered(): void
    {
        $declared = array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            new \ReflectionClass(Connection::class)->getMethods(),
        );

        $covered = array_map(
            static fn(array $case): string => $case[0],
            iterator_to_array($this->operationProvider(), false),
        );

        sort($declared);
        sort($covered);

        $this->assertSame($declared, $covered, 'Every Connection method must be covered by the Locking test.');
    }

    /**
     * @return iterable<string, array{0: string, 1: array<mixed>, 2: mixed}>
     */
    public static function operationProvider(): iterable
    {
        yield 'rightPushArray' => ['rightPushArray', ['queue', ['a' => 1]], true];
        yield 'rightPushMany' => ['rightPushMany', ['queue', ['{"a":1}', '{"b":2}']], true];
        yield 'rightPopArray' => ['rightPopArray', ['queue', 5], ['popped' => 'right']];
        yield 'rightPopLeftPushArray' => ['rightPopLeftPushArray', ['queue', 'dest', 5], ['rpoplpush' => true]];
        yield 'leftPushArray' => ['leftPushArray', ['queue', ['a' => 1]], true];
        yield 'leftPushMany' => ['leftPushMany', ['queue', ['{"a":1}', '{"b":2}']], true];
        yield 'leftPopArray' => ['leftPopArray', ['queue', 5], ['popped' => 'left']];
        yield 'rightPush' => ['rightPush', ['queue', 'value'], true];
        yield 'rightPop' => ['rightPop', ['queue', 5], 'right-pop'];
        yield 'rightPopMany' => ['rightPopMany', ['queue', 4, 5], ['right-pop', 'right-pop-2']];
        yield 'rightPopLeftPush' => ['rightPopLeftPush', ['queue', 'dest', 5], 'rpoplpush'];
        yield 'leftPush' => ['leftPush', ['queue', 'value'], true];
        yield 'leftPop' => ['leftPop', ['queue', 5], 'left-pop'];
        yield 'listRemove' => ['listRemove', ['queue', 'key'], true];
        yield 'listSize' => ['listSize', ['key'], 7];
        yield 'listRange' => ['listRange', ['key', 10, 0], ['a', 'b']];
        yield 'remove' => ['remove', ['key'], true];
        yield 'set' => ['set', ['key', 'value', 60], true];
        yield 'get' => ['get', ['key'], 'value'];
        yield 'setArray' => ['setArray', ['key', ['a' => 1], 60], true];
        yield 'increment' => ['increment', ['key'], 3];
        yield 'incrementBy' => ['incrementBy', ['key', 5], 8];
        yield 'decrement' => ['decrement', ['key'], 2];
        yield 'ping' => ['ping', [], true];
        yield 'close' => ['close', [], null];
    }
}

/**
 * Shared event log written to by both the spy lock and the spy connection, so
 * tests can assert the ordering of acquire/command/release across them.
 */
class Recorder
{
    /** @var list<string> */
    public array $events = [];

    /** @var array<string, array<mixed>> */
    public array $calls = [];

    public ?float $lastTimeout = null;
}

class RecordingLock implements Lock
{
    public function __construct(private readonly Recorder $recorder) {}

    public function acquire(float $timeout = 0.0): bool
    {
        return true;
    }

    public function tryAcquire(): bool
    {
        return true;
    }

    public function release(): void {}

    public function withLock(callable $callback, float $timeout = 0.0): mixed
    {
        $this->recorder->lastTimeout = $timeout;
        $this->recorder->events[] = 'acquire';

        try {
            return $callback();
        } finally {
            $this->recorder->events[] = 'release';
        }
    }
}

class RecordingConnection implements Connection
{
    public function __construct(private readonly Recorder $recorder) {}

    private function record(string $method, array $args): void
    {
        $this->recorder->events[] = $method;
        $this->recorder->calls[$method] = $args;
    }

    public function rightPushArray(string $queue, array $payload): bool
    {
        $this->record('rightPushArray', [$queue, $payload]);

        return true;
    }

    public function rightPopArray(string $queue, int $timeout): array|false
    {
        $this->record('rightPopArray', [$queue, $timeout]);

        return ['popped' => 'right'];
    }

    public function rightPopLeftPushArray(string $queue, string $destination, int $timeout): array|false
    {
        $this->record('rightPopLeftPushArray', [$queue, $destination, $timeout]);

        return ['rpoplpush' => true];
    }

    public function leftPushArray(string $queue, array $payload): bool
    {
        $this->record('leftPushArray', [$queue, $payload]);

        return true;
    }

    public function leftPushMany(string $queue, array $payloads): bool
    {
        $this->record('leftPushMany', [$queue, $payloads]);

        return true;
    }

    public function rightPushMany(string $queue, array $payloads): bool
    {
        $this->record('rightPushMany', [$queue, $payloads]);

        return true;
    }

    public function leftPopArray(string $queue, int $timeout): array|false
    {
        $this->record('leftPopArray', [$queue, $timeout]);

        return ['popped' => 'left'];
    }

    public function rightPush(string $queue, string $payload): bool
    {
        $this->record('rightPush', [$queue, $payload]);

        return true;
    }

    public function rightPop(string $queue, int $timeout): string|false
    {
        $this->record('rightPop', [$queue, $timeout]);

        return 'right-pop';
    }

    public function rightPopMany(string $queue, int $count, int $timeout): array
    {
        $this->record('rightPopMany', [$queue, $count, $timeout]);

        return ['right-pop', 'right-pop-2'];
    }

    public function rightPopLeftPush(string $queue, string $destination, int $timeout): string|false
    {
        $this->record('rightPopLeftPush', [$queue, $destination, $timeout]);

        return 'rpoplpush';
    }

    public function leftPush(string $queue, string $payload): bool
    {
        $this->record('leftPush', [$queue, $payload]);

        return true;
    }

    public function leftPop(string $queue, int $timeout): string|false
    {
        $this->record('leftPop', [$queue, $timeout]);

        return 'left-pop';
    }

    public function listRemove(string $queue, string $key): bool
    {
        $this->record('listRemove', [$queue, $key]);

        return true;
    }

    public function listSize(string $key): int
    {
        $this->record('listSize', [$key]);

        return 7;
    }

    public function listRange(string $key, int $total, int $offset): array
    {
        $this->record('listRange', [$key, $total, $offset]);

        return ['a', 'b'];
    }

    public function remove(string $key): bool
    {
        $this->record('remove', [$key]);

        return true;
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        $this->record('set', [$key, $value, $ttl]);

        return true;
    }

    public function get(string $key): array|string|null
    {
        $this->record('get', [$key]);

        return 'value';
    }

    public function setArray(string $key, array $value, int $ttl = 0): bool
    {
        $this->record('setArray', [$key, $value, $ttl]);

        return true;
    }

    public function increment(string $key): int
    {
        $this->record('increment', [$key]);

        return 3;
    }

    public function incrementBy(string $key, int $by): int
    {
        $this->record('incrementBy', [$key, $by]);

        return 8;
    }

    public function decrement(string $key): int
    {
        $this->record('decrement', [$key]);

        return 2;
    }

    public function ping(): bool
    {
        $this->record('ping', []);

        return true;
    }

    public function close(): void
    {
        $this->record('close', []);
    }
}

class ThrowingConnection implements Connection
{
    public function rightPushArray(string $queue, array $payload): bool
    {
        return true;
    }

    public function rightPopMany(string $queue, int $count, int $timeout): array
    {
        return [];
    }

    public function incrementBy(string $key, int $by): int
    {
        return $by;
    }

    public function rightPopArray(string $queue, int $timeout): array|false
    {
        return false;
    }

    public function rightPopLeftPushArray(string $queue, string $destination, int $timeout): array|false
    {
        return false;
    }

    public function leftPushArray(string $queue, array $payload): bool
    {
        return true;
    }

    public function leftPopArray(string $queue, int $timeout): array|false
    {
        return false;
    }

    public function rightPush(string $queue, string $payload): bool
    {
        return true;
    }

    public function rightPop(string $queue, int $timeout): string|false
    {
        return false;
    }

    public function rightPopLeftPush(string $queue, string $destination, int $timeout): string|false
    {
        return false;
    }

    public function leftPush(string $queue, string $payload): bool
    {
        return true;
    }

    public function leftPop(string $queue, int $timeout): string|false
    {
        return false;
    }

    public function listRemove(string $queue, string $key): bool
    {
        return true;
    }

    public function listSize(string $key): int
    {
        return 0;
    }

    public function listRange(string $key, int $total, int $offset): array
    {
        return [];
    }

    public function remove(string $key): bool
    {
        return true;
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        return true;
    }

    public function get(string $key): array|string|null
    {
        return null;
    }

    public function setArray(string $key, array $value, int $ttl = 0): bool
    {
        return true;
    }

    public function increment(string $key): int
    {
        return 1;
    }

    public function decrement(string $key): int
    {
        return 0;
    }

    public function ping(): bool
    {
        throw new \RuntimeException('boom');
    }

    public function close(): void {}
    public function leftPushMany(string $queue, array $payloads): bool
    {
        return true;
    }

    public function rightPushMany(string $queue, array $payloads): bool
    {
        return true;
    }

}
