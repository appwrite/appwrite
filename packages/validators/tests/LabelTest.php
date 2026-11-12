<?php

declare(strict_types=1);

namespace Utopia\Validator\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Validator\Label;
use Utopia\Validator\Validator;

final class LabelTest extends TestCase
{
    public function testShape(): void
    {
        $label = new Label();

        $this->assertFalse($label->isArray());
        $this->assertSame(Validator::TYPE_STRING, $label->getType());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validProvider(): array
    {
        return [
            'letters' => ['abcdefghijklmnopqrst'],
            'digits' => ['123'],
            'region' => ['eu-central-1'],
            'single character' => ['a'],
            'max length' => [\str_repeat('a', 63)],
        ];
    }

    #[DataProvider('validProvider')]
    public function testAcceptsLabels(string $value): void
    {
        $this->assertTrue(new Label()->isValid($value));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidProvider(): array
    {
        return [
            'empty' => [''],
            'over length' => [\str_repeat('a', 64)],
            'dot' => ['a.b'],
            'semicolon' => ['a;b'],
            'equals' => ['a=b'],
            'space' => ['a b'],
            'slash' => ['a/b'],
            'colon' => ['a:b'],
            'at' => ['a@b'],
            'underscore' => ['a_b'],
            'uppercase' => ['Abc'],
            'leading hyphen' => ['-abc'],
            'trailing hyphen' => ['abc-'],
            'only hyphen' => ['-'],
            'trailing newline' => ["abc\n"],
            'null byte' => ["abc\0"],
            'non ascii' => ['ábc'],
            'integer' => [123],
            'null' => [null],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testRejectsNonLabels(mixed $value): void
    {
        $this->assertFalse(new Label()->isValid($value));
    }
}
