<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Validator;

use Appwrite\Auth\Validator\PersonalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PersonalDataTest extends TestCase
{
    protected ?PersonalData $object = null;

    public function testStrict(): void
    {
        $this->object = new PersonalData('userId', 'email@example.com', 'name', '+129492323', true);

        $this->assertFalse($this->object->isValid('userId'));
        $this->assertFalse($this->object->isValid('something.userId'));
        $this->assertFalse($this->object->isValid('userId.something'));
        $this->assertFalse($this->object->isValid('something.userId.something'));

        $this->assertFalse($this->object->isValid('email@example.com'));
        $this->assertFalse($this->object->isValid('something.email@example.com'));
        $this->assertFalse($this->object->isValid('email@example.com.something'));
        $this->assertFalse($this->object->isValid('something.email@example.com.something'));

        $this->assertFalse($this->object->isValid('name'));
        $this->assertFalse($this->object->isValid('something.name'));
        $this->assertFalse($this->object->isValid('name.something'));
        $this->assertFalse($this->object->isValid('something.name.something'));

        $this->assertFalse($this->object->isValid('+129492323'));
        $this->assertFalse($this->object->isValid('something.+129492323'));
        $this->assertFalse($this->object->isValid('+129492323.something'));
        $this->assertFalse($this->object->isValid('something.+129492323.something'));

        $this->assertFalse($this->object->isValid('129492323'));
        $this->assertFalse($this->object->isValid('something.129492323'));
        $this->assertFalse($this->object->isValid('129492323.something'));
        $this->assertFalse($this->object->isValid('something.129492323.something'));

        $this->assertFalse($this->object->isValid('email'));
        $this->assertFalse($this->object->isValid('something.email'));
        $this->assertFalse($this->object->isValid('email.something'));
        $this->assertFalse($this->object->isValid('something.email.something'));

        /** Test for success */
        $this->assertTrue($this->object->isValid('893pu5egerfsv3rgersvd'));
    }

    public function testNotStrict(): void
    {
        $this->object = new PersonalData('userId', 'email@example.com', 'name', '+129492323', false);

        $this->assertFalse($this->object->isValid('userId'));
        $this->assertFalse($this->object->isValid('USERID'));
        $this->assertFalse($this->object->isValid('something.USERID'));
        $this->assertFalse($this->object->isValid('USERID.something'));
        $this->assertFalse($this->object->isValid('something.USERID.something'));

        $this->assertFalse($this->object->isValid('email@example.com'));
        $this->assertFalse($this->object->isValid('EMAIL@EXAMPLE.COM'));
        $this->assertFalse($this->object->isValid('something.EMAIL@EXAMPLE.COM'));
        $this->assertFalse($this->object->isValid('EMAIL@EXAMPLE.COM.something'));
        $this->assertFalse($this->object->isValid('something.EMAIL@EXAMPLE.COM.something'));

        $this->assertFalse($this->object->isValid('name'));
        $this->assertFalse($this->object->isValid('NAME'));
        $this->assertFalse($this->object->isValid('something.NAME'));
        $this->assertFalse($this->object->isValid('NAME.something'));
        $this->assertFalse($this->object->isValid('something.NAME.something'));

        $this->assertFalse($this->object->isValid('+129492323'));
        $this->assertFalse($this->object->isValid('129492323'));

        $this->assertFalse($this->object->isValid('email'));
        $this->assertFalse($this->object->isValid('EMAIL'));
        $this->assertFalse($this->object->isValid('something.EMAIL'));
        $this->assertFalse($this->object->isValid('EMAIL.something'));
        $this->assertFalse($this->object->isValid('something.EMAIL.something'));
    }

    #[DataProvider('denyListCases')]
    public function testDenyList(bool $strict, array $denyList, mixed $password, bool $valid): void
    {
        $validator = new PersonalData(name: 'ProfileName', strict: $strict, denyList: $denyList);

        $this->assertSame($valid, $validator->isValid($password));
    }

    public static function denyListCases(): \Iterator
    {
        yield 'exact' => [false, ['appwrite'], 'appwrite', false];
        yield 'substring' => [false, ['appwrite'], 'MyAppWrite2026!', false];
        yield 'capitalized term' => [false, ['APPWRITE'], 'MyAppWrite2026!', false];
        yield 'multiple' => [false, ['other', 'appwrite'], 'MyAppWrite2026!', false];
        yield 'empty list' => [false, [], 'MyAppWrite2026!', true];
        yield 'strict match' => [true, ['AppWrite'], 'MyAppWrite2026!', false];
        yield 'strict case differs' => [true, ['appwrite'], 'MyAppWrite2026!', true];
        yield 'zero' => [false, ['0'], 'Default2026!', false];
        yield 'strict zero' => [true, ['0'], 'Default2026!', false];
        yield 'no unicode folding' => [false, ['ÄCME'], 'Myäcme2026!', true];
        yield 'whitespace preserved' => [false, [' appwrite '], 'MyAppWrite2026!', true];
        yield 'profile still blocked' => [false, [], 'MyProfileName2026!', false];
        yield 'empty terms skipped' => [false, [''], 'SafePassword!', true];
        yield 'short' => [false, [], 'short', false];
        yield 'long' => [false, [], str_repeat('a', 257), false];
        yield 'type' => [false, [], null, false];
    }

    public function testPasswordless(): void
    {
        $validator = new PersonalData(allowEmpty: true, denyList: ['appwrite', '0']);

        $this->assertTrue($validator->isValid(''));
        $this->assertFalse($validator->isValid(null));
    }
}
