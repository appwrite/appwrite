<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

final class SequenceInfo
{
    public function __construct(
        public readonly int $consumerSeq,
        public readonly int $streamSeq,
        public readonly ?string $lastActive = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            consumerSeq: $data['consumer_seq'] ?? 0,
            streamSeq: $data['stream_seq'] ?? 0,
            lastActive: $data['last_active'] ?? null,
        );
    }
}
