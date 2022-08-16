<?php

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\CNAME;
use PHPUnit\Framework\TestCase;

class CNAMETest extends TestCase
{
    protected ?CNAME $object = null;

    public function setUp(): void
    {
        $this->object = new CNAME('appwrite.io');
    }

    public function tearDown(): void
    {
    }

    public function testValues(): void
    {
        $this->assertEquals($this->object->isValid(''), false);
        $this->assertEquals($this->object->isValid(null), false);
        $this->assertEquals($this->object->isValid(false), false);
        $this->assertEquals($this->object->isValid('cname-unit-test.appwrite.org'), true);
        $this->assertEquals($this->object->isValid('test1.appwrite.org'), false);
    }
}
