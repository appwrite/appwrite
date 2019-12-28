<?php

namespace Appwrite\Tests;

use Auth\Validator\Password;
use PHPUnit\Framework\TestCase;

class PasswordTestTest extends TestCase
{
    /**
     * @var Password
     */
    protected $object = null;

    public function setUp()
    {
        $this->object = new Password();
    }

    public function tearDown()
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
        $this->assertEquals($this->object->isValid('123456'), true);
        $this->assertEquals($this->object->isValid('1234567'), true);
        $this->assertEquals($this->object->isValid('WUnOZcn0piQMN8Mh31xw4KQPF0gcNGVA'), true);
        $this->assertEquals($this->object->isValid('WUnOZcn0piQMN8Mh31xw4KQPF0gcNGVAx'), false);
    }
}