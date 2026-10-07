<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Helpers\ID;

class IDTest extends TestCase
{
    public function testCustomID(): void
    {
        $id = ID::custom('test');
        $this->assertEquals('test', $id);
    }

    public function testUniqueID(): void
    {
        $id = ID::unique();
        $this->assertNotEmpty($id);
        $this->assertIsString($id);
    }
}
