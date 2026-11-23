<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

final class StreamInfo
{
    public function __construct(
        public readonly StreamConfig $config,
        public readonly StreamState $state,
        public readonly string $created,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            config: StreamConfig::fromArray($data['config'] ?? []),
            state: StreamState::fromArray($data['state'] ?? []),
            created: $data['created'] ?? '',
        );
    }
}
