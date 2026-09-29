<?php

declare(strict_types=1);

namespace Utopia\NATS\ObjectStore;

use Utopia\NATS\JetStream\DiscardPolicy;
use Utopia\NATS\JetStream\RetentionPolicy;
use Utopia\NATS\JetStream\StorageType;
use Utopia\NATS\JetStream\StreamConfig;

final class ObjectStoreConfig
{
    /**
     * @param float|null $ttl TTL in seconds
     */
    public function __construct(
        public readonly string $bucket,
        public readonly ?string $description = null,
        public readonly int $maxBytes = -1,
        public readonly ?float $ttl = null,
        public readonly StorageType $storage = StorageType::File,
        public readonly int $replicas = 1,
    ) {}

    public function toStreamConfig(): StreamConfig
    {
        return new StreamConfig(
            name: "OBJ_{$this->bucket}",
            subjects: [
                "\$O.{$this->bucket}.C.>",
                "\$O.{$this->bucket}.M.>",
            ],
            description: $this->description,
            retention: RetentionPolicy::Limits,
            maxBytes: $this->maxBytes,
            maxAge: $this->ttl,
            storage: $this->storage,
            replicas: $this->replicas,
            discard: DiscardPolicy::New,
            allowDirect: true,
            allowRollup: true,
        );
    }
}
