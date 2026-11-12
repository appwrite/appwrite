<?php

declare(strict_types=1);

namespace Tests\Unit\Appwrite\Messaging\Adapter\Fakes;

use Appwrite\PubSub\Adapter;

final class RecordingPubSub implements Adapter
{
    /** @var list<array{channel: string, message: string}> */
    public array $published = [];

    public function ping($message = null): bool
    {
        return true;
    }

    public function subscribe($channels, $callback)
    {
    }

    public function publish($channel, $message)
    {
        $this->published[] = ['channel' => (string) $channel, 'message' => (string) $message];

        return 1;
    }
}
