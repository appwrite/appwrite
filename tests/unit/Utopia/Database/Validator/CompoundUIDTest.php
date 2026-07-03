<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator;

use Appwrite\Utopia\Database\Validator\CompoundUID;
use PHPUnit\Framework\TestCase;

final class CompoundUIDTest extends TestCase
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
        $this->assertTrue($this->object->isValid('123:456'));
        $this->assertFalse($this->object->isValid('123'));
        $this->assertFalse($this->object->isValid('123:_456'));
        $this->assertFalse($this->object->isValid('dasda asdasd'));
        $this->assertTrue($this->object->isValid('dasda:asdasd'));
        $this->assertFalse($this->object->isValid('_asdas:dasdas'));
        $this->assertFalse($this->object->isValid('as$$5da:sdasdas'));
        $this->assertFalse($this->object->isValid(false));
        $this->assertFalse($this->object->isValid(null));
        $this->assertFalse($this->object->isValid('socialAccountForYoutubeAndRestSubscribers:12345'));
        $this->assertFalse($this->object->isValid('socialAccountForYoutubeAndRSubscriber:12345'));
        $this->assertTrue($this->object->isValid('socialAccount:ForYoutubeSubscribe'));
        $this->assertTrue($this->object->isValid('socialAccountForYoutubeSubscribe:socialAccountForYoutubeSubscribe'));
    }
}
