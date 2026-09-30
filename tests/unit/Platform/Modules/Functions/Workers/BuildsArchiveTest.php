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
        $listing = '';
        $exit = Console::execute(Builds::archiveCommand($archive, $directory), '', $stdout, $stderr);

        $this->assertSame(0, $exit, 'tar failed: ' . $stderr);

        Console::execute('tar -tzf ' . \escapeshellarg($archive), '', $listing, $stderr);
        $entries = \array_values(\array_filter(\explode("\n", $listing)));

        $this->assertSame(['./', './index.js'], $entries);
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
