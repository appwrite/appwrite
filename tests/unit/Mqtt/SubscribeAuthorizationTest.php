<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt;

use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Suback;

/**
 * The subscribe gate: an ordinary topic is granted only to a user entered on its subscriber list,
 * while the reserved users/<id> namespace stays owner-gated and needs no membership. Runs against
 * the in-memory topics/subscribers graph the ReplayTestCase harness wires up.
 */
final class SubscribeAuthorizationTest extends ReplayTestCase
{
    public function testEnteredSubscriberIsGranted(): void
    {
        $this->seedTopic('news', 0);

        $this->assertSame([Packet::QOS_1], $this->subscribeCodes($this->connect(1), 'news'));
    }

    public function testNonSubscriberIsRefused(): void
    {
        $this->seedTopic('news', 0, subscribed: false);

        $this->assertSame([Suback::DENIED], $this->subscribeCodes($this->connect(1), 'news'));
    }

    public function testUnknownTopicIsRefused(): void
    {
        $this->assertSame([Suback::DENIED], $this->subscribeCodes($this->connect(1), 'ghost'));
    }

    public function testOrdinaryWildcardIsRefused(): void
    {
        $this->seedTopic('scores/live', 0);

        $this->assertSame([Suback::DENIED], $this->subscribeCodes($this->connect(1), 'scores/+'));
    }

    public function testOwnedUserTopicIsGrantedWithoutMembership(): void
    {
        $name = 'users/' . self::USER;
        $this->seedTopic($name, 0, id: \md5($name), subscribed: false);

        $this->assertSame([Packet::QOS_1], $this->subscribeCodes($this->connect(1), $name));
    }

    public function testGrantIsDemotedWhenMembershipVanishesDuringReplay(): void
    {
        $this->seedTopic('news', 12, subscribed: false);
        $subscriber = $this->database->createDocument('subscribers', new Document([
            '$id' => ID::unique(),
            'topicInternalId' => $this->topicSequence('news'),
            'userId' => self::USER,
        ]));

        // Remove the membership right before onSubscribe re-verifies it (the 2nd 'subscribers' find),
        // as a concurrent deleteSubscriber would while the replay query yields.
        $this->database->interleave('subscribers', 2, fn () => $this->database->deleteDocument('subscribers', $subscriber->getId()));

        $this->assertSame([Suback::DENIED], $this->subscribeCodes($this->connect(1), 'news'));
    }

    public function testBacklogIsWithheldWhenMembershipVanishesDuringLedgerQuery(): void
    {
        $this->seedTopic('news', 5, subscribed: false);
        $subscriber = $this->database->createDocument('subscribers', new Document([
            '$id' => ID::unique(),
            'topicInternalId' => $this->topicSequence('news'),
            'userId' => self::USER,
        ]));
        $this->appendLedger('news', 1, 5);
        $this->seedCursor('news', 0);

        // Delete the subscriber row while the ledger query yields, as a concurrent deleteSubscriber
        // would; the backlog must be withheld whether or not the unsubscribe event has landed yet.
        $this->database->interleave('pushLedger', 1, fn () => $this->database->deleteDocument('subscribers', $subscriber->getId()));

        $this->assertSame([Suback::DENIED], $this->subscribeCodes($this->connect(1), 'news'));
        $this->assertSame([], $this->payloads($this->drainPublishes(1)), 'a removed subscriber was handed the backlog');
        $this->assertSame(0, $this->storedCursor('news'), 'the cursor advanced past an undelivered backlog');
    }
}
