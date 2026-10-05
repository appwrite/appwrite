<?php

declare(strict_types=1);

namespace Utopia\Queue\Tests\E2E;

use Utopia\Queue\Connection\Redis;

/** A connection whose process dies after reserving a batch and before claiming it. */
final class CrashingConnection extends Redis
{
    #[\Override]
    public function execute(string $script, array $keys, array $args): mixed
    {
        if (str_starts_with($script, '-- KEYS: reservations, reservation,')) {
            throw new \RuntimeException('Process lost before finalization');
        }

        return parent::execute($script, $keys, $args);
    }
}
