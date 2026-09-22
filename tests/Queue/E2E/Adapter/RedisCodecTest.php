<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use Utopia\Queue\Broker\Redis as Broker;
use Utopia\Queue\Codec;
use Utopia\Queue\Codec\Compat;
use Utopia\Queue\Codec\Igbinary;
use Utopia\Queue\Codec\Json;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

final class RedisCodecTest extends RedisTestCase
{
    private const string QUEUE = 'codec';

    /**
     * @return iterable<string, array{Codec}>
     */
    public static function codecs(): iterable
    {
        yield 'json' => [new Json()];
        yield 'compat writing json' => [new Compat()];

        if (\function_exists('igbinary_serialize')) {
            yield 'igbinary' => [new Igbinary()];
            yield 'compat writing igbinary' => [new Compat(new Igbinary())];
        }
    }

    #[DataProvider('codecs')]
    public function testPublishAndReceiveRoundTrip(Codec $codec): void
    {
        $connection = $this->connection;
        $broker = new Broker($connection, $connection, $codec);
        $queue = new Queue(self::QUEUE, $this->namespace);

        $broker->publish($queue, ['to' => 'a@example.com', 'attempt' => 1]);
        $message = $broker->receive($queue, 0)[0] ?? null;

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame(['to' => 'a@example.com', 'attempt' => 1], $message->getPayload());
        $this->assertSame(self::QUEUE, $message->getQueue());
    }

    #[DataProvider('codecs')]
    public function testReleasePreservesPayloadAndAttempts(Codec $codec): void
    {
        $broker = new Broker($this->connection, $this->connection, $codec);
        $queue = new Queue(self::QUEUE, $this->namespace);
        $payload = ['nested' => ['values' => [1, null, false, 'text']]];
        $broker->publish($queue, $payload);
        $message = $broker->receive($queue, 0)[0];
        $broker->release($queue, $message);
        $released = $broker->receive($queue, 0)[0];
        $this->assertSame($payload, $released->getPayload());
        $this->assertSame($message->getPid(), $released->getPid());
        $this->assertSame($message->getAttempts(), $released->getAttempts());
        $broker->commit($queue, $released);
        $this->assertSame([], $broker->receive($queue, 0));
    }

    /**
     * The cutover: a message written before the deploy is still on the list.
     */
    public function testCompatReadsAMessageAnEarlierReleaseWrote(): void
    {
        $connection = $this->connection;
        $queue = new Queue(self::QUEUE, $this->namespace);

        new Broker($connection, $connection, new Json())->publish($queue, ['n' => 1]);

        $writer = \function_exists('igbinary_serialize') ? new Igbinary() : new Json();
        $message = (new Broker($connection, $connection, new Compat($writer))->receive($queue, 0)[0] ?? null);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame(['n' => 1], $message->getPayload());
    }

    /**
     * Bytes no codec here can read are already off the queue by the time anyone
     * knows: they must not be dropped, and must not wedge the queue either.
     */
    public function testAnUnreadableMessageIsParkedAndTheQueueKeepsMoving(): void
    {
        $connection = $this->connection;
        $broker = new Broker($connection, $connection, new Json());
        $queue = new Queue(self::QUEUE, $this->namespace);

        $key = $this->namespace . '.queue.' . self::QUEUE;
        $poison = '{"pid":"a","queue":"codec"'; // truncated mid-write
        $connection->leftPush($key, $poison);
        $broker->publish($queue, ['n' => 1]);

        $this->assertNotInstanceOf(Message::class, ($broker->receive($queue, 0)[0] ?? null), 'the unreadable message is not handed to a handler');
        $this->assertSame(
            [$poison],
            $connection->listRange($this->namespace . '.poison.' . self::QUEUE, 1, 0),
            'the bytes are set aside for a human, not discarded',
        );

        $this->assertSame(1, $broker->getFailedCount($queue), 'bytes nobody can read are work this queue did not get through');

        $message = $broker->receive($queue, 0)[0] ?? null;
        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame(['n' => 1], $message->getPayload(), 'the message behind it is delivered');
    }

    /**
     * A well-formed document that is not an envelope is poison too: without the
     * shape check it reaches Message and dies on a missing pid.
     */
    public function testAWellFormedNonEnvelopeIsParked(): void
    {
        $connection = $this->connection;
        $broker = new Broker($connection, $connection, new Json());
        $queue = new Queue(self::QUEUE, $this->namespace);

        $connection->leftPush($this->namespace . '.queue.' . self::QUEUE, '{"hello":"world"}');

        $this->assertNotInstanceOf(Message::class, ($broker->receive($queue, 0)[0] ?? null));
        $this->assertSame(1, $connection->listSize($this->namespace . '.poison.' . self::QUEUE));
    }
}
