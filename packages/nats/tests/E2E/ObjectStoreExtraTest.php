<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\Exception\ObjectStoreException;
use Utopia\NATS\JetStream\JetStream;
use Utopia\NATS\ObjectStore\ObjectMeta;
use Utopia\NATS\ObjectStore\ObjectStore;
use Utopia\NATS\ObjectStore\ObjectStoreConfig;

final class ObjectStoreExtraTest extends TestCase
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
        $this->bucket = 'objx_' . uniqid();
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

    public function testWatchFiresOnPutAndDelete(): void
    {
        /** @var list<ObjectMeta> $events */
        $events = [];
        $sub = $this->store->watch(function (ObjectMeta $meta) use (&$events): void {
            $events[] = $meta;
        });

        // Ensure the consumer/subscription is established before producing.
        $this->conn->flush();

        $this->store->put('watched.txt', 'hello');
        $this->pumpUntil(fn(): bool => $events !== []);

        $this->assertCount(1, $events);
        $this->assertSame('watched.txt', $events[0]->name);
        $this->assertFalse($events[0]->deleted);

        $this->store->delete('watched.txt');
        $this->pumpUntil(fn(): bool => \count($events) >= 2);

        $sub->unsubscribe();

        $this->assertCount(2, $events);
        $this->assertSame('watched.txt', $events[1]->name);
        $this->assertTrue($events[1]->deleted);
    }

    public function testWatchIncludeHistoryDeliversCurrentMetaFirst(): void
    {
        $this->store->put('existing.txt', 'already here');

        /** @var list<ObjectMeta> $events */
        $events = [];
        $sub = $this->store->watch(function (ObjectMeta $meta) use (&$events): void {
            $events[] = $meta;
        }, includeHistory: true);

        $this->conn->flush();
        $this->pumpUntil(fn(): bool => $events !== []);

        $sub->unsubscribe();

        $this->assertNotEmpty($events);
        $this->assertSame('existing.txt', $events[0]->name);
    }

    public function testAddLinkResolvesToTargetBytes(): void
    {
        $this->store->put('target.bin', 'the real payload');

        $linkMeta = $this->store->addLink('alias.bin', 'target.bin');
        $this->assertInstanceOf(\Utopia\NATS\ObjectStore\ObjectLink::class, $linkMeta->link);
        $this->assertSame('target.bin', $linkMeta->link->name);

        // get() on the link transparently returns the target's bytes.
        $this->assertSame('the real payload', $this->store->get('alias.bin'));
    }

    public function testBucketLinkCannotBeRead(): void
    {
        $this->store->addBucketLink('otherbucket', 'SOME_OTHER_BUCKET');

        $this->expectException(ObjectStoreException::class);
        $this->store->get('otherbucket');
    }

    public function testUpdateMetaChangesDescriptionAndKeepsBytes(): void
    {
        $payload = 'immutable bytes';
        $this->store->put('doc.txt', $payload);

        $updated = $this->store->updateMeta('doc.txt', description: 'a helpful description');
        $this->assertSame('a helpful description', $updated->description);

        // Meta round-trips the new description...
        $this->assertSame('a helpful description', $this->store->getMeta('doc.txt')->description);

        // ...and the bytes are untouched.
        $this->assertSame($payload, $this->store->get('doc.txt'));
    }

    public function testSealThenPutThrows(): void
    {
        $this->store->put('before.txt', 'written before seal');

        $this->store->seal();

        $threw = false;
        try {
            $this->store->put('after.txt', 'should be rejected');
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw, 'put() after seal() should fail because the stream is sealed');

        // Data written before sealing is still readable.
        $this->assertSame('written before seal', $this->store->get('before.txt'));
    }

    /**
     * @param callable(): bool $done
     */
    private function pumpUntil(callable $done, float $timeout = 3.0): void
    {
        $deadline = microtime(true) + $timeout;
        while (!$done() && microtime(true) < $deadline) {
            $this->conn->processMessage(0.2);
        }
    }

    public function testDeleteConflictLeavesReplacementIntact(): void
    {
        // A stale delete must not corrupt a concurrent replacement: it should conflict
        // on the guarded tombstone publish BEFORE purging any chunks.
        $this->store->put('doc', 'v1');

        $readMeta = new \ReflectionMethod($this->store, 'readMetaWithSeq');
        [$staleMeta, $staleSeq] = $readMeta->invoke($this->store, 'doc');

        // A writer replaces the object, advancing the meta subject past $staleSeq.
        $this->store->put('doc', 'v2');

        // Replay the stale delete: it expects $staleSeq but the subject moved on.
        $deleteVersion = new \ReflectionMethod($this->store, 'deleteVersion');
        $conflicted = false;
        try {
            $deleteVersion->invoke($this->store, $staleMeta, $staleSeq);
        } catch (ObjectStoreException) {
            $conflicted = true;
        }

        $this->assertTrue($conflicted, 'stale delete should have conflicted');
        // The replacement is intact — its chunks were never purged by the stale delete.
        $this->assertSame('v2', $this->store->get('doc'));
    }
}
