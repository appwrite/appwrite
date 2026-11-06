<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\OpenAPI\Exception\InvalidSpecification;
use Utopia\OpenAPI\Parser\Value;

final class ValueTest extends TestCase
{
    public function testEmptyArraysReadAsBothObjectAndList(): void
    {
        self::assertSame([], Value::object([], '#/x'));
        self::assertSame([], Value::list([], '#/x'));
    }

    public function testObjectRejectsListsAndScalars(): void
    {
        self::assertSame(['a' => 1], Value::object(['a' => 1], '#/x'));

        foreach ([[1, 2], 'text', 7, true, null] as $notAnObject) {
            try {
                Value::object($notAnObject, '#/paths');
                self::fail('Expected a non-object to be rejected');
            } catch (InvalidSpecification $exception) {
                self::assertSame('Expected an object at #/paths', $exception->getMessage());
            }
        }
    }

    public function testObjectAcceptsStdClass(): void
    {
        $value = new \stdClass();
        $value->a = 1;

        self::assertSame(['a' => 1], Value::object($value, '#/x'));
    }

    public function testListRejectsMapsAndScalars(): void
    {
        self::assertSame([1, 2], Value::list([1, 2], '#/x'));

        foreach ([['a' => 1], 'text', 7, null] as $notAList) {
            try {
                Value::list($notAList, '#/tags');
                self::fail('Expected a non-list to be rejected');
            } catch (InvalidSpecification $exception) {
                self::assertSame('Expected a list at #/tags', $exception->getMessage());
            }
        }
    }

    public function testStringListRejectsNonStringItems(): void
    {
        self::assertSame(['first', 'second'], Value::stringList(['first', 'second'], '#/tags'));

        $this->expectException(InvalidSpecification::class);
        $this->expectExceptionMessage('Expected string at #/tags/1');
        Value::stringList(['first', 2], '#/tags');
    }

    public function testRequiredStringNamesTheMissingKey(): void
    {
        self::assertSame('Pets', Value::requiredString(['title' => 'Pets'], 'title', '#/info'));

        $this->expectException(InvalidSpecification::class);
        $this->expectExceptionMessage('Expected string #/info/title');
        Value::requiredString([], 'title', '#/info');
    }

    public function testRequiredStringRejectsNonStrings(): void
    {
        $this->expectException(InvalidSpecification::class);
        Value::requiredString(['title' => 7], 'title', '#/info');
    }

    public function testOptionalStringTreatsMissingAndNullAlike(): void
    {
        self::assertSame('Pets', Value::optionalString(['title' => 'Pets'], 'title'));
        self::assertNull(Value::optionalString([], 'title'));
        self::assertNull(Value::optionalString(['title' => null], 'title'));
    }

    public function testOptionalStringStillRejectsWrongTypes(): void
    {
        $this->expectException(InvalidSpecification::class);
        Value::optionalString(['title' => 7], 'title');
    }

    public function testNullableIntAcceptsOnlyIntegers(): void
    {
        self::assertNull(Value::nullableInt(null, '#/x'));
        self::assertSame(3, Value::nullableInt(3, '#/x'));

        $this->expectException(InvalidSpecification::class);
        $this->expectExceptionMessage('Expected integer at #/x/minLength');
        Value::nullableInt(3.5, '#/x/minLength');
    }

    public function testNullableNumberAcceptsIntegersAndFloats(): void
    {
        self::assertNull(Value::nullableNumber(null, '#/x'));
        self::assertSame(3, Value::nullableNumber(3, '#/x'));
        self::assertSame(3.5, Value::nullableNumber(3.5, '#/x'));

        $this->expectException(InvalidSpecification::class);
        $this->expectExceptionMessage('Expected number at #/x/minimum');
        Value::nullableNumber('3', '#/x/minimum');
    }

    public function testExtensionsIgnoreIntegerAndNumericStringKeys(): void
    {
        $data = [0 => 'zero', '200' => 'OK', '0200' => 'padded', 'default' => 'fallback', 'x-owner' => 'team'];

        self::assertSame(['x-owner' => 'team'], Value::extensions($data));
    }

    public function testExtensionsKeepOnlyPrefixedKeysAndAreCaseInsensitive(): void
    {
        self::assertSame(
            ['x-owner' => 'team', 'X-Trace' => true],
            Value::extensions(['title' => 'Pets', 'x-owner' => 'team', 'X-Trace' => true, 'xenon' => 1]),
        );
    }
}
