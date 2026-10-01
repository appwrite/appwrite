<?php

namespace Appwrite\Messaging\Adapter;

use Appwrite\Messaging\Adapter as MessagingAdapter;
use Appwrite\PubSub\Adapter as PubSub;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Histogram;

/**
 * The MQTT producer: publishes messages onto the 'mqtt' pub/sub channel for the broker workers
 * to fan out, and owns the broker telemetry instruments. Connection state, keep-alive and the
 * subscription index now live inside the broker (utopia-php/mqtt Server), so they are gone here.
 */
class Mqtt extends MessagingAdapter
{
    private const CHANNEL = 'mqtt';

    public readonly Counter $messagesPublished;
    public readonly Counter $messagesDelivered;
    public readonly Counter $messagesDropped;
    public readonly Counter $messagesAcked;
    public readonly Counter $pubacksReceived;
    public readonly Counter $reauth;
    public readonly Counter $connectRefused;
    public readonly Histogram $authDuration;
    public readonly Histogram $connectionDuration;
    public readonly Histogram $messageSize;
    public readonly Histogram $deliveryLatency;
    public readonly Histogram $replayBacklog;
    public readonly Histogram $ledgerDuration;

    /** @var array<string, array{connections: int, delivered: int}> per-project usage, drained by flushUsage() */
    private array $usage = [];

    public function __construct(Telemetry $telemetry, private readonly PubSub $pubsub)
    {
        $this->messagesPublished = $telemetry->createCounter('mqtt.messages.published');
        $this->messagesDelivered = $telemetry->createCounter('mqtt.messages.delivered');
        $this->messagesDropped = $telemetry->createCounter('mqtt.messages.dropped');
        $this->messagesAcked = $telemetry->createCounter('mqtt.messages.acked');
        $this->pubacksReceived = $telemetry->createCounter('mqtt.puback.received');
        $this->reauth = $telemetry->createCounter('mqtt.reauth');
        // The broker counts accepted/rejected CONNECTs; this adds the refusal reason it cannot see.
        $this->connectRefused = $telemetry->createCounter('mqtt.connect.refused');
        $this->authDuration = $telemetry->createHistogram('mqtt.auth.duration', 's');
        $this->connectionDuration = $telemetry->createHistogram('mqtt.connection.duration', 's');
        $this->messageSize = $telemetry->createHistogram('mqtt.message.size', 'By');
        $this->deliveryLatency = $telemetry->createHistogram('mqtt.delivery.latency', 's');
        $this->replayBacklog = $telemetry->createHistogram('mqtt.replay.backlog', '{message}');
        $this->ledgerDuration = $telemetry->createHistogram('mqtt.ledger.duration', 's');
    }

    /**
     * Publish a message onto the 'mqtt' pub/sub channel for fan-out. Every broker worker listens
     * on that channel and delivers to its own local subscribers. The binary MQTT payload and its
     * QoS ride in $options ($options['payload'], $options['qos'], $options['sequence']); $channels
     * holds the topics. The payload is base64-encoded to survive the JSON envelope.
     *
     * @param array<mixed> $payload unused for MQTT (payload is binary, see $options)
     * @param array<int, string> $events ignored
     * @param array<int, string> $channels topics to publish to
     * @param array<int, string> $roles ignored
     * @param array{payload?: string, qos?: int, sequence?: int, publishedAt?: float} $options
     */
    public function send(string $projectId, array $payload, array $events, array $channels, array $roles, array $options = []): void
    {
        $message = $options['payload'] ?? '';
        $qos = $options['qos'] ?? 0;
        $sequence = (int) ($options['sequence'] ?? 0);
        $publishedAt = (float) ($options['publishedAt'] ?? \microtime(true));

        $this->messageSize->record(\strlen($message));

        foreach ($channels as $topic) {
            $this->messagesPublished->add(1, ['qos' => $qos]);
            $this->pubsub->publish(self::CHANNEL, (string) json_encode([
                'project' => $projectId,
                'topic' => $topic,
                'qos' => $qos,
                'sequence' => $sequence,
                'publishedAt' => $publishedAt,
                'payload' => base64_encode($message),
            ]));
        }
    }

    /**
     * Part of the messaging-adapter contract but unused for MQTT: subscriptions are owned by the
     * broker (utopia-php/mqtt Server records them from the SUBACK the Handler returns).
     *
     * @param array<int, string> $roles
     * @param array<int, string> $channels
     * @param array<int, mixed> $queryGroup
     */
    public function subscribe(string $projectId, mixed $identifier, string $subscriptionId, array $roles, array $channels, array $queryGroup = []): void
    {
    }

    public function unsubscribe(mixed $identifier): void
    {
    }

    /** Accumulate one accepted connection for a project, for per-project usage. */
    public function recordConnection(string $projectId): void
    {
        if ($projectId === '') {
            return;
        }
        $this->usage[$projectId]['connections'] = ($this->usage[$projectId]['connections'] ?? 0) + 1;
    }

    /** Accumulate delivered messages for a project, for per-project usage. */
    public function recordDeliveries(string $projectId, int $count): void
    {
        if ($projectId === '' || $count <= 0) {
            return;
        }
        $this->usage[$projectId]['delivered'] = ($this->usage[$projectId]['delivered'] ?? 0) + $count;
    }

    /**
     * Drain and reset the accumulated per-project usage so a flush can enqueue it. The broker runs a
     * single worker with cooperative coroutines, so the read-and-reset is atomic (no yield between).
     *
     * @return array<string, array{connections?: int, delivered?: int}>
     */
    public function flushUsage(): array
    {
        $usage = $this->usage;
        $this->usage = [];

        return $usage;
    }
}
