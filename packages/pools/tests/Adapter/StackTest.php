<?php

declare(strict_types=1);

namespace Utopia\Pools\Tests\Adapter;

use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Tests\Base;

final class StackTest extends Base
{
    protected function getAdapter(): Stack
    {
        return new Stack();
    }

    protected function execute(callable $callback): mixed
    {
        return $callback();
    }
}
