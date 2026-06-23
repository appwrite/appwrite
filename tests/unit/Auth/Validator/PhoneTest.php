<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Validator;

use Appwrite\Auth\Validator\Phone;
use PHPUnit\Framework\TestCase;

final class PhoneTest extends TestCase
{
    protected ?Phone $object = null;

    public function setUp(): void
    {
        $this->object = new Phone();
    }

    public function testValues(): void
    {
        $this->assertFalse($this->object->isValid(false));
        $this->assertFalse($this->object->isValid(null));
        $this->assertFalse($this->object->isValid(''));
        $this->assertFalse($this->object->isValid('+1'));
        $this->assertFalse($this->object->isValid('+14'));
        $this->assertFalse($this->object->isValid('+141'));
        $this->assertFalse($this->object->isValid('+1415'));
        $this->assertFalse($this->object->isValid('+14155'));
        $this->assertFalse($this->object->isValid('+141555'));
        $this->assertFalse($this->object->isValid('8989829304'));
        $this->assertFalse($this->object->isValid('786-307-3615'));
        $this->assertFalse($this->object->isValid('+16308A520397'));
        $this->assertFalse($this->object->isValid('+0415553452342'));
        $this->assertFalse($this->object->isValid('+14 155 5524564'));
        $this->assertFalse($this->object->isValid('+1415555245634543'));
        $this->assertFalse($this->object->isValid('+8020000000')); // when country code is not present
        $this->assertFalse($this->object->isValid(+14155552456));

        $this->assertTrue($this->object->isValid('+1415555'));
        $this->assertTrue($this->object->isValid('+14155552'));
        $this->assertTrue($this->object->isValid('+141555526'));
        $this->assertTrue($this->object->isValid('+16308520394'));
        $this->assertTrue($this->object->isValid('+163085205339'));
        $this->assertTrue($this->object->isValid('+5511552563253'));
        $this->assertTrue($this->object->isValid('+55115525632534'));
        $this->assertTrue($this->object->isValid('+919367788755111'));
    }
}
