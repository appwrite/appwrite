<?php

namespace Tests\Query\Schema;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Schema\Order;

final class OrderTest extends TestCase
{
    public function testValues(): void
    {
        $this->assertSame('ASC', Order::Asc->value);
        $this->assertSame('DESC', Order::Desc->value);
    }
}
