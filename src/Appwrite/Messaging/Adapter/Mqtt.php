<?php

namespace Appwrite\Messaging\Adapter;

use Appwrite\Messaging\Adapter as MessagingAdapter;
use Appwrite\PubSub\Adapter as PubSub;
use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Keepalive;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Subscription\Store;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Histogram;
use Utopia\Telemetry\UpDownCounter;

class Mqtt extends MessagingAdapter
{
    private const CHANNEL = 'mqtt';

    /**
     * Connection registry.
     *
     * [FD] -> Connection { protocol, prefix, identity, packetId, active }
     *
     * @var array<int, Connection>
     */
    public array $connections = [];

    // Telemetry instruments the broker records connection and delivery outcomes on
    // (accepted/rejected, granted/denied, delivered/dropped). A no-op telemetry adapter
    // yields no-op instruments, so the broker runs untelemetered under raw protocol tests.
    public readonly Counter $connectionsOpened;
    public readonly UpDownCounter $connectionsActive;
    public readonly Counter $subscriptions;
    public readonly Counter $messagesPublished;
    public readonly Counter $messagesDelivered;
    public readonly Counter $messagesDropped;
    public readonly Counter $messagesAcked;
    public readonly Counter $pubacksReceived;
    public readonly Counter $reauth;
    public readonly Counter $bytesReceived;
    public readonly Counter $bytesSent;
    public readonly Histogram $authDuration;
    public readonly Histogram $connectionDuration;
    public readonly Histogram $messageSize;

    /** Keep-alive reaper wheel: connections register their deadline here, the tick drains it. */
    public readonly Keepalive $keepAlive;

    private ?Store $subscriptionStore = null;

    public function __construct(Telemetry $telemetry, private readonly PubSub $pubsub)
    {
        $this->connectionsOpened = $telemetry->createCounter('mqtt.connections.opened');
        $this->connectionsActive = $telemetry->createUpDownCounter('mqtt.connections.active');
        $this->subscriptions = $telemetry->createCounter('mqtt.subscriptions');
        $this->messagesPublished = $telemetry->createCounter('mqtt.messages.published');
        $this->messagesDelivered = $telemetry->createCounter('mqtt.messages.delivered');
        $this->messagesDropped = $telemetry->createCounter('mqtt.messages.dropped');
        $this->messagesAcked = $telemetry->createCounter('mqtt.messages.acked');
        $this->pubacksReceived = $telemetry->createCounter('mqtt.puback.received');
        $this->reauth = $telemetry->createCounter('mqtt.reauth');
        $this->bytesReceived = $telemetry->createCounter('mqtt.bytes.received', 'By');
        $this->bytesSent = $telemetry->createCounter('mqtt.bytes.sent', 'By');
        $this->authDuration = $telemetry->createHistogram('mqtt.auth.duration', 's');
        $this->connectionDuration = $telemetry->createHistogram('mqtt.connection.duration', 's');
        $this->messageSize = $telemetry->createHistogram('mqtt.message.size', 'By');
        $this->keepAlive = new Keepalive();
    }

    /**
     * The subscription index, created lazily so the adapter owns it rather than
     * receiving it — the in-memory tree has no external dependency to wire in.
     */
    private function subscriptionStore(): Store
    {
        return $this->subscriptionStore ??= new Store();
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
        if ($connection !== null) {
            if ($connection->active) {
                $this->connectionsActive->add(-1);
                $this->connectionDuration->record(microtime(true) - $connection->openedAt);
            }
            $this->keepAlive->remove($fd);
        }
        $this->unsubscribe($fd);
        unset($this->connections[$fd]);
    }

    /**
     * Subscribe a connection to topic filters. $identifier is the fd, $channels the
     * topic filters; roles, queries and $subscriptionId have no MQTT meaning and are
     * ignored (the store keys by topic). The granted QoS is 1 and the user id comes
     * from the resolved identity so the subscription store can scale per client.
     *
     * @param string $subscriptionId ignored (part of the Messaging adapter contract)
     * @param array<int, string> $roles ignored
     * @param array<int, string> $channels topic filters
     * @param array<int, mixed> $queryGroup ignored
     * @param int $qos granted QoS stored per subscription (delivery is clamped to it)
     */
    public function subscribe(string $projectId, mixed $identifier, string $subscriptionId, array $roles, array $channels, array $queryGroup = [], int $qos = Packet::QOS_1): void
    {
        $userId = $this->connections[$identifier]->identity['userId'] ?? '';

        foreach ($channels as $topic) {
            $this->subscriptionStore()->subscribe(
                $projectId,
                $userId,
                $topic,
                $identifier,
                $qos,
            );
        }
    }

    /** Remove every subscription for a connection (used on close). */
    public function unsubscribe(mixed $identifier): void
    {
        $this->subscriptionStore()->close($identifier);
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
     * @param array{payload?: string, qos?: int, sequence?: int} $options
     */
    public function send(string $projectId, array $payload, array $events, array $channels, array $roles, array $options = []): void
    {
        $message = $options['payload'] ?? '';
        $qos = $options['qos'] ?? 0;
        $sequence = (int) ($options['sequence'] ?? 0);

        $this->messageSize->record(\strlen($message));

        foreach ($channels as $topic) {
            $this->messagesPublished->add(1, ['qos' => $qos]);
            $this->pubsub->publish(self::CHANNEL, (string) json_encode([
                'project' => $projectId,
                'topic' => $topic,
                'qos' => $qos,
                'sequence' => $sequence,
                'payload' => base64_encode($message),
            ]));
        }
    }

    /** Remove a single topic subscription (MQTT UNSUBSCRIBE). */
    public function unsubscribeSubscription(int $fd, string $topic): void
    {
        $this->subscriptionStore()->unsubscribe($topic, $fd);
    }

    /**
     * The fds subscribed to a topic in a project, each mapped to its granted QoS.
     *
     * @return array<int, int> fd => granted QoS
     */
    public function getSubscribers(string $projectId, string $topic): array
    {
        return $this->subscriptionStore()->getSubscribers($projectId, $topic);
    }

    public function hasSubscriber(string $projectId, string $topic): bool
    {
        return $this->subscriptionStore()->getSubscribers($projectId, $topic) !== [];
    }

    /**
     * The subscription-store record for an fd: its project, user, and subscriptions,
     * or null when the fd holds none. This is the store's copy (the prefix/userId
     * captured at subscribe time), not the live Connection object from open().
     *
     * @return array{prefix: string, userId: string, subs: array<string, int>}|null
     */
    public function getConnection(int $fd): ?array
    {
        return $this->subscriptionStore()->getConnection($fd);
    }
}
