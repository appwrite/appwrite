<?php

namespace Appwrite\Execution\Writer;

use Swoole\Coroutine\Channel;
use Throwable;

final class Entry
{
    public readonly Channel $channel;

    public ?Throwable $error = null;

    /** @param list<array<string, mixed>> $rows */
    public function __construct(public readonly array $rows)
    {
        $this->channel = new Channel(1);
    }
}
