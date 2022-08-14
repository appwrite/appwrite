<?php

namespace Tests\Unit\Docker;

use Appwrite\Docker\Compose;
use Exception;
use PHPUnit\Framework\TestCase;

class ComposeTest extends TestCase
{
    protected ?Compose $object = null;

    public function setUp(): void
    {
        $data = @file_get_contents(__DIR__ . '/../../resources/docker/docker-compose.yml');

        if ($data === false) {
            throw new Exception('Failed to read compose file');
        }

        $this->object = new Compose($data);
    }

    public function testVersion(): void
    {
        $this->assertEquals('3', $this->object->getVersion());
    }

    public function testServices(): void
    {
        $this->assertCount(17, $this->object->getServices());
        $this->assertEquals('appwrite-telegraf', $this->object->getService('telegraf')->getContainerName());
        $this->assertEquals('appwrite', $this->object->getService('appwrite')->getContainerName());
        $this->assertEquals('', $this->object->getService('appwrite')->getImageVersion());
        $this->assertEquals('2.2', $this->object->getService('traefik')->getImageVersion());
        $this->assertEquals(['2080' => '80', '2443' => '443', '8080' => '8080'], $this->object->getService('traefik')->getPorts());
    }

    public function testNetworks(): void
    {
        $this->assertCount(2, $this->object->getNetworks());
    }

    public function testVolumes(): void
    {
        $this->assertCount(9, $this->object->getVolumes());
        $this->assertEquals('appwrite-mariadb', $this->object->getVolumes()[0]);
        $this->assertEquals('appwrite-redis', $this->object->getVolumes()[1]);
        $this->assertEquals('appwrite-cache', $this->object->getVolumes()[2]);
    }
}
