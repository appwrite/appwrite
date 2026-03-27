<?php

namespace Tests\Unit\Filter;

use Appwrite\Filter\Name;
use PHPUnit\Framework\TestCase;

class NameTest extends TestCase
{
    private Name $filter;

    protected function setUp(): void
    {
        $this->filter = new Name();
    }

    public function testNonStringInput(): void
    {
        $this->assertSame(123, $this->filter->apply(123));
        $this->assertSame(null, $this->filter->apply(null));
        $this->assertSame(true, $this->filter->apply(true));
        $this->assertSame([], $this->filter->apply([]));
    }

    public function testPlainName(): void
    {
        $this->assertSame('John Doe', $this->filter->apply('John Doe'));
    }

    public function testHtmlTags(): void
    {
        $this->assertSame('John Doe', $this->filter->apply('<b>John</b> <script>alert(1)</script>Doe'));
        $this->assertSame('Hello', $this->filter->apply('<a href="http://evil.com">Hello</a>'));
    }

    public function testEmails(): void
    {
        $this->assertSame('John', $this->filter->apply('John user@example.com'));
        $this->assertSame('John Doe', $this->filter->apply('John test@mail.org Doe'));
    }

    public function testUrls(): void
    {
        $this->assertSame('John', $this->filter->apply('John http://example.com'));
        $this->assertSame('John', $this->filter->apply('John https://example.com'));
        $this->assertSame('Visit', $this->filter->apply('Visit www.example.com'));
        $this->assertSame('Visit', $this->filter->apply('Visit WWW.EXAMPLE.COM'));
    }

    public function testPhoneNumbers(): void
    {
        $this->assertSame('John', $this->filter->apply('John 1234567'));
        $this->assertSame('John', $this->filter->apply('John +1-234-567-8901'));
        $this->assertSame('Call', $this->filter->apply('Call 555-123-4567'));
    }

    public function testShortNumbersKept(): void
    {
        $this->assertSame('Agent 007', $this->filter->apply('Agent 007'));
        $this->assertSame('Room 404', $this->filter->apply('Room 404'));
    }

    public function testMaxLength(): void
    {
        $long = str_repeat('A', 50);
        $this->assertSame(str_repeat('A', 32), $this->filter->apply($long));
    }

    public function testCombined(): void
    {
        $this->assertSame('John', $this->filter->apply('<b>John</b> user@example.com https://evil.com +1234567890'));
    }

    public function testEmptyString(): void
    {
        $this->assertSame('', $this->filter->apply(''));
    }
}
