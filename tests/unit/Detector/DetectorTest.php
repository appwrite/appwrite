<?php

namespace Appwrite\Tests;

use Appwrite\Detector\Detector;
use PHPUnit\Framework\TestCase;

class DetectorTest extends TestCase
{
    /**
     * @var Detector
     */
    protected $object = null;

    public function setUp(): void
    {
        $this->object = new Detector('Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:47.0) Gecko/20100101 Firefox/47.0');
    }

    public function tearDown(): void
    {
    }

    public function testGetOS()
    {
        $this->assertEquals([
            'osCode' => 'WIN',
            'osName' => 'Windows',
            'osVersion' => '7',
        ], $this->object->getOS());
    }

    public function testGetClient()
    {
        $this->assertEquals([
            'clientType' => 'browser',
            'clientCode' => 'FF',
            'clientName' => 'Firefox',
            'clientVersion' => '47.0',
            'clientEngine' => 'Gecko',
            'clientEngineVersion' => '47.0',
        ], $this->object->getClient());
    }

    public function testGetDevice()
    {
        $this->assertEquals([
            'deviceName' => 'desktop',
            'deviceBrand' => '',
            'deviceModel' => '',
        ], $this->object->getDevice());
    }
}
