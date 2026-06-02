<?php

namespace Tests\Unit\Platform\Modules\Functions\Workers;

use Appwrite\Platform\Modules\Functions\Workers\Builds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BuildsTest extends TestCase
{
    #[DataProvider('validRootDirectoryProvider')]
    public function testNormalizeRootDirectoryNormalizesValidPaths(string $input, string $expected): void
    {
        $this->assertSame($expected, Builds::normalizeRootDirectory($input));
    }

    public static function validRootDirectoryProvider(): array
    {
        return [
            'empty stays empty' => ['', ''],
            'current directory collapses to empty' => ['./', ''],
            'bare dot collapses to empty' => ['.', ''],
            'bare parent collapses to empty' => ['..', ''],
            'leading ./ is stripped' => ['./src', 'src'],
            'surrounding slashes are stripped' => ['/src/', 'src'],
            'trailing slash is stripped' => ['src/', 'src'],
            'leading ./ with trailing slash' => ['./src/', 'src'],
            'nested path is preserved' => ['src/app', 'src/app'],
            'dotfile parent is preserved' => ['src/.env.local', 'src/.env.local'],
            'single leading parent collapses to in-sandbox subdir' => ['../etc', 'etc'],
        ];
    }

    #[DataProvider('traversalRootDirectoryProvider')]
    public function testNormalizeRootDirectoryRejectsTraversal(string $input): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid root directory');

        Builds::normalizeRootDirectory($input);
    }

    public static function traversalRootDirectoryProvider(): array
    {
        return [
            'multi level parent escape' => ['../../etc/passwd'],
            'embedded parent segment' => ['foo/../bar'],
            'trailing parent segment' => ['foo/..'],
            'dot slash parent' => ['./../etc'],
            'multi embedded parent' => ['foo/../../bar'],
        ];
    }
}
