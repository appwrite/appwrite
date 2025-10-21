<?php

namespace Tests\Unit\Utopia\Database\Validator;

use Appwrite\Utopia\Database\Validator\CompoundUID;
use PHPUnit\Framework\TestCase;

class CompoundUIDTest extends TestCase
{
    protected ?CompoundUID $object = null;

    public function setUp(): void
    {
        $this->object = new CompoundUID();
    }

    public function tearDown(): void
    {
    }

    public function testValues(): void
    {
        $this->assertEquals($this->object->isValid('123:456'), true);
        $this->assertEquals($this->object->isValid('123'), false);
        $this->assertEquals($this->object->isValid('123:_456'), false);
        $this->assertEquals($this->object->isValid('dasda asdasd'), false);
        $this->assertEquals($this->object->isValid('dasda:asdasd'), true);
        $this->assertEquals($this->object->isValid('_asdas:dasdas'), false);
        $this->assertEquals($this->object->isValid('as$$5da:sdasdas'), false);
        $this->assertEquals($this->object->isValid(false), false);
        $this->assertEquals($this->object->isValid(null), false);
        $this->assertEquals($this->object->isValid('socialAccountForYoutubeAndRestSubscribers:12345'), false);
        $this->assertEquals($this->object->isValid('socialAccountForYoutubeAndRSubscriber:12345'), false);
        $this->assertEquals($this->object->isValid('socialAccount:ForYoutubeSubscribe'), true);
        $this->assertEquals($this->object->isValid('socialAccountForYoutubeSubscribe:socialAccountForYoutubeSubscribe'), true);
    }
}
