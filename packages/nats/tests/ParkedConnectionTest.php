<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\NatsException;
use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\JetStream\Consumer;
use Utopia\NATS\JetStream\ConsumerInfo;
use Utopia\NATS\JetStream\PubAck;
use Utopia\NATS\Message;
use Utopia\NATS\Tests\Support\FakeTransport;
use Utopia\NATS\Tests\Support\Responder;

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

    private function connect(FakeTransport $fake, bool $allowReconnect = true, ?\Closure $onError = null): Connection
    {
        return Connection::connect(new ConnectionOptions(
            servers: 'nats://127.0.0.1:4222',
            allowReconnect: $allowReconnect,
            reconnectWait: 0.0,
            reconnectJitter: 0.0,
            requestTimeout: 0.2,
            onError: $onError,
            transportFactory: fn (string $scheme): FakeTransport => $fake,
        ));
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
        $consumer = new Consumer($connection, 'STREAM', ConsumerInfo::fromArray([
            'stream_name' => 'STREAM',
            'name' => 'durable',
        ]));
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
        $fake->pushInbound("-ERR 'Permissions Violation for Publish to \"denied\"'\r\n");

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

    public function testContinuousSubscriptionTrafficCannotHoldARequestBack(): void
    {
        $fake = new FakeTransport();
        $connection = $this->connect($fake);
        $this->answer($fake);
        $subscription = $connection->subscribe('events', static function (Message $message) use ($fake): void {
            $fake->pushInbound("MSG events {$message->sid} 1\r\nx\r\n");
        });
        for ($i = 0; $i < 10; $i++) {
            $fake->pushInbound("MSG events {$subscription->sid} 1\r\nx\r\n");
        }

        $started = microtime(true);
        $reply = $connection->request('work', timeout: 1.0);
        $elapsed = microtime(true) - $started;

        $this->assertSame('ok', $reply->data);
        $this->assertLessThan(1.0, $elapsed, 'Traffic that never stops must not hold the request past its bound');

        $connection->close();
    }
}
