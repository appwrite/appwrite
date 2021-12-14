<?php

namespace Appwrite\Tests;

use Appwrite\Database\Validator\Key;
use PHPUnit\Framework\TestCase;

class KeyTest extends TestCase
{
    /**
     * @var Key
     */
    protected $object = null;

    public function setUp(): void
    {
        $this->object = new Key();
    }

    public function tearDown(): void
    {
    }

    public function testValues()
    {
        $this->assertEquals($this->object->isValid('with space'), false);
        $this->assertEquals($this->object->isValid('with_underscore'), true);
        $this->assertEquals($this->object->isValid('justtext'), true);
        $this->assertEquals($this->object->isValid('some$sign'), false);
        $this->assertEquals($this->object->isValid(false), false);
        $this->assertEquals($this->object->isValid(null), false);
        $this->assertEquals($this->object->isValid('socialAccountForYoutubeAndRestSubscribers'), false);
        $this->assertEquals($this->object->isValid('socialAccountForYoutubeAndRSubscriber'), false);
        $this->assertEquals($this->object->isValid('socialAccountForYoutubeSubscribe'), true);
        $this->assertEquals($this->object->isValid('socialAccountForYoutubeSubscrib'), true);
    }
}
