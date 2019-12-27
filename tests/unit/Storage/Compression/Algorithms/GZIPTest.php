<?php

namespace Appwrite\Tests;

use Exception;
use Storage\Compression\Algorithms\GZIP;
use Storage\Devices\Local;
use PHPUnit\Framework\TestCase;

class GZIPTest extends TestCase
{
    /**
     * @var GZIP
     */
    protected $object = null;

    public function setUp()
    {
        $this->object = new GZIP();
    }

    public function tearDown()
    {
    }

    public function testName()
    {
        $this->assertEquals($this->object->getName(), 'gzip');
    }
    
    public function testCompressDecompressWithText()
    {
        $demo = 'This is a demo string';
        $demoSize = mb_strlen($demo, '8bit');

        $data = $this->object->compress($demo);
        $dataSize = mb_strlen($data, '8bit');

        $this->assertEquals($demoSize, 21);
        $this->assertEquals($dataSize, 39);
        
        $this->assertEquals($this->object->decompress($data), $demo);
    }
    
    public function testCompressDecompressWithJPGImage()
    {
        $demo = \file_get_contents(__DIR__ . '/../../../../resources/disk-a/kitten-1.jpg');
        $demoSize = mb_strlen($demo, '8bit');

        $data = $this->object->compress($demo);
        $dataSize = mb_strlen($data, '8bit');

        $this->assertEquals($demoSize, 599639);
        $this->assertEquals($dataSize, 599107);
        
        $this->assertGreaterThan($dataSize, $demoSize);
        
        $data = $this->object->decompress($data);
        $dataSize = mb_strlen($data, '8bit');
        
        $this->assertEquals($dataSize, 599639);
    }
    
    public function testCompressDecompressWithPNGImage()
    {
        $demo = \file_get_contents(__DIR__ . '/../../../../resources/disk-b/kitten-1.png');
        $demoSize = mb_strlen($demo, '8bit');

        $data = $this->object->compress($demo);
        $dataSize = mb_strlen($data, '8bit');

        $this->assertEquals($demoSize, 3038056);
        $this->assertEquals($dataSize, 3029202);
        
        $this->assertGreaterThan($dataSize, $demoSize);
        
        $data = $this->object->decompress($data);
        $dataSize = mb_strlen($data, '8bit');
        
        $this->assertEquals($dataSize, 3038056);
    }
}