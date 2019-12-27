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
        $this->object = new Local(realpath(__DIR__ . '/../../../resources/disk-a'));
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
        $this->assertEquals($this->object->getRoot(), '/usr/share/nginx/html/tests/resources/disk-a');
    }

    public function testPath()
    {
        $this->assertEquals($this->object->getPath('image.png'), '/usr/share/nginx/html/tests/resources/disk-a/i/m/a/g/image.png');
        $this->assertEquals($this->object->getPath('x.png'), '/usr/share/nginx/html/tests/resources/disk-a/x/./p/n/x.png');
        $this->assertEquals($this->object->getPath('y'), '/usr/share/nginx/html/tests/resources/disk-a/y/x/x/x/y');
    }
    
    public function testFileSize()
    {
        $this->assertEquals($this->object->getFileSize(__DIR__ . '/../../../resources/disk-a/kitten-1.jpg'), 599639);
        $this->assertEquals($this->object->getFileSize(__DIR__ . '/../../../resources/disk-a/kitten-2.jpg'), 131958);
    }
    
    public function testFileMimeType()
    {
        $this->assertEquals($this->object->getFileMimeType(__DIR__ . '/../../../resources/disk-a/kitten-1.jpg'), 'image/jpeg');
        $this->assertEquals($this->object->getFileMimeType(__DIR__ . '/../../../resources/disk-a/kitten-2.jpg'), 'image/jpeg');
        $this->assertEquals($this->object->getFileMimeType(__DIR__ . '/../../../resources/disk-b/kitten-1.png'), 'image/png');
        $this->assertEquals($this->object->getFileMimeType(__DIR__ . '/../../../resources/disk-b/kitten-2.png'), 'image/png');
    }
    
    public function testFileHash()
    {
        $this->assertEquals($this->object->getFileHash(__DIR__ . '/../../../resources/disk-a/kitten-1.jpg'), '7551f343143d2e24ab4aaf4624996b6a');
        $this->assertEquals($this->object->getFileHash(__DIR__ . '/../../../resources/disk-a/kitten-2.jpg'), '81702fdeef2e55b1a22617bce4951cb5');
        $this->assertEquals($this->object->getFileHash(__DIR__ . '/../../../resources/disk-b/kitten-1.png'), '03010f4f02980521a8fd6213b52ec313');
        $this->assertEquals($this->object->getFileHash(__DIR__ . '/../../../resources/disk-b/kitten-2.png'), '8a9ed992b77e4b62b10e3a5c8ed72062');
    }
    
    public function testDirectorySize()
    {
        $this->assertEquals($this->object->getDirectorySize(__DIR__ . '/../../../resources/disk-a/'), 731597);
        $this->assertEquals($this->object->getDirectorySize(__DIR__ . '/../../../resources/disk-b/'), 3728550);
    }
    
    public function testPartitionFreeSpace()
    {
        $this->assertGreaterThan(0, $this->object->getPartitionFreeSpace());
    }
    
    public function testPartitionTotalSpace()
    {
        $this->assertGreaterThan(0, $this->object->getPartitionTotalSpace());
    }
}