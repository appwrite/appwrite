<?php

namespace Appwrite\Tests;

use Appwrite\Database\Validator\UID;
use PHPUnit\Framework\TestCase;

class UIDTest extends TestCase
{
    /**
     * @var UID
     */
    protected $object = null;

    public function setUp(): void
    {
        $this->object = new UID();
    }

    public function tearDown(): void
    {
    }

    public function testValues()
    {
        $this->assertEquals($this->object->isValid('5f058a8925807'), true);
        $this->assertEquals($this->object->isValid('5f058a89258075f058a89258075f058t'), true);
        $this->assertEquals($this->object->isValid('5f058a89258075f058a89258075f058tx'), false);
    }
}
