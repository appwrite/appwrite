<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Publisher\Notification as NotificationPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Platform\Workers\Webhooks;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

require_once __DIR__ . '/../../../../app/init.php';

final class WebhooksTest extends TestCase
{
    public function testSendAlertPublishesNotificationMessage(): void
    {
        $database = $this->createPlatformDatabase();
        $this->seedOwnerUser($database);

        $publisher = new MockPublisher();
        $publisherForNotifications = new NotificationPublisher($publisher, new Queue('v1-notifications'));
        $worker = new Webhooks();

        $worker->sendAlert(
            attempts: 10,
            statusCode: 500,
            webhook: new Document([
                '$id' => 'webhook-1',
                '$updatedAt' => '2026-01-01T00:00:00.000+00:00',
                'name' => 'Payments',
                'url' => 'https://example.test/webhook',
            ]),
            project: new Document([
                '$id' => 'project-1',
                '$sequence' => 'project-internal-1',
                'name' => 'Production',
                'teamInternalId' => 'team-internal-1',
                'region' => 'fra',
            ]),
            dbForPlatform: $database,
            publisherForNotifications: $publisherForNotifications,
            platform: ['consoleUrl' => 'https://console.example.test'],
            plan: []
        );

        $events = $publisher->getEvents('v1-notifications');

        $this->assertCount(1, $events);

        $payload = $events[0];
        $this->assertSame('project-1', $payload['project']['$id']);
        $this->assertSame('project-internal-1', $payload['project']['$sequence']);
        $this->assertSame('Webhook deliveries have been paused', $payload['subject']);
        $this->assertSame('Webhook "Payments" has been paused after 10 failed delivery attempts.', $payload['preview']);
        $this->assertSame('webhook:webhook-1:paused:2026-01-01T00:00:00.000+00:00', $payload['deduplicationKey']);
        $this->assertSame(
            \realpath(__DIR__ . '/../../../../app/config/locale/templates/email-base-styled.tpl'),
            \realpath($payload['bodyTemplate'])
        );
        $this->assertStringContainsString('Payments', (string) $payload['body']);
        $this->assertStringContainsString('Ada Lovelace', (string) $payload['body']);
        $this->assertStringContainsString('https://console.example.test/projects/project-1/settings/webhooks', (string) $payload['body']);
        $this->assertStringNotContainsString('{{', (string) $payload['body']);
        $this->assertSame(APP_NAME, $payload['variables']['platform']);
        $this->assertSame(APP_EMAIL_LOGO_URL, $payload['variables']['logoUrl']);
        $this->assertCount(2, $payload['recipients']);
        $this->assertSame([
            'address' => 'user-1',
            'channel' => NOTIFICATION_TYPE_CONSOLE,
            'resourceType' => RESOURCE_TYPE_USERS,
            'resourceId' => 'user-1',
            'resourceInternalId' => '101',
            'parentResourceType' => RESOURCE_TYPE_PROJECTS,
            'parentResourceId' => 'project-1',
            'parentResourceInternalId' => 'project-internal-1',
        ], $payload['recipients'][0]);
        $this->assertSame([
            'address' => 'owner@example.test',
            'channel' => NOTIFICATION_TYPE_EMAIL,
            'resourceType' => RESOURCE_TYPE_USERS,
            'resourceId' => 'user-1',
            'resourceInternalId' => '101',
            'parentResourceType' => RESOURCE_TYPE_PROJECTS,
            'parentResourceId' => 'project-1',
            'parentResourceInternalId' => 'project-internal-1',
        ], $payload['recipients'][1]);
    }

    public function testSendAlertPersonalizesBodyPerOwner(): void
    {
        $database = $this->createPlatformDatabase();
        $this->seedOwnerUser($database);
        $database->createDocument('memberships', new Document([
            '$id' => 'membership-3',
            'teamInternalId' => 'team-internal-1',
            'userId' => 'user-3',
            'roles' => 'owner',
        ]));
        $database->createDocument('users', new Document([
            '$id' => 'user-3',
            '$sequence' => 103,
            'email' => 'grace@example.test',
            'name' => 'Grace Hopper',
        ]));

        $publisher = new MockPublisher();
        $publisherForNotifications = new NotificationPublisher($publisher, new Queue('v1-notifications'));
        $worker = new Webhooks();

        $worker->sendAlert(
            attempts: 10,
            statusCode: 500,
            webhook: new Document([
                '$id' => 'webhook-1',
                '$updatedAt' => '2026-01-01T00:00:00.000+00:00',
                'name' => 'Payments',
                'url' => 'https://example.test/webhook',
            ]),
            project: new Document([
                '$id' => 'project-1',
                '$sequence' => 'project-internal-1',
                'name' => 'Production',
                'teamInternalId' => 'team-internal-1',
                'region' => 'fra',
            ]),
            dbForPlatform: $database,
            publisherForNotifications: $publisherForNotifications,
            platform: ['consoleUrl' => 'https://console.example.test'],
            plan: []
        );

        $events = $publisher->getEvents('v1-notifications');

        $this->assertCount(2, $events);

        $bodies = [];
        foreach ($events as $event) {
            foreach ($event['recipients'] as $recipient) {
                if ($recipient['channel'] === NOTIFICATION_TYPE_EMAIL) {
                    $bodies[$recipient['address']] = $event['body'];
                }
            }
        }

        $this->assertArrayHasKey('owner@example.test', $bodies);
        $this->assertArrayHasKey('grace@example.test', $bodies);
        $this->assertStringContainsString('Ada Lovelace', (string) $bodies['owner@example.test']);
        $this->assertStringNotContainsString('Grace Hopper', (string) $bodies['owner@example.test']);
        $this->assertStringContainsString('Grace Hopper', (string) $bodies['grace@example.test']);
        $this->assertStringNotContainsString('Ada Lovelace', (string) $bodies['grace@example.test']);
    }

    public function testSendAlertDeduplicationKeyChangesPerPauseCycle(): void
    {
        $database = $this->createPlatformDatabase();
        $this->seedOwnerUser($database);
        $publisher = new MockPublisher();
        $publisherForNotifications = new NotificationPublisher($publisher, new Queue('v1-notifications'));
        $worker = new Webhooks();
        $project = new Document([
            '$id' => 'project-1',
            '$sequence' => 'project-internal-1',
            'name' => 'Production',
            'teamInternalId' => 'team-internal-1',
            'region' => 'fra',
        ]);

        foreach (['2026-01-01T00:00:00.000+00:00', '2026-01-02T00:00:00.000+00:00'] as $updatedAt) {
            $worker->sendAlert(
                attempts: 10,
                statusCode: 500,
                webhook: new Document([
                    '$id' => 'webhook-1',
                    '$updatedAt' => $updatedAt,
                    'name' => 'Payments',
                    'url' => 'https://example.test/webhook',
                ]),
                project: $project,
                dbForPlatform: $database,
                publisherForNotifications: $publisherForNotifications,
                platform: ['consoleUrl' => 'https://console.example.test'],
                plan: []
            );
        }

        $events = $publisher->getEvents('v1-notifications');

        $this->assertCount(2, $events);
        $this->assertSame('webhook:webhook-1:paused:2026-01-01T00:00:00.000+00:00', $events[0]['deduplicationKey']);
        $this->assertSame('webhook:webhook-1:paused:2026-01-02T00:00:00.000+00:00', $events[1]['deduplicationKey']);
    }

    public function testFailedDeliveryIsRetriedWithoutBeingPublished(): void
    {
        $database = $this->createPlatformDatabase();
        $webhook = $database->createDocument('webhooks', new Document([
            '$id' => 'webhook-1',
            'name' => 'Welcome',
            'url' => 'https://receiver.example.invalid/webhook',
            'events' => ['users.*.create'],
            'enabled' => true,
            'security' => true,
            'signatureKey' => 'secret',
            'attempts' => 0,
        ]));
        $publisher = new MockPublisher();

        try {
            (new Webhooks())->action(
                message: new Message([
                    'pid' => 'pid-1',
                    'queue' => 'v1-webhooks',
                    'timestamp' => \time(),
                    'payload' => [
                        'events' => ['users.*.create'],
                        'payload' => ['$id' => 'user-1'],
                    ],
                ]),
                project: new Document([
                    '$id' => 'project-1',
                    '$sequence' => 'project-internal-1',
                    'teamInternalId' => 'team-internal-1',
                    'webhooks' => [$webhook],
                ]),
                dbForPlatform: $database,
                publisherForNotifications: new NotificationPublisher($publisher, new Queue('v1-notifications')),
                publisherForUsage: new UsagePublisher($publisher, new Queue('v1-usage')),
                platform: [],
                plan: [],
                clientForWebhooks: new Client(new CurlAdapter()),
                cache: new Cache(new NoCache()),
            );
            $this->fail('A failed delivery must throw so the broker delivers the event again');
        } catch (AppwriteException $error) {
            $this->assertSame(AppwriteException::WEBHOOK_DELIVERY_FAILED, $error->getType());
            $this->assertFalse($error->isPublishable());
        }

        $stored = $database->getDocument('webhooks', 'webhook-1');
        $this->assertSame(1, $stored->getAttribute('attempts'));
        $this->assertTrue($stored->getAttribute('enabled'));
        $this->assertStringContainsString('Client Error: ', (string) $stored->getAttribute('logs'));
    }

    private function createPlatformDatabase(): Database
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::any()->toString());

        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('webhookTests')
            ->setNamespace('webhook_' . \uniqid());

        $permissions = [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];

        $database->create();
        $database->createCollection(Collection::create(id: 'memberships', permissions: $permissions, documentSecurity: false));
        $database->createAttribute('memberships', Attribute::string(key: 'teamInternalId', required: true));
        $database->createAttribute('memberships', Attribute::string(key: 'userId', required: true));
        $database->createAttribute('memberships', Attribute::string(key: 'roles', size: 1024, required: true));
        $database->createCollection(Collection::create(id: 'users', permissions: $permissions, documentSecurity: false));
        $database->createAttribute('users', Attribute::string(key: 'email', size: 320));
        $database->createAttribute('users', Attribute::string(key: 'name', size: 256));
        $database->createCollection(Collection::create(id: 'webhooks', permissions: $permissions, documentSecurity: false));
        $database->createAttribute('webhooks', Attribute::string(key: 'name', size: 256));
        $database->createAttribute('webhooks', Attribute::string(key: 'url', size: 2048));
        $database->createAttribute('webhooks', Attribute::string(key: 'events', size: 256, array: true));
        $database->createAttribute('webhooks', Attribute::boolean(key: 'enabled'));
        $database->createAttribute('webhooks', Attribute::boolean(key: 'security'));
        $database->createAttribute('webhooks', Attribute::string(key: 'signatureKey', size: 2048));
        $database->createAttribute('webhooks', Attribute::integer(key: 'attempts'));
        $database->createAttribute('webhooks', Attribute::string(key: 'logs', size: 1000000));
        $database->createCollection(Collection::create(id: 'projects', permissions: $permissions, documentSecurity: false));

        return $database;
    }

    private function seedOwnerUser(Database $database): void
    {
        $database->createDocument('memberships', new Document([
            '$id' => 'membership-1',
            'teamInternalId' => 'team-internal-1',
            'userId' => 'user-1',
            'roles' => 'owner',
        ]));
        $database->createDocument('memberships', new Document([
            '$id' => 'membership-2',
            'teamInternalId' => 'team-internal-1',
            'userId' => 'user-2',
            'roles' => 'developer',
        ]));
        $database->createDocument('users', new Document([
            '$id' => 'user-1',
            '$sequence' => 101,
            'email' => 'owner@example.test',
            'name' => 'Ada Lovelace',
        ]));
        $database->createDocument('users', new Document([
            '$id' => 'user-2',
            '$sequence' => 102,
            'email' => 'developer@example.test',
            'name' => 'Developer User',
        ]));
    }
}
