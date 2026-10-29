<?php

namespace Appwrite\Mqtt;

use Appwrite\Messaging\Adapter\Mqtt;
use Swoole\Coroutine\Channel;
use Utopia\Console\Console;
use Utopia\Mqtt\Server;
use Utopia\Span\Span;

/**
 * The broker's subscriber to the internal 'mqtt' pub/sub channel: revokes, unsubscribes and
 * deliveries produced elsewhere, applied to this worker's connections through the Handler.
 *
 * Revoke and unsubscribe verify against the project database. Each runs in a child coroutine so
 * the connections it opens leave with its context instead of piling up here, and the subscriber
 * waits on it at most $timeout seconds: a verification stuck on a dead database read must not park
 * the subscriber, which would stop every delivery and revoke on this worker. On timeout, or when
 * no child coroutine can start, the broker fails closed — the access is withdrawn unverified — and
 * a late child finds its connections already handled (Handler skips untracked connections).
 */
class Subscriber
{
    public function __construct(
        private readonly Server $server,
        private readonly Handler $handler,
        private readonly Mqtt $mqtt,
        private readonly float $timeout,
    ) {
    }

    /** Apply one event from the channel; the phpredis subscribe callback. */
    public function receive(mixed $redis, string $channel, string $payload): void
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
     * Revoke a user's connections after they were blocked or deleted. On timeout every connection
     * is closed unverified: clients reconnect and CONNECT re-authenticates them with fresh
     * credentials, so an unverifiable user is excluded rather than left connected.
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
                $span->set('mqtt.disconnected', $this->handler->revoke($projectId, $userId));
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

        // An inline check could block on the same dead read, so a full coroutine table fails closed too.
        if ($started === false) {
            $disconnected = $this->handler->expel($projectId, $userId);
            Console::error("MQTT coroutine limit reached for revoke of user {$userId} in project {$projectId}; closed {$disconnected} connections unverified");

            return;
        }

        if ($revoked->pop($this->timeout) === false) {
            $disconnected = $this->handler->expel($projectId, $userId);
            Console::error("MQTT revoke verification timed out after {$this->timeout}s for user {$userId} in project {$projectId}; closed {$disconnected} connections unverified");
        }
    }

    /**
     * Drop a user's live subscription to a topic after they were removed as a subscriber. On
     * timeout the subscription is dropped unverified: a user subscribed again can re-SUBSCRIBE.
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
                $span->set('mqtt.unsubscribed', $this->handler->unsubscribe($this->server, $projectId, $userId, $topic));
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
            $dropped = $this->handler->drop($this->server, $projectId, $userId, $topic);
            Console::error("MQTT coroutine limit reached for unsubscribe of user {$userId} in project {$projectId}; dropped the subscription from {$dropped} connections unverified");

            return;
        }

        if ($unsubscribed->pop($this->timeout) === false) {
            $dropped = $this->handler->drop($this->server, $projectId, $userId, $topic);
            Console::error("MQTT unsubscribe membership check timed out after {$this->timeout}s for user {$userId} in project {$projectId}; dropped the subscription from {$dropped} connections unverified");
        }
    }

    /**
     * Fan a produced message out to this worker's local subscribers of the topic.
     *
     * @param array<string, mixed> $event
     */
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

        $delivered = $this->handler->deliver($this->server, $projectId, $topic, $message, $qos, $sequence, $publishedAt);

        $span->set('mqtt.subscribers', $delivered);
        if ($delivered === 0) {
            $this->mqtt->messagesDropped->add(1, ['reason' => 'no_subscriber']);
        }

        $span->finish();
    }
}
