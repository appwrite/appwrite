<?php

declare(strict_types=1);

namespace Utopia\Client\Adapter\SwooleCoroutine;

use Swoole\Coroutine\Http\Client as SwooleClient;

/**
 * A cached connection, the origin it is bound to, and the monotonic nanoseconds
 * after which it is redialled instead of reused (0 until a request on it completes).
 */
final class Slot
{
    public ?SwooleClient $client = null;

    public string $key = '';

    public int $deadline = 0;
}
