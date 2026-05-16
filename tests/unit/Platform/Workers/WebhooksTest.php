<?php

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

class WebhooksTest extends TestCase
{
    public function testSendAlertPublishesNotificationMessage(): void
    {
        $database = $this->createPlatformDatabase();
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
        ]));
        $database->createDocument('users', new Document([
            '$id' => 'user-2',
            '$sequence' => 102,
            'email' => 'developer@example.test',
        ]));

        $publisher = new MockPublisher();
        $publisherForNotifications = new NotificationPublisher($publisher, new Queue('v1-notifications'));
        $worker = new Webhooks();

        $worker->sendAlert(
            attempts: 10,
            statusCode: 500,
            webhook: new Document([
                '$id' => 'webhook-1',
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
        $this->assertSame('Webhook deliveries to your endpoint have been paused.', $payload['preview']);
        $this->assertSame('webhook:webhook-1:paused:10', $payload['deduplicationKey']);
        $this->assertSame(
            \realpath(__DIR__ . '/../../../../app/config/locale/templates/email-base-styled.tpl'),
            \realpath($payload['bodyTemplate'])
        );
        $this->assertStringContainsString('Payments', $payload['body']);
        $this->assertStringContainsString('/console/project-fra-project-1/settings/webhooks/webhook-1', $payload['body']);
        $this->assertStringNotContainsString('{{', $payload['body']);
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

    #[DataProvider('ownerRoleProvider')]
    public function testOwnerRoleDetectionAcceptsArrayAndLegacyStringRoles(mixed $roles, bool $expected): void
    {
        $method = new \ReflectionMethod(Webhooks::class, 'hasOwnerRole');
        $membership = new Document([
            '$id' => 'membership-1',
            'roles' => $roles,
        ]);

        $this->assertSame($expected, $method->invoke(null, $membership, 'project-1'));
    }

    public static function ownerRoleProvider(): array
    {
        return [
            'array owner' => [['owner'], true],
            'array mixed case owner' => [['Owner'], true],
            'project owner' => [['project-project-1-owner'], true],
            'mixed case project owner' => [['Project-Project-1-Owner'], true],
            'comma string owner' => ['developer, owner', true],
            'comma string project owner' => ['developer, project-project-1-owner', true],
            'other project owner' => [['project-project-2-owner'], false],
            'non owner' => [['developer'], false],
            'invalid roles' => [null, false],
        ];
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

        return $database;
    }
}
