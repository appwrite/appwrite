<?php

declare(strict_types=1);

namespace Utopia\NATS\KeyValue;

use Utopia\NATS\JetStream\StreamInfo;

final class KeyValueStatus
{
    public function __construct(
        public readonly string $bucket,
        public readonly int $values,
        public readonly int $bytes,
        public readonly int $history,
        public readonly ?float $ttl,
        public readonly StreamInfo $streamInfo,
    ) {}
}
