<?php

namespace Appwrite\Mqtt\Event;

use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Mqtt\Handler as Broker;
use Swoole\Coroutine\Channel;
use Utopia\Console\Console;
use Utopia\Mqtt\Server;
use Utopia\Span\Span;

/** Handles the broker's internal events: revoke, unsubscribe and deliver. */
class Handler
{
    public function __construct(
        private readonly Server $server,
        private readonly Broker $broker,
        private readonly Mqtt $mqtt,
        private readonly float $timeout,
    ) {
    }

    public function handle(mixed $redis, string $channel, string $payload): void
    {
        $event = \json_decode($payload, true);
        if (!\is_array($event)) {
            return;
        }

        $projectId = (string) ($event['project'] ?? '');

        match ($event['action'] ?? '') {
            Mqtt::ACTION_REVOKE => $this->revoke($projectId, $event),
            Mqtt::ACTION_UNSUBSCRIBE => $this->unsubscribe($projectId, $event),
            default => $this->deliver($projectId, $event),
        };
    }

    /**
     * Revoke a user's connections; fails closed if verification doesn't finish in time.
     *
     * @param array<string, mixed> $event
     */
    private function revoke(string $projectId, array $event): void
    {
        $userId = (string) ($event['userId'] ?? '');

        $revoke = function () use ($projectId, $userId): void {
            $span = null;

            try {
                $span = Span::init('mqtt.revoke');
                $span->set('project.id', $projectId);
                $span->set('user.id', $userId);
                $span->set('mqtt.is_broker', true);
                $span->set('mqtt.disconnected', $this->broker->revoke($projectId, $userId));
            } catch (\Throwable $error) {
                $span?->setError($error);
                Console::error('Failed to revoke MQTT connections: ' . $error->getMessage());
            } finally {
                $span?->finish();
            }
        };

        $revoked = new Channel(1);
        $started = \go(function () use ($revoke, $revoked): void {
            try {
                $revoke();
            } finally {
                $revoked->push(true);
            }
        });

        // An inline check could block on the same dead read.
        if ($started === false) {
            $disconnected = $this->broker->expel($projectId, $userId);
            Console::error("MQTT coroutine limit reached for revoke of user {$userId} in project {$projectId}; closed {$disconnected} connections unverified");

            return;
        }

        if ($revoked->pop($this->timeout) === false) {
            $disconnected = $this->broker->expel($projectId, $userId);
            Console::error("MQTT revoke verification timed out after {$this->timeout}s for user {$userId} in project {$projectId}; closed {$disconnected} connections unverified");
        }
    }

    /**
     * Drop a user's subscription to a topic; fails closed if the membership check doesn't finish in time.
     *
     * @param array<string, mixed> $event
     */
    private function unsubscribe(string $projectId, array $event): void
    {
        $userId = (string) ($event['userId'] ?? '');
        $topic = (string) ($event['topic'] ?? '');

        $unsubscribe = function () use ($projectId, $userId, $topic): void {
            $span = null;

            try {
                $span = Span::init('mqtt.unsubscribe');
                $span->set('project.id', $projectId);
                $span->set('user.id', $userId);
                $span->set('mqtt.topic', $topic);
                $span->set('mqtt.is_broker', true);
                $span->set('mqtt.unsubscribed', $this->broker->unsubscribe($this->server, $projectId, $userId, $topic));
            } catch (\Throwable $error) {
                $span?->setError($error);
                Console::error('Failed to unsubscribe MQTT connections: ' . $error->getMessage());
            } finally {
                $span?->finish();
            }
        };

        $unsubscribed = new Channel(1);
        $started = \go(function () use ($unsubscribe, $unsubscribed): void {
            try {
                $unsubscribe();
            } finally {
                $unsubscribed->push(true);
            }
        });

        if ($started === false) {
            $dropped = $this->broker->drop($this->server, $projectId, $userId, $topic);
            Console::error("MQTT coroutine limit reached for unsubscribe of user {$userId} in project {$projectId}; dropped the subscription from {$dropped} connections unverified");

            return;
        }

        if ($unsubscribed->pop($this->timeout) === false) {
            $dropped = $this->broker->drop($this->server, $projectId, $userId, $topic);
            Console::error("MQTT unsubscribe membership check timed out after {$this->timeout}s for user {$userId} in project {$projectId}; dropped the subscription from {$dropped} connections unverified");
        }
    }

    /** @param array<string, mixed> $event */
    private function deliver(string $projectId, array $event): void
    {
        $topic = (string) ($event['topic'] ?? '');
        $qos = (int) ($event['qos'] ?? 0);
        $sequence = (int) ($event['sequence'] ?? 0);
        $publishedAt = (float) ($event['publishedAt'] ?? 0.0);
        $message = \base64_decode((string) ($event['payload'] ?? ''));

        $span = Span::init('mqtt.deliver');
        $span->set('project.id', $projectId);
        $span->set('mqtt.topic', $topic);
        $span->set('mqtt.qos', $qos);
        $span->set('mqtt.is_broker', true);

        $delivered = $this->broker->deliver($this->server, $projectId, $topic, $message, $qos, $sequence, $publishedAt);

        $span->set('mqtt.subscribers', $delivered);
        if ($delivered === 0) {
            $this->mqtt->messagesDropped->add(1, ['reason' => 'no_subscriber']);
        }

        $span->finish();
    }
}
