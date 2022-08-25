<?php

namespace Appwrite\Tests;

use Appwrite\Auth\Validator\Phone;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    protected ?Phone $object = null;

    public function setUp(): void
    {
        $this->object = new Phone();
    }

    public function tearDown(): void
    {
    }

    public function testValues()
    {
        $this->assertEquals(false, $this->object->isValid(false));
        $this->assertEquals(false, $this->object->isValid(null));
        $this->assertEquals(false, $this->object->isValid(''));
        $this->assertEquals(false, $this->object->isValid('+1'));
        $this->assertEquals(false, $this->object->isValid('8989829304'));
        $this->assertEquals(false, $this->object->isValid('786-307-3615'));
        $this->assertEquals(false, $this->object->isValid('+16308A520397'));
        $this->assertEquals(false, $this->object->isValid('+0415553452342'));
        $this->assertEquals(false, $this->object->isValid('+14 155 5524564'));
        $this->assertEquals(false, $this->object->isValid(+14155552456));

        $this->assertEquals(true, $this->object->isValid('+14155552'));
        $this->assertEquals(true, $this->object->isValid('+141555526'));
        $this->assertEquals(true, $this->object->isValid('+16308520394'));
        $this->assertEquals(true, $this->object->isValid('+163085205339'));
        $this->assertEquals(true, $this->object->isValid('+5511552563253'));
        $this->assertEquals(true, $this->object->isValid('+55115525632534'));
        $this->assertEquals(true, $this->object->isValid('+919367788755111'));
    }
}
