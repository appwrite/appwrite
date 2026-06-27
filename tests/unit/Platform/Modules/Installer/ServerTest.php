<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Installer;

use Appwrite\Platform\Installer\Runtime\Config;
use Appwrite\Platform\Installer\Server;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ServerTest extends TestCase
{
    public function testLocalUpgradeDetectionUsesInstallerFiles(): void
    {
        $paths = $this->getUpgradeDetectionPaths(new Config([
            'isLocal' => true,
        ]));

        $this->assertSame([
            '/tmp/appwrite/docker-compose.web-installer.yml',
            '/tmp/appwrite/.env.web-installer',
        ], $paths);
    }

    public function testProductionUpgradeDetectionUsesDefaultFiles(): void
    {
        $paths = $this->getUpgradeDetectionPaths(new Config());

        $this->assertSame([
            '/tmp/appwrite/docker-compose.yml',
            '/tmp/appwrite/.env',
        ], $paths);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function getUpgradeDetectionPaths(Config $config): array
    {
        $method = new ReflectionMethod(Server::class, 'getUpgradeDetectionPaths');

        return $method->invoke(new Server(), $config, '/tmp/appwrite');
    }
}
