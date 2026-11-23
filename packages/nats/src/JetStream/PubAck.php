<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

final class PubAck
{
    public function __construct(
        public readonly string $stream,
        public readonly int $sequence,
        public readonly ?string $domain = null,
        public readonly bool $duplicate = false,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            stream: $data['stream'] ?? '',
            sequence: $data['seq'] ?? 0,
            domain: $data['domain'] ?? null,
            duplicate: $data['duplicate'] ?? false,
        );
    }
}
