<?php

declare(strict_types=1);

namespace Utopia\Validator\JSON;

use PHPUnit\Framework\TestCase;
use Utopia\Validator;

final class ObjectValidatorTest extends TestCase
{
    public function testAcceptsDecodedObjects(): void
    {
        $validator = new ObjectValidator();

        $this->assertTrue($validator->isValid(['test' => 'demo']));
        $this->assertTrue($validator->isValid(['nested' => ['test' => 'demo']]));
        $this->assertTrue($validator->isValid(['list' => ['one', 'two']]));
        $this->assertTrue($validator->isValid((object) ['test' => 'demo']));
        $this->assertTrue($validator->isValid(new \stdClass()));
    }

    public function testAcceptsEncodedObjects(): void
    {
        $validator = new ObjectValidator();

        $this->assertTrue($validator->isValid('{}'));
        $this->assertTrue($validator->isValid('{"test": "demo"}'));
        $this->assertTrue($validator->isValid('{"nested": {"test": "demo"}}'));
        $this->assertTrue($validator->isValid('{"list": ["one", "two"]}'));
    }

    public function testAcceptsAmbiguousEmptyArray(): void
    {
        // PHP cannot tell a decoded `{}` from a decoded `[]`, so the empty array stays valid.
        $this->assertTrue(new ObjectValidator()->isValid([]));
    }

    public function testRejectsLists(): void
    {
        $validator = new ObjectValidator();

        $this->assertFalse($validator->isValid('[]'));
        $this->assertFalse($validator->isValid('[1, 2]'));
        $this->assertFalse($validator->isValid('[{"test": "demo"}]'));
        $this->assertFalse($validator->isValid(['test']));
        $this->assertFalse($validator->isValid([['test' => 'demo']]));
    }

    public function testRejectsEncodedScalars(): void
    {
        $validator = new ObjectValidator();

        $this->assertFalse($validator->isValid('"not-an-object"'));
        $this->assertFalse($validator->isValid('1'));
        $this->assertFalse($validator->isValid('1.2'));
        $this->assertFalse($validator->isValid('true'));
        $this->assertFalse($validator->isValid('null'));
    }

    public function testRejectsNonJson(): void
    {
        $validator = new ObjectValidator();

        $this->assertFalse($validator->isValid(''));
        $this->assertFalse($validator->isValid('string'));
        $this->assertFalse($validator->isValid('{"test": "demo"'));
        $this->assertFalse($validator->isValid("{'test': 'demo'}"));
    }

    public function testRejectsScalars(): void
    {
        $validator = new ObjectValidator();

        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid(false));
        $this->assertFalse($validator->isValid(true));
        $this->assertFalse($validator->isValid(1));
        $this->assertFalse($validator->isValid(1.2));
    }

    public function testRejectsObjectsNestedBeyondDecodingDepth(): void
    {
        $encoded = str_repeat('{"a":', 600) . '{}' . str_repeat('}', 600);

        $this->assertFalse(new ObjectValidator()->isValid($encoded));
    }

    public function testEnforcesLength(): void
    {
        $encoded = '{"test":"demo"}';

        $this->assertTrue(new ObjectValidator(\strlen($encoded))->isValid($encoded));
        $this->assertFalse(new ObjectValidator(\strlen($encoded) - 1)->isValid($encoded));
        $this->assertTrue(new ObjectValidator(\strlen($encoded))->isValid(['test' => 'demo']));
        $this->assertFalse(new ObjectValidator(\strlen($encoded) - 1)->isValid(['test' => 'demo']));
        $this->assertTrue(new ObjectValidator(\strlen($encoded))->isValid((object) ['test' => 'demo']));
        $this->assertFalse(new ObjectValidator(\strlen($encoded) - 1)->isValid((object) ['test' => 'demo']));
    }

    public function testContract(): void
    {
        $validator = new ObjectValidator();

        $this->assertFalse($validator->isArray());
        $this->assertSame(Validator::TYPE_OBJECT, $validator->getType());
        $this->assertSame('Value must be a valid JSON object', $validator->getDescription());
        $this->assertSame(
            'Value must be a valid JSON object no longer than 16 characters when encoded',
            new ObjectValidator(16)->getDescription(),
        );
    }
}
