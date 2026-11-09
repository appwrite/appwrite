<?php

declare(strict_types=1);

namespace Utopia\Queue\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Queue;

final class RedisRetryTest extends TestCase
{
    /**
     * @return \Iterator<string, array{int, ?int}>
     */
    public static function failedJobProvider(): \Iterator
    {
        yield 'requeued' => [0, null];
        yield 'parked on the dead queue' => [3, 3];
    }

    #[DataProvider('failedJobProvider')]
    public function testJobStaysFailedWhenTheSweepDiesBeforeMovingIt(int $attempts, ?int $maxAttempts): void
    {
        $queue = new Queue('mail');
        $connection = new DroppingConnection();
        $connection->lists['utopia-queue.failed.mail'] = ['job-1'];
        $connection->values['utopia-queue.jobs.mail.job-1'] = (string) json_encode([
            'pid' => 'job-1',
            'queue' => 'mail',
            'timestamp' => time() - 60,
            'payload' => ['to' => 'a@example.com'],
            'attempts' => $attempts,
        ]);
        $broker = new Redis($connection, $connection);

        try {
            $broker->retry($queue, maxAttempts: $maxAttempts);
            $this->fail('The lost connection must surface');
        } catch (\RedisException) {
        }

        $this->assertSame(['job-1'], $connection->lists['utopia-queue.failed.mail']);
    }
}
