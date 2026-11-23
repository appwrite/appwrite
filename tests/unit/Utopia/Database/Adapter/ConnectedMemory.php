<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Adapter;

use Utopia\Database\Adapter\Feature\Connection;
use Utopia\Database\Adapter\Memory;

/**
 * A Memory adapter that reports the hostname it is given, the way a SQL connection reports the host it dialled.
 * Memory has no connection of its own, so Database::getHostname() would return null for every instance.
 */
class ConnectedMemory extends Memory implements Connection
{
    #[\Override]
    public function ping(): bool
    {
        return true;
    }

    #[\Override]
    public function reconnect(): void
    {
    }

    #[\Override]
    public function id(): string
    {
        return (string) \spl_object_id($this);
    }

    #[\Override]
    public function hostname(): string
    {
        return $this->hostname;
    }
}
