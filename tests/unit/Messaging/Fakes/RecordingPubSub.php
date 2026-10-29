<?php

declare(strict_types=1);

namespace Tests\Unit\Messaging\Fakes;

use Appwrite\PubSub\Adapter;

/** A pub/sub that records what is published instead of sending it. */
final class RecordingPubSub implements Adapter
{
    /** @var list<array{channel: string, message: array<string, mixed>}> */
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
        $this->published[] = ['channel' => $channel, 'message' => \json_decode($message, true)];

        return 1;
    }
}
