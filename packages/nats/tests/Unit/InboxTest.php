<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Inbox;

final class InboxTest extends TestCase
{
    public function testCreateWithDefaultPrefix(): void
    {
        $inbox = Inbox::create();
        $this->assertStringStartsWith('_INBOX.', $inbox);
        $this->assertSame(29, \strlen($inbox)); // "_INBOX." (7) + 22 chars
    }

    public function testCreateWithCustomPrefix(): void
    {
        $inbox = Inbox::create('MY_INBOX');
        $this->assertStringStartsWith('MY_INBOX.', $inbox);
    }

    public function testCreateUnique(): void
    {
        $inbox1 = Inbox::create();
        $inbox2 = Inbox::create();
        $this->assertNotSame($inbox1, $inbox2);
    }

    public function testGenerateId(): void
    {
        $id = Inbox::generateId();
        $this->assertSame(22, \strlen($id));
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]+$/', $id);
    }
}
