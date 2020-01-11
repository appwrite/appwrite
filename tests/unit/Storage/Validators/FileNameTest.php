<?php

namespace Appwrite\Tests;

use Storage\Validators\FileName;
use PHPUnit\Framework\TestCase;

class FileNameTest extends TestCase
{
    /**
     * @var FileName
     */
    protected $object = null;

    public function setUp()
    {
        $this->object = new FileName();
    }

    public function tearDown()
    {
    }

    public function testValues()
    {
        $this->assertEquals($this->object->isValid(''), false);
        $this->assertEquals($this->object->isValid(null), false);
        $this->assertEquals($this->object->isValid(false), false);
        $this->assertEquals($this->object->isValid('../test'), false);
        $this->assertEquals($this->object->isValid('test.png'), true);
        $this->assertEquals($this->object->isValid('test'), true);
    }
}