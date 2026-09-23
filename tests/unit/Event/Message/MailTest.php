<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Message;

use Appwrite\Event\Message\Mail as MailMessage;
use PHPUnit\Framework\TestCase;

final class MailTest extends TestCase
{
    public function testHeadersSurviveTheQueue(): void
    {
        $message = new MailMessage(
            recipient: 'owner@example.test',
            subject: 'Subject',
            platform: ['name' => 'test-platform'],
            headers: ['List-Unsubscribe' => '<https://example.test/u>'],
        );

        $restored = MailMessage::fromArray($message->toArray());

        $this->assertSame(['List-Unsubscribe' => '<https://example.test/u>'], $restored->headers);
    }

    public function testPayloadsQueuedBeforeHeadersExistedStillDecode(): void
    {
        $restored = MailMessage::fromArray(['recipient' => 'owner@example.test']);

        $this->assertSame([], $restored->headers);
    }
}
