<?php

declare(strict_types=1);

namespace Utopia\Queue\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Connection;
use Utopia\Queue\Queue;

final class RedisPurgeTest extends TestCase
{
    public function testUnfilteredPurgeDeletesAllFailedEntriesAndPayloads(): void
    {
        $queue = new Queue('mail');
        $connection = new InMemoryConnection();
        $connection->lists['utopia-queue.failed.mail'] = ['job-3', 'job-2', 'job-1'];
        $connection->lists['utopia-queue.queue.mail'] = ['active-1'];
        $connection->values['utopia-queue.jobs.mail.job-1'] = (string) json_encode([
            'pid' => 'job-1',
            'queue' => 'mail',
            'timestamp' => time() - 300,
            'payload' => ['to' => 'a@example.com'],
        ]);
        $connection->values['utopia-queue.jobs.mail.job-2'] = (string) json_encode([
            'pid' => 'job-2',
            'queue' => 'mail',
            'timestamp' => time() - 200,
            'payload' => ['to' => 'b@example.com'],
        ]);
        $connection->values['utopia-queue.jobs.mail.job-3'] = (string) json_encode([
            'pid' => 'job-3',
            'queue' => 'mail',
            'timestamp' => time() - 100,
            'payload' => ['to' => 'c@example.com'],
        ]);
        $connection->values['utopia-queue.jobs.mail.active-1'] = (string) json_encode([
            'pid' => 'active-1',
            'queue' => 'mail',
            'timestamp' => time(),
            'payload' => ['to' => 'active@example.com'],
        ]);

        $broker = new Redis($connection, $connection);
        $purged = $broker->purge($queue);

        $this->assertSame(3, $purged);
        $this->assertSame([], $connection->lists['utopia-queue.failed.mail'] ?? []);
        $this->assertArrayNotHasKey('utopia-queue.jobs.mail.job-1', $connection->values);
        $this->assertArrayNotHasKey('utopia-queue.jobs.mail.job-2', $connection->values);
        $this->assertArrayNotHasKey('utopia-queue.jobs.mail.job-3', $connection->values);
        // Active queue should be untouched
        $this->assertSame(['active-1'], $connection->lists['utopia-queue.queue.mail']);
        $this->assertArrayHasKey('utopia-queue.jobs.mail.active-1', $connection->values);
    }

    public function testPurgeRespectsAgeCutoff(): void
    {
        $queue = new Queue('mail');
        $connection = new InMemoryConnection();
        // Failed list is LPUSHed: newest at head (index 0), oldest at tail (index -1)
        $connection->lists['utopia-queue.failed.mail'] = ['new-job', 'old-job'];
        $connection->values['utopia-queue.jobs.mail.old-job'] = (string) json_encode([
            'pid' => 'old-job',
            'queue' => 'mail',
            'timestamp' => time() - 3600, // 1 hour old
            'payload' => ['to' => 'old@example.com'],
        ]);
        $connection->values['utopia-queue.jobs.mail.new-job'] = (string) json_encode([
            'pid' => 'new-job',
            'queue' => 'mail',
            'timestamp' => time() - 30, // 30 seconds old
            'payload' => ['to' => 'new@example.com'],
        ]);

        $broker = new Redis($connection, $connection);
        $purged = $broker->purge($queue, olderThan: 300); // Only jobs older than 5 minutes

        $this->assertSame(1, $purged);
        $this->assertSame(['new-job'], $connection->lists['utopia-queue.failed.mail']);
        $this->assertArrayNotHasKey('utopia-queue.jobs.mail.old-job', $connection->values);
        $this->assertArrayHasKey('utopia-queue.jobs.mail.new-job', $connection->values);
    }

    public function testPurgeRespectsLimit(): void
    {
        $queue = new Queue('mail');
        $connection = new InMemoryConnection();
        $connection->lists['utopia-queue.failed.mail'] = ['job-3', 'job-2', 'job-1'];
        $connection->values['utopia-queue.jobs.mail.job-1'] = (string) json_encode([
            'pid' => 'job-1',
            'queue' => 'mail',
            'timestamp' => time() - 300,
            'payload' => [],
        ]);
        $connection->values['utopia-queue.jobs.mail.job-2'] = (string) json_encode([
            'pid' => 'job-2',
            'queue' => 'mail',
            'timestamp' => time() - 200,
            'payload' => [],
        ]);
        $connection->values['utopia-queue.jobs.mail.job-3'] = (string) json_encode([
            'pid' => 'job-3',
            'queue' => 'mail',
            'timestamp' => time() - 100,
            'payload' => [],
        ]);

        $broker = new Redis($connection, $connection);
        $purged = $broker->purge($queue, limit: 2);

        $this->assertSame(2, $purged);
        $this->assertSame(['job-3'], $connection->lists['utopia-queue.failed.mail']);
        $this->assertArrayNotHasKey('utopia-queue.jobs.mail.job-1', $connection->values);
        $this->assertArrayNotHasKey('utopia-queue.jobs.mail.job-2', $connection->values);
        $this->assertArrayHasKey('utopia-queue.jobs.mail.job-3', $connection->values);
    }

    public function testPurgeHandlesMissingPayload(): void
    {
        $queue = new Queue('mail');
        $connection = new InMemoryConnection();
        $connection->lists['utopia-queue.failed.mail'] = ['valid-pid', 'orphan-pid'];
        // orphan-pid has no payload
        $connection->values['utopia-queue.jobs.mail.valid-pid'] = (string) json_encode([
            'pid' => 'valid-pid',
            'queue' => 'mail',
            'timestamp' => time() - 100,
            'payload' => [],
        ]);

        $broker = new Redis($connection, $connection);
        $purged = $broker->purge($queue);

        $this->assertSame(2, $purged);
        $this->assertSame([], $connection->lists['utopia-queue.failed.mail'] ?? []);
        $this->assertArrayNotHasKey('utopia-queue.jobs.mail.valid-pid', $connection->values);
    }

    public function testPurgeHandlesCorruptPayloadStopsWhenOlderThanSet(): void
    {
        $queue = new Queue('mail');
        $connection = new InMemoryConnection();
        $connection->lists['utopia-queue.failed.mail'] = ['corrupt-pid'];
        $connection->values['utopia-queue.jobs.mail.corrupt-pid'] = 'not valid json {{{';

        $broker = new Redis($connection, $connection);
        $purged = $broker->purge($queue, olderThan: 300);

        // Cannot determine age of corrupt payload, so sweep stops with 0 purged
        $this->assertSame(0, $purged);
        $this->assertSame(['corrupt-pid'], $connection->lists['utopia-queue.failed.mail']);
        $this->assertArrayHasKey('utopia-queue.jobs.mail.corrupt-pid', $connection->values);
    }

    public function testPurgeHandlesCorruptPayloadRemovesWhenOlderThanNull(): void
    {
        $queue = new Queue('mail');
        $connection = new InMemoryConnection();
        // corrupt-pid at tail (-1) is encountered first
        $connection->lists['utopia-queue.failed.mail'] = ['valid-pid', 'corrupt-pid'];
        $connection->values['utopia-queue.jobs.mail.corrupt-pid'] = 'not valid json {{{';
        $connection->values['utopia-queue.jobs.mail.valid-pid'] = (string) json_encode([
            'pid' => 'valid-pid',
            'queue' => 'mail',
            'timestamp' => time() - 100,
            'payload' => [],
        ]);

        $broker = new Redis($connection, $connection);
        $purged = $broker->purge($queue); // unfiltered purge

        // Corrupt payload is removed in unfiltered purge without blocking valid-pid
        $this->assertSame(2, $purged);
        $this->assertSame([], $connection->lists['utopia-queue.failed.mail'] ?? []);
        $this->assertArrayNotHasKey('utopia-queue.jobs.mail.corrupt-pid', $connection->values);
        $this->assertArrayNotHasKey('utopia-queue.jobs.mail.valid-pid', $connection->values);
    }

    public function testMaintainTrimsExpiredPidsFromFailedList(): void
    {
        $queue = new Queue('mail');
        $connection = new InMemoryConnection();
        $connection->lists['utopia-queue.failed.mail'] = ['valid-pid', 'expired-pid'];
        // expired-pid has no payload (simulating expired key)
        $connection->values['utopia-queue.jobs.mail.valid-pid'] = (string) json_encode([
            'pid' => 'valid-pid',
            'queue' => 'mail',
            'timestamp' => time() - 100,
            'payload' => [],
        ]);

        $broker = new Redis($connection, $connection);
        // Register the queue as served
        $broker->receive($queue, 0);
        $broker->maintain();

        // The expired PID should be removed from the failed list
        $this->assertSame(['valid-pid'], $connection->lists['utopia-queue.failed.mail']);
    }
}

/**
 * In-memory connection for testing Redis broker operations.
 */
final class InMemoryConnection implements Connection
{
    /** @var array<string, list<string>> */
    public array $lists = [];

    /** @var array<string, string> */
    public array $values = [];

    public function execute(string $script, array $keys, array $args): mixed
    {
        return [];
    }

    /** @param array<mixed> $payload */
    public function rightPushArray(string $queue, array $payload): bool
    {
        $this->lists[$queue][] = (string) json_encode($payload);
        return true;
    }

    public function rightPushMany(string $queue, array $payloads): bool
    {
        foreach ($payloads as $payload) {
            $this->lists[$queue][] = \is_string($payload) ? $payload : (string) json_encode($payload);
        }
        return true;
    }

    /** @return array<mixed>|false */
    public function rightPopArray(string $queue, int $timeout): array|false
    {
        if (empty($this->lists[$queue])) {
            return false;
        }
        $item = array_pop($this->lists[$queue]);
        return $item !== null ? json_decode($item, true) : false;
    }

    /** @return array<mixed>|false */
    public function rightPopLeftPushArray(string $queue, string $destination, int $timeout): array|false
    {
        return false;
    }

    /** @param array<mixed> $payload */
    public function leftPushArray(string $queue, array $payload): bool
    {
        $this->lists[$queue] ??= [];
        array_unshift($this->lists[$queue], (string) json_encode($payload));
        return true;
    }

    public function leftPushMany(string $queue, array $payloads): bool
    {
        $this->lists[$queue] ??= [];
        foreach (array_reverse($payloads) as $payload) {
            array_unshift($this->lists[$queue], \is_string($payload) ? $payload : (string) json_encode($payload));
        }
        return true;
    }

    /** @return array<mixed>|false */
    public function leftPopArray(string $queue, int $timeout): array|false
    {
        if (empty($this->lists[$queue])) {
            return false;
        }
        $item = array_shift($this->lists[$queue]);
        return $item !== null ? json_decode($item, true) : false;
    }

    public function rightPush(string $queue, string $payload): bool
    {
        $this->lists[$queue][] = $payload;
        return true;
    }

    public function rightPop(string $queue, int $timeout): string|false
    {
        if (empty($this->lists[$queue])) {
            return false;
        }
        return array_pop($this->lists[$queue]) ?? false;
    }

    public function rightPopMany(string $queue, int $count, int $timeout): array
    {
        $result = [];
        for ($i = 0; $i < $count && !empty($this->lists[$queue]); $i++) {
            $item = array_pop($this->lists[$queue]);
            if ($item !== null) {
                $result[] = $item;
            }
        }
        return $result;
    }

    public function rightPopLeftPush(string $queue, string $destination, int $timeout): string|false
    {
        if (empty($this->lists[$queue])) {
            return false;
        }
        $item = array_pop($this->lists[$queue]);
        if ($item !== null) {
            $this->lists[$destination] ??= [];
            array_unshift($this->lists[$destination], $item);
            return $item;
        }
        return false;
    }

    public function leftPush(string $queue, string $payload): bool
    {
        $this->lists[$queue] ??= [];
        array_unshift($this->lists[$queue], $payload);
        return true;
    }

    public function leftPop(string $queue, int $timeout): string|false
    {
        if (empty($this->lists[$queue])) {
            return false;
        }
        return array_shift($this->lists[$queue]) ?? false;
    }

    public function listRemove(string $queue, string $key): bool
    {
        if (isset($this->lists[$queue])) {
            $this->lists[$queue] = array_values(array_filter(
                $this->lists[$queue],
                fn ($item) => $item !== $key
            ));
        }
        return true;
    }

    public function listSize(string $key): int
    {
        return \count($this->lists[$key] ?? []);
    }

    /** @return list<string> */
    public function listRange(string $key, int $total, int $offset): array
    {
        $list = $this->lists[$key] ?? [];
        $start = $offset < 0 ? \count($list) + $offset : $offset;
        return \array_slice($list, max(0, $start), $total);
    }

    public function remove(string $key): bool
    {
        unset($this->values[$key]);
        return true;
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        $this->values[$key] = $value;
        return true;
    }

    public function setNotExists(string $key, string $value, int $ttl = 0): bool
    {
        if (!isset($this->values[$key])) {
            $this->values[$key] = $value;
            return true;
        }
        return false;
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    /** @param array<mixed> $value */
    public function setArray(string $key, array $value, int $ttl = 0): bool
    {
        $this->values[$key] = (string) json_encode($value);
        return true;
    }

    public function increment(string $key): int
    {
        $value = (int) ($this->values[$key] ?? 0);
        $this->values[$key] = (string) ++$value;
        return $value;
    }

    public function incrementBy(string $key, int $by): int
    {
        $value = (int) ($this->values[$key] ?? 0) + $by;
        $this->values[$key] = (string) $value;
        return $value;
    }

    public function decrement(string $key): int
    {
        $value = (int) ($this->values[$key] ?? 0);
        $this->values[$key] = (string) --$value;
        return $value;
    }

    public function ping(): bool
    {
        return true;
    }

    public function close(): void
    {
    }
}
