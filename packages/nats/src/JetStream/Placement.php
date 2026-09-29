<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

final class Placement
{
    /**
     * @param list<string>|null $tags
     */
    public function __construct(
        public readonly ?string $cluster = null,
        public readonly ?array $tags = null,
    ) {}

    public function toArray(): array
    {
        $data = [];
        if ($this->cluster !== null) {
            $data['cluster'] = $this->cluster;
        }
        if ($this->tags !== null) {
            $data['tags'] = $this->tags;
        }

        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            cluster: $data['cluster'] ?? null,
            tags: $data['tags'] ?? null,
        );
    }
}
