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
    /** A stored message, as the server acknowledges one. */
    private const PUB_ACK = '{"stream":"OBJ_BUCKET","seq":1}';

    /** The server refusing to store a message because the stream is at max_bytes. */
    private const MAX_BYTES = '{"error":{"code":400,"err_code":10077,"description":"maximum bytes exceeded"}}';

    private function connection(FakeTransport $fake, float $requestTimeout = 1.0): Connection
    {
        return Connection::connect(new ConnectionOptions(
            servers: 'nats://127.0.0.1:4222',
            allowReconnect: false,
            requestTimeout: $requestTimeout,
            transportFactory: fn (string $scheme): FakeTransport => $fake,
        ));
    }

    /**
     * A connection whose requests are never answered: js->publish() waits for a
     * PubAck that never arrives and raises TimeoutException.
     */
    private function unresponsiveConnection(FakeTransport $fake): Connection
    {
        return $this->connection($fake, 0.02);
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
        $metas = [];
        $purges = [];
        // The bucket's byte budget runs out on the second chunk.
        $fake = $this->scriptedBucket(
            static fn (int $chunk): string => $chunk === 1 ? self::PUB_ACK : self::MAX_BYTES,
            static fn (): string => self::PUB_ACK,
            $chunks,
            $metas,
            $purges,
        );
        $conn = $this->connection($fake);
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
        $this->assertSame([], $metas, 'A failed chunk write must not be committed');
        $this->assertCount(1, $purges, 'The chunks this put wrote have to be reclaimed');
        $this->assertSame(['filter' => $chunks[0]], json_decode($purges[0], true));
    }

    /**
     * The same rejection can land on the meta publish instead: the chunks fit, the
     * record naming them does not. The server decoded and refused that publish, so
     * no meta record exists and the chunks are orphaned exactly as above -- only a
     * CAS conflict used to be compensated, which left a full bucket leaking the
     * whole of every failed put.
     */
    public function testObjectStorePutPurgesItsChunksWhenTheBucketRefusesTheMetaRecord(): void
    {
        $chunks = [];
        $metas = [];
        $purges = [];
        $fake = $this->scriptedBucket(
            static fn (int $chunk): string => self::PUB_ACK,
            static fn (): string => self::MAX_BYTES,
            $chunks,
            $metas,
            $purges,
        );
        $conn = $this->connection($fake);
        $store = new ObjectStore($conn, $conn->jetStream(), 'BUCKET');

        try {
            $store->put('object', 'payload');
            $this->fail('Expected the full bucket to reject the meta publish');
        } catch (JetStreamException $e) {
            $this->assertSame('maximum bytes exceeded', $e->getMessage());
        } finally {
            $conn->close();
        }

        $this->assertCount(1, $chunks, 'The chunk fits, so it is written');
        $this->assertCount(1, $metas, 'The meta publish is attempted and refused');
        $this->assertCount(1, $purges, 'The orphaned chunk has to be reclaimed');
        $this->assertSame(['filter' => $chunks[0]], json_decode($purges[0], true));
    }

    /**
     * The counterpart: a meta publish that is never answered is not a verdict. It
     * may have been stored, and a stored meta record names these chunks, so purging
     * them would corrupt the object instead of reclaiming waste.
     */
    public function testObjectStorePutKeepsItsChunksWhenTheMetaPublishIsUnanswered(): void
    {
        $chunks = [];
        $metas = [];
        $purges = [];
        $fake = $this->scriptedBucket(
            static fn (int $chunk): string => self::PUB_ACK,
            static fn (): ?string => null,
            $chunks,
            $metas,
            $purges,
        );
        $conn = $this->connection($fake, 0.02);
        $store = new ObjectStore($conn, $conn->jetStream(), 'BUCKET');

        try {
            $store->put('object', 'payload');
            $this->fail('Expected the unanswered meta publish to raise');
        } catch (TimeoutException) {
            // expected
        } finally {
            $conn->close();
        }

        $this->assertCount(1, $chunks);
        $this->assertCount(1, $metas);
        $this->assertSame([], $purges, 'An ambiguous meta publish must not purge the chunks it may name');
    }

    /**
     * A scripted object-store bucket on a fake transport: the meta read answers "no
     * message found", a purge always succeeds and is recorded, and the chunk and meta
     * publishes are answered by the given closures -- which return the reply body, or
     * null to leave the publish unanswered the way a dead server would.
     *
     * @param \Closure(int): ?string $chunkReply Called with this put's chunk number, from 1.
     * @param \Closure(): ?string $metaReply
     * @param list<string> $chunks Receives every chunk subject published, in order.
     * @param list<string> $metas Receives every meta subject published, in order.
     * @param list<string> $purges Receives every purge request body, in order.
     */
    private function scriptedBucket(\Closure $chunkReply, \Closure $metaReply, array &$chunks, array &$metas, array &$purges): FakeTransport
    {
        $fake = new FakeTransport();
        $fake->onWrite = static function (string $wire, FakeTransport $fake) use ($chunkReply, $metaReply, &$chunks, &$metas, &$purges): void {
            $frame = self::publishedFrame($wire);
            if ($frame === null) {
                return;
            }

            $subject = $frame['subject'];
            $body = null;
            if (str_starts_with($subject, '$JS.API.STREAM.MSG.GET.')) {
                $body = '{"error":{"code":404,"err_code":10037,"description":"no message found"}}';
            } elseif (str_starts_with($subject, '$O.BUCKET.C.')) {
                $chunks[] = $subject;
                $body = $chunkReply(\count($chunks));
            } elseif (str_starts_with($subject, '$JS.API.STREAM.PURGE.')) {
                $purges[] = $frame['payload'];
                $body = '{"success":true,"purged":1}';
            } elseif (str_starts_with($subject, '$O.BUCKET.M.')) {
                $metas[] = $subject;
                $body = $metaReply();
            }

            if ($body !== null) {
                $fake->pushInbound("MSG {$frame['reply']} 1 " . \strlen($body) . "\r\n{$body}\r\n");
            }
        };

        return $fake;
    }

    /**
     * Split a PUB / HPUB frame the client wrote into the subject it went to, the
     * reply subject an answer has to go back on, and its payload. Returns null for
     * anything that is not a request: CONNECT, PING, SUB, UNSUB, or a publish with
     * no reply subject.
     *
     * @return array{subject: string, reply: string, payload: string}|null
     */
    private static function publishedFrame(string $wire): ?array
    {
        $end = strpos($wire, "\r\n");
        if ($end === false) {
            return null;
        }

        // PUB <subject> <reply> <bytes>, HPUB <subject> <reply> <header bytes> <total bytes>
        $parts = explode(' ', substr($wire, 0, $end));
        $headers = $parts[0] === 'HPUB';
        if (!$headers && $parts[0] !== 'PUB') {
            return null;
        }
        if (\count($parts) !== ($headers ? 5 : 4)) {
            return null;
        }

        $headerLength = $headers ? (int) $parts[3] : 0;

        return [
            'subject' => $parts[1],
            'reply' => $parts[2],
            'payload' => substr($wire, $end + 2 + $headerLength, (int) $parts[$headers ? 4 : 3] - $headerLength),
        ];
    }
}
