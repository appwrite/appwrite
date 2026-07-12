<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Publisher\Notification as NotificationPublisher;
use Appwrite\Platform\Workers\Webhooks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
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
        $this->assertStringContainsString('/projects/project-1/settings/webhooks', (string) $payload['body']);
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
                plan: []
            );
        }

        $events = $publisher->getEvents('v1-notifications');

        $this->assertCount(2, $events);
        $this->assertSame('webhook:webhook-1:paused:2026-01-01T00:00:00.000+00:00', $events[0]['deduplicationKey']);
        $this->assertSame('webhook:webhook-1:paused:2026-01-02T00:00:00.000+00:00', $events[1]['deduplicationKey']);
    }

    #[DataProvider('ownerRoleProvider')]
    public function testOwnerRoleDetectionAcceptsArrayAndCommaStringRoles(mixed $roles, bool $expected): void
    {
        $method = new \ReflectionMethod(Webhooks::class, 'hasOwnerRole');
        $membership = new Document([
            '$id' => 'membership-1',
            'roles' => $roles,
        ]);

        $this->assertSame($expected, $method->invoke(null, $membership));
    }

    public static function ownerRoleProvider(): \Iterator
    {
        yield 'array owner' => [['owner'], true];
        yield 'array mixed case owner' => [['Owner'], true];
        yield 'comma string owner' => ['developer, owner', true];
        yield 'project-scoped owner string not recognized' => [['project-project-1-owner'], false];
        yield 'mixed case project-scoped owner string not recognized' => [['Project-Project-1-Owner'], false];
        yield 'comma string project-scoped owner not recognized' => ['developer, project-project-1-owner', false];
        yield 'other project owner' => [['project-project-2-owner'], false];
        yield 'non owner' => [['developer'], false];
        yield 'invalid roles' => [null, false];
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
        $database->createCollection('memberships', [], [], $permissions, false);
        $database->createAttribute('memberships', 'teamInternalId', Database::VAR_STRING, 255, true);
        $database->createAttribute('memberships', 'userId', Database::VAR_STRING, 255, true);
        $database->createAttribute('memberships', 'roles', Database::VAR_STRING, 1024, true);
        $database->createCollection('users', [], [], $permissions, false);
        $database->createAttribute('users', 'email', Database::VAR_STRING, 320, false);
        $database->createAttribute('users', 'name', Database::VAR_STRING, 256, false);

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
