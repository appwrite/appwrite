<?php

namespace Tests\Utopia\Agents\Messages;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Message;

class TextTest extends TestCase
{
    public function testConstructor(): void
    {
        $content = 'Hello, world!';
        $message = new Message($content);

        $this->assertInstanceOf(Message::class, $message);
    }

    public function testGetContent(): void
    {
        $content = 'Test message content';
        $message = new Message($content);

        $this->assertSame($content, $message->getContent());
        $this->assertIsString($message->getContent());
    }

    public function testEmptyContent(): void
    {
        $message = new Message('');

        $this->assertSame('', $message->getContent());
        $this->assertIsString($message->getContent());
    }

    public function testMultilineContent(): void
    {
        $content = "Line 1\nLine 2\nLine 3";
        $message = new Message($content);

        $this->assertSame($content, $message->getContent());
        $this->assertStringContainsString("\n", $message->getContent());
    }

    public function testSpecialCharacters(): void
    {
        $content = 'Special chars: !@#$%^&*()_+ 😀 🌟';
        $message = new Message($content);

        $this->assertSame($content, $message->getContent());
    }
}
