<?php

namespace Appwrite\Tests;

use Network\Validators\CNAME;
use PHPUnit\Framework\TestCase;

class CNAMETest extends TestCase
{
    /**
     * @var CNAME
     */
    protected $object = null;

    public function setUp()
    {
        $this->object = new CNAME('new.appwrite.io');
    }

    public function tearDown()
    {
    }

    public function testValues()
    {
        $this->assertEquals($this->object->isValid(''), false);
        $this->assertEquals($this->object->isValid(null), false);
        $this->assertEquals($this->object->isValid(false), false);
        $this->assertEquals($this->object->isValid('cert1.tests.appwrite.io'), true);
        $this->assertEquals($this->object->isValid('cert2.tests.appwrite.io'), true);
        $this->assertEquals($this->object->isValid('cert1.tests.appwrite.com'), false);
        $this->assertEquals($this->object->isValid('cert1.tests.appwrite.com'), false);
    }
}