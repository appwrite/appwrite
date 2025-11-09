<?php

use PHPUnit\Framework\TestCase;

class RuntimesTest extends TestCase
{
    public function testRuntimesConfig()
    {
        $runtimes = require __DIR__ . '/../config/runtimes.php';

        $this->assertArrayHasKey('NODE', $runtimes);
        $this->assertEquals('node', $runtimes['NODE']['name']);
        $this->assertContains('22', $runtimes['NODE']['versions']);
        $this->assertArrayHasKey('PYTHON', $runtimes);
        $this->assertEquals('python', $runtimes['PYTHON']['name']);
    }
}