<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\JetStream\AckPolicy;
use Utopia\NATS\JetStream\ConsumerConfig;
use Utopia\NATS\JetStream\JetStream;
use Utopia\NATS\JetStream\StorageType;
use Utopia\NATS\JetStream\StreamConfig;
use Utopia\NATS\Tests\Support\LateReadTransport;
use Utopia\NATS\Transport\TcpTransport;

/**
 * What a no_wait pull request costs against a real server.
 *
 * Only the server can show this. A pull request that asks for no_wait and also
 * carries an expiry is answered by waiting out the expiry and returning 408, not
 * by returning 404 at once -- so the call still behaves correctly and returns no
 * messages, it just takes the whole timeout to do it. Anything that polls an
 * empty consumer is then capped at one call per timeout, and no unit test over
 * the request body can observe that. Requires a JetStream-enabled server
 * (NATS_URL).
 */
final class ConsumerNoWaitTest extends TestCase
{
    private Connection $conn;

    private JetStream $js;

    /** @var list<string> */
    private array $streams = [];

    private function getServerUrl(): string
    {
        return getenv('NATS_URL') ?: 'nats://127.0.0.1:4222';
    }

    protected function setUp(): void
    {
        $this->conn = Connection::connect($this->getServerUrl());
        $this->js = $this->conn->jetStream();
    }

    protected function tearDown(): void
    {
        foreach ($this->streams as $stream) {
            try {
                $this->js->deleteStream($stream);
            } catch (\Throwable) {
                // already gone
            }
        }
        $this->conn->close();
    }

    /** @return array{0: string, 1: string} stream name and subject */
    private function createStream(): array
    {
        $name = 'NW_' . uniqid();
        $subject = "nowait.{$name}";

        $this->js->createStream(new StreamConfig(
            name: $name,
            subjects: [$subject],
            storage: StorageType::Memory,
        ));
        $this->streams[] = $name;

        return [$name, $subject];
    }

    public function testNoWaitFetchOnAnEmptyConsumerReturnsWithoutWaitingOutTheTimeout(): void
    {
        [$stream, $subject] = $this->createStream();
        $consumer = $this->js->createConsumer($stream, new ConsumerConfig(
            durableName: 'nowait',
            ackPolicy: AckPolicy::Explicit,
            filterSubject: $subject,
        ));

        // A deliberately long timeout, so "returned promptly" cannot be confused
        // with "the timeout happened to be short".
        $timeout = 2.0;
        $started = microtime(true);
        $batch = $consumer->fetch(1, $timeout, true);
        $elapsed = microtime(true) - $started;

        $this->assertCount(0, $batch, 'The consumer is empty, so nothing comes back');
        $this->assertLessThan(
            $timeout / 4,
            $elapsed,
            \sprintf(
                'A no_wait fetch on an empty consumer took %.3fs of a %.1fs timeout; it is asking the '
                . 'server to answer immediately, so it must not track the timeout',
                $elapsed,
                $timeout,
            ),
        );
    }

    public function testNoWaitFetchStillReturnsAMessageThatIsWaiting(): void
    {
        [$stream, $subject] = $this->createStream();
        $consumer = $this->js->createConsumer($stream, new ConsumerConfig(
            durableName: 'nowait',
            ackPolicy: AckPolicy::Explicit,
            filterSubject: $subject,
        ));

        $this->js->publish($subject, 'payload');

        $batch = $consumer->fetch(1, 2.0, true);

        $this->assertCount(1, $batch, 'Answering immediately must not mean answering empty');
        foreach ($batch as $message) {
            $this->assertSame('payload', $message->getData());
            $message->ack();
        }
    }

    public function testWaitingFetchStillHonoursItsTimeoutOnAnEmptyConsumer(): void
    {
        [$stream, $subject] = $this->createStream();
        $consumer = $this->js->createConsumer($stream, new ConsumerConfig(
            durableName: 'waiting',
            ackPolicy: AckPolicy::Explicit,
            filterSubject: $subject,
        ));

        // The complement of the first test: without no_wait the caller asked to
        // be held, and dropping the expiry from that request would leave the
        // server holding it for its own default instead of the one given.
        $timeout = 0.5;
        $started = microtime(true);
        $batch = $consumer->fetch(1, $timeout, false);
        $elapsed = microtime(true) - $started;

        $this->assertCount(0, $batch);
        $this->assertGreaterThanOrEqual(
            $timeout * 0.5,
            $elapsed,
            'A waiting fetch must actually wait',
        );
        $this->assertLessThan(
            $timeout * 3,
            $elapsed,
            'A waiting fetch must return near its own timeout, not the server default',
        );
    }

    /**
     * The server answers a no_wait pull at once and counts every message in the
     * answer as delivered. A caller that reads it after the short timeout -- a
     * coroutine held off by busy neighbours -- must still get all of them, or
     * the rest sit unacknowledged until ackWait and are charged an attempt.
     */
    public function testNoWaitFetchReadAfterItsTimeoutLeavesNothingDeliveredBehind(): void
    {
        [$stream, $subject] = $this->createStream();
        for ($i = 0; $i < 5; $i++) {
            $this->js->publish($subject, "m{$i}");
        }

        $late = new LateReadTransport(new TcpTransport());
        $conn = Connection::connect(new ConnectionOptions(
            servers: $this->getServerUrl(),
            transportFactory: fn (string $scheme): LateReadTransport => $late,
        ));
        $consumer = $conn->jetStream()->createConsumer($stream, new ConsumerConfig(
            durableName: 'late',
            ackPolicy: AckPolicy::Explicit,
            filterSubject: $subject,
        ));

        // Far past the timeout: however long the reader is held off, the answer
        // is already on the socket.
        $late->delayNextRead(1.2);
        $batch = $consumer->fetch(5, 0.05, true);

        $this->assertCount(5, $batch, 'The whole answer reaches the caller');
        $this->assertSame(
            0,
            $consumer->info(true)->numAckPending - \count($batch),
            'Every delivery the server counted is one the caller holds',
        );

        $conn->close();
    }

    public function testPartialNoWaitFetchReturnsWithoutWaitingOutTheTimeout(): void
    {
        [$stream, $subject] = $this->createStream();
        $consumer = $this->js->createConsumer($stream, new ConsumerConfig(
            durableName: 'partial',
            ackPolicy: AckPolicy::Explicit,
            filterSubject: $subject,
        ));
        $this->js->publish($subject, 'm0');
        $this->js->publish($subject, 'm1');

        // Fewer messages than asked for: the server sends them and then 404s,
        // which is what has to end the fetch.
        $timeout = 2.0;
        $started = microtime(true);
        $batch = $consumer->fetch(5, $timeout, true);
        $elapsed = microtime(true) - $started;

        $this->assertCount(2, $batch);
        $this->assertLessThan($timeout / 4, $elapsed, 'A partial no_wait reply must not wait out the timeout');
    }
}
