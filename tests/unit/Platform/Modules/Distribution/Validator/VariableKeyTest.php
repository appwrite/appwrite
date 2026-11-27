<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Distribution\Validator;

use Appwrite\Platform\Modules\Distribution\Validator\VariableKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VariableKeyTest extends TestCase
{
    public static function keys(): \Iterator
    {
        yield 'plain' => ['API_KEY', true];
        yield 'lowercase' => ['api_key', true];
        yield 'leading underscore' => ['_KEY', true];
        yield 'prefix without underscore' => ['DISTRIBUTIONS', true];
        yield 'appwrite without underscore' => ['APPWRITEKEY', true];
        yield 'prefix inside the key' => ['MY_APPWRITE_KEY', true];
        yield 'distribution prefix' => ['DISTRIBUTION_FOO', false];
        yield 'appwrite prefix' => ['APPWRITE_KEY', false];
        yield 'lowercase distribution prefix' => ['distribution_foo', false];
        yield 'lowercase appwrite prefix' => ['appwrite_x', false];
        yield 'mixed case prefix' => ['AppWrite_Key', false];
        yield 'bare distribution prefix' => ['DISTRIBUTION_', false];
        yield 'leading digit' => ['1KEY', false];
        yield 'hyphen' => ['MY-KEY', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('keys')]
    public function testIsValid(string $key, bool $valid): void
    {
        $this->assertSame($valid, (new VariableKey())->isValid($key));
    }

    public function testNonStringIsInvalid(): void
    {
        $this->assertFalse((new VariableKey())->isValid(null));
        $this->assertFalse((new VariableKey())->isValid(42));
    }

    public function testMaxLengthIsEnforced(): void
    {
        $validator = new VariableKey(4);

        $this->assertTrue($validator->isValid('ABCD'));
        $this->assertFalse($validator->isValid('ABCDE'));
    }

    public function testDescriptionNamesReservedPrefixes(): void
    {
        $description = (new VariableKey())->getDescription();

        $this->assertStringContainsString('DISTRIBUTION_', $description);
        $this->assertStringContainsString('APPWRITE_', $description);
    }
}
