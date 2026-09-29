<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Headers;

final class HeadersTest extends TestCase
{
    public function testSetAndGet(): void
    {
        $h = new Headers();
        $h->set('X-Foo', 'bar');
        $this->assertSame('bar', $h->get('X-Foo'));
    }

    public function testAddMultipleValues(): void
    {
        $h = new Headers();
        $h->add('X-Multi', 'a');
        $h->add('X-Multi', 'b');
        $this->assertSame('a', $h->get('X-Multi'));
        $this->assertSame(['a', 'b'], $h->getAll('X-Multi'));
    }

    public function testSetOverwrites(): void
    {
        $h = new Headers();
        $h->add('X-Key', 'a');
        $h->add('X-Key', 'b');
        $h->set('X-Key', 'c');
        $this->assertSame(['c'], $h->getAll('X-Key'));
    }

    public function testHas(): void
    {
        $h = new Headers();
        $this->assertFalse($h->has('X-Missing'));
        $h->set('X-Present', 'yes');
        $this->assertTrue($h->has('X-Present'));
    }

    public function testDelete(): void
    {
        $h = new Headers();
        $h->set('X-Del', 'val');
        $h->delete('X-Del');
        $this->assertFalse($h->has('X-Del'));
    }

    public function testCount(): void
    {
        $h = new Headers();
        $this->assertCount(0, $h);
        $h->set('A', '1');
        $h->set('B', '2');
        $this->assertCount(2, $h);
    }

    public function testToWire(): void
    {
        $h = new Headers();
        $h->set('X-Key', 'value');
        $h->set('Content-Type', 'text/plain');

        $wire = $h->toWire();
        $this->assertStringStartsWith("NATS/1.0\r\n", $wire);
        $this->assertStringContainsString("X-Key: value\r\n", $wire);
        $this->assertStringContainsString("Content-Type: text/plain\r\n", $wire);
        $this->assertStringEndsWith("\r\n\r\n", $wire);
    }

    public function testToWireWithStatus(): void
    {
        $h = new Headers();
        $h->setStatus('503', 'No Responders');

        $wire = $h->toWire();
        $this->assertStringStartsWith("NATS/1.0 503 No Responders\r\n", $wire);
    }

    public function testFromWire(): void
    {
        $wire = "NATS/1.0\r\nX-Key: value\r\nAnother: test\r\n\r\n";
        $h = Headers::fromWire($wire);

        $this->assertSame('value', $h->get('X-Key'));
        $this->assertSame('test', $h->get('Another'));
        $this->assertSame('', $h->getStatus());
    }

    public function testFromWireWithStatus(): void
    {
        $wire = "NATS/1.0 503 No Responders\r\n\r\n";
        $h = Headers::fromWire($wire);

        $this->assertSame('503', $h->getStatus());
        $this->assertSame('No Responders', $h->getDescription());
    }

    public function testFromWireWithStatusOnly(): void
    {
        $wire = "NATS/1.0 408\r\n\r\n";
        $h = Headers::fromWire($wire);

        $this->assertSame('408', $h->getStatus());
        $this->assertSame('', $h->getDescription());
    }

    public function testRoundTrip(): void
    {
        $original = new Headers();
        $original->set('Nats-Msg-Id', 'abc-123');
        $original->set('X-Custom', 'test');

        $wire = $original->toWire();
        $parsed = Headers::fromWire($wire);

        $this->assertSame('abc-123', $parsed->get('Nats-Msg-Id'));
        $this->assertSame('test', $parsed->get('X-Custom'));
    }
}
