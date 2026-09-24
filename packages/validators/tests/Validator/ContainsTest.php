<?php

declare(strict_types=1);

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;
use stdClass;

final class ContainsTest extends TestCase
{
    public function testCanValidateWithSinglePattern(): void
    {
        $validator = new Contains(['[skip ci]']);

        $this->assertTrue($validator->isValid('[skip ci] update changelog'));
        $this->assertTrue($validator->isValid('docs: update readme [skip ci]'));
        $this->assertTrue($validator->isValid('prefix[skip ci]suffix'));

        $this->assertFalse($validator->isValid('fix: real bug fix'));
        $this->assertFalse($validator->isValid('skip deploy without brackets'));
        $this->assertFalse($validator->isValid(''));
    }

    public function testCanValidateWithMultiplePatterns(): void
    {
        $validator = new Contains(['[skip ci]', '[no ci]', '[ci skip]']);

        $this->assertTrue($validator->isValid('[skip ci]'));
        $this->assertTrue($validator->isValid('[no ci]'));
        $this->assertTrue($validator->isValid('[ci skip]'));
        $this->assertFalse($validator->isValid('[skip deploy]'));
    }

    public function testCanValidateLoosely(): void
    {
        $validator = new Contains(['[skip ci]']);

        $this->assertTrue($validator->isValid('[skip ci]'));
        $this->assertTrue($validator->isValid('[SKIP CI]'));
        $this->assertTrue($validator->isValid('[Skip Ci]'));
        $this->assertTrue($validator->isValid('Docs update [SKIP CI]'));
    }

    public function testCanValidateStrictly(): void
    {
        $validator = new Contains(['[skip ci]'], true);

        $this->assertTrue($validator->isValid('[skip ci]'));
        $this->assertTrue($validator->isValid('prefix[skip ci]suffix'));

        $this->assertFalse($validator->isValid('[SKIP CI]'));
        $this->assertFalse($validator->isValid('[Skip Ci]'));
    }

    public function testCanValidateMultilineStrings(): void
    {
        $validator = new Contains(['[skip ci]']);

        $message = "feat: add new stuff\n\nMore detail here.\n\n[skip ci]";
        $this->assertTrue($validator->isValid($message));
    }

    public function testThrowsExceptionForEmptyPatternsArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Patterns array cannot be empty');

        new Contains([]);
    }

    public function testCanValidateWithEmptyPatternString(): void
    {
        $validator = new Contains(['']);

        $this->assertTrue($validator->isValid('any string'));
        $this->assertTrue($validator->isValid(''));
    }

    public function testCanValidateWithNonStringValues(): void
    {
        $validator = new Contains(['[skip ci]']);

        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid([]));
        $this->assertFalse($validator->isValid(123));
        $this->assertFalse($validator->isValid(12.34));
        $this->assertFalse($validator->isValid(true));
        $this->assertFalse($validator->isValid(false));
        $this->assertFalse($validator->isValid(new stdClass()));
    }

    public function testCanValidatePartialMatches(): void
    {
        $validator = new Contains(['skip']);

        $this->assertTrue($validator->isValid('skip'));
        $this->assertTrue($validator->isValid('skip ci'));
        $this->assertTrue($validator->isValid('please skip this'));
        $this->assertTrue($validator->isValid('skipping'));

        $this->assertFalse($validator->isValid('ski'));
        $this->assertFalse($validator->isValid(''));
    }

    public function testCanValidateWithUnicodeCharacters(): void
    {
        $validator = new Contains(['café', 'naïve']);

        $this->assertTrue($validator->isValid('I love café'));
        $this->assertTrue($validator->isValid('Naïve approach'));
        $this->assertTrue($validator->isValid('CAFÉ'));

        $this->assertFalse($validator->isValid('I love coffee'));
    }

    public function testReturnsCorrectMetadata(): void
    {
        $validator = new Contains(['foo', 'bar']);

        $this->assertFalse($validator->isArray());
        $this->assertSame(\Utopia\Validator::TYPE_STRING, $validator->getType());
        $this->assertStringContainsString('foo', $validator->getDescription());
        $this->assertStringContainsString('bar', $validator->getDescription());
        $this->assertStringContainsString('case-insensitive', $validator->getDescription());

        $validatorStrict = new Contains(['foo', 'bar'], true);

        $this->assertStringContainsString('case-sensitive', $validatorStrict->getDescription());
    }
}
