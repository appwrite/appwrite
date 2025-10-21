<?php

namespace Tests\Unit\Utopia\Database\Validator;

use Appwrite\Utopia\Database\Validator\CustomId;
use PHPUnit\Framework\TestCase;

class CustomIdTest extends TestCase
{
    protected ?CustomId $object = null;

    public function setUp(): void
    {
        $this->object = new CustomId();
    }

    public function tearDown(): void
    {
    }

    public function testValues(): void
    {
        $this->assertEquals($this->object->isValid('unique()'), true);
        $this->assertEquals($this->object->isValid('unique)'), false);
        $this->assertEquals($this->object->isValid('else()'), false);
        $this->assertEquals($this->object->isValid('dasda asdasd'), false);
        $this->assertEquals($this->object->isValid('dasda_asdasd'), true);
        $this->assertEquals($this->object->isValid('asdasdasdas'), true);
        $this->assertEquals($this->object->isValid('_asdasdasdas'), false);
        $this->assertEquals($this->object->isValid('as$$5dasdasdas'), false);
        $this->assertEquals($this->object->isValid(false), false);
        $this->assertEquals($this->object->isValid(null), false);
        $this->assertEquals($this->object->isValid('socialAccountForYoutubeAndRestSubscribers'), false);
        $this->assertEquals($this->object->isValid('socialAccountForYoutubeAndRSubscriber'), false);
        $this->assertEquals($this->object->isValid('socialAccountForYoutubeSubscribe'), true);
        $this->assertEquals($this->object->isValid('socialAccountForYoutubeSubscrib'), true);
    }
}
