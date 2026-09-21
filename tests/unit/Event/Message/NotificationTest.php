<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Message;

use Appwrite\Event\Message\Notification as NotificationMessage;
use Appwrite\Event\Publisher\Notification as NotificationPublisher;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Database\Document;
use Utopia\Queue\Queue;

require_once __DIR__ . '/../../../../app/init.php';

final class NotificationTest extends TestCase
{
    public function testNotificationMessageSerializesPublisherPayload(): void
    {
        $message = new NotificationMessage(
            project: new Document([
                '$id' => 'project-1',
                '$sequence' => 'project-internal-1',
                'database' => 'mysql://database',
            ]),
            recipient: 'owner@example.test',
            subject: 'Subject',
            body: 'Body',
            preview: 'Preview',
            event: 'databases.[databaseId].collections.[collectionId].documents.[documentId].create',
            params: [
                'databaseId' => 'database-1',
                'collectionId' => 'collection-1',
                'documentId' => 'document-1',
            ],
            platform: ['name' => 'test-platform'],
        );

        $payload = $message->toArray();

        $this->assertSame([
            '$id' => 'project-1',
            '$sequence' => 'project-internal-1',
            'database' => 'mysql://database',
        ], $payload['project']);
        $this->assertSame([
            [
                'address' => 'owner@example.test',
                'channel' => NOTIFICATION_TYPE_EMAIL,
            ],
        ], $payload['recipients']);
        $this->assertSame('Subject', $payload['subject']);
        $this->assertSame('Body', $payload['body']);
        $this->assertSame('Preview', $payload['preview']);
        $this->assertContains(
            'databases.database-1.collections.collection-1.documents.document-1.create',
            $payload['events']
        );
        $this->assertSame(['name' => 'test-platform'], $payload['platform']);

        $this->assertSame($payload, NotificationMessage::fromArray($payload)->toArray());
    }

    public function testNotificationPublisherUsesConfiguredQueue(): void
    {
        $publisher = new MockPublisher();
        $publisherForNotifications = new NotificationPublisher($publisher, new Queue('custom-notifications'));

        $publisherForNotifications->enqueue(new NotificationMessage(
            project: new Document(['$id' => 'project-1']),
            recipients: [
                [
                    'address' => 'user-1',
                    'channel' => NOTIFICATION_TYPE_CONSOLE,
                ],
            ],
            subject: 'Alert',
        ));

        $events = $publisher->getEvents('custom-notifications');

        $this->assertCount(1, $events);
        $this->assertSame('Alert', $events[0]['subject']);
        $this->assertSame(1, $publisherForNotifications->getSize());
    }
}
