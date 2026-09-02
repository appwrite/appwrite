<?php

namespace Appwrite\Messaging\Adapter;

use Appwrite\Messaging\Adapter as MessagingAdapter;
use Appwrite\PubSub\Adapter\Pool as PubSubPool;
use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Metrics;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\SubscriptionStore;
use Utopia\Telemetry\Adapter as Telemetry;

class Mqtt extends MessagingAdapter
{
    private const CHANNEL = 'mqtt';

    /**
     * Connection registry.
     *
     * [FD] -> Connection { protocol, projectId, identity, packetId, active }
     *
     * @var array<int, Connection>
     */
    public array $connections = [];

    /** Telemetry instruments the handlers record connection and delivery outcomes on. */
    public readonly Metrics $metrics;

    private ?SubscriptionStore $subscriptions = null;

    private ?PubSubPool $pubSubPool = null;

    public function __construct(Telemetry $telemetry)
    {
        $this->metrics = new Metrics($telemetry);
    }

    /**
     * The subscription index, created lazily so the adapter owns it rather than
     * receiving it — the in-memory tree has no external dependency to wire in.
     */
    private function subscriptions(): SubscriptionStore
    {
        return $this->subscriptions ??= new SubscriptionStore();
    }

    private function getPubSubPool(): PubSubPool
    {
        if ($this->pubSubPool === null) {
            global $register;
            $this->pubSubPool = new PubSubPool($register->get('pools')->get('pubsub'));
        }

        return $this->pubSubPool;
    }

    /** Get or create the connection state for a file descriptor. */
    public function open(int $fd): Connection
    {
        return $this->connections[$fd] ??= new Connection($fd);
    }

    /**
     * Drop a connection: balance the active gauge, remove every subscription, and
     * forget its state. The transport-level socket close is handled by the caller.
     */
    public function close(int $fd): void
    {
        $connection = $this->connections[$fd] ?? null;
        if ($connection !== null && $connection->active) {
            $this->metrics->connectionsActive->add(-1);
        }
        $this->unsubscribe($fd);
        unset($this->connections[$fd]);
    }

    /**
     * Subscribe a connection to topic filters. $identifier is the fd, $channels the
     * topic filters; roles and queries have no MQTT meaning and are ignored. The
     * granted QoS is 1 and the user id comes from the resolved identity so the
     * subscription store can scale per client.
     *
     * @param array<int, string> $roles ignored
     * @param array<int, string> $channels topic filters
     * @param array<int, mixed> $queryGroup ignored
     */
    public function subscribe(string $projectId, mixed $identifier, string $subscriptionId, array $roles, array $channels, array $queryGroup = []): void
    {
        $userId = $this->connections[$identifier]->identity['userId'] ?? '';

        foreach ($channels as $topic) {
            $this->subscriptions()->subscribe(
                $projectId,
                $userId,
                $subscriptionId ?: $topic,
                $topic,
                $identifier,
                Packet::QOS_1,
            );
        }
    }

    /** Remove every subscription for a connection (used on close). */
    public function unsubscribe(mixed $identifier): void
    {
        $this->subscriptions()->close($identifier);
    }

    /**
     * Publish a message onto the 'mqtt' pub/sub channel for fan-out. Every broker
     * worker listens on that channel and delivers to its own local subscribers, so a
     * publish reaches subscribers regardless of which worker holds them — the MQTT
     * analogue of Realtime::send publishing to the realtime firehose.
     *
     * MQTT carries raw bytes, so the message and its inbound QoS ride in $options
     * ($options['payload'], $options['qos']) rather than the array $payload;
     * $channels holds the topics. The payload is base64-encoded to survive the JSON
     * envelope.
     *
     * @param array<mixed> $payload unused for MQTT (payload is binary, see $options)
     * @param array<int, string> $events ignored
     * @param array<int, string> $channels topics to publish to
     * @param array<int, string> $roles ignored
     * @param array{payload?: string, qos?: int} $options
     */
    public function send(string $projectId, array $payload, array $events, array $channels, array $roles, array $options = []): void
    {
        $message = $options['payload'] ?? '';
        $qos = $options['qos'] ?? 0;

        foreach ($channels as $topic) {
            $this->metrics->messagesPublished->add(1, ['qos' => $qos]);
            $this->getPubSubPool()->publish(self::CHANNEL, (string) json_encode([
                'project' => $projectId,
                'topic' => $topic,
                'qos' => $qos,
                'payload' => base64_encode($message),
            ]));
        }
    }

    /** Remove a single subscription (MQTT UNSUBSCRIBE). */
    public function unsubscribeSubscription(int $fd, string $subscriptionId): void
    {
        $this->subscriptions()->unsubscribe($subscriptionId, $fd);
    }

    /**
     * The fds subscribed to a topic in a project, each mapped to its granted QoS.
     *
     * @return array<int, int> fd => granted QoS
     */
    public function getSubscribers(string $projectId, string $topic): array
    {
        return $this->subscriptions()->getSubscribers($projectId, $topic);
    }

    public function hasSubscriber(string $projectId, string $topic): bool
    {
        return $this->subscriptions()->getSubscribers($projectId, $topic) !== [];
    }
}
