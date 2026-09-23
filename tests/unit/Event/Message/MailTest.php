<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Message;

use Appwrite\Event\Message\Mail as MailMessage;
use Appwrite\Event\Publisher\Mail as MailPublisher;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Queue\Queue;

final class MailTest extends TestCase
{
    public function testHeadersReachTheQueuedPayload(): void
    {
        $publisher = new MockPublisher();
        $queue = new Queue('v1-mails');

        (new MailPublisher($publisher, $queue))->enqueue(new MailMessage(
            recipient: 'owner@example.test',
            subject: 'Subject',
            platform: ['name' => 'test-platform'],
            headers: ['List-Unsubscribe' => '<https://example.test/u>'],
        ));

        // The mails worker reads this raw payload, not a rehydrated message.
        $payload = $publisher->getEvents('v1-mails')[0];
        $this->assertSame(['List-Unsubscribe' => '<https://example.test/u>'], $payload['headers']);
    }

    public function testPayloadsQueuedBeforeHeadersExistedStillDecode(): void
    {
        $restored = MailMessage::fromArray(['recipient' => 'owner@example.test']);

        $this->assertSame([], $restored->headers);
    }
}
