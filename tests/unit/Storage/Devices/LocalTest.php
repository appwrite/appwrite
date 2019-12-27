<?php

namespace Appwrite\Tests;

use Exception;
use Storage\Devices\Local;
use PHPUnit\Framework\TestCase;

class LocalTest extends TestCase
{
    /**
     * @var Local
     */
    protected $object = null;

    public function setUp()
    {
        $this->object = new Local(__DIR__ . '/../../../resources/disk-a');
    }

    public function tearDown()
    {
    }

    public function testName()
    {
        $this->assertEquals($this->object->getName(), 'Local Storage');
    }

    public function testDescription()
    {
        $this->assertEquals($this->object->getDescription(), 'Adapter for Local storage that is in the physical or virtual machine or mounted to it.');
    }

    public function testRoot()
    {
        $this->assertEquals($this->object->getRoot(), '/storage/uploads//usr/share/nginx/html/tests/unit/Storage/Devices/../../../resources/disk-a');
    }

    public function testPath()
    {
        $this->assertEquals($this->object->getPath('image.png'), '/storage/uploads//usr/share/nginx/html/tests/unit/Storage/Devices/../../../resources/disk-a/i/m/a/g/image.png');
        $this->assertEquals($this->object->getPath('x.png'), '/storage/uploads//usr/share/nginx/html/tests/unit/Storage/Devices/../../../resources/disk-a/x/./p/n/x.png');
        $this->assertEquals($this->object->getPath('y'), '/storage/uploads//usr/share/nginx/html/tests/unit/Storage/Devices/../../../resources/disk-a/y/x/x/x/y');
    }
}