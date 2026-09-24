<?php

namespace Tests\Query\Hook\Attribute;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Hook\Attribute\Map;

class AttributeTest extends TestCase
{
    public function testMappedAttribute(): void
    {
        $hook = new Map([
            '$id' => '_uid',
            '$createdAt' => '_createdAt',
        ]);

        $this->assertSame('_uid', $hook->resolve('$id'));
        $this->assertSame('_createdAt', $hook->resolve('$createdAt'));
    }

    public function testUnmappedPassthrough(): void
    {
        $hook = new Map(['$id' => '_uid']);

        $this->assertSame('name', $hook->resolve('name'));
        $this->assertSame('status', $hook->resolve('status'));
    }

    public function testEmptyMap(): void
    {
        $hook = new Map([]);

        $this->assertSame('anything', $hook->resolve('anything'));
    }
}
