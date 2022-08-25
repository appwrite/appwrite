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
        $this->assertEquals(false, $this->object->isValid(''));
        $this->assertEquals(false, $this->object->isValid(null));
        $this->assertEquals(false, $this->object->isValid(false));
        $this->assertEquals(true, $this->object->isValid('cname-unit-test.appwrite.org'));
        $this->assertEquals(false, $this->object->isValid('test1.appwrite.org'));
    }
}
