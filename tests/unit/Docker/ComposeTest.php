<?php

declare(strict_types=1);

namespace Tests\Unit\Docker;

use Appwrite\Docker\Compose;
use Exception;
use PHPUnit\Framework\TestCase;
use Spyc;

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
        $this->assertSame('worker-executions', $this->object->getService('appwrite-worker-executions')->getEntrypoint());
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

    /**
     * `app/init.php` sets each process's memory limit from `_APP_MEMORY_LIMIT`, so a service that
     * doesn't receive it silently ignores the operator's setting.
     */
    public function testEveryAppwriteServiceReceivesTheMemoryLimit(): void
    {
        $data = \file_get_contents(__DIR__ . '/../../../docker-compose.yml');
        $this->assertIsString($data);

        $services = (\function_exists('yaml_parse') ? \yaml_parse($data) : Spyc::YAMLLoadString($data))['services'];

        $missing = [];
        foreach ($services as $name => $service) {
            $image = (string) ($service['image'] ?? '');
            if (!isset($service['extends']) && !\str_contains($image, 'appwrite/appwrite') && $image !== 'appwrite-dev') {
                continue;
            }

            $environment = [];
            for ($current = $service; $current !== null; $current = isset($current['extends']) ? $services[$current['extends']['service']] : null) {
                \array_push($environment, ...($current['environment'] ?? []));
            }

            if (!\in_array('_APP_MEMORY_LIMIT', $environment, true)) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing);
    }
}
