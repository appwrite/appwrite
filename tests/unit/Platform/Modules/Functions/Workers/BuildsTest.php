<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Functions\Workers;

use Appwrite\Platform\Modules\Functions\Workers\Builds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BuildsTest extends TestCase
{
    #[DataProvider('validRootDirectoryProvider')]
    public function testNormalizeRootDirectoryNormalizesValidPaths(string $input, string $expected): void
    {
        $this->assertSame($expected, Builds::normalizeRootDirectory($input));
    }

    public static function validRootDirectoryProvider(): \Iterator
    {
        yield 'empty stays empty' => ['', ''];
        yield 'current directory collapses to empty' => ['./', ''];
        yield 'bare dot collapses to empty' => ['.', ''];
        yield 'bare parent collapses to empty' => ['..', ''];
        yield 'leading ./ is stripped' => ['./src', 'src'];
        yield 'surrounding slashes are stripped' => ['/src/', 'src'];
        yield 'trailing slash is stripped' => ['src/', 'src'];
        yield 'leading ./ with trailing slash' => ['./src/', 'src'];
        yield 'nested path is preserved' => ['src/app', 'src/app'];
        yield 'dotfile parent is preserved' => ['src/.env.local', 'src/.env.local'];
        yield 'single leading parent collapses to in-sandbox subdir' => ['../etc', 'etc'];
    }

    #[DataProvider('traversalRootDirectoryProvider')]
    public function testNormalizeRootDirectoryRejectsTraversal(string $input): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid root directory');

        Builds::normalizeRootDirectory($input);
    }

    public static function traversalRootDirectoryProvider(): \Iterator
    {
        yield 'multi level parent escape' => ['../../etc/passwd'];
        yield 'embedded parent segment' => ['foo/../bar'];
        yield 'trailing parent segment' => ['foo/..'];
        yield 'dot slash parent' => ['./../etc'];
        yield 'multi embedded parent' => ['foo/../../bar'];
    }
}
