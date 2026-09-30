<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Functions\Workers;

use Appwrite\Platform\Modules\Functions\Workers\Builds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Console;

final class BuildsArchiveTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/builds-archive-' . \uniqid();
        \mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $stdout = '';
        $stderr = '';
        Console::execute('rm -rf ' . \escapeshellarg($this->root), '', $stdout, $stderr);
    }

    #[DataProvider('directoryNameProvider')]
    public function testArchivesOnlyTheNamedDirectory(string $name): void
    {
        $directory = $this->root . '/' . $name;
        \mkdir($directory);
        \file_put_contents($directory . '/index.js', 'export default () => {};');
        \file_put_contents($this->root . '/outside.txt', 'not part of the build');

        $archive = $this->root . '/code.tar.gz';
        $stdout = '';
        $stderr = '';
        $exit = Console::execute(Builds::archiveCommand($archive, $directory), '', $stdout, $stderr);

        $this->assertSame(0, $exit, 'tar failed: ' . $stderr);

        $extracted = $this->root . '/extracted';
        \mkdir($extracted);
        $exit = Console::execute('tar -xzf ' . \escapeshellarg($archive) . ' -C ' . \escapeshellarg($extracted), '', $stdout, $stderr);

        $this->assertSame(0, $exit, 'extract failed: ' . $stderr);
        $this->assertSame(['index.js' => 'export default () => {};'], $this->readFiles($extracted));
    }

    /**
     * @return array<string, string>
     */
    private function readFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $files[\substr($file->getPathname(), \strlen($directory) + 1)] = \file_get_contents($file->getPathname());
        }

        \ksort($files);

        return $files;
    }

    public static function directoryNameProvider(): \Iterator
    {
        yield 'plain' => ['functions'];
        yield 'space' => ['my function'];
        yield 'tab' => ["my\tfunction"];
        yield 'single quote' => ["it's"];
        yield 'double quote' => ['say "hi"'];
        yield 'leading dash' => ['-function'];
        yield 'shell characters' => ['a;b&c|d$e'];
    }
}
