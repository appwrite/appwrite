<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

final class AccountInfo
{
    /**
     * @param array<string, mixed> $limits
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly int $memory,
        public readonly int $storage,
        public readonly int $streams,
        public readonly int $consumers,
        public readonly array $limits,
        public readonly int $apiTotal,
        public readonly int $apiErrors,
        public readonly ?string $domain = null,
        public readonly array $raw = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            memory: $data['memory'] ?? 0,
            storage: $data['storage'] ?? 0,
            streams: $data['streams'] ?? 0,
            consumers: $data['consumers'] ?? 0,
            limits: $data['limits'] ?? [],
            apiTotal: $data['api']['total'] ?? 0,
            apiErrors: $data['api']['errors'] ?? 0,
            domain: $data['domain'] ?? null,
            raw: $data,
        );
    }
}
