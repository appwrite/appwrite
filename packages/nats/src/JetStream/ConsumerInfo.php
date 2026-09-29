<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

final class ConsumerInfo
{
    /**
     * @param array<string, string>|null $metadata Consumer metadata (ADR-33)
     */
    public function __construct(
        public readonly string $streamName,
        public readonly string $name,
        public readonly ConsumerConfig $config,
        public readonly string $created,
        public readonly int $numAckPending,
        public readonly int $numRedelivered,
        public readonly int $numWaiting,
        public readonly int $numPending,
        public readonly SequenceInfo $delivered,
        public readonly SequenceInfo $ackFloor,
        public readonly bool $pushBound = false,
        public readonly ?string $cluster = null,
        public readonly ?array $metadata = null,
    ) {}

    public static function fromArray(array $data): self
    {
        $config = ConsumerConfig::fromArray($data['config'] ?? []);

        return new self(
            streamName: $data['stream_name'] ?? '',
            name: $data['name'] ?? '',
            config: $config,
            created: $data['created'] ?? '',
            numAckPending: $data['num_ack_pending'] ?? 0,
            numRedelivered: $data['num_redelivered'] ?? 0,
            numWaiting: $data['num_waiting'] ?? 0,
            numPending: $data['num_pending'] ?? 0,
            delivered: SequenceInfo::fromArray($data['delivered'] ?? []),
            ackFloor: SequenceInfo::fromArray($data['ack_floor'] ?? []),
            pushBound: $data['push_bound'] ?? false,
            cluster: isset($data['cluster']['name']) ? (string) $data['cluster']['name'] : null,
            metadata: $config->metadata,
        );
    }
}
