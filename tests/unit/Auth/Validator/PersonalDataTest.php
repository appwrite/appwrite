<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Validator;

use Appwrite\Auth\Validator\PersonalData;
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
}
