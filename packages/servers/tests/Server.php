<?php

declare(strict_types=1);

namespace Utopia\Servers\Tests;

use Utopia\DI\Container;
use Utopia\Servers\Base;
use Utopia\Servers\Hook;

final class Server extends Base
{
    public function start(): void
    {
    }

    /**
     * @param array<string, mixed> $requestParams
     */
    public function run(Hook $hook, array $requestParams): Container
    {
        return $this->prepare(new Container(), $hook, [], $requestParams);
    }
}
