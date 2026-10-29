<?php

declare(strict_types=1);

namespace Tests\Unit\Messaging\Fakes;

use Appwrite\PubSub\Adapter;

/** A pub/sub whose server is unreachable. */
final class FailingPubSub implements Adapter
{
    public function ping($message = null): bool
    {
        return false;
    }

    public function subscribe($channels, $callback)
    {
    }

    public function publish($channel, $message)
    {
        throw new \RedisException('Connection refused');
    }
}
