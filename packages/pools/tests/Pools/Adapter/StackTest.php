<?php

declare(strict_types=1);

namespace Utopia\Tests\Adapter;

use Utopia\Pools\Adapter\Stack;
use Utopia\Tests\Base;

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
