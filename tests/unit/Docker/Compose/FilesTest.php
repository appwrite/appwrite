<?php

declare(strict_types=1);

namespace Tests\Unit\Docker\Compose;

use Appwrite\Docker\Compose\Files;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FilesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/compose-files-' . uniqid();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    /**
     * @return \Iterator<string, array{array<string>, string, array<string>}>
     */
    public static function provideNames(): \Iterator
    {
        yield 'no override' => [['docker-compose.yml'], 'docker-compose.yml', ['docker-compose.yml']];
        yield 'yml override' => [['docker-compose.yml', 'docker-compose.override.yml'], 'docker-compose.yml', ['docker-compose.yml', 'docker-compose.override.yml']];
        yield 'yaml override' => [['docker-compose.yml', 'docker-compose.override.yaml'], 'docker-compose.yml', ['docker-compose.yml', 'docker-compose.override.yaml']];
        yield 'both overrides prefer yml' => [['docker-compose.yml', 'docker-compose.override.yml', 'docker-compose.override.yaml'], 'docker-compose.yml', ['docker-compose.yml', 'docker-compose.override.yml']];
        yield 'backup is not an override' => [['docker-compose.yml', 'docker-compose.override.yml.pre-2.3.0'], 'docker-compose.yml', ['docker-compose.yml']];
        yield 'other main file' => [['docker-compose.web-installer.yml', 'docker-compose.override.yml'], 'docker-compose.web-installer.yml', ['docker-compose.web-installer.yml']];
    }

    /**
     * @param string[] $present
     * @param string[] $expected
     */
    #[DataProvider('provideNames')]
    public function testNames(array $present, string $main, array $expected): void
    {
        foreach ($present as $name) {
            touch($this->directory . '/' . $name);
        }

        $this->assertSame($expected, (new Files($this->directory, $main))->names());
    }
}
