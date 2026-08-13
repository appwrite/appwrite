<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\SMTP\Capabilities;
use Utopia\SMTP\Reply;

final class CapabilitiesTest extends TestCase
{
    private function parse(string ...$lines): Capabilities
    {
        return Capabilities::fromReply(new Reply(250, array_values(['mail.example.test', ...$lines])));
    }

    public function testIgnoresTheGreetingLine(): void
    {
        $this->assertFalse($this->parse('PIPELINING')->has('mail.example.test'));
    }

    public function testReadsKeywordsAndParameters(): void
    {
        $capabilities = $this->parse('PIPELINING', 'AUTH PLAIN LOGIN');

        $this->assertTrue($capabilities->has('PIPELINING'));
        $this->assertSame(['PLAIN', 'LOGIN'], $capabilities->mechanisms());
    }

    public function testAcceptsTheEqualsFormSomeServersUse(): void
    {
        $this->assertSame(['PLAIN', 'LOGIN'], $this->parse('AUTH=PLAIN LOGIN')->mechanisms());
    }

    public function testKeywordsAreCaseInsensitive(): void
    {
        $this->assertTrue($this->parse('StartTls')->has('STARTTLS'));
    }

    public function testReadsTheSizeLimit(): void
    {
        $this->assertSame(35_882_577, $this->parse('SIZE 35882577')->maxSize());
    }

    public function testTreatsAZeroSizeAsNoLimit(): void
    {
        $this->assertNull($this->parse('SIZE 0')->maxSize());
    }

    public function testTreatsAMissingSizeAsNoLimit(): void
    {
        $this->assertNull($this->parse('PIPELINING')->maxSize());
    }

    public function testNoneAdvertisesNothing(): void
    {
        $this->assertFalse(Capabilities::none()->has('STARTTLS'));
        $this->assertSame([], Capabilities::none()->mechanisms());
    }
}
