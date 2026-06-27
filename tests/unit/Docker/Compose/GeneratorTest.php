<?php

declare(strict_types=1);

namespace Tests\Unit\Docker\Compose;

use Appwrite\Docker\Compose\Generator;
use PHPUnit\Framework\TestCase;

final class GeneratorTest extends TestCase
{
    private Generator $generator;

    public function setUp(): void
    {
        $compose = \file_get_contents(__DIR__ . '/../../../../docker-compose.yml');

        $this->assertIsString($compose);

        $this->generator = new Generator($compose);
    }

    public function testSelectsDatabaseService(): void
    {
        $compose = $this->render([
            'database' => 'mariadb',
            'enableAssistant' => false,
        ]);

        $this->assertArrayHasKey('mariadb', $compose['services']);
        $this->assertArrayNotHasKey('mongodb', $compose['services']);
        $this->assertArrayNotHasKey('postgresql', $compose['services']);
        $this->assertArrayHasKey('appwrite-mariadb', $compose['volumes']);
        $this->assertArrayNotHasKey('appwrite-mongodb', $compose['volumes']);
        $this->assertArrayNotHasKey('appwrite-postgresql', $compose['volumes']);
    }

    public function testTogglesAssistantService(): void
    {
        $disabled = $this->render([
            'enableAssistant' => false,
        ]);
        $enabled = $this->render([
            'enableAssistant' => true,
        ]);

        $this->assertArrayNotHasKey('appwrite-assistant', $disabled['services']);
        $this->assertArrayHasKey('appwrite-assistant', $enabled['services']);
    }

    public function testKeepsProductionWorkers(): void
    {
        $compose = $this->render();

        $this->assertArrayHasKey('appwrite-worker-screenshots', $compose['services']);
        $this->assertArrayHasKey('appwrite-task-interval', $compose['services']);
        $this->assertArrayHasKey('appwrite-embedding', $compose['services']);
    }

    public function testKeepsMongoInitFiles(): void
    {
        $compose = $this->render([
            'database' => 'mongodb',
        ]);

        $this->assertContains('./mongo-init.js:/docker-entrypoint-initdb.d/mongo-init.js:ro', $compose['services']['mongodb']['volumes']);
        $this->assertContains('./mongo-entrypoint.sh:/mongo-entrypoint.sh:ro', $compose['services']['mongodb']['volumes']);
    }

    public function testAddsLocalHostPathMount(): void
    {
        $compose = $this->render([
            'version' => 'local',
            'hostPath' => '/tmp/appwrite',
        ]);

        $this->assertSame('/tmp/appwrite:/usr/src/code:rw', $compose['services']['appwrite']['volumes'][0]);
    }

    public function testKeepsLongCommandsReadable(): void
    {
        $mariadb = $this->render([
            'database' => 'mariadb',
        ]);
        $postgresql = $this->render([
            'database' => 'postgresql',
        ]);

        $this->assertSame(['mysqld', '--innodb-flush-method=fsync'], $mariadb['services']['mariadb']['command']);
        $this->assertSame([
            'postgres',
            '-c',
            'fsync=off',
            '-c',
            'synchronous_commit=off',
            '-c',
            'full_page_writes=off',
        ], $postgresql['services']['postgresql']['command']);
        $this->assertSame([
            'redis-server',
            '--maxmemory',
            '512mb',
            '--maxmemory-policy',
            'allkeys-lru',
            '--maxmemory-samples',
            '5',
        ], $postgresql['services']['redis']['command']);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function render(array $params = []): array
    {
        $yaml = $this->generator->render($params);
        $compose = \yaml_parse($yaml);

        $this->assertIsArray($compose);

        return $compose;
    }
}
