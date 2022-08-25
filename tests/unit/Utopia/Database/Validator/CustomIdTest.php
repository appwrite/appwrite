<?php

namespace Appwrite\Tests;

use Appwrite\Utopia\Database\Validator\CustomId;
use PHPUnit\Framework\TestCase;

class CustomIdTest extends TestCase
{
    /**
     * @var Key
     */
    protected $object = null;

    public function setUp(): void
    {
        $this->object = new CustomId();
    }

    public function tearDown(): void
    {
    }

    public function testValues()
    {
        $this->assertEquals(true, $this->object->isValid('unique()'));
        $this->assertEquals(false, $this->object->isValid('unique)'));
        $this->assertEquals(false, $this->object->isValid('else()'));
        $this->assertEquals(false, $this->object->isValid('dasda asdasd'));
        $this->assertEquals(true, $this->object->isValid('dasda_asdasd'));
        $this->assertEquals(true, $this->object->isValid('asdasdasdas'));
        $this->assertEquals(false, $this->object->isValid('_asdasdasdas'));
        $this->assertEquals(false, $this->object->isValid('as$$5dasdasdas'));
        $this->assertEquals(false, $this->object->isValid(false));
        $this->assertEquals(false, $this->object->isValid(null));
        $this->assertEquals(false, $this->object->isValid('socialAccountForYoutubeAndRestSubscribers'));
        $this->assertEquals(false, $this->object->isValid('socialAccountForYoutubeAndRSubscriber'));
        $this->assertEquals(true, $this->object->isValid('socialAccountForYoutubeSubscribe'));
        $this->assertEquals(true, $this->object->isValid('socialAccountForYoutubeSubscrib'));
    }
}
