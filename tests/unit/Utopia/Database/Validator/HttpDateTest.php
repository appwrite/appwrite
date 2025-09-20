<?php

use PHPUnit\Framework\TestCase;
use Appwrite\Utopia\Database\Validator\HttpDate;
use Appwrite\Utopia\Database\Validator\Enum;

class HttpDateTest extends TestCase
{
    public function testValidHttpDate()
    {
        $validator = new HttpDate();
        $this->assertTrue($validator->isValid('Sun, 06 Nov 1994 08:49:37 GMT'));
        $this->assertTrue($validator->isValid('Mon, 07 Nov 2025 08:00:00 GMT'));
    }

    public function testInvalidHttpDate()
    {
        $validator = new HttpDate();
        $this->assertFalse($validator->isValid('2025-11-07T08:00:00Z'));
        $this->assertFalse($validator->isValid('not a date'));
        $this->assertFalse($validator->isValid(''));
    }
}

class EnumTest extends TestCase
{
    public function testValidEnum()
    {
        $validator = new Enum(['static', 'date', 'file']);
        $this->assertTrue($validator->isValid('static'));
        $this->assertTrue($validator->isValid('date'));
        $this->assertTrue($validator->isValid('file'));
    }

    public function testInvalidEnum()
    {
        $validator = new Enum(['static', 'date', 'file']);
        $this->assertFalse($validator->isValid('other'));
        $this->assertFalse($validator->isValid(''));
        $this->assertFalse($validator->isValid(null));
    }
}
