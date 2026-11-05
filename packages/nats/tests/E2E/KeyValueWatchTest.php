<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\Exception\KeyValueException;
use Utopia\NATS\JetStream\JetStream;
use Utopia\NATS\KeyValue\KeyValue;
use Utopia\NATS\KeyValue\KeyValueConfig;
use Utopia\NATS\KeyValue\KeyValueOperation;

final class KeyValueWatchTest extends TestCase
{
    private Connection $conn;
    private JetStream $js;
    private KeyValue $kv;
    private string $bucket;

    protected function setUp(): void
    {
        $url = getenv('NATS_URL') ?: 'nats://127.0.0.1:4222';
        $this->conn = Connection::connect($url);
        $this->js = $this->conn->jetStream();
        $this->bucket = 'kvw_' . uniqid();
        $this->kv = $this->js->createKeyValue(new KeyValueConfig(
            bucket: $this->bucket,
            history: 10,
        ));
    }

    protected function tearDown(): void
    {
        try {
            $this->js->deleteKeyValue($this->bucket);
        } catch (\Throwable) {
            // ignore
        }
        $this->conn->close();
    }

    public function testHistoryReturnsAllRevisionsInOrder(): void
    {
        $r1 = $this->kv->put('color', 'red');
        $r2 = $this->kv->put('color', 'green');
        $r3 = $this->kv->put('color', 'blue');
        $this->kv->delete('color');

        $history = $this->kv->history('color');

        $this->assertCount(4, $history);
        $this->assertSame('red', $history[0]->value);
        $this->assertSame('green', $history[1]->value);
        $this->assertSame('blue', $history[2]->value);

        $this->assertSame($r1, $history[0]->revision);
        $this->assertSame($r2, $history[1]->revision);
        $this->assertSame($r3, $history[2]->revision);

        // Revisions strictly increasing.
        $this->assertTrue($r1 < $r2 && $r2 < $r3);

        $this->assertSame(KeyValueOperation::Put, $history[0]->operation);
        $this->assertSame(KeyValueOperation::Delete, $history[3]->operation);
    }

    public function testGetRevisionFetchesSpecificSeq(): void
    {
        $r1 = $this->kv->put('name', 'alice');
        $this->kv->put('name', 'bob');

        $entry = $this->kv->getRevision('name', $r1);

        $this->assertSame('alice', $entry->value);
        $this->assertSame($r1, $entry->revision);
        $this->assertSame('name', $entry->key);
    }

    public function testGetRevisionRejectsSeqFromAnotherKey(): void
    {
        // 'alpha' revision seq must not resolve when requested under 'beta'.
        $alphaSeq = $this->kv->put('alpha', 'a-value');
        $this->kv->put('beta', 'b-value');

        $this->expectException(KeyValueException::class);
        $this->kv->getRevision('beta', $alphaSeq);
    }

    public function testWatchFiresCallbackOnPut(): void
    {
        $received = [];
        $sub = $this->kv->watch('greeting', function ($entry) use (&$received): void {
            $received[] = $entry;
        });

        // Ensure the consumer/subscription is established before producing.
        $this->conn->flush();

        $this->kv->put('greeting', 'hello');

        $deadline = microtime(true) + 3.0;
        while ($received === [] && microtime(true) < $deadline) {
            $this->conn->processMessage(0.2);
        }

        $sub->unsubscribe();

        $this->assertCount(1, $received);
        $this->assertSame('greeting', $received[0]->key);
        $this->assertSame('hello', $received[0]->value);
        $this->assertSame(KeyValueOperation::Put, $received[0]->operation);
    }

    public function testWatchFiresCallbackOnDelete(): void
    {
        $this->kv->put('flag', 'on');

        $ops = [];
        $sub = $this->kv->watch('flag', function ($entry) use (&$ops): void {
            $ops[] = $entry->operation;
        });
        $this->conn->flush();

        $this->kv->delete('flag');

        $deadline = microtime(true) + 3.0;
        while ($ops === [] && microtime(true) < $deadline) {
            $this->conn->processMessage(0.2);
        }

        $sub->unsubscribe();

        $this->assertContains(KeyValueOperation::Delete, $ops);
    }
}
