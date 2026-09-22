<?php

namespace Tests\Utopia\Agents;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Message;
use Utopia\Agents\Role;

class MessageTest extends TestCase
{
    public function testWithRoleCreatesCloneWithRequestedRole(): void
    {
        $message = new Message('hello');

        $withAssistantRole = $message->withRole(Role::ROLE_ASSISTANT);

        $this->assertNotSame($message, $withAssistantRole);
        $this->assertSame(Role::ROLE_USER, $message->getRole());
        $this->assertSame(Role::ROLE_ASSISTANT, $withAssistantRole->getRole());
    }

    public function testCanAddAndReadAttachments(): void
    {
        $message = new Message('describe this');
        $attachment = new Message(
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVQYV2NgYAAAAAMAAWgmWQ0AAAAASUVORK5CYII=')
        );

        $message->addAttachment($attachment);

        $this->assertTrue($message->hasAttachments());
        $this->assertCount(1, $message->getAttachments());
        $this->assertSame($attachment, $message->getAttachments()[0]);
    }

    public function testGetMimeTypeReturnsNullForEmptyContent(): void
    {
        $message = new Message('');
        $this->assertNull($message->getMimeType());
    }

    public function testGetMimeTypeDetectsTextPayload(): void
    {
        $message = new Message('hello world');
        $this->assertNotNull($message->getMimeType());
    }
}
