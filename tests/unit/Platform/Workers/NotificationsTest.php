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
 */
class SpyEmailAdapter extends EmailAdapter
{
    public ?EmailMessage $captured = null;

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
        $this->captured = $message;
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
}
