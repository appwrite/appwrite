<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator;

use Appwrite\Utopia\Database\Validator\Folder;
use PHPUnit\Framework\TestCase;

final class FolderTest extends TestCase
{
    protected ?Folder $object = null;

    public function setUp(): void
    {
        $this->object = new Folder();
    }

    public function testValues(): void
    {
        // valid
        $this->assertTrue($this->object->isValid(''));
        $this->assertTrue($this->object->isValid('photos'));
        $this->assertTrue($this->object->isValid('photos/'));
        $this->assertTrue($this->object->isValid('photos/2026'));
        $this->assertTrue($this->object->isValid('photos/2026/'));
        $this->assertTrue($this->object->isValid('photos/2026/july'));
        $this->assertTrue($this->object->isValid('with space/and-dash_underscore.dot'));
        $this->assertTrue($this->object->isValid(\str_repeat('a', 2047) . '/'));

        // invalid
        $this->assertFalse($this->object->isValid(null));
        $this->assertFalse($this->object->isValid(false));
        $this->assertFalse($this->object->isValid(123));
        $this->assertFalse($this->object->isValid('/'));
        $this->assertFalse($this->object->isValid('/photos'));
        $this->assertFalse($this->object->isValid('photos//2026'));
        $this->assertFalse($this->object->isValid('photos/./2026'));
        $this->assertFalse($this->object->isValid('photos/../2026'));
        $this->assertFalse($this->object->isValid('..'));
        $this->assertFalse($this->object->isValid('.'));
        $this->assertFalse($this->object->isValid("photos/\x01"));
        $this->assertFalse($this->object->isValid("photos\n"));
        // 2048 chars without trailing slash normalizes to 2049 -- too long
        $this->assertFalse($this->object->isValid(\str_repeat('a', 2048)));
        $this->assertFalse($this->object->isValid(\str_repeat('a', 3000)));
    }

    public function testNormalize(): void
    {
        $this->assertEquals('', Folder::normalize(''));
        $this->assertEquals('photos/', Folder::normalize('photos'));
        $this->assertEquals('photos/', Folder::normalize('photos/'));
        $this->assertEquals('photos/2026/', Folder::normalize('photos/2026'));
    }
}
