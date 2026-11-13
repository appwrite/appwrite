<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\NatsException;
use Utopia\NATS\Exception\PermissionException;
use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\JetStream\Consumer;
use Utopia\NATS\JetStream\ConsumerInfo;
use Utopia\NATS\JetStream\PubAck;
use Utopia\NATS\Message;
use Utopia\NATS\Tests\Support\FakeTransport;
use Utopia\NATS\Tests\Support\ReadyTransport;
use Utopia\NATS\Tests\Support\Responder;
use Utopia\NATS\Transport\Transport;

/**
 * A pooled connection sits unread between calls, so whatever the server sends it
 * meanwhile waits on the socket: a lame-duck INFO followed by the close, a
 * closing -ERR, a PING. The calls that write and then read their answer must act
 * on that first. Otherwise the request goes into a socket the server has already
 * closed and comes back as an ambiguous write that cannot be replayed.
 */
final class ParkedConnectionTest extends TestCase
{
    private const string LAME_DUCK = 'INFO {"server_id":"FAKE","ldm":true}' . "\r\n";

    private const string PERMISSION_ERROR = "-ERR 'Permissions Violation for Publish to \"denied\"'\r\n";

    private function connect(
        FakeTransport $fake,
        bool $allowReconnect = true,
        ?\Closure $onError = null,
        float $requestTimeout = 0.2,
        ?Transport $transport = null,
    ): Connection {
        return Connection::connect(new ConnectionOptions(
            servers: 'nats://127.0.0.1:4222',
            allowReconnect: $allowReconnect,
            reconnectWait: 0.0,
            reconnectJitter: 0.0,
            requestTimeout: $requestTimeout,
            onError: $onError,
            transportFactory: fn (string $scheme): Transport => $transport ?? $fake,
        ));
    }

    private function consumer(Connection $connection): Consumer
    {
        return new Consumer($connection, 'STREAM', ConsumerInfo::fromArray([
            'stream_name' => 'STREAM',
            'name' => 'durable',
        ]));
    }

    private function park(FakeTransport $fake, string $sent = self::LAME_DUCK): void
    {
        $fake->pushInbound($sent);
        $fake->closedByServer = true;
    }

    private function answer(FakeTransport $fake, string $data = 'ok'): Responder
    {
        return new Responder($fake, static fn (): string => $data);
    }

    private static function handshakes(FakeTransport $fake): int
    {
        return substr_count($fake->written, 'CONNECT ');
    }

    public function testRequestAfterTheServerClosedAnIdleConnectionReconnectsBeforeWriting(): void
    {
        $fake = new FakeTransport();
        $connection = $this->connect($fake);
        $this->answer($fake);
        $this->park($fake);

        $reply = $connection->request('work');

        $this->assertSame('ok', $reply->data);
        $this->assertSame(2, self::handshakes($fake));
        $this->assertSame(1, substr_count($fake->written, 'PUB work '), 'The request is written once');
        $this->assertGreaterThan(
            strrpos($fake->written, 'CONNECT '),
            strpos($fake->written, 'PUB work '),
            'The request must go out on the new connection, not into the closed one',
        );

        $connection->close();
    }

    public function testSettleReconnectsAConnectionTheServerClosedWhileIdle(): void
    {
        $fake = new FakeTransport();
        $connection = $this->connect($fake);
        $this->park($fake);

        $connection->settle();

        $this->assertTrue($connection->isConnected());
        $this->assertSame(2, self::handshakes($fake));

        $connection->close();
    }

    public function testIdleStaleConnectionErrorIsHandledWithoutWritingTheRequestTwice(): void
    {
        $fake = new FakeTransport();
        $connection = $this->connect($fake);
        $this->answer($fake);
        $this->park($fake, "-ERR 'Stale Connection'\r\n");

        $reply = $connection->request('work');

        $this->assertSame('ok', $reply->data);
        $this->assertSame(1, substr_count($fake->written, 'PUB work '), 'The request must not be replayed');
        $this->assertTrue($connection->isConnected());

        $connection->close();
    }

    public function testIdlePingIsAnsweredBeforeTheRequestIsWritten(): void
    {
        $fake = new FakeTransport();
        $connection = $this->connect($fake);
        $this->answer($fake);
        $fake->pushInbound("PING\r\n");

        $connection->request('work');

        $pong = strpos($fake->written, "PONG\r\n");
        $this->assertNotFalse($pong, 'The server PING must be answered');
        $this->assertLessThan(strpos($fake->written, 'PUB work '), $pong);

        $connection->close();
    }

    public function testFlushAfterTheServerClosedAnIdleConnectionReconnects(): void
    {
        $fake = new FakeTransport();
        $connection = $this->connect($fake);
        $this->park($fake);

        $connection->flush();

        $this->assertTrue($connection->isConnected());
        $this->assertSame(2, self::handshakes($fake));

        $connection->close();
    }

    public function testRequestManyAfterTheServerClosedAnIdleConnectionGetsItsReply(): void
    {
        $fake = new FakeTransport();
        $connection = $this->connect($fake);
        $this->answer($fake);
        $this->park($fake);

        $replies = $connection->requestMany('work', '', ['max' => 1, 'timeout' => 0.2]);

        $this->assertCount(1, $replies);
        $this->assertSame('ok', $replies[0]->data);

        $connection->close();
    }

    public function testFetchAfterTheServerClosedAnIdleConnectionGetsItsMessage(): void
    {
        $fake = new FakeTransport();
        $connection = $this->connect($fake);
        $this->answer($fake, 'job');
        $consumer = $this->consumer($connection);
        $this->park($fake);

        $batch = $consumer->fetch(1, 0.1, true);

        $this->assertCount(1, $batch);
        $this->assertSame('job', $batch->getMessages()[0]->getData());

        $connection->close();
    }

    public function testPublishManyAfterTheServerClosedAnIdleConnectionCollectsEveryAck(): void
    {
        $fake = new FakeTransport();
        $connection = $this->connect($fake);
        $sequence = 0;
        new Responder($fake, static function () use (&$sequence): string {
            $sequence++;

            return '{"stream":"EVENTS","seq":' . $sequence . '}';
        });
        $this->park($fake);

        $acks = $connection->jetStream()->publishMany([
            ['subject' => 'events.one'],
            ['subject' => 'events.two'],
            ['subject' => 'events.three'],
        ], 0.2);

        $this->assertCount(3, $acks);
        $this->assertSame([1, 2, 3], array_map(static fn (PubAck $ack): int => $ack->sequence, $acks));

        $connection->close();
    }

    public function testRequestWithoutReconnectFailsBeforeWritingIntoTheClosedConnection(): void
    {
        $fake = new FakeTransport();
        $connection = $this->connect($fake, allowReconnect: false);
        $this->answer($fake);
        $this->park($fake);

        try {
            $connection->request('work');
            $this->fail('A request on a connection the server closed must not succeed');
        } catch (ConnectionException) {
        }

        $this->assertFalse($connection->isConnected());
        $this->assertStringNotContainsString('PUB work ', $fake->written, 'Nothing may be written into the closed socket');

        $connection->close();
    }

    public function testIdleNonClosingErrorGoesToOnErrorAndDoesNotFailTheNextRequest(): void
    {
        $errors = [];
        $fake = new FakeTransport();
        $connection = $this->connect($fake, onError: static function (NatsException $error) use (&$errors): void {
            $errors[] = $error->getMessage();
        });
        $this->answer($fake);
        $fake->pushInbound(self::PERMISSION_ERROR);

        $reply = $connection->request('work');

        $this->assertSame('ok', $reply->data);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Permissions Violation', $errors[0]);
        $this->assertTrue($connection->isConnected());

        $connection->close();
    }

    public function testSubscriptionCallbackFailureWhileSettlingStillPropagates(): void
    {
        $fake = new FakeTransport();
        $connection = $this->connect($fake);
        $failure = new TimeoutException('Nested request timed out');
        $subscription = $connection->subscribe('events', static fn (Message $message): never => throw $failure);
        $fake->pushInbound("MSG events {$subscription->sid} 1\r\nx\r\n");

        try {
            $connection->request('work', timeout: 0.05);
            $this->fail('The callback failure must reach the caller');
        } catch (TimeoutException $error) {
            $this->assertSame($failure, $error);
        }

        $this->assertTrue($connection->isConnected());

        $connection->close();
    }

    public function testHealthyIdleConnectionWritesOnlyTheRequest(): void
    {
        $fake = new FakeTransport();
        $connection = $this->connect($fake);
        $this->answer($fake);
        $writes = \count($fake->writes);

        $this->assertSame('ok', $connection->request('work')->data);

        $this->assertSame(1, self::handshakes($fake));
        $this->assertCount($writes + 2, $fake->writes, 'Only the inbox SUB and the request PUB');

        $connection->close();
    }

    public function testContinuousSubscriptionTrafficCannotHoldARequestBackPastItsTimeout(): void
    {
        $fake = new FakeTransport();
        $connection = $this->connect($fake, requestTimeout: 30.0);
        $this->answer($fake);
        $subscription = $connection->subscribe('events', static function (Message $message) use ($fake): void {
            $fake->pushInbound("MSG events {$message->sid} 1\r\nx\r\n");
        });
        for ($i = 0; $i < 10; $i++) {
            $fake->pushInbound("MSG events {$subscription->sid} 1\r\nx\r\n");
        }

        $started = microtime(true);
        $reply = $connection->request('work', timeout: 0.2);
        $elapsed = microtime(true) - $started;

        $this->assertSame('ok', $reply->data);
        $this->assertLessThan(0.2 + 0.3, $elapsed, 'Traffic that never stops must not hold the request past its own timeout');

        $connection->close();
    }

    public function testReadableSocketWithoutAnOperationCostsOneShortRead(): void
    {
        $fake = new FakeTransport();
        $connection = $this->connect($fake, requestTimeout: 30.0, transport: new ReadyTransport($fake));
        $this->answer($fake);

        $started = microtime(true);
        $reply = $connection->request('work', timeout: 2.0);
        $elapsed = microtime(true) - $started;

        $this->assertSame('ok', $reply->data);
        $this->assertLessThan(0.5, $elapsed, 'A socket that looks readable but has no operation must not stall the request');

        $connection->close();
    }

    public function testSettleLeavesAPartialLineForTheNextRead(): void
    {
        $fake = new FakeTransport();
        $connection = $this->connect($fake);
        $fake->pushInbound('PI');

        $connection->settle();
        $fake->pushInbound("NG\r\n");
        $connection->processMessage(0.05);

        $this->assertStringContainsString("PONG\r\n", $fake->written, 'The PING split across reads is still answered');
        $this->assertTrue($connection->isConnected());

        $connection->close();
    }

    public function testFlushRaisesANonClosingErrorTheServerSentBeforeIt(): void
    {
        $errors = [];
        $fake = new FakeTransport();
        $connection = $this->connect($fake, onError: static function (NatsException $error) use (&$errors): void {
            $errors[] = $error->getMessage();
        });
        $fake->pushInbound(self::PERMISSION_ERROR);

        try {
            $connection->flush();
            $this->fail('flush() must report the error for the earlier write');
        } catch (PermissionException $error) {
            $this->assertStringContainsString('Permissions Violation', $error->getMessage());
        }

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Permissions Violation', $errors[0]);
        $this->assertTrue($connection->isConnected());
        $connection->flush();

        $connection->close();
    }

    public function testRequestAfterAFailedFrameReadFailsBeforeWriting(): void
    {
        $fake = new FakeTransport();
        $connection = $this->connect($fake, allowReconnect: false);
        $this->answer($fake);
        $subscription = $connection->subscribe('events');
        $fake->pushInbound("MSG events {$subscription->sid} 10\r\nabc");

        try {
            $connection->processMessage(0.05);
            $this->fail('A frame cut short must fail the read');
        } catch (ConnectionException) {
        }

        try {
            $connection->request('work');
            $this->fail('A request on a desynced connection must not succeed');
        } catch (ConnectionException) {
        }

        $this->assertFalse($connection->isConnected());
        $this->assertStringNotContainsString('PUB work ', $fake->written, 'Nothing may be written into a desynced stream');

        $connection->close();
    }

    /** @return array<string, array{\Closure(Connection): mixed}> */
    public static function subscribingCalls(): array
    {
        return [
            'requestMany' => [static fn (Connection $connection): array => $connection->requestMany('work', '', ['max' => 1, 'timeout' => 0.2])],
            'fetch' => [static fn (Connection $connection): mixed => new Consumer($connection, 'STREAM', ConsumerInfo::fromArray([
                'stream_name' => 'STREAM',
                'name' => 'durable',
            ]))->fetch(1, 0.1, true)],
            'publishMany' => [static fn (Connection $connection): array => $connection->jetStream()->publishMany([['subject' => 'events.one']], 0.2)],
        ];
    }

    /** @param \Closure(Connection): mixed $call */
    #[DataProvider('subscribingCalls')]
    public function testFailedSettleWithoutReconnectOpensNoSubscription(\Closure $call): void
    {
        $fake = new FakeTransport();
        $connection = $this->connect($fake, allowReconnect: false);
        $this->answer($fake);
        $this->park($fake);
        $written = \strlen($fake->written);

        try {
            $call($connection);
            $this->fail('A call on a connection the server closed must not succeed');
        } catch (ConnectionException $error) {
            $this->assertSame('Connection closed by server', $error->getMessage());
        }

        $this->assertFalse($connection->isConnected());
        $this->assertSame('', substr($fake->written, $written), 'No SUB, UNSUB or PUB may follow the close');

        $connection->close();
    }
}
