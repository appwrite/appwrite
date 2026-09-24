<?php

declare(strict_types=1);

namespace Utopia\Validator\JSON;

use PHPUnit\Framework\TestCase;
use Utopia\Validator;

final class ArrayValidatorTest extends TestCase
{
    public function testAcceptsDecodedArrays(): void
    {
        $validator = new ArrayValidator();

        $this->assertTrue($validator->isValid(['test']));
        $this->assertTrue($validator->isValid([1, 2]));
        $this->assertTrue($validator->isValid([['test' => 'demo']]));
        $this->assertTrue($validator->isValid([[1, 2], [3, 4]]));
    }

    public function testAcceptsEncodedArrays(): void
    {
        $validator = new ArrayValidator();

        $this->assertTrue($validator->isValid('[]'));
        $this->assertTrue($validator->isValid('[1, 2]'));
        $this->assertTrue($validator->isValid('["test"]'));
        $this->assertTrue($validator->isValid('[{"test": "demo"}]'));
    }

    public function testAcceptsAmbiguousEmptyArray(): void
    {
        // PHP cannot tell a decoded `[]` from a decoded `{}`, so the empty array stays valid.
        $this->assertTrue(new ArrayValidator()->isValid([]));
    }

    public function testRejectsObjects(): void
    {
        $validator = new ArrayValidator();

        $this->assertFalse($validator->isValid('{}'));
        $this->assertFalse($validator->isValid('{"test": "demo"}'));
        $this->assertFalse($validator->isValid(['test' => 'demo']));
        $this->assertFalse($validator->isValid((object) ['test' => 'demo']));
        $this->assertFalse($validator->isValid(new \stdClass()));
    }

    public function testRejectsEncodedScalars(): void
    {
        $validator = new ArrayValidator();

        $this->assertFalse($validator->isValid('"not-an-array"'));
        $this->assertFalse($validator->isValid('1'));
        $this->assertFalse($validator->isValid('1.2'));
        $this->assertFalse($validator->isValid('true'));
        $this->assertFalse($validator->isValid('null'));
    }

    public function testRejectsNonJson(): void
    {
        $validator = new ArrayValidator();

        $this->assertFalse($validator->isValid(''));
        $this->assertFalse($validator->isValid('string'));
        $this->assertFalse($validator->isValid('[1, 2'));
        $this->assertFalse($validator->isValid("['test']"));
    }

    public function testRejectsScalars(): void
    {
        $validator = new ArrayValidator();

        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid(false));
        $this->assertFalse($validator->isValid(true));
        $this->assertFalse($validator->isValid(1));
        $this->assertFalse($validator->isValid(1.2));
    }

    public function testRejectsArraysNestedBeyondDecodingDepth(): void
    {
        $encoded = str_repeat('[', 600) . str_repeat(']', 600);

        $this->assertFalse(new ArrayValidator()->isValid($encoded));
    }

    public function testEnforcesLength(): void
    {
        $encoded = '["test"]';

        $this->assertTrue(new ArrayValidator(\strlen($encoded))->isValid($encoded));
        $this->assertFalse(new ArrayValidator(\strlen($encoded) - 1)->isValid($encoded));
        $this->assertTrue(new ArrayValidator(\strlen($encoded))->isValid(['test']));
        $this->assertFalse(new ArrayValidator(\strlen($encoded) - 1)->isValid(['test']));
    }

    public function testContract(): void
    {
        $validator = new ArrayValidator();

        $this->assertTrue($validator->isArray());
        $this->assertSame(Validator::TYPE_ARRAY, $validator->getType());
        $this->assertSame('Value must be a valid JSON array', $validator->getDescription());
        $this->assertSame(
            'Value must be a valid JSON array no longer than 16 characters when encoded',
            new ArrayValidator(16)->getDescription(),
        );
    }
}
