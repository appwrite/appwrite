<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\Headers;

/**
 * Integration tests require a running NATS server at localhost:4222.
 */
final class PubSubTest extends TestCase
{
    private function getServerUrl(): string
    {
        return getenv('NATS_URL') ?: 'nats://127.0.0.1:4222';
    }

    public function testPubSub(): void
    {
        $conn = Connection::connect($this->getServerUrl());

        $received = null;
        $sub = $conn->subscribe('test.pubsub', function ($msg) use (&$received): void {
            $received = $msg;
        });

        $conn->publish('test.pubsub', 'hello');
        $conn->processMessage(1.0);

        $this->assertNotNull($received);
        $this->assertSame('test.pubsub', $received->subject);
        $this->assertSame('hello', $received->data);

        $sub->unsubscribe();
        $conn->close();
    }

    public function testSyncSubscribe(): void
    {
        $conn = Connection::connect($this->getServerUrl());

        $sub = $conn->subscribe('test.sync');

        $conn->publish('test.sync', 'sync-message');
        $msg = $sub->nextMessage(1.0);

        $this->assertInstanceOf(\Utopia\NATS\Message::class, $msg);
        $this->assertSame('sync-message', $msg->data);

        $sub->unsubscribe();
        $conn->close();
    }

    public function testWildcard(): void
    {
        $conn = Connection::connect($this->getServerUrl());

        $messages = [];
        $sub = $conn->subscribe('test.wild.*', function ($msg) use (&$messages): void {
            $messages[] = $msg;
        });

        $conn->publish('test.wild.one', 'first');
        $conn->publish('test.wild.two', 'second');
        $conn->processMessage(1.0);
        $conn->processMessage(1.0);

        $this->assertCount(2, $messages);

        $sub->unsubscribe();
        $conn->close();
    }

    public function testQueueSubscribe(): void
    {
        $conn = Connection::connect($this->getServerUrl());

        $count1 = 0;
        $count2 = 0;

        $sub1 = $conn->queueSubscribe('test.queue', 'workers', function () use (&$count1): void {
            $count1++;
        });
        $sub2 = $conn->queueSubscribe('test.queue', 'workers', function () use (&$count2): void {
            $count2++;
        });

        for ($i = 0; $i < 10; $i++) {
            $conn->publish('test.queue', "msg-{$i}");
        }

        for ($i = 0; $i < 10; $i++) {
            $conn->processMessage(1.0);
        }

        $this->assertSame(10, $count1 + $count2);

        $sub1->unsubscribe();
        $sub2->unsubscribe();
        $conn->close();
    }

    public function testPubSubWithHeaders(): void
    {
        $conn = Connection::connect($this->getServerUrl());

        $received = null;
        $sub = $conn->subscribe('test.headers', function ($msg) use (&$received): void {
            $received = $msg;
        });

        $headers = new Headers();
        $headers->set('X-Custom', 'test-value');
        $headers->set('Content-Type', 'text/plain');

        $conn->publish('test.headers', 'with-headers', headers: $headers);
        $conn->processMessage(1.0);

        $this->assertNotNull($received);
        $this->assertNotNull($received->headers);
        $this->assertSame('test-value', $received->headers->get('X-Custom'));
        $this->assertSame('text/plain', $received->headers->get('Content-Type'));

        $sub->unsubscribe();
        $conn->close();
    }
}
