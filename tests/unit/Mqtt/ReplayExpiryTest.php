<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt;

use Utopia\Mqtt\Packet;

/** Message expiry in the offline replay of Handler: the topic's own, else the providers' lowest. */
final class ReplayExpiryTest extends ReplayTestCase
{
    private const HOUR = 3600;

    private const DAY = 86400;

    public function testTopicExpirySkipsOlderMessages(): void
    {
        $this->seedTopic('news', 2, expiry: 60);
        $this->appendLedger('news', 1, 1, age: 120);
        $this->appendLedger('news', 2, 2, age: 10);
        $this->seedCursor('news', 0);

        $this->subscribe($this->connect(1), 'news');

        $this->assertSame(['message-2'], $this->payloads($this->drainPublishes(1)), 'a message older than the topic expiry must not be replayed');
    }

    public function testCursorAdvancesPastExpiredRows(): void
    {
        $this->seedTopic('news', 4, expiry: 60);
        $this->appendLedger('news', 1, 2, age: 120);
        $this->appendLedger('news', 3, 4, age: 10);
        $this->seedCursor('news', 0);
        $connection = $this->connect(1);

        $this->subscribe($connection, 'news');
        $persisted = $this->storedCursor('news');
        $replayed = $this->drainPublishes(1);
        $this->acknowledge($connection, ...$replayed);

        $this->assertSame(['message-3', 'message-4'], $this->payloads($replayed));
        $this->assertSame(2, $persisted, 'the cursor must move past expired messages before any acknowledgement');
        $this->assertSame(4, $this->storedCursor('news'));

        $this->subscribe($this->connect(2), 'news');

        $this->assertSame([], $this->payloads($this->drainPublishes(2)), 'a reconnect must not replay expired or acknowledged messages');
    }

    public function testExpiredMessageBetweenFreshOnesCutsTheWindow(): void
    {
        $this->seedTopic('news', 3, expiry: 60);
        $this->appendLedger('news', 1, 1, age: 10);
        $this->appendLedger('news', 2, 2, age: 120);
        $this->appendLedger('news', 3, 3, age: 10);
        $this->seedCursor('news', 0);
        $connection = $this->connect(1);

        $this->subscribe($connection, 'news');
        $replayed = $this->drainPublishes(1);
        $this->acknowledge($connection, ...$replayed);

        $this->assertSame(['message-3'], $this->payloads($replayed), 'a message older than an expired one must not be replayed');
        $this->assertSame(3, $connection->cursor('news'), 'an expired message between fresh ones must not leave a gap in the acknowledged run');
        $this->assertSame(3, $this->storedCursor('news'), 'acknowledging the replay must advance the cursor past the expired message');
    }

    public function testProviderExpiryAppliesToUserTopic(): void
    {
        $this->seedProvider(MESSAGE_PROVIDER_APPWRITE, true, 20);
        $name = 'users/' . self::USER;
        $id = $this->seedTopic($name, 1, id: \md5($name));
        $this->appendLedger($id, 1, 1, age: 60);
        $this->seedCursor($name, 0);

        $this->subscribe($this->connect(1), $name);

        $this->assertSame([], $this->payloads($this->drainPublishes(1)), 'a user topic must inherit the provider expiry, so a message 60 s old under a 20 s expiry is not replayed');
        $this->assertSame(1, $this->storedCursor($name));
    }

    public function testLaterProviderUpdateIsHonoured(): void
    {
        $provider = $this->seedProvider(MESSAGE_PROVIDER_APPWRITE, true, null);
        $this->seedTopic('news', 1);
        $this->appendLedger('news', 1, 1, age: 60);
        $this->seedCursor('news', 0);

        $this->subscribe($this->connect(1), 'news');
        $before = $this->payloads($this->drainPublishes(1));
        $this->updateProviderExpiry($provider, 20);
        $this->subscribe($this->connect(2), 'news');

        $this->assertSame(['message-1'], $before);
        $this->assertSame([], $this->payloads($this->drainPublishes(2)), 'an expiry set on the provider after publishing must apply at replay time');
    }

    public function testTopicExpiryOverridesProvider(): void
    {
        $this->seedProvider(MESSAGE_PROVIDER_APPWRITE, true, 20);
        $this->seedTopic('news', 2, expiry: 120);
        $this->appendLedger('news', 1, 1, age: 180);
        $this->appendLedger('news', 2, 2, age: 60);
        $this->seedCursor('news', 0);

        $this->subscribe($this->connect(1), 'news');

        $this->assertSame(['message-2'], $this->payloads($this->drainPublishes(1)), 'the topic expiry must replace the provider expiry');
    }

    public function testMinimumExpiryAcrossEnabledProvidersWins(): void
    {
        $this->seedProvider(MESSAGE_PROVIDER_APPWRITE, true, self::HOUR);
        $this->seedProvider(MESSAGE_PROVIDER_APPWRITE, true, 20);
        $this->seedProvider(MESSAGE_PROVIDER_APPWRITE, true, null);
        $this->seedProvider(MESSAGE_PROVIDER_APPWRITE, false, 5);
        $this->seedProvider('fcm', true, 5);
        $this->seedTopic('news', 2);
        $this->appendLedger('news', 1, 1, age: 60);
        $this->appendLedger('news', 2, 2, age: 10);
        $this->seedCursor('news', 0);

        $this->subscribe($this->connect(1), 'news');

        $this->assertSame(['message-2'], $this->payloads($this->drainPublishes(1)), 'the lowest expiry of the enabled Appwrite providers must apply');
    }

    public function testProviderExpiryIsResolvedOncePerSubscribe(): void
    {
        $this->seedProvider(MESSAGE_PROVIDER_APPWRITE, true, 20);
        $this->seedTopic('news', 1);
        $this->appendLedger('news', 1, 1, age: 10);
        $this->seedCursor('news', 0);
        $this->seedTopic('scores', 1);
        $this->appendLedger('scores', 1, 1, age: 10);
        $this->seedCursor('scores', 0);
        $this->seedTopic('weather', 1);
        $this->seedCursor('weather', 1);

        $this->subscribe($this->connect(1), 'weather');
        $withoutBacklog = \count($this->database->finds('providers'));
        $this->subscribe($this->connect(2), 'news', 'scores');

        $this->assertSame(0, $withoutBacklog, 'providers must not be read for a topic with nothing to replay');
        $this->assertCount(1, $this->database->finds('providers'), 'providers must be read once per subscribe, however many topics inherit their expiry');
        $this->assertSame(['message-1', 'message-1'], $this->payloads($this->drainPublishes(2)));
    }

    public function testNullExpiryReachesBackToLedgerWindow(): void
    {
        $this->seedProvider(MESSAGE_PROVIDER_APPWRITE, true, null);
        $this->seedTopic('news', 1);
        $this->appendLedger('news', 1, 1, age: 2 * self::DAY);
        $this->seedCursor('news', 0);

        $this->subscribe($this->connect(1), 'news');

        $this->assertSame(['message-1'], $this->payloads($this->drainPublishes(1)), 'without any expiry a message must stay replayable for as long as the ledger keeps it');
    }

    public function testZeroExpiryReplaysNothing(): void
    {
        $this->seedTopic('news', 2, expiry: 0);
        $this->appendLedger('news', 1, 2, age: 1);
        $this->seedCursor('news', 0);

        $this->subscribe($this->connect(1), 'news');

        $this->assertSame([], $this->payloads($this->drainPublishes(1)), 'a zero expiry disables offline replay');
        $this->assertSame([], $this->database->finds('pushLedger'), 'a zero expiry must not read the ledger');
        $this->assertSame(2, $this->storedCursor('news'));
    }

    public function testCursorOlderThanAnHourStillResumes(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 9, writtenAt: self::NOW - 2 * self::HOUR);

        $this->subscribe($this->connect(1), 'news');

        $this->assertSame(['message-10', 'message-11', 'message-12'], $this->payloads($this->drainPublishes(1)), 'a cursor written two hours ago must still be resumed from');
    }

    public function testPubackReadsCursorWithCursorTtl(): void
    {
        $this->seedTopic('news', 12);
        $connection = $this->connect(1);
        $connection->publish('news', 'message-10', qos: Packet::QOS_1, sequence: 10);
        $this->seedCursor('news', 12);
        $this->now = self::NOW + 2 * self::HOUR;

        $this->acknowledge($connection, ...$this->drainPublishes(1));

        $this->assertSame(12, $this->storedCursor('news'), 'a late acknowledgement must not lower a cursor written more than an hour earlier');
    }
}
