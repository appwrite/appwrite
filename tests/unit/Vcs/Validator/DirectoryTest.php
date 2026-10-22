<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs\Validator;

use Appwrite\Vcs\Validator\Directory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DirectoryTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function values(): iterable
    {
        yield 'empty' => ['', true];
        yield 'current' => ['./', true];
        yield 'dot' => ['.', true];
        yield 'root anchored' => ['/functions/starter', true];
        yield 'nested' => ['./node/starter', true];
        yield 'trailing slash' => ['functions/', true];
        yield 'spaces' => ['my function', true];
        yield 'dotted name' => ['..hidden/a..b/...', true];
        yield 'parent' => ['..', false];
        yield 'leading parent' => ['../other', false];
        yield 'inner parent' => ['functions/../../storage', false];
        yield 'trailing parent' => ['functions/..', false];
        yield 'root anchored parent' => ['/../etc', false];
        yield 'null byte' => ["functions\0", false];
        yield 'newline' => ["functions\nstarter", false];
        yield 'delete' => ["functions\x7F", false];
        yield 'too long' => [\str_repeat('a', 129), false];
        yield 'not a string' => [['functions'], false];
        yield 'null' => [null, false];
    }

    #[DataProvider('values')]
    public function testIsValid(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, (new Directory())->isValid($value));
    }

    public function testLengthIsConfigurable(): void
    {
        $this->assertTrue((new Directory(256))->isValid(\str_repeat('a', 256)));
        $this->assertFalse((new Directory(256))->isValid(\str_repeat('a', 257)));
    }

    public function testIsString(): void
    {
        $validator = new Directory();

        $this->assertSame(Directory::TYPE_STRING, $validator->getType());
        $this->assertFalse($validator->isArray());
    }
}
