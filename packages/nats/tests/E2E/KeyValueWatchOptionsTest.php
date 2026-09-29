<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\JetStream\JetStream;
use Utopia\NATS\KeyValue\KeyValue;
use Utopia\NATS\KeyValue\KeyValueConfig;
use Utopia\NATS\KeyValue\KeyValueEntry;
use Utopia\NATS\KeyValue\KeyValueOperation;
use Utopia\NATS\KeyValue\KeyValueWatchOptions;

final class KeyValueWatchOptionsTest extends TestCase
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
        $this->bucket = 'kvwo_' . uniqid();
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

    private function pumpUntil(callable $done, float $seconds = 3.0): void
    {
        $deadline = microtime(true) + $seconds;
        while (!$done() && microtime(true) < $deadline) {
            $this->conn->processMessage(0.2);
        }
    }

    public function testWatchIncludeHistoryThenLiveUpdates(): void
    {
        $this->kv->put('k', 'v1');
        $this->kv->put('k', 'v2');
        $this->kv->put('k', 'v3');
        $this->kv->delete('k');

        /** @var list<KeyValueEntry> $received */
        $received = [];
        $initDone = false;
        $countAtInit = null;

        $sub = $this->kv->watch(
            'k',
            function (KeyValueEntry $entry) use (&$received): void {
                $received[] = $entry;
            },
            new KeyValueWatchOptions(includeHistory: true),
            function () use (&$initDone, &$received, &$countAtInit): void {
                $initDone = true;
                $countAtInit = \count($received);
            },
        );
        $this->conn->flush();

        $this->pumpUntil(function () use (&$initDone): bool {
            return $initDone;
        });

        $this->assertTrue($initDone, 'onInitDone should fire after historical replay');
        $this->assertSame(4, $countAtInit, 'all four historical entries delivered before init-done');

        $values = array_map(static fn(KeyValueEntry $e): string => $e->value, \array_slice($received, 0, 3));
        $this->assertSame(['v1', 'v2', 'v3'], $values);
        $this->assertSame(KeyValueOperation::Delete, $received[3]->operation);

        // Live update after the historical set.
        $this->kv->put('k', 'v4');
        $this->pumpUntil(function () use (&$received): bool {
            return \count($received) >= 5;
        });
        $sub->unsubscribe();

        $this->assertGreaterThanOrEqual(5, \count($received));
        $this->assertSame('v4', $received[4]->value);
        $this->assertSame(KeyValueOperation::Put, $received[4]->operation);
    }

    public function testWatchIgnoreDeletesSkipsMarkers(): void
    {
        $this->kv->put('k', 'v1');
        $this->kv->delete('k');

        $ops = [];
        $initDone = false;

        $sub = $this->kv->watch(
            'k',
            function ($entry) use (&$ops): void {
                $ops[] = $entry->operation;
            },
            new KeyValueWatchOptions(includeHistory: true, ignoreDeletes: true),
            function () use (&$initDone): void {
                $initDone = true;
            },
        );
        $this->conn->flush();

        $this->pumpUntil(function () use (&$initDone): bool {
            return $initDone;
        });
        $sub->unsubscribe();

        $this->assertTrue($initDone);
        $this->assertContains(KeyValueOperation::Put, $ops);
        $this->assertNotContains(KeyValueOperation::Delete, $ops);
        $this->assertNotContains(KeyValueOperation::Purge, $ops);
    }

    public function testWatchMetaOnlyDeliversNoValue(): void
    {
        $this->kv->put('k', 'a-real-value');

        $entries = [];
        $initDone = false;

        $sub = $this->kv->watch(
            'k',
            function ($entry) use (&$entries): void {
                $entries[] = $entry;
            },
            new KeyValueWatchOptions(includeHistory: true, metaOnly: true),
            function () use (&$initDone): void {
                $initDone = true;
            },
        );
        $this->conn->flush();

        $this->pumpUntil(function () use (&$initDone): bool {
            return $initDone;
        });
        $sub->unsubscribe();

        $this->assertCount(1, $entries);
        $this->assertSame('k', $entries[0]->key);
        $this->assertSame('', $entries[0]->value, 'metaOnly entries carry no value body');
        $this->assertGreaterThan(0, $entries[0]->revision);
    }

    public function testPurgeDeletesRemovesTombstones(): void
    {
        $this->kv->put('a', '1');
        $this->kv->put('b', '2');
        $this->kv->put('c', '3');
        $this->kv->delete('a');
        $this->kv->purge('b');
        // 'c' stays live.

        // Markers keep 'a' and 'b' present before purging.
        $keysBefore = $this->kv->keys();
        sort($keysBefore);
        $this->assertSame(['a', 'b', 'c'], $keysBefore);
        $valuesBefore = $this->kv->status()->values;

        $removed = $this->kv->purgeDeletes();

        $this->assertSame(2, $removed);

        $keysAfter = $this->kv->keys();
        sort($keysAfter);
        $this->assertSame(['c'], $keysAfter);

        $valuesAfter = $this->kv->status()->values;
        $this->assertLessThan($valuesBefore, $valuesAfter);
        $this->assertSame(1, $valuesAfter);
    }

    public function testPurgeDeletesRespectsThreshold(): void
    {
        $this->kv->put('a', '1');
        $this->kv->delete('a');

        // Marker was just created; "older than 60s" keeps it.
        $removed = $this->kv->purgeDeletes(60.0);

        $this->assertSame(0, $removed);
        $this->assertContains('a', $this->kv->keys());
    }
}
