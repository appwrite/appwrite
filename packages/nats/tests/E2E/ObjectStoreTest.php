<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\Exception\ObjectStoreException;
use Utopia\NATS\JetStream\JetStream;
use Utopia\NATS\ObjectStore\ObjectStore;
use Utopia\NATS\ObjectStore\ObjectStoreConfig;

final class ObjectStoreTest extends TestCase
{
    private Connection $conn;
    private JetStream $js;
    private ObjectStore $store;
    private string $bucket;

    protected function setUp(): void
    {
        $url = getenv('NATS_URL') ?: 'nats://127.0.0.1:4222';
        $this->conn = Connection::connect($url);
        $this->js = $this->conn->jetStream();
        $this->bucket = 'obj_' . uniqid();
        $this->store = ObjectStore::createOrUpdate($this->conn, $this->js, new ObjectStoreConfig(
            bucket: $this->bucket,
        ));
    }

    protected function tearDown(): void
    {
        try {
            $this->js->deleteStream("OBJ_{$this->bucket}");
        } catch (\Throwable) {
            // ignore
        }
        $this->conn->close();
    }

    public function testRoundTripMultiChunkPayload(): void
    {
        // ~300KB => spans multiple 128KB chunks.
        $payload = random_bytes(300 * 1024);

        $putMeta = $this->store->put('big.bin', $payload);
        $this->assertGreaterThan(1, $putMeta->chunks);
        $this->assertSame(\strlen($payload), $putMeta->size);

        $fetched = $this->store->get('big.bin');
        $this->assertSame($payload, $fetched);

        $meta = $this->store->getMeta('big.bin');
        $this->assertSame($putMeta->digest, $meta->digest);

        // Digest matches an independent computation.
        $expectedDigest = 'SHA-256=' . rtrim(strtr(base64_encode(hash('sha256', $payload, true)), '+/', '-_'), '=');
        $this->assertSame($expectedDigest, $meta->digest);
    }

    public function testSmallObjectRoundTrip(): void
    {
        $this->store->put('hello.txt', 'hello world');
        $this->assertSame('hello world', $this->store->get('hello.txt'));
    }

    public function testListReturnsStoredObjects(): void
    {
        $this->store->put('a.txt', 'aaa');
        $this->store->put('b.txt', 'bbb');

        $names = array_map(fn(\Utopia\NATS\ObjectStore\ObjectMeta $m): string => $m->name, $this->store->list());
        sort($names);

        $this->assertSame(['a.txt', 'b.txt'], $names);
    }

    public function testDeleteRemovesObject(): void
    {
        $this->store->put('temp.txt', 'gone soon');
        $this->assertSame('gone soon', $this->store->get('temp.txt'));

        $this->store->delete('temp.txt');

        $this->expectException(\RuntimeException::class);
        $this->store->get('temp.txt');
    }

    public function testOverwriteReplacesData(): void
    {
        $this->store->put('file', 'version-one');
        $this->store->put('file', 'version-two-longer');

        $this->assertSame('version-two-longer', $this->store->get('file'));
        $this->assertCount(1, $this->store->list());
    }

    public function testStatusReportsBucketStream(): void
    {
        $this->store->put('x.txt', 'data');
        $info = $this->store->status();
        $this->assertSame("OBJ_{$this->bucket}", $info->config->name);
        $this->assertGreaterThan(0, $info->state->messages);
    }

    public function testSingleWriterOverwriteReclaimsOldChunks(): void
    {
        // Each version is a single chunk. After overwrite, only the new chunk
        // plus one rolled-up meta record should remain (2 messages total);
        // a leftover previous chunk would push the count to 3.
        $this->store->put('reclaim.bin', 'version-one');
        $this->store->put('reclaim.bin', 'version-two');

        $this->assertSame('version-two', $this->store->get('reclaim.bin'));
        $this->assertSame(2, $this->store->status()->state->messages);
    }

    public function testConcurrentOverwriteConflictsInsteadOfOrphaning(): void
    {
        // Deterministically drive the optimistic-concurrency conflict rather than
        // racing two real writers (which serialises on a fast host). Capture the meta
        // sequence a stale writer would hold, let a second write advance the subject,
        // then replay the stale write via the seq-aware seam and assert it conflicts
        // and cleans up its own chunks.
        $this->store->put('conflict.bin', 'v1');

        $readMeta = new \ReflectionMethod($this->store, 'readMetaWithSeq');
        [$stalePrev, $staleSeq] = $readMeta->invoke($this->store, 'conflict.bin');

        // A second writer wins, advancing the meta subject past $staleSeq.
        $this->store->put('conflict.bin', 'v2');

        // Replay the stale write: it expects $staleSeq but the subject moved on.
        $writeVersion = new \ReflectionMethod($this->store, 'writeVersion');
        $conflicted = false;
        try {
            $writeVersion->invoke($this->store, 'conflict.bin', 'v3-stale', $stalePrev, $staleSeq);
        } catch (ObjectStoreException) {
            $conflicted = true;
        }

        $this->assertTrue($conflicted, 'stale write should have conflicted');

        // Winner intact, and no orphaned chunks: only v2's single chunk plus the
        // rolled-up meta remain (the stale attempt purged its own chunk).
        $this->assertSame('v2', $this->store->get('conflict.bin'));
        $this->assertCount(1, $this->store->list());
        $this->assertSame(2, $this->store->status()->state->messages);
    }
}
