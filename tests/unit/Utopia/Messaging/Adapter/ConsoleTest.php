<?php

namespace Tests\Unit\Utopia\Messaging\Adapter;

use Appwrite\Utopia\Messaging\Adapter\Console;
use Appwrite\Utopia\Messaging\Messages\Console as ConsoleMessage;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;

class ConsoleTest extends TestCase
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
        $this->database->createCollection('alerts', [], [], [Permission::create(Role::any()), Permission::read(Role::any())], false);
        $this->database->createAttribute('alerts', 'messageId', Database::VAR_STRING, 255, false);
        $this->database->createAttribute('alerts', 'recipientHash', Database::VAR_STRING, 64, true);
        $this->database->createAttribute('alerts', 'type', Database::VAR_STRING, 64, false, 'info');
        $this->database->createAttribute('alerts', 'channel', Database::VAR_STRING, 64, true);
        $this->database->createAttribute('alerts', 'userId', Database::VAR_STRING, 255, false);
        $this->database->createAttribute('alerts', 'teamId', Database::VAR_STRING, 255, false);
        $this->database->createAttribute('alerts', 'projectId', Database::VAR_STRING, 255, false);
        $this->database->createAttribute('alerts', 'title', Database::VAR_STRING, 256, true);
        $this->database->createAttribute('alerts', 'body', Database::VAR_STRING, 16384, true);
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
    private function alertId(string $messageId, string $userId = '', string $teamId = ''): string
    {
        $key = $userId !== '' ? 'user:' . $userId : 'team:' . $teamId;
        return \substr($messageId, 0, 19) . '_' . \substr(\md5($key), 0, 16);
    }

    public function testWritesAlertWithCorrectSchema(): void
    {
        $message = new ConsoleMessage(
            recipients: [['userId' => 'user-1']],
            title: 'Hello',
            body: 'World',
            type: 'info',
            messageId: ID::custom('msg-aaa'),
            projectId: 'project-1',
        );

        $adapter = new Console($this->database);
        $result = $adapter->send($message);

        $this->assertSame(1, $result['deliveredTo']);

        $stored = $this->database->getDocument('alerts', $this->alertId('msg-aaa', userId: 'user-1'));
        $this->assertFalse($stored->isEmpty());
        $this->assertSame('msg-aaa', $stored->getAttribute('messageId'));
        $this->assertSame('console', $stored->getAttribute('channel'));
        $this->assertSame('user-1', $stored->getAttribute('userId'));
        $this->assertSame('project-1', $stored->getAttribute('projectId'));
        $this->assertSame('Hello', $stored->getAttribute('title'));
        $this->assertSame('World', $stored->getAttribute('body'));
        $this->assertSame('info', $stored->getAttribute('type'));
    }

    public function testUserPermissionsScopedToRecipient(): void
    {
        $message = new ConsoleMessage(
            recipients: [['userId' => 'user-2']],
            title: 'Title',
            body: 'Body',
            messageId: ID::custom('msg-perms-user'),
        );

        (new Console($this->database))->send($message);

        $stored = $this->database->getDocument('alerts', $this->alertId('msg-perms-user', userId: 'user-2'));
        $permissions = $stored->getPermissions();

        $this->assertContains(Permission::read(Role::user('user-2')), $permissions);
        $this->assertContains(Permission::update(Role::user('user-2')), $permissions);
        $this->assertContains(Permission::delete(Role::user('user-2')), $permissions);
    }

    public function testTeamRecipientGrantsTeamReadAndOwnerWrite(): void
    {
        $message = new ConsoleMessage(
            recipients: [['teamId' => 'team-9']],
            title: 'Heads up',
            body: '...',
            messageId: ID::custom('msg-team'),
        );

        (new Console($this->database))->send($message);

        $stored = $this->database->getDocument('alerts', $this->alertId('msg-team', teamId: 'team-9'));
        $permissions = $stored->getPermissions();

        $this->assertContains(Permission::read(Role::team('team-9')), $permissions);
        $this->assertContains(Permission::update(Role::team('team-9', 'owner')), $permissions);
        $this->assertContains(Permission::delete(Role::team('team-9', 'owner')), $permissions);
    }

    public function testMultiRecipientWithSameMessageIdGeneratesDistinctIds(): void
    {
        $message = new ConsoleMessage(
            recipients: [
                ['userId' => 'a'],
                ['userId' => 'b'],
            ],
            title: 'Heads up',
            body: 'multi',
            messageId: ID::custom('same-msg'),
        );

        $adapter = new Console($this->database);
        $result = $adapter->send($message);

        $this->assertSame(2, $result['deliveredTo']);

        $idA = $this->alertId('same-msg', userId: 'a');
        $idB = $this->alertId('same-msg', userId: 'b');

        $this->assertNotSame($idA, $idB, 'recipient suffixes must be distinct');

        $rowA = $this->database->getDocument('alerts', $idA);
        $rowB = $this->database->getDocument('alerts', $idB);
        $this->assertFalse($rowA->isEmpty(), 'first recipient row must exist');
        $this->assertFalse($rowB->isEmpty(), 'second recipient row must exist');

        $this->assertSame('same-msg', $rowA->getAttribute('messageId'));
        $this->assertSame('same-msg', $rowB->getAttribute('messageId'));
        $this->assertSame('a', $rowA->getAttribute('userId'));
        $this->assertSame('b', $rowB->getAttribute('userId'));

        $this->assertMatchesRegularExpression('/^same-msg_[0-9a-f]{16}$/', $idA);
        $this->assertMatchesRegularExpression('/^same-msg_[0-9a-f]{16}$/', $idB);
    }

    public function testRejectsForeignMessageType(): void
    {
        $adapter = new Console($this->database);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid message type.');

        // ConsoleMessage extends nothing; pass an unrelated Message implementation
        $adapter->send(new \Appwrite\Utopia\Messaging\Messages\Webhook(urls: ['https://example.test'], payload: []));
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
        $documentId = $this->alertId($messageId, userId: $userId);

        // Pre-insert an alert with the SAME id the adapter will compute. The
        // adapter's createDocument will hit the primary-key DuplicateException
        // and must treat it as a successful (idempotent) send.
        $this->database->createDocument('alerts', new \Utopia\Database\Document([
            '$id' => $documentId,
            '$permissions' => [Permission::read(Role::any())],
            'messageId' => $messageId,
            'recipientHash' => \substr(\md5('user:' . $userId), 0, 16),
            'channel' => 'console',
            'userId' => $userId,
            'title' => 'pre-existing',
            'body' => 'pre-existing',
        ]));

        $message = new ConsoleMessage(
            recipients: [['userId' => $userId]],
            title: 'Same alert resent',
            body: 'b',
            messageId: ID::custom($messageId),
            projectId: 'project-x',
        );

        $adapter = new Console($this->database);
        $result = $adapter->send($message);

        $this->assertSame(1, $result['deliveredTo'], 'duplicate must count as a successful delivery');
        $this->assertCount(1, $result['results']);
        $this->assertSame('success', $result['results'][0]['status'] ?? '', 'duplicate must report success status');
        $this->assertSame('', $result['results'][0]['error'] ?? 'unset', 'duplicate must not surface a per-recipient error');

        // Still exactly ONE row: the pre-existing one. The adapter must not
        // overwrite it nor create a sibling.
        $rows = $this->database->find('alerts');
        $this->assertCount(1, $rows);
        $this->assertSame($documentId, $rows[0]->getId());
        $this->assertSame('pre-existing', $rows[0]->getAttribute('title'), 'duplicate path must not overwrite existing row');
    }
}
