<?php

declare(strict_types=1);

namespace Utopia\Queue\Tests\E2E;

use Swoole\Coroutine;
use Utopia\Queue\Connection\Redis;

/** A connection that yields before each script, so concurrent settlements share one flush. */
final class SlowConnection extends Redis
{
    /** @var list<int> */
    public array $keys = [];

    #[\Override]
    public function execute(string $script, array $keys, array $args): mixed
    {
        if (Coroutine::getCid() >= 0) {
            $this->keys[] = \count($keys);
            Coroutine::sleep(0.01);
        }

        return parent::execute($script, $keys, $args);
    }
}
