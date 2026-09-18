<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\E2E\Adapter\InMemoryConnection;
use Utopia\Queue\Broker\Redis as Broker;
use Utopia\Queue\Codec;
use Utopia\Queue\Codec\Compat;
use Utopia\Queue\Codec\Igbinary;
use Utopia\Queue\Codec\Json;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

final class RedisCodecTest extends TestCase
{
    private const string NAMESPACE = 'tests';
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
        $connection = new InMemoryConnection();
        $broker = new Broker($connection, $connection, $codec);
        $queue = new Queue(self::QUEUE, self::NAMESPACE);

        $broker->publish($queue, ['to' => 'a@example.com', 'attempt' => 1]);
        $message = $broker->receive($queue, 0);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame(['to' => 'a@example.com', 'attempt' => 1], $message->getPayload());
        $this->assertSame(self::QUEUE, $message->getQueue());
    }

    /**
     * The claim stores the bytes the queue carried, rather than re-encoding the
     * array it just decoded -- which is the second encode this path used to pay.
     */
    #[DataProvider('codecs')]
    public function testTheClaimStoresTheBytesAsTheyArrived(Codec $codec): void
    {
        $connection = new InMemoryConnection();
        $broker = new Broker($connection, $connection, $codec);
        $queue = new Queue(self::QUEUE, self::NAMESPACE);

        $broker->publish($queue, ['n' => 1]);
        $published = $connection->listRange(self::NAMESPACE . '.queue.' . self::QUEUE, 1, 0)[0];

        $message = $broker->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $message);

        $this->assertSame(
            $published,
            $connection->get(self::NAMESPACE . '.jobs.' . self::QUEUE . '.' . $message->getPid()),
        );
    }

    /**
     * The cutover: a message written before the deploy is still on the list.
     */
    public function testCompatReadsAMessageAnEarlierReleaseWrote(): void
    {
        $connection = new InMemoryConnection();
        $queue = new Queue(self::QUEUE, self::NAMESPACE);

        new Broker($connection, $connection, new Json())->publish($queue, ['n' => 1]);

        $writer = \function_exists('igbinary_serialize') ? new Igbinary() : new Json();
        $message = new Broker($connection, $connection, new Compat($writer))->receive($queue, 0);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame(['n' => 1], $message->getPayload());
    }

    /**
     * Bytes no codec here can read are already off the queue by the time anyone
     * knows: they must not be dropped, and must not wedge the queue either.
     */
    public function testAnUnreadableMessageIsParkedAndTheQueueKeepsMoving(): void
    {
        $connection = new InMemoryConnection();
        $broker = new Broker($connection, $connection, new Json());
        $queue = new Queue(self::QUEUE, self::NAMESPACE);

        $key = self::NAMESPACE . '.queue.' . self::QUEUE;
        $poison = '{"pid":"a","queue":"codec"'; // truncated mid-write
        $connection->leftPush($key, $poison);
        $broker->publish($queue, ['n' => 1]);

        $this->assertNotInstanceOf(Message::class, $broker->receive($queue, 0), 'the unreadable message is not handed to a handler');
        $this->assertSame(
            [$poison],
            $connection->listRange(self::NAMESPACE . '.poison.' . self::QUEUE, 1, 0),
            'the bytes are set aside for a human, not discarded',
        );

        $message = $broker->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame(['n' => 1], $message->getPayload(), 'the message behind it is delivered');
    }

    /**
     * A well-formed document that is not an envelope is poison too: without the
     * shape check it reaches Message and dies on a missing pid.
     */
    public function testAWellFormedNonEnvelopeIsParked(): void
    {
        $connection = new InMemoryConnection();
        $broker = new Broker($connection, $connection, new Json());
        $queue = new Queue(self::QUEUE, self::NAMESPACE);

        $connection->leftPush(self::NAMESPACE . '.queue.' . self::QUEUE, '{"hello":"world"}');

        $this->assertNotInstanceOf(Message::class, $broker->receive($queue, 0));
        $this->assertSame(1, $connection->listSize(self::NAMESPACE . '.poison.' . self::QUEUE));
    }
}
