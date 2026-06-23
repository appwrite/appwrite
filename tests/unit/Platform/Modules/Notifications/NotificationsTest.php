<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Notifications;

use Appwrite\Platform\Modules\Notifications\Http\Notifications\XList;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;

require_once __DIR__ . '/../../../../../app/init.php';

final class CapturingNotificationsResponse extends Response
{
    public Document $document;
    public string $model = '';

    public function __construct()
    {
    }

    public function dynamic(Document $document, string $model): void
    {
        $this->document = $document;
        $this->model = $model;
    }
}

class NotificationsTest extends TestCase
{
    private Authorization $authorization;
    private Database $database;

    protected function setUp(): void
    {
        $this->authorization = new Authorization();
        $this->authorization->addRole(Role::any()->toString());

        $this->database = new Database(new Memory(), new Cache(new NoCache()));
        $this->database
            ->setAuthorization($this->authorization)
            ->setDatabase('notificationEndpointTests')
            ->setNamespace('notifications_' . \uniqid());

        $permissions = [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];

        $this->database->create();
        $this->database->createCollection('notifications', [], [], $permissions, false);
        $this->database->createAttribute('notifications', 'messageId', Database::VAR_STRING, 255, false);
        $this->database->createAttribute('notifications', 'recipientHash', Database::VAR_STRING, 64, true);
        $this->database->createAttribute('notifications', 'type', Database::VAR_STRING, 64, false, 'info');
        $this->database->createAttribute('notifications', 'channel', Database::VAR_STRING, 64, true);
        $this->database->createAttribute('notifications', 'projectId', Database::VAR_STRING, 255, true);
        $this->database->createAttribute('notifications', 'projectInternalId', Database::VAR_ID, 0, true);
        $this->database->createAttribute('notifications', 'resourceType', Database::VAR_STRING, 64, true);
        $this->database->createAttribute('notifications', 'resourceId', Database::VAR_STRING, 255, true);
        $this->database->createAttribute('notifications', 'resourceInternalId', Database::VAR_ID, 0, true);
        $this->database->createAttribute('notifications', 'parentResourceType', Database::VAR_STRING, 64, true);
        $this->database->createAttribute('notifications', 'parentResourceId', Database::VAR_STRING, 255, true);
        $this->database->createAttribute('notifications', 'parentResourceInternalId', Database::VAR_ID, 0, true);
        $this->database->createAttribute('notifications', 'title', Database::VAR_STRING, 256, true);
        $this->database->createAttribute('notifications', 'body', Database::VAR_STRING, 16384, true);
        $this->database->createAttribute('notifications', 'read', Database::VAR_BOOLEAN, 0, false, false);
    }

    protected function tearDown(): void
    {
        $this->authorization->cleanRoles();
        $this->authorization->addRole(Role::any()->toString());
    }

    public function testListReturnsOnlyCurrentUserNotifications(): void
    {
        $this->createNotification('user-alert', RESOURCE_TYPE_USERS, 'user-a');
        $this->createNotification('team-alert', RESOURCE_TYPE_TEAMS, 'team-a');
        $this->createNotification('user-email-receipt', RESOURCE_TYPE_USERS, 'user-a', NOTIFICATION_TYPE_EMAIL);

        $response = new CapturingNotificationsResponse();

        (new XList())->action(
            [],
            $response,
            $this->database,
            new Document(['$id' => 'console']),
            new Document(['$id' => 'user-a']),
        );

        $notifications = $response->document->getAttribute('notifications');

        $this->assertCount(1, $notifications);
        $this->assertSame('user-alert', $notifications[0]->getId());
        $this->assertSame(1, $response->document->getAttribute('total'));
        $this->assertSame(Response::MODEL_NOTIFICATION_LIST, $response->model);
    }

    private function createNotification(string $id, string $resourceType, string $resourceId, string $channel = NOTIFICATION_TYPE_CONSOLE): Document
    {
        return $this->database->createDocument('notifications', new Document([
            '$id' => $id,
            'messageId' => $id,
            'recipientHash' => \substr(\md5($id), 0, 16),
            'type' => 'info',
            'channel' => $channel,
            'projectId' => 'project',
            'projectInternalId' => '10',
            'resourceType' => $resourceType,
            'resourceId' => $resourceId,
            'resourceInternalId' => '20',
            'parentResourceType' => RESOURCE_TYPE_PROJECTS,
            'parentResourceId' => 'project',
            'parentResourceInternalId' => '10',
            'title' => 'Title',
            'body' => 'Body',
            'read' => false,
        ]));
    }
}
