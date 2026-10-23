<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Validator;

use Appwrite\Auth\Validator\PasswordDictionary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PasswordDictionaryTest extends TestCase
{
    protected ?PasswordDictionary $object = null;

    public function setUp(): void
    {
        $this->object = new PasswordDictionary(
            ['password' => true, '123456' => true],
            true
        );
    }

    public function testValues(): void
    {
        $this->assertFalse($this->object->isValid('1')); // to check parent is being called
        $this->assertFalse($this->object->isValid('123456'));
        $this->assertFalse($this->object->isValid('password'));
        $this->assertTrue($this->object->isValid('myPasswordIsRight'));

        $pass = ''; // 256 chars
        for ($i = 0; $i < 256; $i++) {
            $pass .= 'p';
        }

        $this->assertTrue($this->object->isValid($pass));

        $pass .= 'p'; // 257 chars

        $this->assertFalse($this->object->isValid($pass));
    }

    #[DataProvider('denyListCases')]
    public function testDenyList(bool $enabled, array $denyList, mixed $password, bool $valid): void
    {
        $validator = new PasswordDictionary(['football' => true], enabled: $enabled, denyList: $denyList);

        $this->assertSame($valid, $validator->isValid($password));
    }

    public static function denyListCases(): \Iterator
    {
        yield 'exact' => [true, ['AcmeDefault123!'], 'AcmeDefault123!', false];
        yield 'case sensitive' => [true, ['AcmeDefault123!'], 'acmedefault123!', true];
        yield 'substring allowed' => [true, ['AcmeDefault123!'], 'MyAcmeDefault123!', true];
        yield 'multiple' => [true, ['OtherDefault123!', 'AcmeDefault123!'], 'AcmeDefault123!', false];
        yield 'empty list' => [true, [], 'AcmeDefault123!', true];
        yield 'disabled custom' => [false, ['AcmeDefault123!'], 'AcmeDefault123!', true];
        yield 'disabled bundled' => [false, [], 'football', true];
        yield 'bundled' => [true, [], 'football', false];
        yield 'strict equality' => [true, [12345678], '12345678', true];
        yield 'zero is not substring' => [true, ['0'], 'Default2026!', true];
        yield 'short' => [false, [], 'short', false];
        yield 'long' => [false, [], str_repeat('a', 257), false];
        yield 'type' => [false, [], null, false];
    }
}
