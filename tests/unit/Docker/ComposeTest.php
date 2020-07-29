<?php

namespace Appwrite\Tests;

use Appwrite\Docker\Compose;
use Exception;
use PHPUnit\Framework\TestCase;

class ComposeTest extends TestCase
{
    /**
     * @var Compose
     */
    protected $object = null;


    public function setUp()
    {
        $data = @file_get_contents(__DIR__.'/../../resources/docker/docker-compose.yml');

        if($data === false) {
            throw new Exception('Failed to read compose file');
        }

        $this->object = new Compose($data);
    }

    public function tearDown()
    {
    }

    public function testVersion()
    {
        $this->assertEquals('3', $this->object->getVersion());
    }

    public function testServices()
    {
        $this->assertCount(17, $this->object->getServices());
        $this->assertEquals('appwrite-telegraf', $this->object->getService('telegraf')->getContainerName());
        $this->assertEquals('appwrite', $this->object->getService('appwrite')->getContainerName());
    }

    public function testNetworks()
    {
        $this->assertCount(2, $this->object->getNetworks());
    }

    public function testVolumes()
    {
        $this->assertCount(9, $this->object->getVolumes());
        $this->assertEquals('appwrite-mariadb', $this->object->getVolumes()[0]);
        $this->assertEquals('appwrite-redis', $this->object->getVolumes()[1]);
        $this->assertEquals('appwrite-cache', $this->object->getVolumes()[2]);
    }
}