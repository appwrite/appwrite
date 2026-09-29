<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

final class StreamState
{
    public function __construct(
        public readonly int $messages,
        public readonly int $bytes,
        public readonly int $firstSeq,
        public readonly ?string $firstTs,
        public readonly int $lastSeq,
        public readonly ?string $lastTs,
        public readonly int $consumerCount,
        public readonly int $numDeleted = 0,
        public readonly int $numSubjects = 0,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            messages: $data['messages'] ?? 0,
            bytes: $data['bytes'] ?? 0,
            firstSeq: $data['first_seq'] ?? 0,
            firstTs: $data['first_ts'] ?? null,
            lastSeq: $data['last_seq'] ?? 0,
            lastTs: $data['last_ts'] ?? null,
            consumerCount: $data['consumer_count'] ?? 0,
            numDeleted: $data['num_deleted'] ?? 0,
            numSubjects: $data['num_subjects'] ?? 0,
        );
    }
}
