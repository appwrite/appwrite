<?php

namespace Tests\Unit\Auth\Validator;

use Appwrite\Auth\Validator\PersonalData;
use PHPUnit\Framework\TestCase;

class PersonalDataTest extends TestCase
{
    protected ?PersonalData $object = null;

    public function testStrict(): void
    {
        $this->object = new PersonalData('userId', 'email@example.com', 'name', '+129492323', true);

        $this->assertEquals($this->object->isValid('userId'), false);
        $this->assertEquals($this->object->isValid('something.userId'), false);
        $this->assertEquals($this->object->isValid('userId.something'), false);
        $this->assertEquals($this->object->isValid('something.userId.something'), false);

        $this->assertEquals($this->object->isValid('email@example.com'), false);
        $this->assertEquals($this->object->isValid('something.email@example.com'), false);
        $this->assertEquals($this->object->isValid('email@example.com.something'), false);
        $this->assertEquals($this->object->isValid('something.email@example.com.something'), false);

        $this->assertEquals($this->object->isValid('name'), false);
        $this->assertEquals($this->object->isValid('something.name'), false);
        $this->assertEquals($this->object->isValid('name.something'), false);
        $this->assertEquals($this->object->isValid('something.name.something'), false);

        $this->assertEquals($this->object->isValid('+129492323'), false);
        $this->assertEquals($this->object->isValid('something.+129492323'), false);
        $this->assertEquals($this->object->isValid('+129492323.something'), false);
        $this->assertEquals($this->object->isValid('something.+129492323.something'), false);

        $this->assertEquals($this->object->isValid('129492323'), false);
        $this->assertEquals($this->object->isValid('something.129492323'), false);
        $this->assertEquals($this->object->isValid('129492323.something'), false);
        $this->assertEquals($this->object->isValid('something.129492323.something'), false);

        $this->assertEquals($this->object->isValid('email'), false);
        $this->assertEquals($this->object->isValid('something.email'), false);
        $this->assertEquals($this->object->isValid('email.something'), false);
        $this->assertEquals($this->object->isValid('something.email.something'), false);

        /** Test for success */
        $this->assertEquals($this->object->isValid('893pu5egerfsv3rgersvd'), true);
    }

    public function testNotStrict(): void
    {
        $this->object = new PersonalData('userId', 'email@example.com', 'name', '+129492323', false);

        $this->assertEquals($this->object->isValid('userId'), false);
        $this->assertEquals($this->object->isValid('USERID'), false);
        $this->assertEquals($this->object->isValid('something.USERID'), false);
        $this->assertEquals($this->object->isValid('USERID.something'), false);
        $this->assertEquals($this->object->isValid('something.USERID.something'), false);

        $this->assertEquals($this->object->isValid('email@example.com'), false);
        $this->assertEquals($this->object->isValid('EMAIL@EXAMPLE.COM'), false);
        $this->assertEquals($this->object->isValid('something.EMAIL@EXAMPLE.COM'), false);
        $this->assertEquals($this->object->isValid('EMAIL@EXAMPLE.COM.something'), false);
        $this->assertEquals($this->object->isValid('something.EMAIL@EXAMPLE.COM.something'), false);

        $this->assertEquals($this->object->isValid('name'), false);
        $this->assertEquals($this->object->isValid('NAME'), false);
        $this->assertEquals($this->object->isValid('something.NAME'), false);
        $this->assertEquals($this->object->isValid('NAME.something'), false);
        $this->assertEquals($this->object->isValid('something.NAME.something'), false);

        $this->assertEquals($this->object->isValid('+129492323'), false);
        $this->assertEquals($this->object->isValid('129492323'), false);

        $this->assertEquals($this->object->isValid('email'), false);
        $this->assertEquals($this->object->isValid('EMAIL'), false);
        $this->assertEquals($this->object->isValid('something.EMAIL'), false);
        $this->assertEquals($this->object->isValid('EMAIL.something'), false);
        $this->assertEquals($this->object->isValid('something.EMAIL.something'), false);
    }
}
