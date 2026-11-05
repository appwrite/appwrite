<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt\Fakes;

use Appwrite\PubSub\Adapter;

/** A pub/sub that accepts and drops everything; Handler::deliver() never reaches it. */
final class NullPubSub implements Adapter
{
    public function ping($message = null): bool
    {
        return true;
    }

    public function subscribe($channels, $callback)
    {
    }

    public function publish($channel, $message)
    {
        return 0;
    }
}
