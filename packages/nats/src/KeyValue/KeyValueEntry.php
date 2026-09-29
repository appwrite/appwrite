<?php

declare(strict_types=1);

namespace Utopia\NATS\KeyValue;

final class KeyValueEntry
{
    public function __construct(
        public readonly string $bucket,
        public readonly string $key,
        public readonly string $value,
        public readonly int $revision,
        public readonly ?string $created = null,
        public readonly KeyValueOperation $operation = KeyValueOperation::Put,
    ) {}
}
