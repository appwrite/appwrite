<?php

namespace Appwrite\Tests;

use Appwrite\Auth\Validator\Password;
use PHPUnit\Framework\TestCase;

class PasswordTest extends TestCase
{
    /**
     * @var Password
     */
    protected $object = null;

    public function setUp(): void
    {
        $this->object = new Password();
    }

    public function tearDown(): void
    {
    }

    public function testValues()
    {
        $this->assertEquals(false, $this->object->isValid(false));
        $this->assertEquals(false, $this->object->isValid(null));
        $this->assertEquals(false, $this->object->isValid(''));
        $this->assertEquals(false, $this->object->isValid('1'));
        $this->assertEquals(false, $this->object->isValid('12'));
        $this->assertEquals(false, $this->object->isValid('123'));
        $this->assertEquals(false, $this->object->isValid('1234'));
        $this->assertEquals(false, $this->object->isValid('12345'));
        $this->assertEquals(false, $this->object->isValid('123456'));
        $this->assertEquals(false, $this->object->isValid('1234567'));
        $this->assertEquals(true, $this->object->isValid('WUnOZcn0piQMN8Mh31xw4KQPF0gcNGVA'));
    }
}
