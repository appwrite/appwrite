<?php

namespace Appwrite\Tests;

use Exception;
use Storage\Storage;
use Storage\Devices\Local;
use PHPUnit\Framework\TestCase;

Storage::addDevice('disk-a', new Local(__DIR__ . '../../resources/disk-a'));
Storage::addDevice('disk-b', new Local(__DIR__ . '../../resources/disk-b'));

class StorageTest extends TestCase
{
    public function setUp()
    {
    }

    public function tearDown()
    {
    }

    public function testGetters()
    {
        $this->assertEquals(get_class(Storage::getDevice('disk-a')), 'Storage\Devices\Local');
        $this->assertEquals(get_class(Storage::getDevice('disk-b')), 'Storage\Devices\Local');

        try {
            get_class(Storage::getDevice('disk-c'));
            $this->fail("Expected exception not thrown");
        } catch(Exception $e) {
            $this->assertEquals('The device "disk-c" is not listed', $e->getMessage());
        }
    }

    public function testExists()
    {
        $this->assertEquals(Storage::exists('disk-a'), true);
        $this->assertEquals(Storage::exists('disk-b'), true);
        $this->assertEquals(Storage::exists('disk-c'), false);
    }
}