<?php

namespace Tests\Unit\Platform\Workers;

use Appwrite\Platform\Workers\Notifications;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Logger\Log;
use Utopia\Queue\Message;
use Utopia\Registry\Registry;

require_once __DIR__ . '/../../../../app/init.php';

/**
 * Spy worker that records dispatch invocations instead of touching SMTP, the
 * console alerts table, or external HTTP. Lets the worker tests assert
 * routing, error handling, and alert persistence in isolation.
 */
class SpyNotifications extends Notifications
{
    /** @var array<int, array{channel: string, address: string, signatureKey: ?string, payload: array<string, mixed>}> */
    public array $dispatched = [];

    /** @var array<string, \Throwable> */
    public array $throwOn = [];

    protected function dispatch(
        array $recipient,
        string $messageId,
        array $payload,
        Document $project,
        Registry $register,
        Database $database,
        Log $log,
    ): ?string {
        $channel = $recipient['channel'];
        $this->dispatched[] = [
            'channel' => $channel,
            'address' => $recipient['address'],
            'signatureKey' => $recipient['signatureKey'] ?? null,
            'payload' => $payload,
        ];

        if (isset($this->throwOn[$channel])) {
            throw $this->throwOn[$channel];
        }

        // Mirror the real adapters' persistence contract so the action
        // loop's branching (console/email persist internally; webhook
        // persists in caller) is exercised end-to-end.
        if ($messageId === '') {
            return null;
        }

        if ($channel === NOTIFICATION_TYPE_CONSOLE || $channel === NOTIFICATION_TYPE_EMAIL) {
            return $this->persistAlert($database, $messageId, $recipient, $payload);
        }

        return null;
    }
}

class NotificationsTest extends TestCase
{
    private Database $database;
    private Authorization $authorization;
    private Registry $registry;
    private Document $project;
    private Log $log;

    protected function setUp(): void
    {
        $this->authorization = new Authorization();
        $this->authorization->addRole(Role::any()->toString());

        $this->database = new Database(new Memory(), new Cache(new NoCache()));
        $this->database
            ->setAuthorization($this->authorization)
            ->setDatabase('notifTests')
            ->setNamespace('notif_' . \uniqid());

        $this->database->create();
        $this->database->createCollection(
            'alerts',
            [],
            [],
            [Permission::create(Role::any()), Permission::read(Role::any()), Permission::update(Role::any()), Permission::delete(Role::any())],
            false,
        );
        $this->database->createAttribute('alerts', 'messageId', Database::VAR_STRING, 255, false);
        $this->database->createAttribute('alerts', 'type', Database::VAR_STRING, 64, false, 'info');
        $this->database->createAttribute('alerts', 'channel', Database::VAR_STRING, 64, true);
        $this->database->createAttribute('alerts', 'userId', Database::VAR_STRING, 255, false);
        $this->database->createAttribute('alerts', 'teamId', Database::VAR_STRING, 255, false);
        $this->database->createAttribute('alerts', 'projectId', Database::VAR_STRING, 255, false);
        $this->database->createAttribute('alerts', 'title', Database::VAR_STRING, 256, true);
        $this->database->createAttribute('alerts', 'body', Database::VAR_STRING, 16384, true);
        $this->database->createAttribute('alerts', 'read', Database::VAR_BOOLEAN, 0, false, false);

        $this->registry = new Registry();
        $this->project = new Document(['$id' => 'project-x']);
        $this->log = new Log();
    }

    protected function tearDown(): void
    {
        $this->authorization->cleanRoles();
        $this->authorization->addRole(Role::any()->toString());
    }

    private function buildMessage(array $payload): Message
    {
        return new Message([
            'pid' => 'pid',
            'queue' => 'v1-notifications',
            'timestamp' => \time(),
            'payload' => $payload,
        ]);
    }

    public function testDispatchesPerChannelToCorrectAdapter(): void
    {
        $worker = new SpyNotifications();

        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [
                ['address' => 'user@example.test', 'channel' => NOTIFICATION_TYPE_EMAIL],
                ['address' => 'user-1', 'channel' => NOTIFICATION_TYPE_CONSOLE],
                ['address' => 'https://hooks.example.test/in', 'channel' => NOTIFICATION_TYPE_WEBHOOK],
            ],
            'subject' => 'Hi',
            'body' => 'Body',
            'deduplicationKey' => 'event-1',
        ];

        $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

        $this->assertCount(3, $worker->dispatched);
        $channels = \array_map(static fn ($d) => $d['channel'], $worker->dispatched);
        $this->assertSame([NOTIFICATION_TYPE_EMAIL, NOTIFICATION_TYPE_CONSOLE, NOTIFICATION_TYPE_WEBHOOK], $channels);
    }

    public function testPersistsOneAlertPerRecipientChannel(): void
    {
        $worker = new SpyNotifications();
        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [
                ['address' => 'user-1', 'channel' => NOTIFICATION_TYPE_CONSOLE],
                ['address' => 'user-2', 'channel' => NOTIFICATION_TYPE_CONSOLE],
            ],
            'subject' => 'Heads up',
            'body' => 'Read me',
            'deduplicationKey' => 'evt-multi',
            'permissions' => [Permission::read(Role::any())],
        ];

        $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

        $rows = $this->database->find('alerts');
        $this->assertCount(2, $rows);
        $userIds = \array_map(static fn (Document $row) => $row->getAttribute('userId'), $rows);
        \sort($userIds);
        $this->assertSame(['user-1', 'user-2'], $userIds);

        foreach ($rows as $row) {
            $this->assertSame(\md5('evt-multi'), $row->getAttribute('messageId'));
            $this->assertSame('console', $row->getAttribute('channel'));
            $this->assertSame('project-x', $row->getAttribute('projectId'));
            $this->assertSame('Heads up', $row->getAttribute('title'));
        }
    }

    public function testDedupHitShortCircuitsBeforeDispatch(): void
    {
        $worker = new SpyNotifications();
        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [['address' => 'user-1', 'channel' => NOTIFICATION_TYPE_CONSOLE]],
            'subject' => 'Sub',
            'body' => 'B',
            'deduplicationKey' => 'dup-key',
        ];

        // First run delivers and persists.
        $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);
        $this->assertCount(1, $worker->dispatched);

        // Manually insert a row with the dedup messageId so alreadyDelivered() returns true.
        $messageId = \md5('dup-key');
        $this->database->createDocument('alerts', new Document([
            '$id' => $messageId,
            '$permissions' => [Permission::read(Role::any())],
            'messageId' => $messageId,
            'channel' => 'console',
            'title' => 'x',
            'body' => 'y',
        ]));

        $worker->dispatched = [];
        $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);
        $this->assertCount(0, $worker->dispatched, 'second invocation must short-circuit on dedup hit');
    }

    public function testMissingRecipientsAndAddressThrows(): void
    {
        $worker = new SpyNotifications();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No recipients in payload');

        $worker->action(
            $this->buildMessage(['project' => ['$id' => 'project-x'], 'subject' => '', 'body' => '']),
            $this->project,
            $this->registry,
            $this->database,
            $this->log,
        );
    }

    public function testFallbackToLegacyRecipient(): void
    {
        $worker = new SpyNotifications();
        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipient' => 'legacy@example.test',
            'subject' => 'X',
            'body' => 'Y',
        ];

        $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

        $this->assertCount(1, $worker->dispatched);
        $this->assertSame('legacy@example.test', $worker->dispatched[0]['address']);
        $this->assertSame(NOTIFICATION_TYPE_EMAIL, $worker->dispatched[0]['channel']);
    }

    public function testWebhookRecipientForwardsSignatureKey(): void
    {
        $worker = new SpyNotifications();
        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [
                [
                    'address' => 'https://hooks.example.test/signed',
                    'channel' => NOTIFICATION_TYPE_WEBHOOK,
                    'signatureKey' => 'tenant-secret',
                ],
                [
                    'address' => 'https://hooks.example.test/unsigned',
                    'channel' => NOTIFICATION_TYPE_WEBHOOK,
                ],
            ],
            'subject' => 's',
            'body' => 'b',
        ];

        $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

        $this->assertCount(2, $worker->dispatched);
        $this->assertSame('tenant-secret', $worker->dispatched[0]['signatureKey']);
        $this->assertNull($worker->dispatched[1]['signatureKey']);
    }

    public function testDispatchErrorTagsLogAndPropagates(): void
    {
        $worker = new SpyNotifications();
        $worker->throwOn[NOTIFICATION_TYPE_WEBHOOK] = new \RuntimeException('boom');

        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [['address' => 'https://h.example.test', 'channel' => NOTIFICATION_TYPE_WEBHOOK]],
            'subject' => 's',
            'body' => 'b',
            'deduplicationKey' => 'err-1',
        ];

        try {
            $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);
            $this->fail('expected exception to propagate');
        } catch (\Throwable $error) {
            $this->assertSame('boom', $error->getMessage());
        }

        $tags = $this->log->getTags();
        $this->assertSame(NOTIFICATION_TYPE_WEBHOOK, $tags['channel'] ?? null);
        $this->assertSame('boom', $tags['error'] ?? null);

        $rows = $this->database->find('alerts');
        $this->assertCount(0, $rows, 'failed dispatch must not persist alert');
    }
}
