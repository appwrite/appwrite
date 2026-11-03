<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\JetStreamException;
use Utopia\NATS\Exception\KeyValueException;
use Utopia\NATS\Exception\ObjectStoreException;
use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\KeyValue\KeyValue;
use Utopia\NATS\ObjectStore\ObjectStore;
use Utopia\NATS\Tests\Support\FakeTransport;

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
            transportFactory: fn (string $scheme): FakeTransport => $fake,
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

    /**
     * A bucket that has hit max_bytes rejects a publish with "maximum bytes
     * exceeded" instead of evicting, because the object-store stream is created
     * with discard=New. When that rejection lands on chunk N of a multi-chunk
     * put, chunks 1..N-1 are already durably stored under a NUID no meta record
     * will ever reference: nothing in the library can reclaim them, so their
     * bytes occupy the bucket's limit forever and every later put fails the same
     * way. The partially written version has to be purged before the failure is
     * handed back to the caller.
     */
    public function testObjectStorePutPurgesItsChunksWhenTheBucketIsFull(): void
    {
        $chunks = [];
        $purges = [];
        $metaPublishes = [];
        $fake = new FakeTransport();
        $fake->onWrite = static function (string $wire, FakeTransport $fake) use (&$chunks, &$purges, &$metaPublishes): void {
            if (!preg_match('/^(?:H?PUB) (\S+) (\S+) (?:\d+ )?(\d+)\r\n/', $wire, $match)) {
                return;
            }

            [$frame, $subject, $reply] = [$match[0], $match[1], $match[2]];
            $payload = substr($wire, \strlen($frame), (int) $match[3]);
            $respond = static function (string $body) use ($fake, $reply): void {
                $fake->pushInbound("MSG {$reply} 1 " . \strlen($body) . "\r\n{$body}\r\n");
            };

            if (str_starts_with($subject, '$JS.API.STREAM.MSG.GET.')) {
                $respond('{"error":{"code":404,"err_code":10037,"description":"no message found"}}');

                return;
            }

            if (str_starts_with($subject, '$O.BUCKET.C.')) {
                $chunks[] = $subject;
                // The bucket's byte budget runs out on the second chunk.
                $respond(\count($chunks) === 1
                    ? '{"stream":"OBJ_BUCKET","seq":1}'
                    : '{"error":{"code":400,"err_code":10077,"description":"maximum bytes exceeded"}}');

                return;
            }

            if (str_starts_with($subject, '$JS.API.STREAM.PURGE.')) {
                $purges[] = $payload;
                $respond('{"success":true,"purged":1}');

                return;
            }

            if (str_starts_with($subject, '$O.BUCKET.M.')) {
                $metaPublishes[] = $subject;
                $respond('{"stream":"OBJ_BUCKET","seq":2}');
            }
        };

        $conn = Connection::connect(new ConnectionOptions(
            servers: 'nats://127.0.0.1:4222',
            allowReconnect: false,
            requestTimeout: 1.0,
            transportFactory: fn (string $scheme): FakeTransport => $fake,
        ));
        $store = new ObjectStore($conn, $conn->jetStream(), 'BUCKET');

        try {
            // Two chunks: 128 KiB is the chunk size, so this spills over.
            $store->put('object', str_repeat('a', 128 * 1024 + 1));
            $this->fail('Expected the full bucket to reject the chunk publish');
        } catch (JetStreamException $e) {
            $this->assertSame('maximum bytes exceeded', $e->getMessage());
        } finally {
            $conn->close();
        }

        $this->assertCount(2, $chunks, 'Both chunks have to be attempted');
        $this->assertSame([], $metaPublishes, 'A failed chunk write must not be committed');
        $this->assertCount(1, $purges, 'The chunks this put wrote have to be reclaimed');
        $this->assertSame(['filter' => $chunks[0]], json_decode($purges[0], true));
    }
}
