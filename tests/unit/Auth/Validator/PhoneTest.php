<?php

namespace Tests\Unit\Auth\Validator;

use Appwrite\Auth\Validator\Phone;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    protected ?Phone $object = null;

    public function setUp(): void
    {
        $this->object = new Phone();
    }

    public function testValues(): void
    {
        $this->assertEquals($this->object->isValid(false), false);
        $this->assertEquals($this->object->isValid(null), false);
        $this->assertEquals($this->object->isValid(''), false);
        $this->assertEquals($this->object->isValid('+1'), false);
        $this->assertEquals($this->object->isValid('8989829304'), false);
        $this->assertEquals($this->object->isValid('786-307-3615'), false);
        $this->assertEquals($this->object->isValid('+16308A520397'), false);
        $this->assertEquals($this->object->isValid('+0415553452342'), false);
        $this->assertEquals($this->object->isValid('+14 155 5524564'), false);
        $this->assertEquals($this->object->isValid(+14155552456), false);

        $this->assertEquals($this->object->isValid('+14155552'), true);
        $this->assertEquals($this->object->isValid('+141555526'), true);
        $this->assertEquals($this->object->isValid('+16308520394'), true);
        $this->assertEquals($this->object->isValid('+163085205339'), true);
        $this->assertEquals($this->object->isValid('+5511552563253'), true);
        $this->assertEquals($this->object->isValid('+55115525632534'), true);
        $this->assertEquals($this->object->isValid('+919367788755111'), true);
    }
}
