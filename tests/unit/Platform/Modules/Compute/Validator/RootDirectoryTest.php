<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Compute\Validator;

use Appwrite\Platform\Modules\Compute\Validator\RootDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RootDirectoryTest extends TestCase
{
    #[DataProvider('paths')]
    public function testIsValid(string $path, bool $expected): void
    {
        $this->assertSame($expected, (new RootDirectory())->isValid($path));
        $this->assertSame($expected, RootDirectory::isConfined($path));
    }

    /**
     * @return \Iterator<string, array{0: string, 1: bool}>
     */
    public static function paths(): \Iterator
    {
        yield 'repository root' => ['', true];
        yield 'directory' => ['functions/hello', true];
        yield 'leading dot slash' => ['./functions/hello', true];
        yield 'leading slash' => ['/functions/hello', true];
        yield 'trailing slash' => ['functions/hello/', true];
        yield 'spaces' => ['my functions/hello world', true];
        yield 'dot segment' => ['functions/./hello', true];
        yield 'dotfile' => ['.github/workflows', true];
        yield 'three dots' => ['functions/.../hello', true];
        yield 'parent segment' => ['../other', false];
        yield 'parent segment inside' => ['functions/../../../../storage', false];
        yield 'parent segment at the end' => ['functions/..', false];
        yield 'parent only' => ['..', false];
        yield 'backslash parent segment' => ['functions\\..\\..\\storage', false];
        yield 'newline' => ["functions\nhello", false];
        yield 'carriage return' => ["functions\rhello", false];
        yield 'nul byte' => ["functions\0hello", false];
        yield 'delete byte' => ["functions\x7Fhello", false];
    }

    public function testLengthIsBounded(): void
    {
        $this->assertTrue((new RootDirectory(8))->isValid('a/b/c'));
        $this->assertFalse((new RootDirectory(8))->isValid('a/b/c/d/e'));
    }

    public function testRejectsNonStrings(): void
    {
        $this->assertFalse((new RootDirectory())->isValid(['functions']));
        $this->assertFalse((new RootDirectory())->isValid(null));
    }
}
