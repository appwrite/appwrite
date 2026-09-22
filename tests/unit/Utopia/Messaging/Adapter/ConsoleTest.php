<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Messaging\Adapter;

use Appwrite\Utopia\Messaging\Adapter\Console;
use Appwrite\Utopia\Messaging\Messages\Console as ConsoleMessage;
use Appwrite\Utopia\Messaging\Messages\Webhook as WebhookMessage;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;

final class ConsoleTest extends TestCase
{
    private Database $database;
    private Authorization $authorization;

    protected function setUp(): void
    {
        $this->authorization = new Authorization();
        $this->authorization->addRole(Role::any()->toString());

        $this->database = new Database(new Memory(), new Cache(new NoCache()));
        $this->database
            ->setAuthorization($this->authorization)
            ->setDatabase('alertsTests')
            ->setNamespace('alerts_' . \uniqid());

        $this->database->create();
        $this->database->createCollection('notifications', [], [], [Permission::create(Role::any()), Permission::read(Role::any())], false);
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
        $this->database->createAttribute('notifications', 'firstSeen', Database::VAR_DATETIME, 0, false);
        $this->database->createAttribute('notifications', 'lastSeen', Database::VAR_DATETIME, 0, false);
    }

    protected function tearDown(): void
    {
        $this->authorization->cleanRoles();
        $this->authorization->addRole(Role::any()->toString());
    }

    /**
     * Mirrors the per-recipient `$id` derivation in
     * Appwrite\Utopia\Messaging\Adapter\Console::process().
     */
    private function alertId(string $messageId, string $address, string $resourceType, string $resourceId): string
    {
        $key = $address
            . ':' . $resourceType
            . ':' . $resourceId
            . ':' . $resourceId . '-internal'
            . ':projects:project-1:project-internal-1';
        return \substr($messageId, 0, 19) . '_' . \substr(\md5($key), 0, 16);
    }

    /**
     * @return array{address: string, resourceType: string, resourceId: string, resourceInternalId: string, parentResourceType: string, parentResourceId: string, parentResourceInternalId: string}
     */
    private function recipient(string $address, string $resourceId, string $resourceType = RESOURCE_TYPE_USERS): array
    {
        return [
            'address' => $address,
            'resourceType' => $resourceType,
            'resourceId' => $resourceId,
            'resourceInternalId' => $resourceId . '-internal',
            'parentResourceType' => RESOURCE_TYPE_PROJECTS,
            'parentResourceId' => 'project-1',
            'parentResourceInternalId' => 'project-internal-1',
        ];
    }

    public function testWritesAlertWithCorrectSchema(): void
    {
        $message = new ConsoleMessage(
            recipients: [$this->recipient('user-1', 'user-1')],
            title: 'Hello',
            body: 'World',
            type: 'info',
            messageId: ID::custom('msg-aaa'),
            projectId: 'project-1',
            projectInternalId: 'project-internal-1',
        );

        $adapter = new Console($this->database);
        $result = $adapter->send($message);

        $this->assertSame(1, $result['deliveredTo']);

        $stored = $this->database->getDocument('notifications', $this->alertId('msg-aaa', 'user-1', RESOURCE_TYPE_USERS, 'user-1'));
        $this->assertFalse($stored->isEmpty());
        $this->assertSame('msg-aaa', $stored->getAttribute('messageId'));
        $this->assertSame('console', $stored->getAttribute('channel'));
        $this->assertSame('project-1', $stored->getAttribute('projectId'));
        $this->assertSame('project-internal-1', $stored->getAttribute('projectInternalId'));
        $this->assertSame(RESOURCE_TYPE_USERS, $stored->getAttribute('resourceType'));
        $this->assertSame('user-1', $stored->getAttribute('resourceId'));
        $this->assertSame('user-1-internal', $stored->getAttribute('resourceInternalId'));
        $this->assertSame(RESOURCE_TYPE_PROJECTS, $stored->getAttribute('parentResourceType'));
        $this->assertSame('project-1', $stored->getAttribute('parentResourceId'));
        $this->assertSame('project-internal-1', $stored->getAttribute('parentResourceInternalId'));
        $this->assertSame('Hello', $stored->getAttribute('title'));
        $this->assertSame('World', $stored->getAttribute('body'));
        $this->assertSame('info', $stored->getAttribute('type'));
        $this->assertFalse($stored->getAttribute('read'));
        $this->assertNull($stored->getAttribute('firstSeen'));
        $this->assertNull($stored->getAttribute('lastSeen'));
    }

    public function testUserPermissionsScopedToRecipient(): void
    {
        $message = new ConsoleMessage(
            recipients: [$this->recipient('user-2', 'user-2')],
            title: 'Title',
            body: 'Body',
            messageId: ID::custom('msg-perms-user'),
            projectId: 'project-1',
            projectInternalId: 'project-internal-1',
        );

        (new Console($this->database))->send($message);

        $stored = $this->database->getDocument('notifications', $this->alertId('msg-perms-user', 'user-2', RESOURCE_TYPE_USERS, 'user-2'));
        $permissions = $stored->getPermissions();

        $this->assertContains(Permission::read(Role::user('user-2')), $permissions);
        $this->assertContains(Permission::update(Role::user('user-2')), $permissions);
        $this->assertContains(Permission::delete(Role::user('user-2')), $permissions);
    }

    public function testTeamRecipientGrantsTeamReadAndOwnerWrite(): void
    {
        $message = new ConsoleMessage(
            recipients: [$this->recipient('team-9', 'team-9', RESOURCE_TYPE_TEAMS)],
            title: 'Heads up',
            body: '...',
            messageId: ID::custom('msg-team'),
            projectId: 'project-1',
            projectInternalId: 'project-internal-1',
        );

        (new Console($this->database))->send($message);

        $stored = $this->database->getDocument('notifications', $this->alertId('msg-team', 'team-9', RESOURCE_TYPE_TEAMS, 'team-9'));
        $permissions = $stored->getPermissions();

        $this->assertContains(Permission::read(Role::team('team-9')), $permissions);
        $this->assertContains(Permission::update(Role::team('team-9', 'owner')), $permissions);
        $this->assertContains(Permission::delete(Role::team('team-9', 'owner')), $permissions);
        $this->assertContains(Permission::read(Role::team('team-9', 'project-project-1-owner')), $permissions);
        $this->assertContains(Permission::update(Role::team('team-9', 'project-project-1-owner')), $permissions);
        $this->assertContains(Permission::delete(Role::team('team-9', 'project-project-1-owner')), $permissions);
    }

    public function testMultiRecipientWithSameMessageIdGeneratesDistinctIds(): void
    {
        $message = new ConsoleMessage(
            recipients: [
                $this->recipient('a', 'a'),
                $this->recipient('b', 'b'),
            ],
            title: 'Heads up',
            body: 'multi',
            messageId: ID::custom('same-msg'),
            projectId: 'project-1',
            projectInternalId: 'project-internal-1',
        );

        $adapter = new Console($this->database);
        $result = $adapter->send($message);

        $this->assertSame(2, $result['deliveredTo']);

        $idA = $this->alertId('same-msg', 'a', RESOURCE_TYPE_USERS, 'a');
        $idB = $this->alertId('same-msg', 'b', RESOURCE_TYPE_USERS, 'b');

        $this->assertNotSame($idA, $idB, 'recipient suffixes must be distinct');

        $rowA = $this->database->getDocument('notifications', $idA);
        $rowB = $this->database->getDocument('notifications', $idB);
        $this->assertFalse($rowA->isEmpty(), 'first recipient row must exist');
        $this->assertFalse($rowB->isEmpty(), 'second recipient row must exist');

        $this->assertSame('same-msg', $rowA->getAttribute('messageId'));
        $this->assertSame('same-msg', $rowB->getAttribute('messageId'));
        $this->assertSame('a', $rowA->getAttribute('resourceId'));
        $this->assertSame('b', $rowB->getAttribute('resourceId'));

        $this->assertMatchesRegularExpression('/^same-msg_[0-9a-f]{16}$/', $idA);
        $this->assertMatchesRegularExpression('/^same-msg_[0-9a-f]{16}$/', $idB);
    }

    public function testRejectsForeignMessageType(): void
    {
        $adapter = new Console($this->database);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid message type.');

        // ConsoleMessage extends nothing; pass an unrelated Message implementation
        $adapter->send(new WebhookMessage(urls: ['https://example.test'], payload: []));
    }

    /**
     * Reviewer C4: a `DuplicateException` thrown by `createDocument` must be
     * treated as a SUCCESSFUL delivery, not a failure. The adapter previously
     * lumped Duplicate into the generic Throwable catch, which surfaced as a
     * per-recipient `error` and caused the worker to throw, re-queueing the
     * notification and never marking the duplicate as delivered.
     */
    public function testConsoleAdapterTreatsDuplicateAsDelivered(): void
    {
        $userId = 'user-dup';
        $messageId = 'msg-dup';
        $documentId = $this->alertId($messageId, $userId, RESOURCE_TYPE_USERS, $userId);

        // Pre-insert an alert with the SAME id the adapter will compute. The
        // adapter's createDocument will hit the primary-key DuplicateException
        // and must treat it as a successful (idempotent) send.
        $this->database->createDocument('notifications', new Document([
            '$id' => $documentId,
            '$permissions' => [Permission::read(Role::any())],
            'messageId' => $messageId,
            'recipientHash' => \substr(\md5($userId . ':' . RESOURCE_TYPE_USERS . ':' . $userId . ':' . $userId . '-internal:projects:project-1:project-internal-1'), 0, 16),
            'channel' => 'console',
            'projectId' => 'project-x',
            'projectInternalId' => 'project-internal-x',
            'resourceType' => RESOURCE_TYPE_USERS,
            'resourceId' => $userId,
            'resourceInternalId' => $userId . '-internal',
            'parentResourceType' => RESOURCE_TYPE_PROJECTS,
            'parentResourceId' => 'project-1',
            'parentResourceInternalId' => 'project-internal-1',
            'title' => 'pre-existing',
            'body' => 'pre-existing',
        ]));

        $message = new ConsoleMessage(
            recipients: [$this->recipient($userId, $userId)],
            title: 'Same alert resent',
            body: 'b',
            messageId: ID::custom($messageId),
            projectId: 'project-x',
            projectInternalId: 'project-internal-x',
        );

        $adapter = new Console($this->database);
        $result = $adapter->send($message);

        $this->assertSame(1, $result['deliveredTo'], 'duplicate must count as a successful delivery');
        $this->assertCount(1, $result['results']);
        $this->assertSame('success', $result['results'][0]['status'] ?? '', 'duplicate must report success status');
        $this->assertSame('', $result['results'][0]['error'] ?? 'unset', 'duplicate must not surface a per-recipient error');

        // Still exactly ONE row: the pre-existing one. The adapter must not
        // overwrite it nor create a sibling.
        $rows = $this->database->find('notifications');
        $this->assertCount(1, $rows);
        $this->assertSame($documentId, $rows[0]->getId());
        $this->assertSame('pre-existing', $rows[0]->getAttribute('title'), 'duplicate path must not overwrite existing row');
    }
}
