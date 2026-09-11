<?php

declare(strict_types=1);

namespace Tests\Unit\Appwrite\Mqtt;

use Appwrite\Mqtt\SubscriptionStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
        $this->store->subscribe(self::PROJECT, 'user', $filter, 1, 1);

        $subscribers = $this->store->getSubscribers(self::PROJECT, $topic);

        $this->assertSame($shouldMatch, \array_key_exists(1, $subscribers));
    }

    public function testNoSubscribersReturnsEmpty(): void
    {
        $this->store->subscribe(self::PROJECT, 'user', 'test/hello', 1, 1);

        $this->assertSame([], $this->store->getSubscribers(self::PROJECT, 'nope'));
    }

    public function testReturnsGrantedQos(): void
    {
        $this->store->subscribe(self::PROJECT, 'user', 'test/hello', 1, 1);

        $this->assertSame([1 => 1], $this->store->getSubscribers(self::PROJECT, 'test/hello'));
    }

    public function testFanOutToMultipleConnections(): void
    {
        $this->store->subscribe(self::PROJECT, 'user', 'test/+', 11, 1);
        $this->store->subscribe(self::PROJECT, 'user', 'test/#', 12, 1);
        $this->store->subscribe(self::PROJECT, 'user', 'test/hello', 13, 1);

        $subscribers = $this->store->getSubscribers(self::PROJECT, 'test/hello');

        \ksort($subscribers);
        $this->assertSame([11 => 1, 12 => 1, 13 => 1], $subscribers);
    }

    public function testConnectionMatchedByTwoFiltersIsDedupedToHighestQos(): void
    {
        // Same fd matched by an exact (QoS 0) and a wildcard (QoS 1) subscription.
        $this->store->subscribe(self::PROJECT, 'user', 'test/hello', 7, 0);
        $this->store->subscribe(self::PROJECT, 'user', 'test/#', 7, 1);

        $subscribers = $this->store->getSubscribers(self::PROJECT, 'test/hello');

        $this->assertSame([7 => 1], $subscribers);
    }

    public function testResubscribeSameFilterReplacesGrantedQos(): void
    {
        // MQTT 3.8.4: re-subscribing the same (connection, topic) replaces the grant.
        $this->store->subscribe(self::PROJECT, 'user', 'test/hello', 1, 1);
        $this->store->subscribe(self::PROJECT, 'user', 'test/hello', 1, 0);

        $this->assertSame([1 => 0], $this->store->getSubscribers(self::PROJECT, 'test/hello'));
    }

    public function testProjectIsolation(): void
    {
        $this->store->subscribe('projectA', 'user', 'test/hello', 1, 1);
        $this->store->subscribe('projectB', 'user', 'test/hello', 2, 1);

        $this->assertSame([1 => 1], $this->store->getSubscribers('projectA', 'test/hello'));
        $this->assertSame([2 => 1], $this->store->getSubscribers('projectB', 'test/hello'));
    }

    public function testUnsubscribeRemovesOnlyThatTopic(): void
    {
        // One connection subscribed to two topics.
        $this->store->subscribe(self::PROJECT, 'user', 'test/hello', 1, 1);
        $this->store->subscribe(self::PROJECT, 'user', 'test/world', 1, 1);

        $this->store->unsubscribe('test/hello', 1);

        $this->assertArrayNotHasKey(1, $this->store->getSubscribers(self::PROJECT, 'test/hello'));
        $this->assertArrayHasKey(1, $this->store->getSubscribers(self::PROJECT, 'test/world'));
    }

    public function testUnsubscribeUnknownTopicIsNoop(): void
    {
        $this->store->subscribe(self::PROJECT, 'user', 'test/hello', 1, 1);

        $this->store->unsubscribe('missing/topic', 1);
        $this->store->unsubscribe('test/hello', 999);

        $this->assertSame([1 => 1], $this->store->getSubscribers(self::PROJECT, 'test/hello'));
    }

    public function testUnsubscribeThenTopicNoLongerMatchesWildcard(): void
    {
        // Covers pruning behaviour observably: after the only subscriber leaves, a
        // wildcard publish that used to match returns nothing.
        $this->store->subscribe(self::PROJECT, 'user', 'test/deep/leaf', 1, 1);
        $this->assertArrayHasKey(1, $this->store->getSubscribers(self::PROJECT, 'test/deep/leaf'));

        $this->store->unsubscribe('test/deep/leaf', 1);

        $this->assertSame([], $this->store->getSubscribers(self::PROJECT, 'test/deep/leaf'));
    }

    public function testCloseRemovesEverySubscriptionForConnection(): void
    {
        $this->store->subscribe(self::PROJECT, 'user', 'test/hello', 1, 1);
        $this->store->subscribe(self::PROJECT, 'user', 'other/#', 1, 1);
        // A second connection that must survive the close.
        $this->store->subscribe(self::PROJECT, 'user', 'test/hello', 2, 1);

        $this->store->close(1);

        $this->assertSame([2 => 1], $this->store->getSubscribers(self::PROJECT, 'test/hello'));
        $this->assertSame([], $this->store->getSubscribers(self::PROJECT, 'other/x'));
    }

    public function testGetConnectionReturnsTheFdRecord(): void
    {
        $this->store->subscribe(self::PROJECT, 'user-1', 'test/hello', 9, 1);

        $connection = $this->store->getConnection(9);

        $this->assertSame(self::PROJECT, $connection['projectId']);
        $this->assertSame('user-1', $connection['userId']);
        $this->assertSame(1, $connection['subs']['test/hello']);
    }

    public function testGetConnectionIsNullForAnUnknownFd(): void
    {
        $this->assertNull($this->store->getConnection(404));
    }
}
