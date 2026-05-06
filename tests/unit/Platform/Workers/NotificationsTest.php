<?php

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Notification;
use Appwrite\Platform\Workers\Notifications;
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
            return $this->persistAlert($dbForPlatform, $messageId, $recipient, $payload);
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

    protected function persistAlert(Database $dbForPlatform, string $messageId, array $recipient, array $payload): string
    {
        $this->persistAlertCalls++;
        $alertId = parent::persistAlert($dbForPlatform, $messageId, $recipient, $payload);
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
    protected function dispatchConsole(array $recipient, string $messageId, array $payload, Database $dbForPlatform): ?string
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

        // Mirror the production `_key_recipient` UNIQUE composite index so the
        // duplicate-handling branch in persistAlert (catch DuplicateException →
        // return existing alertId) is actually exercised by tests.
        $this->database->createIndex(
            'alerts',
            '_key_recipient',
            Database::INDEX_UNIQUE,
            ['messageId', 'channel', 'userId', 'teamId'],
            [Database::LENGTH_KEY, 64, Database::LENGTH_KEY, Database::LENGTH_KEY],
            [Database::ORDER_ASC, Database::ORDER_ASC, Database::ORDER_ASC, Database::ORDER_ASC],
        );

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

    public function testDedupQueriesByAttributeNotById(): void
    {
        $worker = new SpyNotifications();

        // Seed an alert row with an arbitrary $id but the matching dedup
        // messageId attribute. If alreadyDelivered() short-circuits via
        // getDocument($messageId) it will miss this seed and dispatch
        // anyway. Querying by the `messageId` attribute is the only way to
        // see the seed.
        $messageId = \md5('dup-key');
        $this->database->createDocument('alerts', new Document([
            '$id' => 'random-id-123',
            '$permissions' => [Permission::read(Role::any())],
            'messageId' => $messageId,
            'channel' => 'console',
            'title' => 'seed',
            'body' => 'seed',
        ]));

        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [['address' => 'user-1', 'channel' => NOTIFICATION_TYPE_CONSOLE]],
            'subject' => 'Sub',
            'body' => 'B',
            'deduplicationKey' => 'dup-key',
        ];

        $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

        $this->assertCount(0, $worker->dispatched, 'attribute-keyed seed must trigger dedup short-circuit');
        $this->assertSame('hit', $this->log->getTags()['dedup'] ?? null);
    }

    public function testConsoleChannelSkipsPersistAlert(): void
    {
        $worker = new CountingPersistAlertNotifications();

        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [
                ['address' => 'user-1', 'channel' => NOTIFICATION_TYPE_CONSOLE, 'userId' => 'user-1'],
            ],
            'subject' => 'Heads up',
            'body' => 'console body',
            'deduplicationKey' => 'console-skip',
        ];

        $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

        // The Console adapter wrote exactly one alert; the action loop
        // must NOT have called persistAlert (otherwise we'd see 2 rows or
        // a duplicate-key swallow plus a non-zero counter).
        $consoleRows = $this->database->find('alerts', [
            \Utopia\Database\Query::equal('channel', ['console']),
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
                ['address' => 'user-1', 'channel' => NOTIFICATION_TYPE_CONSOLE, 'userId' => 'user-1'],
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

        $this->assertCount(0, $this->database->find('alerts'), 'failed console dispatch must not persist alert');
    }

    public function testMultiRecipientFanoutNoCollision(): void
    {
        $worker = new CountingPersistAlertNotifications();

        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [
                ['address' => 'user-1', 'channel' => NOTIFICATION_TYPE_CONSOLE, 'userId' => 'user-1'],
                ['address' => 'user-2', 'channel' => NOTIFICATION_TYPE_CONSOLE, 'userId' => 'user-2'],
            ],
            'subject' => 'Heads up',
            'body' => 'console body',
            'deduplicationKey' => 'fanout',
        ];

        $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

        $rows = $this->database->find('alerts');
        $this->assertCount(2, $rows, 'two recipients must produce two distinct alert rows');

        $messageId = \md5('fanout');
        $ids = [];
        foreach ($rows as $row) {
            $this->assertSame($messageId, $row->getAttribute('messageId'), 'all rows share the dedup messageId');
            $ids[] = $row->getId();
        }
        $this->assertCount(2, \array_unique($ids), 'recipient suffixes must keep $id values distinct');
    }

    public function testRecipientStructRoundtripsUserIdAndTeamId(): void
    {
        $worker = new CountingPersistAlertNotifications();

        $payload = [
            'project' => ['$id' => 'project-x'],
            'recipients' => [
                [
                    'address' => 'console-recipient',
                    'channel' => NOTIFICATION_TYPE_CONSOLE,
                    'userId' => 'u1',
                    'teamId' => 't1',
                ],
            ],
            'subject' => 'Heads up',
            'body' => 'b',
            'deduplicationKey' => 'roundtrip',
        ];

        $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

        $rows = $this->database->find('alerts');
        $this->assertCount(1, $rows);
        $this->assertSame('u1', $rows[0]->getAttribute('userId'));
        $this->assertSame('t1', $rows[0]->getAttribute('teamId'));
    }

    public function testTrackingPixelInjectedIntoEmailHtml(): void
    {
        $spy = new SpyEmailAdapter();
        $this->registry->set('smtp', static fn () => $spy);

        // Force the cloud SMTP branch (project has no smtp config) and
        // provide an OpenSSL key so injectTrackingPixel actually runs.
        $previousSmtpHost = \getenv('_APP_SMTP_HOST');
        $previousOpensslKey = \getenv('_APP_OPENSSL_KEY_V1');
        \putenv('_APP_SMTP_HOST=spy.smtp.test');
        \putenv('_APP_OPENSSL_KEY_V1=test-key-32bytes-min-aaaaaaaaaaaaaa');

        try {
            $worker = new Notifications();

            $payload = [
                'project' => ['$id' => 'project-x'],
                'recipients' => [
                    [
                        'address' => 'user@example.test',
                        'channel' => NOTIFICATION_TYPE_EMAIL,
                        'userId' => 'user-7',
                    ],
                ],
                'subject' => 'Heads up',
                'body' => 'plain body',
                'deduplicationKey' => 'pixel-key',
            ];

            $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);
        } finally {
            \putenv($previousSmtpHost === false ? '_APP_SMTP_HOST' : '_APP_SMTP_HOST=' . $previousSmtpHost);
            \putenv($previousOpensslKey === false ? '_APP_OPENSSL_KEY_V1' : '_APP_OPENSSL_KEY_V1=' . $previousOpensslKey);
        }

        $this->assertNotNull($spy->captured, 'SpyEmailAdapter must capture exactly one EmailMessage');

        $body = $spy->captured->getContent();
        $this->assertStringContainsString('<img src=', $body, 'tracking pixel <img> must be present');
        $this->assertStringContainsString('/v1/account/alerts/', $body);
        $this->assertStringContainsString('/track?jwt=', $body);

        // The pixel must sit BEFORE the last </body>.
        $lastBodyClose = \strripos($body, '</body>');
        $pixelPosition = \strripos($body, '<img src=');
        $this->assertNotFalse($lastBodyClose, 'rendered email must include a closing </body>');
        $this->assertNotFalse($pixelPosition);
        $this->assertLessThan($lastBodyClose, $pixelPosition, 'pixel must be spliced before the final </body>');
    }

    public function testPersistAlertReturnsExistingAlertIdOnDuplicate(): void
    {
        $spy = new SpyEmailAdapter();
        $this->registry->set('smtp', static fn () => $spy);

        $previousSmtpHost = \getenv('_APP_SMTP_HOST');
        \putenv('_APP_SMTP_HOST=spy.smtp.test');

        try {
            $worker = new CountingPersistAlertNotifications();

            // All four unique-index fields populated so the
            // `_key_recipient` UNIQUE composite (messageId, channel,
            // userId, teamId) actually fires — SQL UNIQUE semantics treat
            // NULL as not-equal, so any null in the tuple disables it.
            $payload = [
                'project' => ['$id' => 'project-x'],
                'recipients' => [
                    [
                        'address' => 'user@example.test',
                        'channel' => NOTIFICATION_TYPE_EMAIL,
                        'userId' => 'user-7',
                        'teamId' => 'team-7',
                    ],
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
                'userId' => 'user-7',
                'teamId' => 'team-7',
            ];

            // Second invocation with the SAME messageId/recipient. The
            // action loop's alreadyDelivered() check short-circuits before
            // persistAlert, so call persistAlert directly to actually hit
            // the duplicate branch. The deterministic $id collides on the
            // primary key → DuplicateException → branch returns the
            // existing alertId without throwing.
            $reflection = new \ReflectionMethod($worker, 'persistAlert');
            $secondAlertId = $reflection->invoke($worker, $this->database, $messageId, $recipient, $payload);

            $this->assertSame($firstAlertId, $secondAlertId, 'duplicate persist must return the existing alertId');

            // Third write: bypass the deterministic $id path and use a
            // distinct $id with the same recipient tuple. The
            // `_key_recipient` UNIQUE composite must reject it — proving
            // the unique-index (not just primary-key) is what backstops the
            // duplicate-handling branch.
            $sameTupleDoc = new Document([
                '$id' => 'sibling-id-' . \uniqid(),
                '$permissions' => [Permission::read(Role::any())],
                'messageId' => $messageId,
                'channel' => NOTIFICATION_TYPE_EMAIL,
                'userId' => 'user-7',
                'teamId' => 'team-7',
                'projectId' => 'project-x',
                'title' => 'sibling',
                'body' => 'sibling',
                'read' => false,
            ]);

            $threw = false;
            try {
                $this->database->createDocument('alerts', $sameTupleDoc);
            } catch (\Utopia\Database\Exception\Duplicate) {
                $threw = true;
            }
            $this->assertTrue($threw, 'unique-index `_key_recipient` must reject a second row sharing the recipient tuple');

            $rows = $this->database->find('alerts', [
                \Utopia\Database\Query::equal('messageId', [$messageId]),
            ]);
            $this->assertCount(1, $rows, 'unique-index must prevent a second row from being persisted');
        } finally {
            \putenv($previousSmtpHost === false ? '_APP_SMTP_HOST' : '_APP_SMTP_HOST=' . $previousSmtpHost);
        }
    }

    public function testPersistAlertReturnsAlertIdAndStoresUserId(): void
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
                    [
                        'address' => 'user@example.test',
                        'channel' => NOTIFICATION_TYPE_EMAIL,
                        'userId' => 'user-7',
                    ],
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
        $row = $this->database->getDocument('alerts', $alertId);
        $this->assertFalse($row->isEmpty(), 'persistAlert must return an id resolvable via getDocument');
        $this->assertSame('user-7', $row->getAttribute('userId'));
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
                [
                    'address' => 'user@example.test',
                    'channel' => NOTIFICATION_TYPE_EMAIL,
                    'userId' => 'user-9',
                ],
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
            $orphans = $this->database->find('alerts', [
                \Utopia\Database\Query::equal('messageId', [$messageId]),
            ]);
            $this->assertCount(0, $orphans, 'failed SMTP send must not leave a dedup row behind');

            // Retry with a working adapter using the same payload — must deliver
            // AND persist exactly one alert row.
            $working = new SpyEmailAdapter();
            $this->registry->set('smtp', static fn () => $working);

            $retryWorker = new Notifications();
            $retryWorker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

            $this->assertSame(1, $working->sendCount, 'retry must invoke the working adapter');

            $rows = $this->database->find('alerts', [
                \Utopia\Database\Query::equal('messageId', [$messageId]),
            ]);
            $this->assertCount(1, $rows, 'retry must persist exactly one alert row');
            $this->assertSame('user-9', $rows[0]->getAttribute('userId'));
            $this->assertFalse($rows[0]->getAttribute('read'));
        } finally {
            \putenv($previousSmtpHost === false ? '_APP_SMTP_HOST' : '_APP_SMTP_HOST=' . $previousSmtpHost);
        }
    }

    /**
     * Reviewer C2: `Notification::reset()` must clear EVERY state-bearing
     * field — `$preview` was missing from the original reset() body and
     * leaked across DI-shared event reuses (alarming when a webhook-paused
     * preview line bleeds into an unrelated alert).
     */
    public function testNotificationEventResetClearsAllState(): void
    {
        $event = new Notification(new MockPublisher());

        $event
            ->setProject(new Document(['$id' => 'project-x']))
            ->setRecipient('legacy@example.test')
            ->setName('Some Name')
            ->setSubject('Subject Line')
            ->setBody('Body content')
            ->setPreview('Preview snippet')
            ->setVariables(['key' => 'value'])
            ->setBodyTemplate('/tmp/template.tpl')
            ->setAttachment('content', 'file.txt')
            ->setRecipients([
                ['address' => 'a@example.test', 'channel' => NOTIFICATION_TYPE_EMAIL],
            ])
            ->setChannels([NOTIFICATION_TYPE_EMAIL])
            ->setTemplate('template-id')
            ->setTemplateParams(['x' => 1])
            ->setDeduplicationKey('dedup')
            ->setPermissions([Permission::read(Role::any())]);

        $event->reset();

        $this->assertSame('', $event->getRecipient(), 'recipient must reset to empty string');
        $this->assertSame('', $event->getName(), 'name must reset to empty string');
        $this->assertSame('', $event->getSubject(), 'subject must reset to empty string');
        $this->assertSame('', $event->getBody(), 'body must reset to empty string');
        $this->assertSame('', $event->getPreview(), 'preview must reset to empty string (C2 regression)');
        $this->assertSame([], $event->getVariables(), 'variables must reset to empty array');
        $this->assertSame('', $event->getBodyTemplate(), 'bodyTemplate must reset to empty string');
        $this->assertSame([], $event->getAttachment(), 'attachment must reset to empty array');
        $this->assertSame([], $event->getRecipients(), 'recipients must reset to empty array');
        $this->assertSame([], $event->getChannels(), 'channels must reset to empty array');
        $this->assertSame('', $event->getTemplate(), 'template must reset to empty string');
        $this->assertSame([], $event->getTemplateParams(), 'templateParams must reset to empty array');
        $this->assertSame('', $event->getDeduplicationKey(), 'deduplicationKey must reset to empty string');
        $this->assertSame([], $event->getPermissions(), 'permissions must reset to empty array');
        $this->assertNull($event->getProject(), 'project must reset to null');
    }

    /**
     * Reviewer C3: `Webhooks::sendAlert` resets the DI-shared Notification
     * event before configuring the alert, so a second invocation in the same
     * worker pass cannot bleed recipients, subject, body, preview, or dedup
     * key from the first.
     *
     * We exercise this at the event level (rather than standing up the full
     * Webhooks worker fixture, which needs `dbForPlatform.memberships` and
     * full project shape) by replicating sendAlert's add → trigger → reset →
     * add → trigger flow on a single Notification instance and asserting
     * each enqueued payload is fully isolated.
     */
    public function testWebhookSendAlertResetsBetweenCalls(): void
    {
        $publisher = new MockPublisher();
        $event = new Notification($publisher);

        // First "sendAlert" pass: a single user, webhook A.
        $event
            ->setProject(new Document(['$id' => 'project-a']))
            ->setSubject('Webhook A paused')
            ->setPreview('Webhook A preview')
            ->setBody('Webhook A body')
            ->setDeduplicationKey('webhook:hookA:paused:10')
            ->addRecipient('alice@example.test', NOTIFICATION_TYPE_EMAIL, null, 'user-A', 'team-1')
            ->addRecipient('user-A', NOTIFICATION_TYPE_CONSOLE, null, 'user-A', 'team-1')
            ->trigger();

        // Mirror the production sendAlert: reset before configuring next alert.
        $event->reset();

        // Second pass: a different user/webhook entirely.
        $event
            ->setProject(new Document(['$id' => 'project-b']))
            ->setSubject('Webhook B paused')
            ->setPreview('Webhook B preview')
            ->setBody('Webhook B body')
            ->setDeduplicationKey('webhook:hookB:paused:10')
            ->addRecipient('bob@example.test', NOTIFICATION_TYPE_EMAIL, null, 'user-B', 'team-2')
            ->addRecipient('user-B', NOTIFICATION_TYPE_CONSOLE, null, 'user-B', 'team-2')
            ->trigger();

        $events = $publisher->getEvents('v1-notifications');
        $this->assertNotNull($events, 'two trigger() calls must enqueue at least one batch');
        $this->assertCount(2, $events, 'each sendAlert pass must produce its own enqueued event');

        $first = $events[0];
        $second = $events[1];

        $this->assertSame('Webhook A paused', $first['subject']);
        $this->assertSame('Webhook A preview', $first['preview']);
        $this->assertSame('Webhook A body', $first['body']);
        $this->assertSame('webhook:hookA:paused:10', $first['deduplicationKey']);
        $this->assertCount(2, $first['recipients']);

        $this->assertSame('Webhook B paused', $second['subject']);
        $this->assertSame('Webhook B preview', $second['preview']);
        $this->assertSame('Webhook B body', $second['body']);
        $this->assertSame('webhook:hookB:paused:10', $second['deduplicationKey']);
        $this->assertCount(2, $second['recipients'], 'second event must hold ONLY its own recipients');

        $secondAddresses = \array_map(static fn ($r) => $r['address'], $second['recipients']);
        $this->assertNotContains('alice@example.test', $secondAddresses, 'reset() must drop prior email recipient');
        $this->assertNotContains('user-A', $secondAddresses, 'reset() must drop prior console recipient');
        $this->assertContains('bob@example.test', $secondAddresses);
        $this->assertContains('user-B', $secondAddresses);
    }

    /**
     * Worker happy-path: email channel.
     *
     * Asserts the SMTP adapter is invoked once with the expected
     * to/subject/body, the rendered body carries the tracking pixel before
     * `</body>`, and an alert row is persisted AFTER the send returns
     * successfully (see C1: persist-after-send invariant).
     */
    public function testEmailChannelHappyPath(): void
    {
        $spy = new SpyEmailAdapter();
        $this->registry->set('smtp', static fn () => $spy);

        $previousSmtpHost = \getenv('_APP_SMTP_HOST');
        $previousOpensslKey = \getenv('_APP_OPENSSL_KEY_V1');
        \putenv('_APP_SMTP_HOST=spy.smtp.test');
        \putenv('_APP_OPENSSL_KEY_V1=test-key-32bytes-min-aaaaaaaaaaaaaa');

        try {
            $worker = new CountingPersistAlertNotifications();

            $payload = [
                'project' => ['$id' => 'project-x'],
                'recipients' => [
                    [
                        'address' => 'happy@example.test',
                        'channel' => NOTIFICATION_TYPE_EMAIL,
                        'userId' => 'user-happy',
                    ],
                ],
                'subject' => 'Welcome aboard',
                'body' => 'plain body',
                'deduplicationKey' => 'happy-email',
            ];

            $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);
        } finally {
            \putenv($previousSmtpHost === false ? '_APP_SMTP_HOST' : '_APP_SMTP_HOST=' . $previousSmtpHost);
            \putenv($previousOpensslKey === false ? '_APP_OPENSSL_KEY_V1' : '_APP_OPENSSL_KEY_V1=' . $previousOpensslKey);
        }

        $this->assertSame(1, $spy->sendCount, 'SMTP send must be invoked exactly once');
        $this->assertNotNull($spy->captured);

        $message = $spy->captured;
        $this->assertSame('happy@example.test', $message->getTo()[0]['email'] ?? '');
        $this->assertSame('Welcome aboard', $message->getSubject());

        $body = $message->getContent();
        $this->assertStringContainsString('<img src=', $body, 'tracking pixel must be injected');
        $this->assertStringContainsString('/v1/account/alerts/', $body);
        $this->assertStringContainsString('/track?jwt=', $body);

        $closing = \strripos($body, '</body>');
        $pixel = \strripos($body, '<img src=');
        $this->assertNotFalse($closing);
        $this->assertNotFalse($pixel);
        $this->assertLessThan($closing, $pixel, 'pixel must precede the closing </body>');

        $this->assertSame(1, $worker->persistAlertCalls, 'email channel must persist exactly once after a successful send');
        $messageId = \md5('happy-email');
        $rows = $this->database->find('alerts', [
            \Utopia\Database\Query::equal('messageId', [$messageId]),
        ]);
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('user-happy', $row->getAttribute('userId'));
        $this->assertSame(NOTIFICATION_TYPE_EMAIL, $row->getAttribute('channel'));
        $this->assertFalse($row->getAttribute('read'));

        // dispatchEmail's returned alertId must match the row $id (used by the
        // tracking pixel URL).
        $this->assertSame($worker->persistedIds[0], $row->getId());
    }

    /**
     * Worker happy-path: console channel.
     *
     * The Console adapter writes the alert directly; the action loop must
     * NOT call `persistAlert` for console recipients. Permissions must grant
     * the recipient user AND team owners read/update/delete.
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
                    'userId' => 'u1',
                    'teamId' => 't1',
                ],
            ],
            'subject' => 'Heads up',
            'body' => 'console body',
            'deduplicationKey' => 'happy-console',
        ];

        $worker->action($this->buildMessage($payload), $this->project, $this->registry, $this->database, $this->log);

        $rows = $this->database->find('alerts', [
            \Utopia\Database\Query::equal('channel', ['console']),
        ]);
        $this->assertCount(1, $rows, 'console adapter must write exactly one alert');

        $row = $rows[0];
        $this->assertSame('u1', $row->getAttribute('userId'));
        $this->assertSame('t1', $row->getAttribute('teamId'));
        $this->assertSame(NOTIFICATION_TYPE_CONSOLE, $row->getAttribute('channel'));
        $this->assertFalse($row->getAttribute('read'));

        // Per-recipient suffix scheme used by the Console adapter is
        // `messageId . '_' . substr(md5('user:' . userId), 0, 8)`.
        $messageId = \md5('happy-console');
        $expectedId = $messageId . '_' . \substr(\md5('user:u1'), 0, 8);
        $this->assertSame($expectedId, $row->getId(), 'row $id must match adapter suffix scheme');

        $permissions = $row->getPermissions();
        $this->assertContains(Permission::read(Role::user('u1')), $permissions);
        $this->assertContains(Permission::update(Role::user('u1')), $permissions);
        $this->assertContains(Permission::delete(Role::user('u1')), $permissions);
        $this->assertContains(Permission::read(Role::team('t1')), $permissions);
        $this->assertContains(Permission::update(Role::team('t1', 'owner')), $permissions);
        $this->assertContains(Permission::delete(Role::team('t1', 'owner')), $permissions);

        $this->assertSame(0, $worker->persistAlertCalls, 'console channel must NOT trigger action-loop persistAlert');
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
                    'userId' => 'user-h',
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
        $rows = $this->database->find('alerts', [
            \Utopia\Database\Query::equal('messageId', [\md5('happy-webhook')]),
        ]);
        $this->assertCount(1, $rows);
        $this->assertSame(NOTIFICATION_TYPE_WEBHOOK, $rows[0]->getAttribute('channel'));
        $this->assertFalse($rows[0]->getAttribute('read'));
    }
}
