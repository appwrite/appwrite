<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Ahc\Jwt\JWT;
use Appwrite\Platform\Workers\Notifications;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Logger\Log;
use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Messages\Email as EmailMessage;
use Utopia\Queue\Message;
use Utopia\Registry\Registry;

require_once __DIR__ . '/../../../../app/init.php';

/**
 * Spy worker that records dispatch invocations instead of touching SMTP, the
 * console alerts table, or external HTTP. Lets the worker tests assert
 * routing, error handling, and alert persistence in isolation.
 */
final class SpyNotifications extends Notifications
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
        Database $dbForPlatform,
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
            return $this->persistAlert($dbForPlatform, $messageId, $recipient, $payload, $project);
        }

        return null;
    }
}

/**
 * Worker that runs the real `dispatchConsole` (so the ConsoleAdapter writes
 * the alert row) but counts how many times the action loop calls
 * `persistAlert`. Used to assert that the loop does NOT double-persist for
 * console recipients (Greptile P1 #3).
 */
class CountingPersistAlertNotifications extends Notifications
{
    public int $persistAlertCalls = 0;

    /** @var array<int, string> */
    public array $persistedIds = [];

    protected function persistAlert(Database $dbForPlatform, string $messageId, array $recipient, array $payload, Document $project): string
    {
        $this->persistAlertCalls++;
        $alertId = parent::persistAlert($dbForPlatform, $messageId, $recipient, $payload, $project);
        $this->persistedIds[] = $alertId;
        return $alertId;
    }
}

/**
 * Worker that simulates a console-adapter zero-delivery outcome (e.g.
 * permission denied on createDocument) without depending on a real adapter
 * failure mode.
 */
class ZeroDeliveryConsoleNotifications extends Notifications
{
    protected function dispatchConsole(array $recipient, string $messageId, array $payload, Document $project, Database $dbForPlatform): ?string
    {
        // Simulate the Console adapter swallowing a per-recipient
        // exception and reporting zero deliveries.
        throw new \Exception('Console alert delivery failed: permission denied');
    }
}

/**
 * Captures the EmailMessage handed to the SMTP adapter so tests can assert
 * on the rendered HTML body without touching a real mail server.
 *
 * Set `$throwOnSend = true` to simulate a hard SMTP failure (DNS, refused
 * connection, auth error). The adapter's `send()` calls `process()` directly,
 * so a throw from here propagates exactly like a real PHPMailer error.
 */
class SpyEmailAdapter extends EmailAdapter
{
    public ?EmailMessage $captured = null;
    public int $sendCount = 0;
    public bool $throwOnSend = false;

    public function getName(): string
    {
        return 'SpySMTP';
    }

    public function getMaxMessagesPerRequest(): int
    {
        return 1000;
    }

    protected function process(EmailMessage $message): array
    {
        $this->sendCount++;
        $this->captured = $message;

        if ($this->throwOnSend) {
            throw new \Exception('SMTP unavailable');
        }

        return [
            'deliveredTo' => 1,
            'type' => $this->getType(),
            'results' => [['recipient' => $message->getTo()[0]['email'] ?? '', 'status' => 'sent']],
        ];
    }
}

final class NotificationsTest extends TestCase
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
            'notifications',
            [],
            [],
            [Permission::create(Role::any()), Permission::read(Role::any()), Permission::update(Role::any()), Permission::delete(Role::any())],
            false,
        );
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

        // Mirror the production `_key_recipient` UNIQUE composite index so the
        // duplicate-handling branch in persistAlert (catch DuplicateException ->
        // return existing alertId) is actually exercised by tests.
        $this->database->createIndex(
            'notifications',
            '_key_recipient',
            Database::INDEX_UNIQUE,
            ['messageId', 'channel', 'recipientHash'],
            [Database::LENGTH_KEY, 64, 64],
            [Database::ORDER_ASC, Database::ORDER_ASC, Database::ORDER_ASC],
        );

        $this->registry = new Registry();
        $this->project = new Document(['$id' => 'project-x', '$sequence' => 'project-internal-x']);
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

    private function recipientHash(
        string $channel,
        string $address,
        string $resourceType,
        string $resourceId,
        string $resourceInternalId,
        string $parentResourceType = RESOURCE_TYPE_PROJECTS,
        string $parentResourceId = 'project-x',
        string $parentResourceInternalId = 'project-internal-x',
    ): string {
        return \substr(\md5(
            $channel
                . ':' . $address
                . ':' . $resourceType
                . ':' . $resourceId
                . ':' . $resourceInternalId
                . ':' . $parentResourceType
                . ':' . $parentResourceId
                . ':' . $parentResourceInternalId
        ), 0, 16);
    }

    private function alertId(string $messageId, string $channel, string $address, string $resourceType, string $resourceId): string
    {
        return \substr($messageId, 0, 19) . '_' . $this->recipientHash($channel, $address, $resourceType, $resourceId, $resourceId . '-internal');
    }

    /**
     * @return array{address: string, channel: string, resourceType: string, resourceId: string, resourceInternalId: string, parentResourceType: string, parentResourceId: string, parentResourceInternalId: string}
     */
    private function userRecipient(string $address, string $channel, string $resourceId): array
    {
        return [
            'address' => $address,
            'channel' => $channel,
            'resourceType' => RESOURCE_TYPE_USERS,
            'resourceId' => $resourceId,
            'resourceInternalId' => $resourceId . '-internal',
            'parentResourceType' => RESOURCE_TYPE_PROJECTS,
            'parentResourceId' => 'project-x',
            'parentResourceInternalId' => 'project-internal-x',
        ];
    }

    public function testDispatchesPerChannelToCorrectAdapter(): void
    {
        $worker = new SpyNotifications();

        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [
                $this->userRecipient('user@example.test', NOTIFICATION_TYPE_EMAIL, 'user-1'),
                $this->userRecipient('user-1', NOTIFICATION_TYPE_CONSOLE, 'user-1'),
                $this->userRecipient('https://hooks.example.test/in', NOTIFICATION_TYPE_WEBHOOK, 'user-1'),
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
                $this->userRecipient('user-1', NOTIFICATION_TYPE_CONSOLE, 'user-1'),
                $this->userRecipient('user-2', NOTIFICATION_TYPE_CONSOLE, 'user-2'),
            ],
            'subject' => 'Heads up',
            'body' => 'Read me',
            'deduplicationKey' => 'evt-multi',
            'permissions' => [Permission::read(Role::any())],
        ];

        $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

        $rows = $this->database->find('notifications');
        $this->assertCount(2, $rows);
        $resourceIds = \array_map(static fn (Document $row) => $row->getAttribute('resourceId'), $rows);
        \sort($resourceIds);
        $this->assertSame(['user-1', 'user-2'], $resourceIds);

        foreach ($rows as $row) {
            $this->assertSame(\md5('evt-multi'), $row->getAttribute('messageId'));
            $this->assertSame('console', $row->getAttribute('channel'));
            $this->assertSame('project-x', $row->getAttribute('projectId'));
            $this->assertSame('project-internal-x', $row->getAttribute('projectInternalId'));
            $this->assertSame(RESOURCE_TYPE_USERS, $row->getAttribute('resourceType'));
            $this->assertSame(RESOURCE_TYPE_PROJECTS, $row->getAttribute('parentResourceType'));
            $this->assertSame('project-x', $row->getAttribute('parentResourceId'));
            $this->assertSame('project-internal-x', $row->getAttribute('parentResourceInternalId'));
            $this->assertSame('Heads up', $row->getAttribute('title'));
        }
    }

    public function testDedupHitShortCircuitsBeforeDispatch(): void
    {
        $worker = new SpyNotifications();
        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [$this->userRecipient('user-1', NOTIFICATION_TYPE_CONSOLE, 'user-1')],
            'subject' => 'Sub',
            'body' => 'B',
            'deduplicationKey' => 'dup-key',
        ];

        $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);
        $this->assertCount(1, $worker->dispatched);

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

    public function testLegacyMailPayloadOptionsAreAppliedByEmailChannel(): void
    {
        $spy = new SpyEmailAdapter();
        $this->registry->set('smtp', static fn () => $spy);

        $previousSmtpHost = \getenv('_APP_SMTP_HOST');
        \putenv('_APP_SMTP_HOST=spy.smtp.test');

        try {
            $worker = new Notifications();
            $payload = [
                'project' => ['$id' => 'project-x'],
                'recipient' => 'legacy@example.test',
                'name' => 'Legacy User',
                'subject' => 'Hello {{name}}',
                'body' => 'Body {{name}}',
                'variables' => ['name' => 'Legacy'],
                'customMailOptions' => [
                    'senderEmail' => 'sender@example.test',
                    'senderName' => 'Custom Sender',
                    'replyToEmail' => 'reply@example.test',
                    'replyToName' => 'Custom Reply',
                ],
            ];

            $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);
        } finally {
            \putenv($previousSmtpHost === false ? '_APP_SMTP_HOST' : '_APP_SMTP_HOST=' . $previousSmtpHost);
        }

        $this->assertSame(1, $spy->sendCount);
        $this->assertInstanceOf(\Utopia\Messaging\Messages\Email::class, $spy->captured);

        $message = $spy->captured;
        $this->assertSame('legacy@example.test', $message->getTo()[0]['email'] ?? '');
        $this->assertSame('Legacy User', $message->getTo()[0]['name'] ?? '');
        $this->assertSame('Hello Legacy', $message->getSubject());
        $this->assertSame('sender@example.test', $message->getFromEmail());
        $this->assertSame('Custom Sender', $message->getFromName());
        $this->assertSame('reply@example.test', $message->getReplyToEmail());
        $this->assertSame('Custom Reply', $message->getReplyToName());
    }

    public function testLegacyPayloadSmtpOverridesProjectSmtp(): void
    {
        $worker = new Notifications();
        $project = new Document([
            'smtp' => [
                'enabled' => true,
                'host' => 'project.smtp.test',
                'port' => 2525,
                'senderEmail' => 'project@example.test',
            ],
        ]);

        $reflection = new \ReflectionMethod($worker, 'resolveSmtpConfig');
        $reflection->setAccessible(true);
        /** @var array<string, mixed> $smtp */
        $smtp = $reflection->invoke($worker, $project, [
            'smtp' => [
                'host' => 'payload.smtp.test',
                'port' => 587,
                'senderEmail' => 'payload@example.test',
            ],
        ]);

        $this->assertSame('payload.smtp.test', $smtp['host']);
        $this->assertSame(587, $smtp['port']);
        $this->assertSame('payload@example.test', $smtp['senderEmail']);
    }

    public function testLegacyMailPayloadThrowsWhenSmtpIsNotConfigured(): void
    {
        $previousSmtpHost = \getenv('_APP_SMTP_HOST');
        \putenv('_APP_SMTP_HOST=');

        try {
            $worker = new Notifications();
            $payload = [
                'project' => ['$id' => 'project-x'],
                'recipient' => 'legacy@example.test',
                'subject' => 'No SMTP',
                'body' => 'Body',
            ];

            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('Skipped mail processing. No SMTP configuration has been set.');
            $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);
        } finally {
            \putenv($previousSmtpHost === false ? '_APP_SMTP_HOST' : '_APP_SMTP_HOST=' . $previousSmtpHost);
        }
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
            'recipients' => [$this->userRecipient('https://h.example.test', NOTIFICATION_TYPE_WEBHOOK, 'user-1')],
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

        $rows = $this->database->find('notifications');
        $this->assertCount(0, $rows, 'failed dispatch must not persist alert');
    }

    public function testDedupSkipsOnlyDeliveredRecipientAfterPartialFanoutFailure(): void
    {
        $worker = new SpyNotifications();
        $worker->throwOn[NOTIFICATION_TYPE_WEBHOOK] = new \RuntimeException('webhook down');

        $messageId = \md5('partial-key');
        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [
                $this->userRecipient('user-1', NOTIFICATION_TYPE_CONSOLE, 'user-1'),
                $this->userRecipient('https://hooks.example.test/in', NOTIFICATION_TYPE_WEBHOOK, 'user-1'),
            ],
            'subject' => 'Sub',
            'body' => 'B',
            'deduplicationKey' => 'partial-key',
        ];

        try {
            $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);
            $this->fail('expected webhook failure to propagate');
        } catch (\RuntimeException $error) {
            $this->assertSame('webhook down', $error->getMessage());
        }

        $rows = $this->database->find('notifications', [
            Query::equal('messageId', [$messageId]),
        ]);
        $this->assertCount(1, $rows, 'first attempt should persist only the successful console recipient');
        $this->assertSame(NOTIFICATION_TYPE_CONSOLE, $rows[0]->getAttribute('channel'));

        $retry = new SpyNotifications();
        $retry->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

        $this->assertCount(1, $retry->dispatched, 'retry should dispatch only the previously undelivered webhook');
        $this->assertSame(NOTIFICATION_TYPE_WEBHOOK, $retry->dispatched[0]['channel']);

        $rows = $this->database->find('notifications', [
            Query::equal('messageId', [$messageId]),
        ]);
        $this->assertCount(2, $rows, 'retry must complete the missing recipient without duplicating console');
    }

    public function testConsoleChannelSkipsPersistAlert(): void
    {
        $worker = new CountingPersistAlertNotifications();

        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [
                $this->userRecipient('user-1', NOTIFICATION_TYPE_CONSOLE, 'user-1'),
            ],
            'subject' => 'Heads up',
            'body' => 'console body',
            'deduplicationKey' => 'console-skip',
        ];

        $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

        // The Console adapter wrote exactly one alert; the action loop
        // must NOT have called persistAlert (otherwise we'd see 2 rows or
        // a duplicate-key swallow plus a non-zero counter).
        $consoleRows = $this->database->find('notifications', [
            Query::equal('channel', ['console']),
        ]);
        $this->assertCount(1, $consoleRows);
        $this->assertSame(0, $worker->persistAlertCalls, 'console channel must NOT trigger action-loop persistAlert');
    }

    public function testConsoleZeroDeliveryThrows(): void
    {
        $worker = new ZeroDeliveryConsoleNotifications();

        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [
                $this->userRecipient('user-1', NOTIFICATION_TYPE_CONSOLE, 'user-1'),
            ],
            'subject' => 'Heads up',
            'body' => 'console body',
            'deduplicationKey' => 'console-fail',
        ];

        try {
            $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);
            $this->fail('expected console zero-delivery to throw');
        } catch (\Throwable $error) {
            $this->assertStringContainsString('Console alert delivery failed', $error->getMessage());
        }

        $this->assertCount(0, $this->database->find('notifications'), 'failed console dispatch must not persist alert');
    }

    public function testMultiRecipientFanoutNoCollision(): void
    {
        $worker = new CountingPersistAlertNotifications();

        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [
                $this->userRecipient('user-1', NOTIFICATION_TYPE_CONSOLE, 'user-1'),
                $this->userRecipient('user-2', NOTIFICATION_TYPE_CONSOLE, 'user-2'),
            ],
            'subject' => 'Heads up',
            'body' => 'console body',
            'deduplicationKey' => 'fanout',
        ];

        $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

        $rows = $this->database->find('notifications');
        $this->assertCount(2, $rows, 'two recipients must produce two distinct alert rows');

        $messageId = \md5('fanout');
        $ids = [];
        foreach ($rows as $row) {
            $this->assertSame($messageId, $row->getAttribute('messageId'), 'all rows share the dedup messageId');
            $ids[] = $row->getId();
        }
        $this->assertCount(2, \array_unique($ids), 'recipient suffixes must keep $id values distinct');
    }

    public function testRecipientStructRoundtripsResourceFields(): void
    {
        $worker = new CountingPersistAlertNotifications();

        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [
                [
                    'address' => 'console-recipient',
                    'channel' => NOTIFICATION_TYPE_CONSOLE,
                    'resourceType' => RESOURCE_TYPE_USERS,
                    'resourceId' => 'u1',
                    'resourceInternalId' => 'u1-internal',
                    'parentResourceType' => RESOURCE_TYPE_PROJECTS,
                    'parentResourceId' => 'project-x',
                    'parentResourceInternalId' => 'project-internal-x',
                ],
            ],
            'subject' => 'Heads up',
            'body' => 'b',
            'deduplicationKey' => 'roundtrip',
        ];

        $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

        $rows = $this->database->find('notifications');
        $this->assertCount(1, $rows);
        $this->assertSame(RESOURCE_TYPE_USERS, $rows[0]->getAttribute('resourceType'));
        $this->assertSame('u1', $rows[0]->getAttribute('resourceId'));
        $this->assertSame('u1-internal', $rows[0]->getAttribute('resourceInternalId'));
        $this->assertSame(RESOURCE_TYPE_PROJECTS, $rows[0]->getAttribute('parentResourceType'));
        $this->assertSame('project-x', $rows[0]->getAttribute('parentResourceId'));
        $this->assertSame('project-internal-x', $rows[0]->getAttribute('parentResourceInternalId'));
    }

    public function testTrackingLogoInjectedIntoEmailHtml(): void
    {
        $spy = new SpyEmailAdapter();
        $this->registry->set('smtp', static fn () => $spy);

        // Force the cloud SMTP branch (project has no smtp config) and
        // provide the tracking secret so injectTrackingLogo actually runs.
        $previousSmtpHost = \getenv('_APP_SMTP_HOST');
        $previousTrackingSecret = \getenv('_APP_NOTIFICATIONS_TRACKING_SECRET');
        $previousDomain = \getenv('_APP_DOMAIN');
        $previousConsoleDomain = \getenv('_APP_CONSOLE_DOMAIN');
        \putenv('_APP_SMTP_HOST=spy.smtp.test');
        \putenv('_APP_NOTIFICATIONS_TRACKING_SECRET=test-key-32bytes-min-aaaaaaaaaaaaaa');
        \putenv('_APP_DOMAIN=api.example.test');
        \putenv('_APP_CONSOLE_DOMAIN=console.example.test');

        try {
            $worker = new Notifications();

            $payload = [
                'project' => ['$id' => 'project-x'],
                'recipients' => [
                    $this->userRecipient('user@example.test', NOTIFICATION_TYPE_EMAIL, 'user-7'),
                ],
                'subject' => 'Heads up',
                'body' => 'plain body',
                'deduplicationKey' => 'logo-key',
            ];

            $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);
        } finally {
            \putenv($previousSmtpHost === false ? '_APP_SMTP_HOST' : '_APP_SMTP_HOST=' . $previousSmtpHost);
            \putenv($previousTrackingSecret === false ? '_APP_NOTIFICATIONS_TRACKING_SECRET' : '_APP_NOTIFICATIONS_TRACKING_SECRET=' . $previousTrackingSecret);
            \putenv($previousDomain === false ? '_APP_DOMAIN' : '_APP_DOMAIN=' . $previousDomain);
            \putenv($previousConsoleDomain === false ? '_APP_CONSOLE_DOMAIN' : '_APP_CONSOLE_DOMAIN=' . $previousConsoleDomain);
        }

        $this->assertInstanceOf(\Utopia\Messaging\Messages\Email::class, $spy->captured, 'SpyEmailAdapter must capture exactly one EmailMessage');

        $body = $spy->captured->getContent();
        $this->assertStringContainsString('<img src=', $body, 'tracking logo <img> must be present');
        $this->assertStringContainsString('/v1/notifications/logos/appwrite?jwt=', $body);
        $this->assertStringContainsString('alt="Appwrite logo"', $body);
        $this->assertStringContainsString('width="120"', $body);
        $this->assertStringContainsString('height="28"', $body);
        $this->assertStringNotContainsString('display:none', $body);
        $this->assertStringContainsString('http://api.example.test/v1/notifications/logos/appwrite?jwt=', \html_entity_decode($body));
        $this->assertStringNotContainsString('console.example.test/v1/notifications/logos/appwrite', \html_entity_decode($body));

        \preg_match('/logos\/appwrite\?jwt=([^"&]+)/', \html_entity_decode($body), $matches);
        $this->assertNotEmpty($matches[1] ?? '');

        $claims = (new JWT('test-key-32bytes-min-aaaaaaaaaaaaaa', 'HS256', NOTIFICATION_TRACKING_JWT_TTL, 0))
            ->decode(\urldecode($matches[1]));
        $this->assertSame(\md5('logo-key'), $claims['messageId'] ?? null);
        $this->assertSame(NOTIFICATION_TYPE_EMAIL, $claims['channel'] ?? null);
        $this->assertSame($this->recipientHash(
            NOTIFICATION_TYPE_EMAIL,
            'user@example.test',
            RESOURCE_TYPE_USERS,
            'user-7',
            'user-7-internal',
        ), $claims['recipientHash'] ?? null);
        $this->assertSame('project-x', $claims['projectId'] ?? null);
        $this->assertSame('project-internal-x', $claims['projectInternalId'] ?? null);
        $this->assertSame('notification_track', $claims['purpose'] ?? null);
        $this->assertArrayNotHasKey('alertId', $claims);

        // The logo must sit BEFORE the last </body>.
        $lastBodyClose = \strripos($body, '</body>');
        $logoPosition = \strripos($body, '<img src=');
        $this->assertNotFalse($lastBodyClose, 'rendered email must include a closing </body>');
        $this->assertNotFalse($logoPosition);
        $this->assertLessThan($lastBodyClose, $logoPosition, 'logo must be spliced before the final </body>');
    }

    public function testTrackingLogoDoesNotUseOpenSslKeyFallback(): void
    {
        $spy = new SpyEmailAdapter();
        $this->registry->set('smtp', static fn () => $spy);

        $previousSmtpHost = \getenv('_APP_SMTP_HOST');
        $previousTrackingSecret = \getenv('_APP_NOTIFICATIONS_TRACKING_SECRET');
        $previousOpenSslKey = \getenv('_APP_OPENSSL_KEY_V1');
        \putenv('_APP_SMTP_HOST=spy.smtp.test');
        \putenv('_APP_NOTIFICATIONS_TRACKING_SECRET=');
        \putenv('_APP_OPENSSL_KEY_V1=openssl-key-must-not-sign-tracking');

        try {
            $worker = new Notifications();

            $payload = [
                'project' => ['$id' => 'project-x'],
                'recipients' => [
                    $this->userRecipient('user@example.test', NOTIFICATION_TYPE_EMAIL, 'user-8'),
                ],
                'subject' => 'Heads up',
                'body' => 'plain body',
                'deduplicationKey' => 'logo-key-no-tracking-secret',
            ];

            $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);
        } finally {
            \putenv($previousSmtpHost === false ? '_APP_SMTP_HOST' : '_APP_SMTP_HOST=' . $previousSmtpHost);
            \putenv($previousTrackingSecret === false ? '_APP_NOTIFICATIONS_TRACKING_SECRET' : '_APP_NOTIFICATIONS_TRACKING_SECRET=' . $previousTrackingSecret);
            \putenv($previousOpenSslKey === false ? '_APP_OPENSSL_KEY_V1' : '_APP_OPENSSL_KEY_V1=' . $previousOpenSslKey);
        }

        $this->assertInstanceOf(\Utopia\Messaging\Messages\Email::class, $spy->captured, 'SpyEmailAdapter must capture exactly one EmailMessage');
        $this->assertStringNotContainsString('/v1/notifications/logos/appwrite?jwt=', $spy->captured->getContent());
    }

    public function testPersistAlertReturnsExistingAlertIdOnDuplicate(): void
    {
        $spy = new SpyEmailAdapter();
        $this->registry->set('smtp', static fn () => $spy);

        $previousSmtpHost = \getenv('_APP_SMTP_HOST');
        \putenv('_APP_SMTP_HOST=spy.smtp.test');

        try {
            $worker = new CountingPersistAlertNotifications();

            $payload = [
                'project' => ['$id' => 'project-x'],
                'recipients' => [
                    $this->userRecipient('user@example.test', NOTIFICATION_TYPE_EMAIL, 'user-7'),
                ],
                'subject' => 'Heads up',
                'body' => 'b',
                'deduplicationKey' => 'persist-dup',
            ];

            // First dispatch: writes a row through the action loop and
            // returns the deterministic alertId.
            $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

            $this->assertSame(1, $worker->persistAlertCalls);
            $firstAlertId = $worker->persistedIds[0];

            $messageId = \md5('persist-dup');
            $recipient = [
                'address' => 'user@example.test',
                'channel' => NOTIFICATION_TYPE_EMAIL,
                'resourceType' => RESOURCE_TYPE_USERS,
                'resourceId' => 'user-7',
                'resourceInternalId' => 'user-7-internal',
                'parentResourceType' => RESOURCE_TYPE_PROJECTS,
                'parentResourceId' => 'project-x',
                'parentResourceInternalId' => 'project-internal-x',
            ];

            // Second invocation with the SAME messageId/recipient. The
            // action loop's alreadyDelivered() check short-circuits before
            // persistAlert, so call persistAlert directly to actually hit
            // the duplicate branch. The deterministic $id collides on the
            // primary key -> DuplicateException -> branch returns the
            // existing alertId without throwing.
            $reflection = new \ReflectionMethod($worker, 'persistAlert');
            $secondAlertId = $reflection->invoke($worker, $this->database, $messageId, $recipient, $payload, $this->project);

            $this->assertSame($firstAlertId, $secondAlertId, 'duplicate persist must return the existing alertId');

            // Third write: bypass the deterministic $id path and use a
            // distinct $id with the same recipient tuple. The
            // `_key_recipient` UNIQUE composite must reject it, proving
            // the unique-index (not just primary-key) is what backstops the
            // duplicate-handling branch.
            $sameTupleDoc = new Document([
                '$id' => 'sibling-id-' . \uniqid(),
                '$permissions' => [Permission::read(Role::any())],
                'messageId' => $messageId,
                'recipientHash' => $this->recipientHash(NOTIFICATION_TYPE_EMAIL, 'user@example.test', RESOURCE_TYPE_USERS, 'user-7', 'user-7-internal'),
                'channel' => NOTIFICATION_TYPE_EMAIL,
                'projectId' => 'project-x',
                'projectInternalId' => 'project-internal-x',
                'resourceType' => RESOURCE_TYPE_USERS,
                'resourceId' => 'user-7',
                'resourceInternalId' => 'user-7-internal',
                'parentResourceType' => RESOURCE_TYPE_PROJECTS,
                'parentResourceId' => 'project-x',
                'parentResourceInternalId' => 'project-internal-x',
                'title' => 'sibling',
                'body' => 'sibling',
                'read' => false,
            ]);

            $threw = false;
            try {
                $this->database->createDocument('notifications', $sameTupleDoc);
            } catch (\Utopia\Database\Exception\Duplicate) {
                $threw = true;
            }
            $this->assertTrue($threw, 'unique-index `_key_recipient` must reject a second row sharing the recipient tuple');

            $rows = $this->database->find('notifications', [
                Query::equal('messageId', [$messageId]),
            ]);
            $this->assertCount(1, $rows, 'unique-index must prevent a second row from being persisted');
        } finally {
            \putenv($previousSmtpHost === false ? '_APP_SMTP_HOST' : '_APP_SMTP_HOST=' . $previousSmtpHost);
        }
    }

    public function testPersistAlertReturnsAlertIdAndStoresResource(): void
    {
        $spy = new SpyEmailAdapter();
        $this->registry->set('smtp', static fn () => $spy);

        $previousSmtpHost = \getenv('_APP_SMTP_HOST');
        \putenv('_APP_SMTP_HOST=spy.smtp.test');

        try {
            $worker = new CountingPersistAlertNotifications();

            $payload = [
                'project' => ['$id' => 'project-x'],
                'recipients' => [
                    $this->userRecipient('user@example.test', NOTIFICATION_TYPE_EMAIL, 'user-7'),
                ],
                'subject' => 'Heads up',
                'body' => 'b',
                'deduplicationKey' => 'persist-email',
            ];

            $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);
        } finally {
            \putenv($previousSmtpHost === false ? '_APP_SMTP_HOST' : '_APP_SMTP_HOST=' . $previousSmtpHost);
        }

        $this->assertSame(1, $worker->persistAlertCalls, 'email channel must persist exactly once');
        $this->assertCount(1, $worker->persistedIds);

        $alertId = $worker->persistedIds[0];
        $row = $this->database->getDocument('notifications', $alertId);
        $this->assertFalse($row->isEmpty(), 'persistAlert must return an id resolvable via getDocument');
        $this->assertSame(RESOURCE_TYPE_USERS, $row->getAttribute('resourceType'));
        $this->assertSame('user-7', $row->getAttribute('resourceId'));
        $this->assertSame('user-7-internal', $row->getAttribute('resourceInternalId'));
        $this->assertFalse($row->getAttribute('read'), 'new alerts default to unread');
        $this->assertSame(\md5('persist-email'), $row->getAttribute('messageId'));
    }

    /**
     * Reviewer C1: SMTP failure must NOT orphan a dedup row.
     *
     * Order in the worker matters: persist BEFORE send leaves a poisoned
     * `messageId` row that the next retry will dedup-hit and never deliver.
     * The fix is to persist only after a successful adapter send. A retry
     * with the same payload must therefore actually deliver and produce
     * exactly one alert row.
     */
    public function testEmailSendFailureDoesNotPersistAlert(): void
    {
        $failing = new SpyEmailAdapter();
        $failing->throwOnSend = true;
        $this->registry->set('smtp', static fn () => $failing);

        $previousSmtpHost = \getenv('_APP_SMTP_HOST');
        \putenv('_APP_SMTP_HOST=spy.smtp.test');

        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [
                $this->userRecipient('user@example.test', NOTIFICATION_TYPE_EMAIL, 'user-9'),
            ],
            'subject' => 'Subj',
            'body' => 'Body',
            'deduplicationKey' => 'smtp-fail-key',
        ];

        $messageId = \md5('smtp-fail-key');

        try {
            $worker = new Notifications();

            $threw = false;
            try {
                $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);
            } catch (\Throwable $error) {
                $threw = true;
                $this->assertStringContainsString('SMTP unavailable', $error->getMessage());
            }
            $this->assertTrue($threw, 'SMTP failure must propagate so the queue retries');
            $this->assertSame(1, $failing->sendCount, 'adapter must have been invoked exactly once');

            // Critical: no orphan dedup row. If there is one, the retry below
            // will short-circuit and the user never gets the email.
            $orphans = $this->database->find('notifications', [
                Query::equal('messageId', [$messageId]),
            ]);
            $this->assertCount(0, $orphans, 'failed SMTP send must not leave a dedup row behind');

            // Retry with a working adapter using the same payload — must deliver
            // AND persist exactly one alert row.
            $working = new SpyEmailAdapter();
            $this->registry->set('smtp', static fn () => $working);

            $retryWorker = new Notifications();
            $retryWorker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

            $this->assertSame(1, $working->sendCount, 'retry must invoke the working adapter');

            $rows = $this->database->find('notifications', [
                Query::equal('messageId', [$messageId]),
            ]);
            $this->assertCount(1, $rows, 'retry must persist exactly one alert row');
            $this->assertSame('user-9', $rows[0]->getAttribute('resourceId'));
            $this->assertFalse($rows[0]->getAttribute('read'));
        } finally {
            \putenv($previousSmtpHost === false ? '_APP_SMTP_HOST' : '_APP_SMTP_HOST=' . $previousSmtpHost);
        }
    }

    public function testEmailFailureDoesNotBlockConsoleRecipient(): void
    {
        $failing = new SpyEmailAdapter();
        $failing->throwOnSend = true;
        $this->registry->set('smtp', static fn () => $failing);

        $previousSmtpHost = \getenv('_APP_SMTP_HOST');
        \putenv('_APP_SMTP_HOST=spy.smtp.test');

        $payload = [
            'project' => ['$id' => 'project-x', '$sequence' => 'project-internal-x'],
            'recipients' => [
                $this->userRecipient('user@example.test', NOTIFICATION_TYPE_EMAIL, 'user-9'),
                $this->userRecipient('user-9', NOTIFICATION_TYPE_CONSOLE, 'user-9'),
            ],
            'subject' => 'Subj',
            'body' => 'Body',
            'deduplicationKey' => 'smtp-fail-console-key',
        ];

        $messageId = \md5('smtp-fail-console-key');

        try {
            $worker = new Notifications();

            $threw = false;
            try {
                $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);
            } catch (\Throwable $error) {
                $threw = true;
                $this->assertStringContainsString('SMTP unavailable', $error->getMessage());
            }
            $this->assertTrue($threw, 'SMTP failure must still propagate so the email recipient is retried');

            $consoleRows = $this->database->find('notifications', [
                Query::equal('messageId', [$messageId]),
                Query::equal('channel', [NOTIFICATION_TYPE_CONSOLE]),
            ]);
            $this->assertCount(1, $consoleRows, 'console recipient must be persisted even when email fails first');
            $this->assertSame('project-x', $consoleRows[0]->getAttribute('projectId'));
            $this->assertSame('project-internal-x', $consoleRows[0]->getAttribute('projectInternalId'));

            $emailRows = $this->database->find('notifications', [
                Query::equal('messageId', [$messageId]),
                Query::equal('channel', [NOTIFICATION_TYPE_EMAIL]),
            ]);
            $this->assertCount(0, $emailRows, 'failed email recipient must not leave an orphan dedup row');

            $working = new SpyEmailAdapter();
            $this->registry->set('smtp', static fn () => $working);

            $retryWorker = new Notifications();
            $retryWorker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

            $this->assertSame(1, $working->sendCount, 'retry must still deliver the email recipient');
            $rows = $this->database->find('notifications', [
                Query::equal('messageId', [$messageId]),
            ]);
            $this->assertCount(2, $rows, 'retry must add email without duplicating the already-delivered console alert');
        } finally {
            \putenv($previousSmtpHost === false ? '_APP_SMTP_HOST' : '_APP_SMTP_HOST=' . $previousSmtpHost);
        }
    }

    /**
     * Worker happy-path: email channel.
     *
     * Asserts the SMTP adapter is invoked once with the expected
     * to/subject/body, the rendered body carries the tracking logo before
     * `</body>`, and an alert row is persisted AFTER the send returns
     * successfully (see C1: persist-after-send invariant).
     */
    public function testEmailChannelHappyPath(): void
    {
        $spy = new SpyEmailAdapter();
        $this->registry->set('smtp', static fn () => $spy);

        $previousSmtpHost = \getenv('_APP_SMTP_HOST');
        $previousTrackingSecret = \getenv('_APP_NOTIFICATIONS_TRACKING_SECRET');
        \putenv('_APP_SMTP_HOST=spy.smtp.test');
        \putenv('_APP_NOTIFICATIONS_TRACKING_SECRET=test-key-32bytes-min-aaaaaaaaaaaaaa');

        try {
            $worker = new CountingPersistAlertNotifications();

            $payload = [
                'project' => ['$id' => 'project-x'],
                'recipients' => [
                    $this->userRecipient('happy@example.test', NOTIFICATION_TYPE_EMAIL, 'user-happy'),
                ],
                'subject' => 'Welcome aboard',
                'body' => 'plain body',
                'deduplicationKey' => 'happy-email',
            ];

            $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);
        } finally {
            \putenv($previousSmtpHost === false ? '_APP_SMTP_HOST' : '_APP_SMTP_HOST=' . $previousSmtpHost);
            \putenv($previousTrackingSecret === false ? '_APP_NOTIFICATIONS_TRACKING_SECRET' : '_APP_NOTIFICATIONS_TRACKING_SECRET=' . $previousTrackingSecret);
        }

        $this->assertSame(1, $spy->sendCount, 'SMTP send must be invoked exactly once');
        $this->assertInstanceOf(\Utopia\Messaging\Messages\Email::class, $spy->captured);

        $message = $spy->captured;
        $this->assertSame('happy@example.test', $message->getTo()[0]['email'] ?? '');
        $this->assertSame('Welcome aboard', $message->getSubject());

        $body = $message->getContent();
        $this->assertStringContainsString('<img src=', $body, 'tracking logo must be injected');
        $this->assertStringContainsString('/v1/notifications/logos/appwrite?jwt=', $body);

        $closing = \strripos($body, '</body>');
        $logo = \strripos($body, '<img src=');
        $this->assertNotFalse($closing);
        $this->assertNotFalse($logo);
        $this->assertLessThan($closing, $logo, 'logo must precede the closing </body>');

        $this->assertSame(1, $worker->persistAlertCalls, 'email channel must persist exactly once after a successful send');
        $messageId = \md5('happy-email');
        $rows = $this->database->find('notifications', [
            Query::equal('messageId', [$messageId]),
        ]);
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('user-happy', $row->getAttribute('resourceId'));
        $this->assertSame(NOTIFICATION_TYPE_EMAIL, $row->getAttribute('channel'));
        $this->assertFalse($row->getAttribute('read'));

        // dispatchEmail's returned alertId must match the row $id.
        $this->assertSame($worker->persistedIds[0], $row->getId());
        $this->assertLessThanOrEqual(36, \strlen($row->getId()), 'alert ids must pass the UID route validator');
    }

    /**
     * Worker happy-path: console channel.
     *
     * The Console adapter writes the alert directly; the action loop must
     * NOT call `persistAlert` for console recipients. Permissions must grant
     * the recipient resource read/update/delete access.
     */
    public function testConsoleChannelHappyPath(): void
    {
        $worker = new CountingPersistAlertNotifications();

        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [
                [
                    'address' => 'console-recipient',
                    'channel' => NOTIFICATION_TYPE_CONSOLE,
                    'resourceType' => RESOURCE_TYPE_TEAMS,
                    'resourceId' => 't1',
                    'resourceInternalId' => 't1-internal',
                    'parentResourceType' => RESOURCE_TYPE_PROJECTS,
                    'parentResourceId' => 'project-x',
                    'parentResourceInternalId' => 'project-internal-x',
                ],
            ],
            'subject' => 'Heads up',
            'body' => 'console body',
            'deduplicationKey' => 'happy-console',
        ];

        $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

        $rows = $this->database->find('notifications', [
            Query::equal('channel', ['console']),
        ]);
        $this->assertCount(1, $rows, 'console adapter must write exactly one alert');

        $row = $rows[0];
        $this->assertSame(RESOURCE_TYPE_TEAMS, $row->getAttribute('resourceType'));
        $this->assertSame('t1', $row->getAttribute('resourceId'));
        $this->assertSame('t1-internal', $row->getAttribute('resourceInternalId'));
        $this->assertSame(NOTIFICATION_TYPE_CONSOLE, $row->getAttribute('channel'));
        $this->assertFalse($row->getAttribute('read'));

        $messageId = \md5('happy-console');
        $expectedId = $this->alertId($messageId, NOTIFICATION_TYPE_CONSOLE, 'console-recipient', RESOURCE_TYPE_TEAMS, 't1');
        $this->assertSame($expectedId, $row->getId(), 'row $id must match adapter suffix scheme');
        $this->assertLessThanOrEqual(36, \strlen($row->getId()), 'alert ids must pass the UID route validator');

        $permissions = $row->getPermissions();
        $this->assertContains(Permission::read(Role::team('t1')), $permissions);
        $this->assertContains(Permission::update(Role::team('t1', 'owner')), $permissions);
        $this->assertContains(Permission::delete(Role::team('t1', 'owner')), $permissions);

        $this->assertSame(0, $worker->persistAlertCalls, 'console channel must NOT trigger action-loop persistAlert');
    }

    public function testConsoleChannelUsesPreviewBodyInsteadOfRenderedEmailHtml(): void
    {
        $worker = new CountingPersistAlertNotifications();

        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [
                $this->userRecipient('user-preview', NOTIFICATION_TYPE_CONSOLE, 'user-preview'),
            ],
            'subject' => 'Webhook {{name}} paused',
            'preview' => 'Plain alert for {{name}}.',
            'body' => '<!DOCTYPE html><html><head><style>body{}</style></head><body><strong>Email-only HTML</strong></body></html>',
            'templateParams' => ['name' => 'orders'],
            'deduplicationKey' => 'console-preview',
        ];

        $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

        $rows = $this->database->find('notifications');
        $this->assertCount(1, $rows);
        $this->assertSame('Webhook orders paused', $rows[0]->getAttribute('title'));
        $this->assertSame('Plain alert for orders.', $rows[0]->getAttribute('body'));
        $this->assertStringNotContainsString('<!DOCTYPE html>', $rows[0]->getAttribute('body'));
    }

    public function testEmailChannelThrowsWhenSmtpIsNotConfigured(): void
    {
        $previousSmtpHost = \getenv('_APP_SMTP_HOST');
        \putenv('_APP_SMTP_HOST=');
        $threw = false;

        try {
            $worker = new CountingPersistAlertNotifications();
            $payload = [
                'project' => ['$id' => 'project-x'],
                'recipients' => [
                    $this->userRecipient('missing-smtp@example.test', NOTIFICATION_TYPE_EMAIL, 'user-smtp'),
                ],
                'subject' => 'No SMTP',
                'body' => 'Body',
                'deduplicationKey' => 'missing-smtp',
            ];

            try {
                $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);
            } catch (\Exception $error) {
                $this->assertStringContainsString('No SMTP configuration has been set', $error->getMessage());
                $threw = true;
            }
        } finally {
            \putenv($previousSmtpHost === false ? '_APP_SMTP_HOST' : '_APP_SMTP_HOST=' . $previousSmtpHost);
        }

        $this->assertTrue($threw, 'missing SMTP must fail email delivery so the queue can retry');
        $this->assertSame(0, $worker->persistAlertCalls);
        $this->assertCount(0, $this->database->find('notifications'));
        $this->assertSame('no_smtp', $this->log->getTags()['email_skipped'] ?? null);
    }

    public function testConsoleChannelRejectsInvalidImplicitUserId(): void
    {
        $worker = new CountingPersistAlertNotifications();

        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [
                ['address' => 'not an appwrite user id', 'channel' => NOTIFICATION_TYPE_CONSOLE],
            ],
            'subject' => 'Invalid',
            'body' => 'Body',
            'deduplicationKey' => 'invalid-console-user',
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid console alert resourceId');

        $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);
    }

    /**
     * Worker happy-path: webhook channel.
     *
     * The webhook adapter receives a POST with the rendered subject/body,
     * an `X-Appwrite-Webhook-Signature` header derived from the per-recipient
     * `signatureKey`, and a single alert row is persisted by the action loop
     * AFTER the send returns successfully (webhook adapters do not persist
     * themselves).
     *
     * We swap in a worker subclass that uses the in-process `CapturingWebhook`
     * adapter so we exercise the real signing path without touching the network.
     */
    public function testWebhookChannelHappyPath(): void
    {
        $captured = [];
        $worker = new class ($captured) extends CountingPersistAlertNotifications {
            /** @param array<int, array<string, mixed>> $captured */
            public function __construct(public array &$captured)
            {
                parent::__construct();
            }

            protected function dispatchWebhook(array $recipient, array $payload, Log $log): ?string
            {
                $adapter = new \Tests\Unit\Utopia\Messaging\Adapter\CapturingWebhook();
                $message = new \Appwrite\Utopia\Messaging\Messages\Webhook(
                    urls: [$recipient['address']],
                    payload: [
                        'subject' => $payload['subject'] ?? '',
                        'body' => $payload['body'] ?? '',
                        'template' => $payload['template'] ?? '',
                        'params' => $payload['templateParams'] ?? [],
                        'project' => \is_array($payload['project'] ?? null) ? ($payload['project']['$id'] ?? null) : null,
                        'deduplicationKey' => $payload['deduplicationKey'] ?? '',
                        'events' => $payload['events'] ?? [],
                    ],
                    signingSecret: $recipient['signatureKey'] ?? null,
                );

                $result = $adapter->send($message);
                $this->captured = $adapter->captured;

                if (($result['deliveredTo'] ?? 0) === 0) {
                    throw new \Exception('Webhook delivery failed');
                }
                return null;
            }
        };

        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [
                [
                    'address' => 'https://hooks.example.test/in',
                    'channel' => NOTIFICATION_TYPE_WEBHOOK,
                    'signatureKey' => 'tenant-secret',
                    'resourceType' => RESOURCE_TYPE_USERS,
                    'resourceId' => 'user-h',
                    'resourceInternalId' => 'user-h-internal',
                    'parentResourceType' => RESOURCE_TYPE_PROJECTS,
                    'parentResourceId' => 'project-x',
                    'parentResourceInternalId' => 'project-internal-x',
                ],
            ],
            'subject' => 'Heads up',
            'body' => 'webhook body',
            'deduplicationKey' => 'happy-webhook',
        ];

        $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

        $this->assertCount(1, $worker->captured, 'adapter must POST exactly once');
        $request = $worker->captured[0];

        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://hooks.example.test/in', $request['url']);

        $sent = \json_decode($request['body'], true);
        $this->assertSame('Heads up', $sent['subject']);
        $this->assertSame('webhook body', $sent['body']);

        $headerLine = \implode("\n", $request['headers']);
        $this->assertStringContainsString('Content-Type: application/json', $headerLine);
        $this->assertStringContainsString('X-Appwrite-Webhook-Signature: sha256=', $headerLine);

        // Verify the HMAC matches the signing secret on the recipient struct.
        $signature = null;
        $timestamp = null;
        foreach ($request['headers'] as $header) {
            if (\str_starts_with($header, 'X-Appwrite-Webhook-Signature: ')) {
                $signature = \substr($header, \strlen('X-Appwrite-Webhook-Signature: '));
            } elseif (\str_starts_with($header, 'X-Appwrite-Webhook-Timestamp: ')) {
                $timestamp = \substr($header, \strlen('X-Appwrite-Webhook-Timestamp: '));
            }
        }
        $this->assertNotNull($signature);
        $this->assertNotNull($timestamp);
        $expected = 'sha256=' . \hash_hmac('sha256', $timestamp . '.' . $request['body'], 'tenant-secret');
        $this->assertSame($expected, $signature);

        // Action loop must persist exactly one webhook alert row AFTER the send.
        $this->assertSame(1, $worker->persistAlertCalls);
        $rows = $this->database->find('notifications', [
            Query::equal('messageId', [\md5('happy-webhook')]),
        ]);
        $this->assertCount(1, $rows);
        $this->assertSame(NOTIFICATION_TYPE_WEBHOOK, $rows[0]->getAttribute('channel'));
        $this->assertFalse($rows[0]->getAttribute('read'));
    }
}
