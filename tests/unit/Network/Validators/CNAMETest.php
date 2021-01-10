<?php

namespace Appwrite\Tests;

use Appwrite\Network\Validator\CNAME;
use PHPUnit\Framework\TestCase;

class CNAMETest extends TestCase
{
    /**
     * @var CNAME
     */
    protected $object = null;

    public function setUp(): void
    {
        $this->object = new CNAME('appwrite.io');
    }

    public function tearDown(): void
    {
    }

    public function testValues()
    {
        $this->assertEquals($this->object->isValid(''), false);
        $this->assertEquals($this->object->isValid(null), false);
        $this->assertEquals($this->object->isValid(false), false);
        $this->assertEquals($this->object->isValid('test1.appwrite.io'), true);
        $this->assertEquals($this->object->isValid('test1.appwrite.io'), true);
        $this->assertEquals($this->object->isValid('test1.appwrite.org'), false);
        $this->assertEquals($this->object->isValid('test1.appwrite.org'), false);
    }
}
