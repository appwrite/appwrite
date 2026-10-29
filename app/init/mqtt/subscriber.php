<?php

use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Mqtt\Handler;
use Swoole\Coroutine\Channel;
use Utopia\Console\Console;
use Utopia\Mqtt\Server;
use Utopia\Span\Span;

/**
 * The per-action dispatcher for the Redis 'mqtt' control/delivery channel. Span init/finish and
 * the Swoole coroutine orchestration live here (the entrypoint layer), not in Handler, which only
 * Span::add()s. Each handler takes the resolved projectId and the decoded event.
 *
 * Revoke and unsubscribe verify against the project database. Each runs in a child coroutine so
 * the connections it opens leave with its context instead of piling up in this subscriber, and the
 * subscriber waits on it at most $permissionTimeout seconds: a verification stuck on a dead
 * database read must not park the subscriber, which would stop every delivery and revoke on this
 * worker. On timeout, or when no child coroutine can start, the broker fails closed — the access
 * is withdrawn unverified — and a late child finds its connections already handled (Handler skips
 * untracked connections).
 */
return function (Server $server, Handler $handler, Mqtt $mqtt, float $permissionTimeout): Closure {
    // Revoke a user's connections after they were blocked or deleted. On timeout every connection
    // is closed unverified: clients reconnect and CONNECT re-authenticates them with fresh
    // credentials, so an unverifiable user is excluded rather than left connected.
    $onRevoke = function (string $projectId, array $event) use ($handler, $permissionTimeout): void {
        $userId = (string) ($event['userId'] ?? '');

        $revoke = function () use ($handler, $projectId, $userId): void {
            $span = null;

            try {
                $span = Span::init('mqtt.revoke');
                $span->set('project.id', $projectId);
                $span->set('user.id', $userId);
                $span->set('mqtt.is_broker', true);
                $span->set('mqtt.disconnected', $handler->revoke($projectId, $userId));
            } catch (\Throwable $error) {
                $span?->setError($error);
                Console::error('Failed to revoke MQTT connections: ' . $error->getMessage());
            } finally {
                $span?->finish();
            }
        };

        $revoked = new Channel(1);
        $started = go(function () use ($revoke, $revoked): void {
            try {
                $revoke();
            } finally {
                $revoked->push(true);
            }
        });

        // An inline check could block on the same dead read, so a full coroutine table fails closed too.
        if ($started === false) {
            $disconnected = $handler->expel($projectId, $userId);
            Console::error("MQTT coroutine limit reached for revoke of user {$userId} in project {$projectId}; closed {$disconnected} connections unverified");

            return;
        }

        if ($revoked->pop($permissionTimeout) === false) {
            $disconnected = $handler->expel($projectId, $userId);
            Console::error("MQTT revoke verification timed out after {$permissionTimeout}s for user {$userId} in project {$projectId}; closed {$disconnected} connections unverified");
        }
    };

    // Drop a user's live subscription to a topic after they were removed as a subscriber. On
    // timeout the subscription is dropped unverified: a user subscribed again can re-SUBSCRIBE.
    $onUnsubscribe = function (string $projectId, array $event) use ($server, $handler, $permissionTimeout): void {
        $userId = (string) ($event['userId'] ?? '');
        $topic = (string) ($event['topic'] ?? '');

        $unsubscribe = function () use ($server, $handler, $projectId, $userId, $topic): void {
            $span = null;

            try {
                $span = Span::init('mqtt.unsubscribe');
                $span->set('project.id', $projectId);
                $span->set('user.id', $userId);
                $span->set('mqtt.topic', $topic);
                $span->set('mqtt.is_broker', true);
                $span->set('mqtt.unsubscribed', $handler->unsubscribe($server, $projectId, $userId, $topic));
            } catch (\Throwable $error) {
                $span?->setError($error);
                Console::error('Failed to unsubscribe MQTT connections: ' . $error->getMessage());
            } finally {
                $span?->finish();
            }
        };

        $unsubscribed = new Channel(1);
        $started = go(function () use ($unsubscribe, $unsubscribed): void {
            try {
                $unsubscribe();
            } finally {
                $unsubscribed->push(true);
            }
        });

        if ($started === false) {
            $dropped = $handler->drop($server, $projectId, $userId, $topic);
            Console::error("MQTT coroutine limit reached for unsubscribe of user {$userId} in project {$projectId}; dropped the subscription from {$dropped} connections unverified");

            return;
        }

        if ($unsubscribed->pop($permissionTimeout) === false) {
            $dropped = $handler->drop($server, $projectId, $userId, $topic);
            Console::error("MQTT unsubscribe membership check timed out after {$permissionTimeout}s for user {$userId} in project {$projectId}; dropped the subscription from {$dropped} connections unverified");
        }
    };

    // Fan a produced message out to this worker's local subscribers of the topic.
    $onDeliver = function (string $projectId, array $event) use ($server, $handler, $mqtt): void {
        $topic = (string) ($event['topic'] ?? '');
        $qos = (int) ($event['qos'] ?? 0);
        $sequence = (int) ($event['sequence'] ?? 0);
        $publishedAt = (float) ($event['publishedAt'] ?? 0.0);
        $message = base64_decode((string) ($event['payload'] ?? ''));

        $span = Span::init('mqtt.deliver');
        $span->set('project.id', $projectId);
        $span->set('mqtt.topic', $topic);
        $span->set('mqtt.qos', $qos);
        $span->set('mqtt.is_broker', true);

        $delivered = $handler->deliver($server, $projectId, $topic, $message, $qos, $sequence, $publishedAt);

        $span->set('mqtt.subscribers', $delivered);
        if ($delivered === 0) {
            $mqtt->messagesDropped->add(1, ['reason' => 'no_subscriber']);
        }

        $span->finish();
    };

    return function (mixed $redis, string $channel, string $payload) use ($onRevoke, $onUnsubscribe, $onDeliver): void {
        $event = json_decode($payload, true);
        if (!\is_array($event)) {
            return;
        }

        $projectId = (string) ($event['project'] ?? '');

        match ($event['action'] ?? '') {
            Mqtt::ACTION_REVOKE => $onRevoke($projectId, $event),
            Mqtt::ACTION_UNSUBSCRIBE => $onUnsubscribe($projectId, $event),
            default => $onDeliver($projectId, $event),
        };
    };
};
