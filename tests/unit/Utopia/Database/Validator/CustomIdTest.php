<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator;

use Appwrite\Utopia\Database\Validator\CustomId;
use PHPUnit\Framework\TestCase;

final class CustomIdTest extends TestCase
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
        $this->assertTrue($this->object->isValid('unique()'));
        $this->assertFalse($this->object->isValid('unique)'));
        $this->assertFalse($this->object->isValid('else()'));
        $this->assertFalse($this->object->isValid('dasda asdasd'));
        $this->assertTrue($this->object->isValid('dasda_asdasd'));
        $this->assertTrue($this->object->isValid('asdasdasdas'));
        $this->assertFalse($this->object->isValid('_asdasdasdas'));
        $this->assertFalse($this->object->isValid('as$$5dasdasdas'));
        $this->assertFalse($this->object->isValid(false));
        $this->assertFalse($this->object->isValid(null));
        $this->assertFalse($this->object->isValid('socialAccountForYoutubeAndRestSubscribers'));
        $this->assertFalse($this->object->isValid('socialAccountForYoutubeAndRSubscriber'));
        $this->assertTrue($this->object->isValid('socialAccountForYoutubeSubscribe'));
        $this->assertTrue($this->object->isValid('socialAccountForYoutubeSubscrib'));
    }
}
