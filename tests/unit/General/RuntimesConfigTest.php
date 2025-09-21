<?php

use PHPUnit\Framework\TestCase;

class RuntimesConfigTest extends TestCase
{
    public function testRuntimesConfigArray()
    {
        $runtimes = require __DIR__ . '/../../../app/config/runtimes.php';

        $this->assertIsArray($runtimes);
        $this->assertArrayHasKey('NODE', $runtimes);
        $this->assertEquals('node', $runtimes['NODE']['name']);
        $this->assertContains('22', $runtimes['NODE']['versions']);
        $this->assertArrayHasKey('PYTHON', $runtimes);
        $this->assertEquals('python', $runtimes['PYTHON']['name']);
    }
}
