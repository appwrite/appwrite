<?php

declare(strict_types=1);

namespace Utopia\Cache\Adapter\Redis;

final class ConnectionError
{
    public function __construct(
        public ConnectionException $exception,
    ) {}
}
