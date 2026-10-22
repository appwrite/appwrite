<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator;

use Appwrite\Utopia\Database\Validator\FileName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileNameTest extends TestCase
{
    #[DataProvider('names')]
    public function testIsValid(mixed $name, bool $expected): void
    {
        $this->assertSame($expected, (new FileName())->isValid($name));
    }

    /**
     * @return \Iterator<string, array{0: mixed, 1: bool}>
     */
    public static function names(): \Iterator
    {
        yield 'plain' => ['report.pdf', true];
        yield 'spaces and unicode' => ['Q3 report – ünïcode.pdf', true];
        yield 'quotes and semicolons' => ['a "quoted"; name.txt', true];
        yield 'empty' => ['', true];
        yield 'line feed' => ["a\nb.html", false];
        yield 'carriage return' => ["a\rb.html", false];
        yield 'header injection' => ["x.txt\r\nContent-Type: text/html", false];
        yield 'nul' => ["a\0.html", false];
        yield 'tab' => ["a\tb.txt", false];
        yield 'delete' => ["a\x7Fb.txt", false];
        yield 'not a string' => [['a.txt'], false];
    }

    public function testLengthIsBounded(): void
    {
        $this->assertTrue((new FileName(8))->isValid('abcd.txt'));
        $this->assertFalse((new FileName(8))->isValid('abcde.txt'));
    }
}
