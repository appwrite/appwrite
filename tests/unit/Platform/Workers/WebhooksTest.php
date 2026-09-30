<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Notification as NotificationPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Webhook as WebhookEvent;
use Appwrite\Platform\Workers\Webhooks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Codec\Json;
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

    public function testDeliversEventToEveryMatchingWebhookUnderItsOwnDeliveryId(): void
    {
        $this->startReceiver();
        $database = $this->createPlatformDatabase();
        $project = $this->createProject($database, ['/ok/a', '/ok/b']);
        $worker = new Webhooks();
        $cache = new Cache(new MemoryCache());

        $first = $this->publish($project);
        $this->assertNull($this->deliver($worker, $first, 'pid-1', 0, $project, $database, $cache));
        $second = $this->publish($project);
        $this->assertNull($this->deliver($worker, $second, 'pid-2', 0, $project, $database, $cache));

        $a = $this->deliveryIds('/ok/a');
        $b = $this->deliveryIds('/ok/b');
        $this->assertCount(2, $a);
        $this->assertCount(2, $b);
        // One id per event and webhook: no two of the four deliveries share one.
        $this->assertCount(4, \array_unique([...$a, ...$b]));
        foreach ([...$a, ...$b] as $id) {
            $this->assertNotEmpty($id);
        }
    }

    /**
     * @return \Iterator<string, array{\Closure(string): string}>
     */
    public static function redeliveries(): \Iterator
    {
        // JetStream redelivers the stored message itself, pid and all.
        yield 'nats' => [fn (string $pid): string => $pid];
        // The Redis broker requeues a failed message under a fresh pid.
        yield 'redis' => [fn (string $pid): string => $pid . '-requeued'];
    }

    #[DataProvider('redeliveries')]
    public function testRedeliveryRetriesOnlyTheWebhookThatFailed(\Closure $redeliveredPid): void
    {
        $this->startReceiver();
        $database = $this->createPlatformDatabase();
        $project = $this->createProject($database, ['/ok/healthy', '/flaky/down']);
        $worker = new Webhooks();
        $cache = new Cache(new MemoryCache());
        $payload = $this->publish($project);

        $failure = $this->deliver($worker, $payload, 'pid-1', 0, $project, $database, $cache);

        $this->assertInstanceOf(\Throwable::class, $failure, 'A failed webhook must ask the broker for another attempt');
        $this->assertStringContainsString('/flaky/down', $failure->getMessage());
        $this->assertStringNotContainsString('/ok/healthy', $failure->getMessage());
        $this->assertSame(1, $database->getDocument('webhooks', 'webhook-2')->getAttribute('attempts'));

        $this->assertNull($this->deliver($worker, $payload, $redeliveredPid('pid-1'), 1, $project, $database, $cache));

        $this->assertCount(1, $this->deliveryIds('/ok/healthy'), 'The webhook that accepted the event received it again');
        $down = $this->deliveryIds('/flaky/down');
        $this->assertCount(2, $down);
        $this->assertSame($down[0], $down[1], 'A retry must carry the delivery id of the attempt it repeats');
        $this->assertSame(0, $database->getDocument('webhooks', 'webhook-2')->getAttribute('attempts'));
    }

    public function testEveryWebhookFailingIsRetriedAndCountedOncePerAttempt(): void
    {
        $this->startReceiver();
        $database = $this->createPlatformDatabase();
        $project = $this->createProject($database, ['/fail/a', '/fail/b']);
        $worker = new Webhooks();
        $cache = new Cache(new MemoryCache());
        $payload = $this->publish($project);

        $first = $this->deliver($worker, $payload, 'pid-1', 0, $project, $database, $cache);
        $second = $this->deliver($worker, $payload, 'pid-1', 1, $project, $database, $cache);

        $this->assertInstanceOf(\Throwable::class, $first);
        $this->assertInstanceOf(\Throwable::class, $second);
        foreach (['/fail/a' => 'webhook-1', '/fail/b' => 'webhook-2'] as $path => $webhookId) {
            $this->assertStringContainsString($path, $second->getMessage());
            $ids = $this->deliveryIds($path);
            $this->assertCount(2, $ids);
            $this->assertSame($ids[0], $ids[1]);
            $this->assertSame(2, $database->getDocument('webhooks', $webhookId)->getAttribute('attempts'));
        }
    }

    public function testMessagePublishedWithoutAnEventIdKeepsItsDeliveryIdAcrossRedelivery(): void
    {
        $this->startReceiver();
        $database = $this->createPlatformDatabase();
        $project = $this->createProject($database, ['/ok/healthy', '/flaky/down']);
        $worker = new Webhooks();
        $cache = new Cache(new MemoryCache());
        // What a publisher from before this change put on the queue.
        $payload = $this->publish($project);
        unset($payload['eventId']);

        $this->assertInstanceOf(\Throwable::class, $this->deliver($worker, $payload, 'pid-1', 0, $project, $database, $cache));
        // Redis requeues it under a fresh pid, which must not change what it is called.
        $this->assertNull($this->deliver($worker, $payload, 'pid-1-requeued', 1, $project, $database, $cache));

        $this->assertCount(1, $this->deliveryIds('/ok/healthy'));
        $down = $this->deliveryIds('/flaky/down');
        $this->assertCount(2, $down);
        $this->assertSame($down[0], $down[1]);
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->receiver)) {
            \proc_terminate($this->receiver);
            \proc_close($this->receiver);
        }
        $this->receiver = null;
        if ($this->receiverLog !== '' && \is_file($this->receiverLog)) {
            \unlink($this->receiverLog);
        }
        if ($this->previousEnv !== null) {
            \putenv('_APP_ENV' . ($this->previousEnv === false ? '' : '=' . $this->previousEnv));
        }
    }

    /** @var resource|null */
    private $receiver = null;
    private string $receiverLog = '';
    private string $receiverUrl = '';
    private string|false|null $previousEnv = null;

    /**
     * Serve Webhooks/receiver.php over real HTTP on a free local port.
     */
    private function startReceiver(): void
    {
        // Production refuses private targets; the delivery path under test is the same either way.
        $this->previousEnv = \getenv('_APP_ENV');
        \putenv('_APP_ENV=development');

        $probe = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe);
        $address = (string) \stream_socket_get_name($probe, false);
        \fclose($probe);
        [$host, $port] = \explode(':', $address);

        $this->receiverLog = (string) \tempnam(\sys_get_temp_dir(), 'webhook-receiver');
        $this->receiverUrl = 'http://' . $address;
        $this->receiver = \proc_open(
            [PHP_BINARY, '-S', $address, __DIR__ . '/Webhooks/receiver.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['WEBHOOK_RECEIVER_LOG' => $this->receiverLog],
        );
        $this->assertIsResource($this->receiver);

        $deadline = \microtime(true) + 5;
        while (($socket = @\fsockopen($host, (int) $port, timeout: 0.1)) === false && \microtime(true) < $deadline) {
            \usleep(20_000);
        }
        $this->assertNotFalse($socket, 'The webhook receiver did not start');
        \fclose($socket);
    }

    /**
     * The X-Appwrite-Webhook-Delivery-Id of every request an endpoint received, in order.
     *
     * @return list<string>
     */
    private function deliveryIds(string $path): array
    {
        $ids = [];
        foreach (\file($this->receiverLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $request = \json_decode($line, true);
            if ($request['path'] === $path) {
                $ids[] = (string) ($request['headers']['X-' . APP_NAME . '-Webhook-Delivery-Id'] ?? '');
            }
        }

        return $ids;
    }

    /**
     * @param list<string> $paths One webhook per receiver path, all subscribed to user creation
     */
    private function createProject(Database $database, array $paths): Document
    {
        $webhooks = [];
        foreach ($paths as $index => $path) {
            $webhooks[] = $database->createDocument('webhooks', new Document([
                '$id' => 'webhook-' . ($index + 1),
                'name' => 'Webhook ' . ($index + 1),
                'url' => $this->receiverUrl . $path,
                'events' => ['users.*.create'],
                'enabled' => true,
                'security' => false,
                'signatureKey' => 'secret',
                'attempts' => 0,
                'logs' => '',
            ]));
        }

        return new Document([
            '$id' => 'project-1',
            '$sequence' => 'project-internal-1',
            'name' => 'Production',
            'teamInternalId' => 'team-internal-1',
            'webhooks' => $webhooks,
        ]);
    }

    /**
     * Trigger a user creation through the publisher the API uses, and return the payload
     * as the worker receives it: after the queue's wire codec.
     *
     * @return array<string, mixed>
     */
    private function publish(Document $project): array
    {
        $publisher = new MockPublisher();
        (new WebhookEvent($publisher))
            ->setProject($project)
            ->setUser(new Document(['$id' => 'user-1']))
            ->setEvent('users.[userId].create')
            ->setParam('userId', 'user-1')
            ->setPayload(['$id' => 'user-1', 'name' => 'Ada Lovelace'])
            ->trigger();

        $codec = new Json();

        return $codec->decode($codec->encode($publisher->getEvents(Event::WEBHOOK_QUEUE_NAME)[0]));
    }

    /**
     * Run one delivery of a message the way the broker hands it to the worker.
     *
     * @param array<string, mixed> $payload
     * @param int $attempts How many times the broker delivered this message before
     * @return \Throwable|null What the handler threw back to the broker, if anything
     */
    private function deliver(Webhooks $worker, array $payload, string $pid, int $attempts, Document $project, Database $database, Cache $cache): ?\Throwable
    {
        $publisher = new MockPublisher();
        // The worker's project resource is read per message, webhooks included, and the worker
        // purges it after a failure; a delivery never sees the webhooks as the last one left them.
        $project = new Document([
            ...$project->getArrayCopy(),
            'webhooks' => \array_map(
                fn (Document $webhook): Document => $database->getDocument('webhooks', $webhook->getId()),
                $project->getAttribute('webhooks', []),
            ),
        ]);

        try {
            $worker->action(
                message: new Message([
                    'pid' => $pid,
                    'queue' => Event::WEBHOOK_QUEUE_NAME,
                    'timestamp' => \time(),
                    'payload' => $payload,
                    'attempts' => $attempts,
                ]),
                project: $project,
                dbForPlatform: $database,
                publisherForNotifications: new NotificationPublisher($publisher, new Queue('v1-notifications')),
                publisherForUsage: new UsagePublisher($publisher, new Queue('v1-stats-usage')),
                platform: ['consoleUrl' => 'https://console.example.test'],
                plan: [],
                cache: $cache,
            );
        } catch (\Throwable $th) {
            return $th;
        }

        return null;
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
        $database->createCollection('webhooks', [], [], $permissions, false);
        $database->createAttribute('webhooks', 'name', Database::VAR_STRING, 128, false);
        $database->createAttribute('webhooks', 'url', Database::VAR_STRING, 2048, false);
        $database->createAttribute('webhooks', 'events', Database::VAR_STRING, 256, false, array: true);
        $database->createAttribute('webhooks', 'enabled', Database::VAR_BOOLEAN, 0, false);
        $database->createAttribute('webhooks', 'security', Database::VAR_BOOLEAN, 0, false);
        $database->createAttribute('webhooks', 'signatureKey', Database::VAR_STRING, 2048, false);
        $database->createAttribute('webhooks', 'attempts', Database::VAR_INTEGER, 0, false);
        $database->createAttribute('webhooks', 'logs', Database::VAR_STRING, 1_000_000, false);

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
