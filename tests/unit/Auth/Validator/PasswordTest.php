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
        $this->assertEquals($this->object->isValid(false), false);
        $this->assertEquals($this->object->isValid(null), false);
        $this->assertEquals($this->object->isValid(''), false);
        $this->assertEquals($this->object->isValid('1'), false);
        $this->assertEquals($this->object->isValid('12'), false);
        $this->assertEquals($this->object->isValid('123'), false);
        $this->assertEquals($this->object->isValid('1234'), false);
        $this->assertEquals($this->object->isValid('12345'), false);
        $this->assertEquals($this->object->isValid('123456'), false);
        $this->assertEquals($this->object->isValid('1234567'), false);
        $this->assertEquals($this->object->isValid('WUnOZcn0piQMN8Mh31xw4KQPF0gcNGVA'), true);
    }
}
