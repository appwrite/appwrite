<?php

namespace Appwrite\Tests;

use Exception;
use Storage\Storage;
use Storage\Devices\Local;
use PHPUnit\Framework\TestCase;

class LocalTest extends TestCase
{
    public function setUp()
    {
    }

    public function tearDown()
    {
    }

    public function testExists()
    {
        $this->assertEquals(Storage::exists('disk-a'), true);
        $this->assertEquals(Storage::exists('disk-b'), true);
        $this->assertEquals(Storage::exists('disk-c'), false);
    }
}