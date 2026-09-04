<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Broker\Redis as Broker;
use Utopia\Queue\Queue;

final class BatchedEnqueueTest extends TestCase
{
    public function testManyPayloadsCostOneCommand(): void
    {
        $connection = new PushRecordingConnection();
        $broker = new Broker($connection, $connection);

        $this->assertTrue($broker->enqueueMany(new Queue('mail'), [
            ['to' => 'a@example.com'],
            ['to' => 'b@example.com'],
            ['to' => 'c@example.com'],
        ]));

        $this->assertSame(
            [['leftPushMany', 'utopia-queue.queue.mail']],
            $connection->calls,
            'three payloads must cost one command, not three',
        );
        $this->assertCount(3, $connection->pushed);
    }

    public function testEachMessageInABatchStandsAlone(): void
    {
        $connection = new PushRecordingConnection();
        $broker = new Broker($connection, $connection);

        $broker->enqueueMany(new Queue('mail'), [['to' => 'a@example.com'], ['to' => 'b@example.com']]);

        $envelopes = array_map(
            static fn(string $encoded): array => json_decode($encoded, true),
            $connection->pushed,
        );

        $this->assertSame(
            ['a@example.com', 'b@example.com'],
            array_column(array_column($envelopes, 'payload'), 'to'),
            'payloads must arrive in the order they were given',
        );
        $this->assertCount(
            2,
            array_unique(array_column($envelopes, 'pid')),
            'each message needs its own pid, or a consumer cannot ack them separately',
        );

        foreach ($envelopes as $envelope) {
            $this->assertSame('mail', $envelope['queue']);
            $this->assertIsInt($envelope['timestamp']);
        }
    }

    public function testAnEmptyBatchTouchesTheConnectionNotAtAll(): void
    {
        $connection = new PushRecordingConnection();
        $broker = new Broker($connection, $connection);

        $this->assertTrue($broker->enqueueMany(new Queue('mail'), []));
        $this->assertSame([], $connection->calls);
    }

    public function testPriorityPushesTheBatchOnTheOtherEnd(): void
    {
        $connection = new PushRecordingConnection();
        $broker = new Broker($connection, $connection);

        $broker->enqueueMany(new Queue('mail'), [['to' => 'a'], ['to' => 'b']], priority: true);

        $this->assertSame([['rightPushMany', 'utopia-queue.queue.mail']], $connection->calls);
        $this->assertCount(2, $connection->pushed);
    }

    /**
     * Two methods rather than one that inspects its argument: a payload that
     * carries a list of its own is one message, and nothing has to guess.
     */
    public function testEnqueuePublishesOneMessageEvenWhenThePayloadNestsAList(): void
    {
        $connection = new PushRecordingConnection();
        $broker = new Broker($connection, $connection);

        $broker->publish(new Queue('mail'), ['recipients' => [['to' => 'a'], ['to' => 'b']]]);

        $this->assertSame([['leftPushArray', 'utopia-queue.queue.mail']], $connection->calls);
        $this->assertSame(
            ['recipients' => [['to' => 'a'], ['to' => 'b']]],
            $connection->arrays[0]['payload'],
            'a payload is passed through whole, never unwrapped into several messages',
        );
    }
}
