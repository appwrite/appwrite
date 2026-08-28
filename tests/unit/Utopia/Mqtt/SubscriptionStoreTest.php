<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Mqtt;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Mqtt\SubscriptionStore;

final class SubscriptionStoreTest extends TestCase
{
    private const PROJECT = 'proj';

    private SubscriptionStore $store;

    protected function setUp(): void
    {
        $this->store = new SubscriptionStore();
    }

    /**
     * @return \Iterator<string, array{string, string, bool}>
     */
    public static function matchProvider(): \Iterator
    {
        // filter, publish topic, should match
        yield 'exact single level' => ['sport', 'sport', true];
        yield 'exact rejects deeper' => ['sport', 'sport/x', false];
        yield 'exact rejects sibling' => ['a/b', 'a/c', false];
        yield '+ matches one level' => ['sport/+', 'sport/x', true];
        yield '+ requires a level' => ['sport/+', 'sport', false];
        yield '+ does not span two levels' => ['sport/+', 'sport/x/y', false];
        yield '+ leading level' => ['+/hello', 'test/hello', true];
        yield '+ leading needs a level' => ['+/hello', 'hello', false];
        yield '+ interior level' => ['test/+/x', 'test/a/x', true];
        yield '+ interior is single level' => ['test/+/x', 'test/a/b/x', false];
        yield '# matches parent level' => ['sport/#', 'sport', true];
        yield '# matches one deeper' => ['sport/#', 'sport/x', true];
        yield '# matches many deeper' => ['sport/#', 'sport/x/y', true];
        yield 'root # matches everything' => ['#', 'a/b/c', true];
    }

    #[DataProvider('matchProvider')]
    public function testWildcardMatching(string $filter, string $topic, bool $shouldMatch): void
    {
        $this->store->subscribe(self::PROJECT, 'user', 'sub', $filter, 1, 1);

        $subscribers = $this->store->getSubscribers(self::PROJECT, $topic);

        $this->assertSame($shouldMatch, \array_key_exists(1, $subscribers));
    }

    public function testNoSubscribersReturnsEmpty(): void
    {
        $this->store->subscribe(self::PROJECT, 'user', 'sub', 'test/hello', 1, 1);

        $this->assertSame([], $this->store->getSubscribers(self::PROJECT, 'nope'));
    }

    public function testReturnsGrantedQos(): void
    {
        $this->store->subscribe(self::PROJECT, 'user', 'sub', 'test/hello', 1, 1);

        $this->assertSame([1 => 1], $this->store->getSubscribers(self::PROJECT, 'test/hello'));
    }

    public function testFanOutToMultipleConnections(): void
    {
        $this->store->subscribe(self::PROJECT, 'user', 'a', 'test/+', 11, 1);
        $this->store->subscribe(self::PROJECT, 'user', 'b', 'test/#', 12, 1);
        $this->store->subscribe(self::PROJECT, 'user', 'c', 'test/hello', 13, 1);

        $subscribers = $this->store->getSubscribers(self::PROJECT, 'test/hello');

        \ksort($subscribers);
        $this->assertSame([11 => 1, 12 => 1, 13 => 1], $subscribers);
    }

    public function testConnectionMatchedByTwoFiltersIsDedupedToHighestQos(): void
    {
        // Same fd matched by an exact (QoS 0) and a wildcard (QoS 1) subscription.
        $this->store->subscribe(self::PROJECT, 'user', 'exact', 'test/hello', 7, 0);
        $this->store->subscribe(self::PROJECT, 'user', 'wild', 'test/#', 7, 1);

        $subscribers = $this->store->getSubscribers(self::PROJECT, 'test/hello');

        $this->assertSame([7 => 1], $subscribers);
    }

    public function testResubscribeSameFilterReplacesGrantedQos(): void
    {
        // MQTT 3.8.4: re-subscribing the same (connection, subId) replaces the grant.
        $this->store->subscribe(self::PROJECT, 'user', 'sub', 'test/hello', 1, 1);
        $this->store->subscribe(self::PROJECT, 'user', 'sub', 'test/hello', 1, 0);

        $this->assertSame([1 => 0], $this->store->getSubscribers(self::PROJECT, 'test/hello'));
    }

    public function testResubscribeToNewTopicDropsOldTopic(): void
    {
        $this->store->subscribe(self::PROJECT, 'user', 'sub', 'test/hello', 1, 1);
        $this->store->subscribe(self::PROJECT, 'user', 'sub', 'test/world', 1, 1);

        $this->assertArrayNotHasKey(1, $this->store->getSubscribers(self::PROJECT, 'test/hello'));
        $this->assertArrayHasKey(1, $this->store->getSubscribers(self::PROJECT, 'test/world'));
    }

    public function testProjectIsolation(): void
    {
        $this->store->subscribe('projectA', 'user', 'sub', 'test/hello', 1, 1);
        $this->store->subscribe('projectB', 'user', 'sub', 'test/hello', 2, 1);

        $this->assertSame([1 => 1], $this->store->getSubscribers('projectA', 'test/hello'));
        $this->assertSame([2 => 1], $this->store->getSubscribers('projectB', 'test/hello'));
    }

    public function testUnsubscribeRemovesOnlyThatSubscription(): void
    {
        // One connection, two subscriptions under different subIds.
        $this->store->subscribe(self::PROJECT, 'user', 'first', 'test/hello', 1, 1);
        $this->store->subscribe(self::PROJECT, 'user', 'second', 'test/world', 1, 1);

        $this->store->unsubscribe('first', 1);

        $this->assertArrayNotHasKey(1, $this->store->getSubscribers(self::PROJECT, 'test/hello'));
        $this->assertArrayHasKey(1, $this->store->getSubscribers(self::PROJECT, 'test/world'));
    }

    public function testUnsubscribeUnknownSubIsNoop(): void
    {
        $this->store->subscribe(self::PROJECT, 'user', 'sub', 'test/hello', 1, 1);

        $this->store->unsubscribe('missing', 1);
        $this->store->unsubscribe('sub', 999);

        $this->assertSame([1 => 1], $this->store->getSubscribers(self::PROJECT, 'test/hello'));
    }

    public function testUnsubscribeThenTopicNoLongerMatchesWildcard(): void
    {
        // Covers pruning behaviour observably: after the only subscriber leaves, a
        // wildcard publish that used to match returns nothing.
        $this->store->subscribe(self::PROJECT, 'user', 'sub', 'test/deep/leaf', 1, 1);
        $this->assertArrayHasKey(1, $this->store->getSubscribers(self::PROJECT, 'test/deep/leaf'));

        $this->store->unsubscribe('sub', 1);

        $this->assertSame([], $this->store->getSubscribers(self::PROJECT, 'test/deep/leaf'));
        $this->assertSame([], $this->store->getSubscribers(self::PROJECT, 'test/deep/leaf'));
    }

    public function testCloseRemovesEverySubscriptionForConnection(): void
    {
        $this->store->subscribe(self::PROJECT, 'user', 'first', 'test/hello', 1, 1);
        $this->store->subscribe(self::PROJECT, 'user', 'second', 'other/#', 1, 1);
        // A second connection that must survive the close.
        $this->store->subscribe(self::PROJECT, 'user', 'keep', 'test/hello', 2, 1);

        $this->store->close(1);

        $this->assertSame([2 => 1], $this->store->getSubscribers(self::PROJECT, 'test/hello'));
        $this->assertSame([], $this->store->getSubscribers(self::PROJECT, 'other/x'));
    }
}
