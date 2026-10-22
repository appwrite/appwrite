<?php

declare(strict_types=1);

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\Label;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LabelTest extends TestCase
{
    /**
     * @return \Iterator<string, array{mixed}>
     */
    public static function validLabels(): \Iterator
    {
        yield 'letters' => ['abcdefgh'];
        yield 'digits' => ['123'];
        yield 'hyphenated' => ['eu-central-1'];
        yield 'mixed case' => ['Region1'];
        yield 'single character' => ['a'];
        yield 'maximum length' => [\str_repeat('a', Label::MAX_LENGTH)];
    }

    /**
     * @return \Iterator<string, array{mixed}>
     */
    public static function invalidLabels(): \Iterator
    {
        yield 'empty' => [''];
        yield 'too long' => [\str_repeat('a', Label::MAX_LENGTH + 1)];
        yield 'leading hyphen' => ['-abc'];
        yield 'trailing hyphen' => ['abc-'];
        yield 'only hyphen' => ['-'];
        yield 'dot' => ['a.b'];
        yield 'port' => ['abc:5432'];
        yield 'path' => ['abc/x'];
        yield 'fragment' => ['abc#'];
        yield 'semicolon' => ['abc;host=x'];
        yield 'space' => ['ab c'];
        yield 'underscore' => ['a_b'];
        yield 'non ascii' => ['bücher'];
        yield 'null byte' => ["abc\0"];
        yield 'not a string' => [123];
        yield 'null' => [null];
    }

    #[DataProvider('validLabels')]
    public function testAcceptsLabel(mixed $value): void
    {
        $this->assertTrue((new Label())->isValid($value));
    }

    #[DataProvider('invalidLabels')]
    public function testRejectsValue(mixed $value): void
    {
        $this->assertFalse((new Label())->isValid($value));
    }
}
