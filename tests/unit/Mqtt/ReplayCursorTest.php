<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt;

use Utopia\Database\Query;
use Utopia\Mqtt\Packet;

/** The offline-replay cursor of Handler, driven through onSubscribe and onPuback. */
final class ReplayCursorTest extends ReplayTestCase
{
    public function testTruncatedReplayAdvancesCursorToTail(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 2);
        $connection = $this->connect(1);

        $this->subscribe($connection, 'news');
        $replayed = $this->drainPublishes(1);
        $this->acknowledge($connection, ...$replayed);

        $this->assertSame(['message-8', 'message-9', 'message-10', 'message-11', 'message-12'], $this->payloads($replayed));
        $this->assertSame(12, $this->storedCursor('news'), 'acknowledging the truncated replay must advance the cursor to the tail');

        $this->subscribe($this->connect(2), 'news');

        $this->assertSame([], $this->payloads($this->drainPublishes(2)), 'a reconnect must not replay messages already acknowledged');
    }

    public function testFullReplayAdvancesCursor(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 9);
        $connection = $this->connect(1);

        $this->subscribe($connection, 'news');
        $replayed = $this->drainPublishes(1);
        $this->acknowledge($connection, ...$replayed);

        $this->assertSame(['message-10', 'message-11', 'message-12'], $this->payloads($replayed));
        $this->assertSame(12, $this->storedCursor('news'));
    }

    public function testPrunedLedgerAnchorsAtTail(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 2);
        $this->seedCursor('news', 2);

        $this->subscribe($this->connect(1), 'news');

        $this->assertSame([], $this->payloads($this->drainPublishes(1)));
        $this->assertSame(12, $this->storedCursor('news'), 'a backlog pruned from the ledger must not pin the cursor below it');
    }

    public function testCursorAboveTailFromARecreatedTopicIsIgnored(): void
    {
        $this->seedTopic('news', 3);
        $this->appendLedger('news', 1, 3);
        $this->seedCursor('news', 150);
        $connection = $this->connect(1);

        $this->subscribe($connection, 'news');
        $replayed = $this->drainPublishes(1);
        $this->acknowledge($connection, ...$replayed);

        $this->assertSame(['message-1', 'message-2', 'message-3'], $this->payloads($replayed), 'a cursor above the tail belongs to an earlier topic of the same name');
        $this->assertSame(3, $this->storedCursor('news'));
    }

    public function testResubscribeReplaysOnlyNewMessages(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 9);
        $connection = $this->connect(1);
        $this->subscribe($connection, 'news');
        $this->acknowledge($connection, ...$this->drainPublishes(1));
        $this->appendLedger('news', 13, 14);
        $this->updateTail('news', 14);

        $this->subscribe($connection, 'news');
        $persisted = $this->storedCursor('news');
        $replayed = $this->drainPublishes(1);
        $this->acknowledge($connection, ...$replayed);

        $this->assertSame(['message-13', 'message-14'], $this->payloads($replayed));
        $this->assertSame(12, $persisted, 'a re-SUBSCRIBE must not persist a cursor below what was acknowledged');
        $this->assertSame(14, $this->storedCursor('news'));
    }

    public function testInsertDuringSubscribeIsReplayed(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 2);
        $connection = $this->connect(1);
        $this->database->interleave('pushLedger', 1, function (): void {
            $this->appendLedger('news', 13, 13);
            $this->updateTail('news', 13);
        });

        $this->subscribe($connection, 'news');
        $replayed = $this->drainPublishes(1);
        $this->acknowledge($connection, ...$replayed);

        $this->assertSame(['message-9', 'message-10', 'message-11', 'message-12', 'message-13'], $this->payloads($replayed), 'a message committed after the topic was read must still be replayed');
        $this->assertSame(13, $this->storedCursor('news'), 'acknowledging the replay must advance the cursor past the late message');

        $this->subscribe($this->connect(2), 'news');

        $this->assertSame([], $this->payloads($this->drainPublishes(2)), 'a reconnect must not replay messages already acknowledged');
    }

    public function testLedgerLookupIsBoundedBelowByDepth(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 2);

        $this->subscribe($this->connect(1), 'news');

        $finds = $this->database->finds('pushLedger');
        $this->assertCount(1, $finds);
        $queries = $finds[0];

        $this->assertContainsEquals(
            Query::greaterThan('sequence', \max(2, 12 - self::MAX_DEPTH)),
            $queries,
            'the ledger lookup must be bounded below by the replay depth so it reads at most that many rows',
        );
        $this->assertContainsEquals(
            Query::orderDesc('$sequence'),
            $queries,
            'the ledger lookup must break ties descending so the (topic, sequence) index serves it with a backward range scan',
        );
    }

    public function testAcknowledgementDuringReplayIsNotOverwritten(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 9);
        $this->seedTopic('scores', 5);
        $this->appendLedger('scores', 1, 5);
        $this->seedCursor('scores', 3);
        $connection = $this->connect(1);
        $this->database->interleave('pushLedger', 2, function () use ($connection): void {
            $this->acknowledge($connection, ...$this->drainPublishes(1));
        });

        $this->subscribe($connection, 'news', 'scores');

        $this->assertSame(12, $this->storedCursor('news'), 'the replay persisted a cursor below acknowledgements made while it ran');
        $this->assertSame(3, $this->storedCursor('scores'));
        $this->assertSame(['message-4', 'message-5'], $this->payloads($this->drainPublishes(1)));
    }

    public function testAcknowledgementDuringLedgerFindIsNotLowered(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 9);
        $connection = $this->connect(1);
        $this->subscribe($connection, 'news');
        $this->acknowledge($connection, ...$this->drainPublishes(1));
        $connection->publish('news', 'message-13', qos: Packet::QOS_1, sequence: 13);
        $connection->publish('news', 'message-14', qos: Packet::QOS_1, sequence: 14);
        $live = $this->drainPublishes(1);
        $this->updateTail('news', 13);
        $this->database->interleave('pushLedger', 2, function () use ($connection, $live): void {
            $this->acknowledge($connection, ...$live);
        });

        $this->subscribe($connection, 'news');

        $this->assertSame(14, $connection->cursor('news'), 'acknowledgements made while the ledger was queried must not be lowered');
        $this->assertSame(14, $this->storedCursor('news'));

        $connection->publish('news', 'message-15', qos: Packet::QOS_1, sequence: 15);
        $this->acknowledge($connection, ...$this->drainPublishes(1));

        $this->assertSame(15, $this->storedCursor('news'), 'the next live acknowledgement must keep advancing the cursor');
    }

    public function testTopicWithNothingToReplayKeepsAcknowledgedCursor(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 12);
        $this->seedTopic('scores', 5);
        $this->appendLedger('scores', 1, 5);
        $this->seedCursor('scores', 3);
        $connection = $this->connect(1);
        $this->subscribe($connection, 'news');
        $this->database->interleave('pushLedger', 1, function () use ($connection): void {
            $this->appendLedger('news', 13, 13);
            $this->updateTail('news', 13);
            $connection->publish('news', 'message-13', qos: Packet::QOS_1, sequence: 13);
            $this->acknowledge($connection, ...$this->drainPublishes(1));
        });

        $this->subscribe($connection, 'news', 'scores');

        $this->assertSame(13, $this->storedCursor('news'), 'a topic with nothing to replay must not persist a cursor below a live acknowledgement');
        $this->assertSame(['message-4', 'message-5'], $this->payloads($this->drainPublishes(1)));
    }

    public function testPubackWritesCursorForLedgerRetention(): void
    {
        $this->seedTopic('news', 10);
        $connection = $this->connect(1);
        $connection->publish('news', 'message-10', qos: Packet::QOS_1, sequence: 10);

        $this->acknowledge($connection, ...$this->drainPublishes(1));

        $this->assertSame(10, $this->storedCursor('news'));
        $this->assertSame(APP_PUSH_LEDGER_RETENTION, $this->cursorTtl(), 'an acknowledgement must expire the cursor with the ledger, or a key it recreates never expires');
    }

    public function testReplayWritesCursorForLedgerRetention(): void
    {
        $this->seedTopic('news', 12, expiry: 60);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 9);

        $this->subscribe($this->connect(1), 'news');

        $this->assertSame(9, $this->storedCursor('news'));
        $this->assertSame(APP_PUSH_LEDGER_RETENTION, $this->cursorTtl(), 'the replay must keep the cursor for the ledger retention, not the topic expiry');
    }
}
