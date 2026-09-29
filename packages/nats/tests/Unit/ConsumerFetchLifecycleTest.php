<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\MaxPayloadException;
use Utopia\NATS\JetStream\Consumer;
use Utopia\NATS\JetStream\ConsumerInfo;
use Utopia\NATS\JetStream\StreamConfig;
use Utopia\NATS\Tests\Unit\Support\FakeTransport;

/**
 * Consumer::fetch() opens an inbox subscription per pull request. It has to be
 * released on every exit, because attemptReconnect() re-subscribes every leaked
 * sid on each reconnect -- so a leak compounds across a reconnect storm. The
 * server's pull expiry also has to land before the client stops waiting.
 */
final class ConsumerFetchLifecycleTest extends TestCase
{
    /** @param array<string, mixed> $extra */
    private function connect(FakeTransport $fake, array $extra = []): Connection
    {
        return Connection::connect(new ConnectionOptions(...array_merge([
            'servers' => 'nats://127.0.0.1:4222',
            'allowReconnect' => false,
            'transportFactory' => fn(string $scheme): FakeTransport => $fake,
        ], $extra)));
    }

    private function consumer(Connection $conn): Consumer
    {
        return new Consumer($conn, 'STREAM', ConsumerInfo::fromArray([
            'stream_name' => 'STREAM',
            'name' => 'durable',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptions(Connection $conn): array
    {
        $property = new \ReflectionProperty(Connection::class, 'subscriptions');

        /** @var array<string, mixed> $subs */
        $subs = $property->getValue($conn);

        return $subs;
    }

    public function testFetchReleasesItsInboxSubscription(): void
    {
        $fake = new FakeTransport();
        $conn = $this->connect($fake);
        $consumer = $this->consumer($conn);

        $before = \count($this->subscriptions($conn));
        $consumer->fetch(1, 0.02);

        $this->assertCount(
            $before,
            $this->subscriptions($conn),
            'The inbox subscription must be released on the success path',
        );
    }

    public function testFetchReleasesItsInboxSubscriptionWhenItThrows(): void
    {
        // A tiny max_payload makes the pull request itself unpublishable, so the
        // throw lands after the inbox subscription is already open.
        $fake = new FakeTransport(['max_payload' => 4]);
        $conn = $this->connect($fake);
        $consumer = $this->consumer($conn);

        $before = \count($this->subscriptions($conn));

        try {
            $consumer->fetch(1, 0.02);
            $this->fail('Expected the oversized pull request to raise');
        } catch (MaxPayloadException) {
            // expected
        }

        $this->assertCount(
            $before,
            $this->subscriptions($conn),
            'A throw must not leak the inbox subscription',
        );
    }

    public function testRepeatedFailedFetchesDoNotAccumulateSubscriptions(): void
    {
        $fake = new FakeTransport(['max_payload' => 4]);
        $conn = $this->connect($fake);
        $consumer = $this->consumer($conn);

        $before = \count($this->subscriptions($conn));

        for ($i = 0; $i < 5; $i++) {
            try {
                $consumer->fetch(1, 0.02);
            } catch (MaxPayloadException) {
                // expected
            }
        }

        $this->assertCount(
            $before,
            $this->subscriptions($conn),
            'Subscription count must stay flat across repeated failures',
        );
    }

    public function testServerPullExpiryLandsBeforeTheClientDeadline(): void
    {
        $fake = new FakeTransport();
        $conn = $this->connect($fake);
        $consumer = $this->consumer($conn);

        $timeout = 0.05;
        $consumer->fetch(1, $timeout);

        $this->assertMatchesRegularExpression('/\{"batch":1,"expires":\d+\}/', $fake->written);
        preg_match('/\{"batch":1,"expires":(\d+)\}/', $fake->written, $matches);
        $expires = (int) $matches[1];

        $this->assertGreaterThan(0, $expires, 'The server still needs a usable window');
        $this->assertLessThan(
            StreamConfig::secondsToNanos($timeout),
            $expires,
            'A message dispatched at a shared boundary is dropped client-side while the server counts the delivery',
        );
    }
}
