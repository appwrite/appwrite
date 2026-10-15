<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\RootDirectory;
use InvalidArgumentException;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RootDirectoryTest extends TestCase
{
    #[DataProvider('validPathProvider')]
    public function testNormalizesValidPath(string $path, string $expected): void
    {
        $this->assertSame($expected, (new RootDirectory($path))->path);
    }

    public static function validPathProvider(): Iterator
    {
        yield 'empty stays empty' => ['', ''];
        yield 'current directory collapses to empty' => ['./', ''];
        yield 'bare dot collapses to empty' => ['.', ''];
        yield 'bare parent collapses to empty' => ['..', ''];
        yield 'leading dot slash is stripped' => ['./src', 'src'];
        yield 'surrounding slashes are stripped' => ['/src/', 'src'];
        yield 'trailing slash is stripped' => ['src/', 'src'];
        yield 'leading dot slash with trailing slash' => ['./src/', 'src'];
        yield 'nested path is preserved' => ['src/app', 'src/app'];
        yield 'dotfile segment is preserved' => ['src/.env.local', 'src/.env.local'];
        yield 'single leading parent collapses into repository' => ['../etc', 'etc'];
    }

    #[DataProvider('traversalPathProvider')]
    public function testRejectsTraversal(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid root directory');

        new RootDirectory($path);
    }

    public static function traversalPathProvider(): Iterator
    {
        yield 'multi level parent escape' => ['../../etc/passwd'];
        yield 'embedded parent segment' => ['foo/../bar'];
        yield 'trailing parent segment' => ['foo/..'];
        yield 'dot slash parent' => ['./../etc'];
        yield 'multi embedded parent' => ['foo/../../bar'];
    }
}
