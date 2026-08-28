<?php

declare(strict_types=1);

namespace Tests\Unit\Appwrite\Messaging\Adapter;

use Appwrite\Messaging\Adapter\Mqtt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Mqtt\Connection;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

/**
 * Unit tests for the MQTT push adapter's own behaviour: the connection registry
 * lifecycle (open/close) and the subscribe/unsubscribe/getSubscribers surface it
 * layers over the subscription store — topic matching, QoS, project isolation.
 *
 * send() publishes onto the Redis 'mqtt' channel and needs a live pool, so it is
 * covered by e2e rather than here; these tests never touch pub/sub or the transport.
 */
final class MqttTest extends TestCase
{
    private function adapter(): Mqtt
    {
        return new Mqtt(new NoTelemetry());
    }

    /** open() the fd, then subscribe it to $topic (subId defaults to the topic). */
    private function join(Mqtt $mqtt, string $projectId, int $fd, string $topic, string $subId = ''): void
    {
        $mqtt->open($fd);
        $mqtt->subscribe($projectId, $fd, $subId, [], [$topic]);
    }

    public function testOpenCreatesAndReusesConnection(): void
    {
        $mqtt = $this->adapter();

        $connection = $mqtt->open(7);

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame(7, $connection->fd);
        $this->assertSame($connection, $mqtt->open(7), 'open() must return the same instance for the same fd');
        $this->assertArrayHasKey(7, $mqtt->connections);
    }

    public function testSubscribeExposesSubscriberWithGrantedQos(): void
    {
        $mqtt = $this->adapter();

        $this->join($mqtt, 'project-a', 11, 'appwrite/push/user-1');

        $this->assertSame([11 => 1], $mqtt->getSubscribers('project-a', 'appwrite/push/user-1'));
        $this->assertTrue($mqtt->hasSubscriber('project-a', 'appwrite/push/user-1'));
        $this->assertFalse($mqtt->hasSubscriber('project-a', 'appwrite/push/user-2'));
    }

    /**
     * Topic-filter matching flows through to the store: + is one level, # is this
     * level onward, an exact filter matches only itself.
     *
     * @return \Iterator<string, array{string, string, bool}>
     */
    public static function wildcardProvider(): \Iterator
    {
        yield 'exact match' => ['test/hello', 'test/hello', true];
        yield 'exact miss' => ['test/hello', 'test/world', false];
        yield '+ one level' => ['test/+', 'test/hello', true];
        yield '+ not multi level' => ['test/+', 'test/a/b', false];
        yield '# multi level' => ['test/#', 'test/a/b', true];
        yield '# matches parent' => ['test/#', 'test', true];
        yield '# not sibling' => ['test/#', 'other', false];
    }

    #[DataProvider('wildcardProvider')]
    public function testTopicFilterMatching(string $filter, string $publishTopic, bool $shouldMatch): void
    {
        $mqtt = $this->adapter();
        $this->join($mqtt, 'project-a', 21, $filter);

        $subscribers = $mqtt->getSubscribers('project-a', $publishTopic);

        $this->assertSame($shouldMatch, isset($subscribers[21]));
    }

    public function testSubscribersAreIsolatedPerProject(): void
    {
        $mqtt = $this->adapter();

        $this->join($mqtt, 'project-a', 31, 'shared/topic');
        $this->join($mqtt, 'project-b', 32, 'shared/topic');

        $this->assertSame([31 => 1], $mqtt->getSubscribers('project-a', 'shared/topic'));
        $this->assertSame([32 => 1], $mqtt->getSubscribers('project-b', 'shared/topic'));
    }

    public function testUnsubscribeSubscriptionRemovesOnlyThatFilter(): void
    {
        $mqtt = $this->adapter();
        $mqtt->open(41);
        $mqtt->subscribe('project-a', 41, 'sub-orders', [], ['orders/new']);
        $mqtt->subscribe('project-a', 41, 'sub-alerts', [], ['alerts/all']);

        $mqtt->unsubscribeSubscription(41, 'sub-orders');

        $this->assertFalse($mqtt->hasSubscriber('project-a', 'orders/new'));
        $this->assertSame([41 => 1], $mqtt->getSubscribers('project-a', 'alerts/all'));
    }

    public function testSubscriptionIdFallsBackToTopic(): void
    {
        $mqtt = $this->adapter();
        // Empty subscription id: the topic itself becomes the id used for removal.
        $this->join($mqtt, 'project-a', 51, 'news/tech');

        $mqtt->unsubscribeSubscription(51, 'news/tech');

        $this->assertFalse($mqtt->hasSubscriber('project-a', 'news/tech'));
    }

    public function testUnsubscribeRemovesEveryFilterForConnection(): void
    {
        $mqtt = $this->adapter();
        $mqtt->open(61);
        $mqtt->subscribe('project-a', 61, 'a', [], ['a/one']);
        $mqtt->subscribe('project-a', 61, 'b', [], ['b/two']);

        $mqtt->unsubscribe(61);

        $this->assertFalse($mqtt->hasSubscriber('project-a', 'a/one'));
        $this->assertFalse($mqtt->hasSubscriber('project-a', 'b/two'));
    }

    public function testCloseRemovesSubscriptionsAndForgetsConnection(): void
    {
        $mqtt = $this->adapter();
        $connection = $mqtt->open(71);
        $connection->active = true;
        $mqtt->subscribe('project-a', 71, 'sub', [], ['live/feed']);

        $mqtt->close(71);

        $this->assertArrayNotHasKey(71, $mqtt->connections);
        $this->assertFalse($mqtt->hasSubscriber('project-a', 'live/feed'));
        $this->assertNotSame($connection, $mqtt->open(71), 'a reopened fd is a fresh connection');
    }
}
