<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime;

use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Realtime\EventTailRegistry;
use Appwrite\Utopia\Database\RuntimeQuery;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Query;
use Utopia\WebSocket\Server;

/**
 * Unit tests for the console live event tail: the compact-metadata projection
 * (Realtime::toTailMetadata) and the per-worker filter-then-sample registry
 * (EventTailRegistry). All exercised through the public API with an injected clock
 * and a mocked websocket Server ΓÇö no Swoole coroutines.
 */
final class EventTailRegistryTest extends TestCase
{
    /**
     * Mocked Server that records every send() as a decoded payload.
     *
     * @param array<int,array<string,mixed>> $sent
     */
    private function server(array &$sent): Server
    {
        // A stub (not a mock): we only capture sends, no expectations to verify.
        $server = $this->createStub(Server::class);
        $server->method('send')->willReturnCallback(
            function (array $connections, string $message) use (&$sent): void {
                $sent[] = json_decode($message, true);
            }
        );
        return $server;
    }

    /**
     * Like server(), but records the target connection id alongside each frame so tests
     * can assert per-connection isolation.
     *
     * @param array<int,array{connId:mixed, frame:array<string,mixed>}> $sent
     */
    private function serverWithConn(array &$sent): Server
    {
        $server = $this->createStub(Server::class);
        $server->method('send')->willReturnCallback(
            function (array $connections, string $message) use (&$sent): void {
                $sent[] = ['connId' => $connections[0] ?? null, 'frame' => json_decode($message, true)];
            }
        );
        return $server;
    }

    /** @param array<int,\Utopia\Database\Query> $queries */
    private function compile(array $queries): array
    {
        return RuntimeQuery::compile($queries);
    }

    // ---- Realtime::toTailMetadata ----

    public function testTailMetadataDocumentEvent(): void
    {
        $event = [
            'userId' => 'user-1',
            'data' => [
                'events' => ['databases.main.collections.posts.documents.p1.create'],
                'timestamp' => '2026-06-06T00:00:00.000+00:00',
                'payload' => ['$id' => 'p1', '$databaseId' => 'main', '$collectionId' => 'posts', 'title' => 'hello'],
            ],
        ];

        $meta = Realtime::toTailMetadata($event);

        $this->assertSame('databases.main.collections.posts.documents.p1.create', $meta['event']);
        $this->assertSame('databases', $meta['type']);
        $this->assertSame('create', $meta['action']);
        $this->assertSame('user-1', $meta['userId']);
        $this->assertSame('p1', $meta['resourceId']);
        $this->assertSame('main', $meta['databaseId']);
        $this->assertSame('posts', $meta['collectionId']);
        // No full document body leaks through.
        $this->assertArrayNotHasKey('title', $meta);
        // Scope is type-specific: a databases event carries no bucket/function ids.
        $this->assertArrayNotHasKey('bucketId', $meta);
        $this->assertArrayNotHasKey('functionId', $meta);
    }

    public function testTailMetadataScopeIsTypeSpecific(): void
    {
        $fn = Realtime::toTailMetadata([
            'userId' => 'u1',
            'data' => [
                'events' => ['functions.fn1.deployments.d1.create'],
                'payload' => ['$id' => 'd1', 'functionId' => 'fn1'],
            ],
        ]);
        $this->assertSame('functions', $fn['type']);
        $this->assertSame('fn1', $fn['functionId']);
        $this->assertArrayNotHasKey('databaseId', $fn);
        $this->assertArrayNotHasKey('bucketId', $fn);

        $file = Realtime::toTailMetadata([
            'userId' => 'u1',
            'data' => [
                'events' => ['buckets.b1.files.f1.create'],
                'payload' => ['$id' => 'f1', 'bucketId' => 'b1'],
            ],
        ]);
        $this->assertSame('buckets', $file['type']);
        $this->assertSame('b1', $file['bucketId']);
        $this->assertArrayNotHasKey('functionId', $file);
        $this->assertArrayNotHasKey('collectionId', $file);

        // Scope comes from the event name, so it's present even when the payload (here an
        // execution doc) carries no functionId field ΓÇö resourceId is the leaf ($id).
        $exec = Realtime::toTailMetadata([
            'data' => ['events' => ['functions.fn1.executions.e1.create'], 'payload' => ['$id' => 'e1']],
        ]);
        $this->assertSame('fn1', $exec['functionId']);
        $this->assertSame('e1', $exec['resourceId']);
    }

    public function testTailMetadataTopLevelResourceEventsCarryScope(): void
    {
        // Top-level resource events: the payload is the resource doc itself (id at $id,
        // not at a teamId/bucketId/functionId field). Scope must still be present so a
        // filter like equal('teamId', ['T']) matches the team's own create/update events.
        $teamCreate = Realtime::toTailMetadata([
            'data' => ['events' => ['teams.T.create'], 'payload' => ['$id' => 'T', 'name' => 'Acme']],
        ]);
        $this->assertSame('teams', $teamCreate['type']);
        $this->assertSame('T', $teamCreate['teamId']);
        $this->assertSame('T', $teamCreate['resourceId']);

        // Nested membership event: same teamId scope, leaf resourceId is the membership.
        $membership = Realtime::toTailMetadata([
            'data' => ['events' => ['teams.T.memberships.M.create'], 'payload' => ['$id' => 'M', 'teamId' => 'T']],
        ]);
        $this->assertSame('T', $membership['teamId']);
        $this->assertSame('M', $membership['resourceId']);

        $bucketCreate = Realtime::toTailMetadata([
            'data' => ['events' => ['buckets.B.create'], 'payload' => ['$id' => 'B']],
        ]);
        $this->assertSame('B', $bucketCreate['bucketId']);

        $functionCreate = Realtime::toTailMetadata([
            'data' => ['events' => ['functions.F.create'], 'payload' => ['$id' => 'F']],
        ]);
        $this->assertSame('F', $functionCreate['functionId']);
    }

    public function testTailMetadataTablesUsesTableIdAsCollection(): void
    {
        $meta = Realtime::toTailMetadata([
            'userId' => 'u1',
            'data' => [
                'events' => ['databases.main.tables.t1.rows.r1.create'],
                'payload' => ['$id' => 'r1', '$databaseId' => 'main', '$tableId' => 't1'],
            ],
        ]);
        $this->assertSame('databases', $meta['type']);
        $this->assertSame('main', $meta['databaseId']);
        $this->assertSame('t1', $meta['collectionId']);
    }

    public function testTailMetadataAttributeTrailingAction(): void
    {
        // `users.U.update.email` ΓÇö action is the second-to-last segment.
        $meta = Realtime::toTailMetadata([
            'userId' => 'u9',
            'data' => ['events' => ['users.u9.update.email'], 'payload' => ['$id' => 'u9']],
        ]);

        $this->assertSame('users', $meta['type']);
        $this->assertSame('update', $meta['action']);
        $this->assertSame('u9', $meta['resourceId']);
    }

    public function testTailMetadataHandlesMissingPayload(): void
    {
        $meta = Realtime::toTailMetadata(['data' => ['events' => ['functions.f1.executions.e1.delete']]]);

        $this->assertSame('functions', $meta['type']);
        $this->assertSame('delete', $meta['action']);
        $this->assertNull($meta['userId']);
        $this->assertNull($meta['resourceId']);
    }

    // ---- channel helpers ----

    public function testChannelHelpers(): void
    {
        $this->assertSame('console.tail.proj1', EventTailRegistry::channel('proj1'));
        $this->assertSame('proj1', EventTailRegistry::projectFromChannel('console.tail.proj1'));
        $this->assertNull(EventTailRegistry::projectFromChannel('documents'));
        $this->assertNull(EventTailRegistry::projectFromChannel('console.tail.'));
    }

    // ---- filter THEN sample ----

    public function testFilterIsAppliedBeforeSampling(): void
    {
        $registry = new EventTailRegistry(rate: 100);
        $registry->add(1, 'sub-a', 'projX', $this->compile([Query::equal('type', ['databases'])]), 0.0);

        $registry->ingest('projX', ['type' => 'databases', 'action' => 'create'], 0.0);
        $registry->ingest('projX', ['type' => 'buckets', 'action' => 'create'], 0.0);

        $sent = [];
        $registry->flush($this->server($sent));

        // Exactly one event frame, carrying only the databases frame; no stats frame
        // because the filtered-out bucket event never consumed a token.
        $this->assertCount(1, $sent);
        $this->assertSame(['console.tail'], $sent[0]['data']['events']);
        $this->assertCount(1, $sent[0]['data']['payload']);
        $this->assertSame('databases', $sent[0]['data']['payload'][0]['type']);
    }

    public function testTokenBucketDropsExcessAndEmitsCounter(): void
    {
        $registry = new EventTailRegistry(rate: 5);
        $registry->add(1, 'sub-a', 'projX', $this->compile([]), 0.0); // selectAll

        for ($i = 0; $i < 20; $i++) {
            $registry->ingest('projX', ['type' => 'databases', 'seq' => $i], 0.0);
        }

        $sent = [];
        $registry->flush($this->server($sent));

        $eventFrames = array_values(array_filter($sent, fn ($f) => $f['data']['events'] === ['console.tail']));
        $statsFrames = array_values(array_filter($sent, fn ($f) => $f['data']['events'] === ['console.tail.stats']));

        $this->assertCount(1, $eventFrames);
        $this->assertCount(5, $eventFrames[0]['data']['payload'], 'rate=5 ΓåÆ only 5 delivered at the same instant');

        $this->assertCount(1, $statsFrames);
        $this->assertSame('tail.stats', $statsFrames[0]['data']['payload']['$type']);
        $this->assertSame(5, $statsFrames[0]['data']['payload']['delivered']);
        $this->assertSame(15, $statsFrames[0]['data']['payload']['dropped']);
        // delivered + dropped accounts for every ingested event.
        $this->assertSame(20, $statsFrames[0]['data']['payload']['delivered'] + $statsFrames[0]['data']['payload']['dropped']);
    }

    public function testNoCounterFrameWhenNothingDropped(): void
    {
        $registry = new EventTailRegistry(rate: 100);
        $registry->add(1, 'sub-a', 'projX', $this->compile([]), 0.0);

        $registry->ingest('projX', ['type' => 'databases'], 0.0);

        $sent = [];
        $registry->flush($this->server($sent));

        // Only the event frame ΓÇö no stats frame because nothing was dropped.
        $this->assertCount(1, $sent);
        $this->assertSame(['console.tail'], $sent[0]['data']['events']);
    }

    public function testDroppedAccumulatesAcrossIngestsInWindow(): void
    {
        $registry = new EventTailRegistry(rate: 2);
        $registry->add(1, 'sub-a', 'projX', $this->compile([]), 0.0);

        // Three separate ingests at the same instant: 2 delivered, 1 dropped.
        $registry->ingest('projX', ['type' => 'databases', 'seq' => 1], 0.0);
        $registry->ingest('projX', ['type' => 'databases', 'seq' => 2], 0.0);
        $registry->ingest('projX', ['type' => 'databases', 'seq' => 3], 0.0);

        $sent = [];
        $registry->flush($this->server($sent));

        $statsFrames = array_values(array_filter($sent, fn ($f) => $f['data']['events'] === ['console.tail.stats']));
        $this->assertCount(1, $statsFrames);
        $this->assertSame(2, $statsFrames[0]['data']['payload']['delivered']);
        $this->assertSame(1, $statsFrames[0]['data']['payload']['dropped']);
    }

    public function testCounterResetsBetweenWindows(): void
    {
        $registry = new EventTailRegistry(rate: 1);
        $registry->add(1, 'sub-a', 'projX', $this->compile([]), 0.0);

        // Window 1: 3 events at t=0 ΓåÆ 1 delivered, 2 dropped ΓåÆ stats frame emitted.
        for ($i = 0; $i < 3; $i++) {
            $registry->ingest('projX', ['type' => 'databases', 'seq' => $i], 0.0);
        }
        $sent = [];
        $registry->flush($this->server($sent));
        $statsFrames = array_values(array_filter($sent, fn ($f) => $f['data']['events'] === ['console.tail.stats']));
        $this->assertCount(1, $statsFrames);
        $this->assertSame(2, $statsFrames[0]['data']['payload']['dropped']);

        // Window 2: a single event well after refill ΓåÆ delivered, nothing dropped ΓåÆ no stats frame
        // and the previous window's dropped count does not carry over.
        $registry->ingest('projX', ['type' => 'databases'], 100.0);
        $sent = [];
        $registry->flush($this->server($sent));
        $statsFrames = array_values(array_filter($sent, fn ($f) => $f['data']['events'] === ['console.tail.stats']));
        $this->assertCount(0, $statsFrames);
    }

    public function testLargeBufferIsChunkedAcrossFrames(): void
    {
        // rate high enough to admit all, batchMax forces splitting on flush.
        $registry = new EventTailRegistry(rate: 100, batchMax: 2);
        $registry->add(1, 'sub-a', 'projX', $this->compile([]), 0.0);

        for ($i = 0; $i < 5; $i++) {
            $registry->ingest('projX', ['type' => 'databases', 'seq' => $i], 0.0);
        }

        $sent = [];
        $registry->flush($this->server($sent));

        $eventFrames = array_values(array_filter($sent, fn ($f) => $f['data']['events'] === ['console.tail']));
        // 5 buffered frames, batchMax 2 ΓåÆ 3 websocket frames (2 + 2 + 1).
        $this->assertCount(3, $eventFrames);
        $this->assertCount(2, $eventFrames[0]['data']['payload']);
        $this->assertCount(2, $eventFrames[1]['data']['payload']);
        $this->assertCount(1, $eventFrames[2]['data']['payload']);
        // No event is lost across the chunk boundaries.
        $total = array_sum(array_map(fn ($f) => count($f['data']['payload']), $eventFrames));
        $this->assertSame(5, $total);
    }

    public function testTokensRefillOverTime(): void
    {
        $registry = new EventTailRegistry(rate: 5);
        $registry->add(1, 'sub-a', 'projX', $this->compile([]), 0.0);

        // Drain the bucket at t=0.
        for ($i = 0; $i < 10; $i++) {
            $registry->ingest('projX', ['type' => 'databases'], 0.0);
        }
        $sent = [];
        $registry->flush($this->server($sent)); // resets the per-window counters

        // One second later, the bucket has refilled to the cap (rate=5).
        for ($i = 0; $i < 10; $i++) {
            $registry->ingest('projX', ['type' => 'databases'], 1.0);
        }
        $sent = [];
        $registry->flush($this->server($sent));

        $eventFrames = array_values(array_filter($sent, fn ($f) => $f['data']['events'] === ['console.tail']));
        $this->assertCount(5, $eventFrames[0]['data']['payload']);
    }

    public function testFlushClearsBufferBetweenWindows(): void
    {
        $registry = new EventTailRegistry(rate: 100);
        $registry->add(1, 'sub-a', 'projX', $this->compile([]), 0.0);
        $registry->ingest('projX', ['type' => 'databases'], 0.0);

        $sent = [];
        $registry->flush($this->server($sent));
        $this->assertCount(1, $sent);

        // Nothing new ingested ΓåÆ second flush sends nothing.
        $sent = [];
        $registry->flush($this->server($sent));
        $this->assertCount(0, $sent);
    }

    public function testRemoveConnectionStopsDelivery(): void
    {
        $registry = new EventTailRegistry(rate: 100);
        $registry->add(7, 'sub-a', 'projX', $this->compile([]), 0.0);
        $registry->add(7, 'sub-b', 'projY', $this->compile([]), 0.0);

        $this->assertTrue($registry->isTailed('projX'));
        $this->assertTrue($registry->isTailed('projY'));

        $registry->removeConnection(7);

        $this->assertFalse($registry->isTailed('projX'));
        $this->assertFalse($registry->isTailed('projY'));

        $registry->ingest('projX', ['type' => 'databases'], 0.0);
        $sent = [];
        $registry->flush($this->server($sent));
        $this->assertCount(0, $sent);
    }

    public function testRemoveSingleSubscription(): void
    {
        $registry = new EventTailRegistry(rate: 100);
        $registry->add(7, 'sub-a', 'projX', $this->compile([]), 0.0);
        $registry->add(7, 'sub-b', 'projX', $this->compile([]), 0.0);

        $registry->remove(7, 'sub-a');
        $this->assertTrue($registry->isTailed('projX'), 'sub-b still tails projX');

        $registry->remove(7, 'sub-b');
        $this->assertFalse($registry->isTailed('projX'));

        // Removing an unknown subscription is a no-op.
        $registry->remove(7, 'does-not-exist');
        $this->assertFalse($registry->isTailed('projX'));
    }

    public function testSameSubscriptionIdOnDifferentConnectionsIsolated(): void
    {
        // Subscription IDs are only unique within a connection. Two connections both
        // using 'dup' must stay fully isolated ΓÇö no cross-project/connection leakage.
        $registry = new EventTailRegistry(rate: 100);
        $registry->add(1, 'dup', 'projA', $this->compile([]), 0.0);
        $registry->add(2, 'dup', 'projB', $this->compile([]), 0.0);

        $registry->ingest('projA', ['type' => 'databases', 'resourceId' => 'a1'], 0.0);
        $registry->ingest('projB', ['type' => 'databases', 'resourceId' => 'b1'], 0.0);

        $sent = [];
        $registry->flush($this->serverWithConn($sent));

        $byConn = [];
        foreach ($sent as $s) {
            $byConn[$s['connId']][] = $s['frame']['data']['channels'][0];
        }
        // Connection 1 only ever receives projA's channel; connection 2 only projB's.
        $this->assertSame(['console.tail.projA'], $byConn[1] ?? []);
        $this->assertSame(['console.tail.projB'], $byConn[2] ?? []);

        // Removing one connection's 'dup' must not touch the other's.
        $registry->remove(1, 'dup');
        $this->assertFalse($registry->isTailed('projA'));
        $this->assertTrue($registry->isTailed('projB'));
    }

    public function testMultipleProjectsUnderOneSubscription(): void
    {
        // A subscription naming several tail channels tails each project independently;
        // none overwrites another, and removing the subscription clears them all.
        $registry = new EventTailRegistry(rate: 100);
        $registry->add(1, 'sub-multi', 'projA', $this->compile([]), 0.0);
        $registry->add(1, 'sub-multi', 'projB', $this->compile([]), 0.0);

        $this->assertTrue($registry->isTailed('projA'));
        $this->assertTrue($registry->isTailed('projB'));

        $registry->ingest('projA', ['type' => 'databases', 'resourceId' => 'a1'], 0.0);
        $registry->ingest('projB', ['type' => 'databases', 'resourceId' => 'b1'], 0.0);

        $sent = [];
        $registry->flush($this->serverWithConn($sent));

        $channels = array_map(fn ($s) => $s['frame']['data']['channels'][0], $sent);
        $this->assertContains('console.tail.projA', $channels);
        $this->assertContains('console.tail.projB', $channels);
        // Both frames carry the same (real) subscription id.
        foreach ($sent as $s) {
            $this->assertSame(['sub-multi'], $s['frame']['data']['subscriptions']);
        }

        $registry->remove(1, 'sub-multi');
        $this->assertFalse($registry->isTailed('projA'));
        $this->assertFalse($registry->isTailed('projB'));
    }

    public function testReAddingSameChannelOverwritesFilterWithoutStaleEntry(): void
    {
        // Reusing a subscription id on the SAME tail channel updates its filter in place
        // (keyed by connId/subId/projectId) ΓÇö no stale entry, no duplicate delivery.
        $registry = new EventTailRegistry(rate: 100);
        $registry->add(1, 'x', 'projA', $this->compile([Query::equal('action', ['create'])]), 0.0);
        $registry->add(1, 'x', 'projA', $this->compile([Query::equal('action', ['delete'])]), 0.0);

        $registry->ingest('projA', ['action' => 'create', 'resourceId' => 'c1'], 0.0);
        $registry->ingest('projA', ['action' => 'delete', 'resourceId' => 'd1'], 0.0);

        $sent = [];
        $registry->flush($this->server($sent));

        $eventFrames = array_values(array_filter($sent, fn ($f) => $f['data']['events'] === ['console.tail']));
        // A lingering create-filter entry would surface the create and/or add a 2nd frame.
        $this->assertCount(1, $eventFrames);
        $this->assertCount(1, $eventFrames[0]['data']['payload']);
        $this->assertSame('delete', $eventFrames[0]['data']['payload'][0]['action']);
        $this->assertSame('d1', $eventFrames[0]['data']['payload'][0]['resourceId']);
    }

    public function testAddingChannelPreservesExistingTail(): void
    {
        // Review scenario: subscribe id 'x' to tail A, later add tail B under the same id.
        // Registration is additive (mirrors Realtime::subscribe()), so A must keep delivering.
        $registry = new EventTailRegistry(rate: 100);
        $registry->add(1, 'x', 'projA', $this->compile([]), 0.0);

        // ... later, the same subscription id also tails projB.
        $registry->add(1, 'x', 'projB', $this->compile([]), 0.0);

        $this->assertTrue($registry->isTailed('projA'), 'projA must remain tailed after adding projB');
        $this->assertTrue($registry->isTailed('projB'));

        $registry->ingest('projA', ['type' => 'databases', 'resourceId' => 'a1'], 0.0);
        $registry->ingest('projB', ['type' => 'databases', 'resourceId' => 'b1'], 0.0);

        $sent = [];
        $registry->flush($this->serverWithConn($sent));

        $channels = array_map(fn ($s) => $s['frame']['data']['channels'][0], $sent);
        $this->assertContains('console.tail.projA', $channels, 'projA stopped delivering after projB was added');
        $this->assertContains('console.tail.projB', $channels);
    }
}
