<?php

declare(strict_types=1);

namespace Tests\Unit\Docker;

use Appwrite\Docker\Compose;
use Exception;
use PHPUnit\Framework\TestCase;

final class ComposeTest extends TestCase
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

    public function testServices(): void
    {
        $this->assertCount(15, $this->object->getServices());
        $this->assertSame('appwrite', $this->object->getService('appwrite')->getContainerName());
        $this->assertSame('', $this->object->getService('appwrite')->getImageVersion());
        $this->assertSame('3.6', $this->object->getService('traefik')->getImageVersion());
        $this->assertSame(['2080' => '80', '2443' => '443', '8080' => '8080'], $this->object->getService('traefik')->getPorts());
        $this->assertSame('appwrite-worker-mails', $this->object->getService('appwrite-worker-mails')->getContainerName());
        $this->assertSame('worker-mails', $this->object->getService('appwrite-worker-mails')->getEntrypoint());
        $this->assertSame('appwrite-worker-notifications', $this->object->getService('appwrite-worker-notifications')->getContainerName());
        $this->assertSame('worker-notifications', $this->object->getService('appwrite-worker-notifications')->getEntrypoint());
    }

    public function testNetworks(): void
    {
        $this->assertCount(2, $this->object->getNetworks());
    }

    public function testVolumes(): void
    {
        $this->assertCount(7, $this->object->getVolumes());
        $this->assertSame('appwrite-mariadb', $this->object->getVolumes()[0]);
        $this->assertSame('appwrite-redis', $this->object->getVolumes()[1]);
        $this->assertSame('appwrite-cache', $this->object->getVolumes()[2]);
    }
}
