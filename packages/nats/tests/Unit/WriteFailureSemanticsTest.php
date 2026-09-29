<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\KeyValueException;
use Utopia\NATS\Exception\ObjectStoreException;
use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\KeyValue\KeyValue;
use Utopia\NATS\ObjectStore\ObjectStore;
use Utopia\NATS\Tests\Unit\Support\FakeTransport;

/**
 * KeyValue and ObjectStore guard their writes with optimistic concurrency, and
 * used to catch \Throwable and rethrow a semantic verdict. That reported a stale
 * connection as "you lost the race" -- a conclusion the caller acts on by not
 * retrying -- and made ObjectStore::put purge the chunks it had just written.
 * Only the server rejecting the CAS publish may be read as a conflict.
 */
final class WriteFailureSemanticsTest extends TestCase
{
    /**
     * A connection whose requests are never answered: js->publish() waits for a
     * PubAck that never arrives and raises TimeoutException.
     */
    private function unresponsiveConnection(FakeTransport $fake): Connection
    {
        return Connection::connect(new ConnectionOptions(
            servers: 'nats://127.0.0.1:4222',
            allowReconnect: false,
            requestTimeout: 0.02,
            transportFactory: fn(string $scheme): FakeTransport => $fake,
        ));
    }

    public function testKeyValueCreatePropagatesATransportFailure(): void
    {
        $conn = $this->unresponsiveConnection(new FakeTransport());
        $kv = new KeyValue($conn, $conn->jetStream(), 'BUCKET');

        // Previously rewritten to KeyValueException('Key already exists'), which
        // tells the caller the write is settled when in fact it is unknown.
        $this->expectException(TimeoutException::class);
        $kv->create('key', 'value');
    }

    public function testKeyValueUpdatePropagatesATransportFailure(): void
    {
        $conn = $this->unresponsiveConnection(new FakeTransport());
        $kv = new KeyValue($conn, $conn->jetStream(), 'BUCKET');

        // Previously rewritten to KeyValueException('Wrong last revision').
        $this->expectException(TimeoutException::class);
        $kv->update('key', 'value', 7);
    }

    public function testKeyValueTransportFailureIsNotAKeyValueException(): void
    {
        $conn = $this->unresponsiveConnection(new FakeTransport());
        $kv = new KeyValue($conn, $conn->jetStream(), 'BUCKET');

        try {
            $kv->create('key', 'value');
            $this->fail('Expected the transport failure to raise');
        } catch (KeyValueException $e) {
            $this->fail('A transport failure must not be reported as a CAS verdict: ' . $e->getMessage());
        } catch (TimeoutException $e) {
            $this->assertStringContainsString('Request timed out', $e->getMessage());
        }
    }

    public function testObjectStorePutPropagatesATransportFailure(): void
    {
        $conn = $this->unresponsiveConnection(new FakeTransport());
        $store = new ObjectStore($conn, $conn->jetStream(), 'BUCKET');

        // An empty object writes no chunks, so the first publish attempted is the
        // guarded meta publish -- the path that used to purge on any failure.
        try {
            $store->put('object', '');
            $this->fail('Expected the transport failure to raise');
        } catch (ObjectStoreException $e) {
            $this->fail('A transport failure must not be reported as a conflict, and must not purge chunks: ' . $e->getMessage());
        } catch (TimeoutException $e) {
            $this->assertStringContainsString('Request timed out', $e->getMessage());
        }
    }
}
