<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Bus\Events\ExecutionCompleted;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Platform\Workers\Functions;
use Executor\Exception\Timeout as ExecutorTimeout;
use Executor\Executor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Bus\Bus;
use Utopia\Bus\Listener;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

require_once __DIR__ . '/../../../../app/init.php';

final class FunctionsTest extends TestCase
{
    private string|false $openSslKey = false;

    protected function setUp(): void
    {
        // The worker signs each execution's API key with it.
        $this->openSslKey = \getenv('_APP_OPENSSL_KEY_V1');
        \putenv('_APP_OPENSSL_KEY_V1=unit-test-openssl-key');
    }

    protected function tearDown(): void
    {
        \putenv($this->openSslKey === false ? '_APP_OPENSSL_KEY_V1' : '_APP_OPENSSL_KEY_V1=' . $this->openSslKey);
    }

    #[DataProvider('functionIdProvider')]
    public function testScheduledExecutionIsHydratedBeforeItIsEnqueued(array $scheduleData, string $fallbackFunctionId, string $expectedFunctionId): void
    {
        $worker = $this->worker();
        $dbForPlatform = $this->createMock(Database::class);
        $schedule = new Document([
            '$id' => 'schedule-id',
            'active' => true,
            'data' => array_merge([
                'userId' => 'user-id',
                'body' => 'body',
                'path' => '/path',
                'headers' => ['x-test' => 'value'],
                'method' => 'PATCH',
            ], $scheduleData),
        ]);
        $claimed = false;

        $dbForPlatform
            ->expects($this->once())
            ->method('getDocument')
            ->with('schedules', 'schedule-id', [], true)
            ->willReturn($schedule);
        $dbForPlatform
            ->expects($this->once())
            ->method('withTransaction')
            ->willReturnCallback(fn (callable $callback): mixed => $callback());
        $dbForPlatform
            ->expects($this->once())
            ->method('updateDocument')
            ->with('schedules', 'schedule-id', $this->callback(function (Document $schedule) use (&$claimed): bool {
                $claimed = $schedule->getAttribute('active') === false;
                return $claimed;
            }))
            ->willReturn(new Document(['$id' => 'schedule-id', 'active' => false]));
        $dbForPlatform
            ->expects($this->once())
            ->method('deleteDocument')
            ->with('schedules', 'schedule-id')
            ->willReturn(true);

        $message = null;
        $project = new Document([
            '$id' => 'project-id',
            'accessedAt' => DateTime::now(),
        ]);
        $execution = new Document([
            '$id' => 'execution-id',
            'scheduleId' => 'schedule-id',
        ]);

        $this->assertTrue($worker->schedule(
            $dbForPlatform,
            $project,
            $execution,
            $fallbackFunctionId,
            function (FunctionMessage $candidate) use (&$claimed, &$message): void {
                $this->assertTrue($claimed, 'Schedule must be claimed before it is published');
                $message = $candidate;
            },
        ));

        $this->assertInstanceOf(FunctionMessage::class, $message);
        $this->assertSame('schedule', $message->type);
        $this->assertSame($expectedFunctionId, $message->functionId);
        $this->assertSame('user-id', $message->userId);
        $this->assertSame('execution-id', $message->execution->getId());
        $this->assertSame('', $message->execution->getAttribute('scheduleId', ''));
        $this->assertSame('body', $message->body);
        $this->assertSame('/path', $message->path);
        $this->assertSame(['x-test' => 'value'], $message->headers);
        $this->assertSame('PATCH', $message->method);
        $this->assertSame(
            0,
            $worker->projectAccessUpdates,
            'Access is recorded once per message in action(), so the republish path must not write it again'
        );
    }

    #[DataProvider('inactiveScheduleProvider')]
    public function testCancelledOrMissingScheduleIsNotEnqueued(Document $schedule): void
    {
        $worker = $this->worker();
        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform
            ->expects($this->once())
            ->method('withTransaction')
            ->willReturnCallback(fn (callable $callback): mixed => $callback());
        $dbForPlatform
            ->expects($this->once())
            ->method('getDocument')
            ->with('schedules', 'schedule-id', [], true)
            ->willReturn($schedule);
        $dbForPlatform->expects($this->never())->method('updateDocument');
        $dbForPlatform->expects($this->never())->method('deleteDocument');

        $this->assertFalse($worker->schedule(
            $dbForPlatform,
            new Document(['$id' => 'project-id']),
            new Document(['$id' => 'execution-id', 'scheduleId' => 'schedule-id']),
            'function-id',
            fn () => $this->fail('Cancelled schedule was enqueued'),
        ));
    }

    public function testFailedPublishReleasesScheduleClaim(): void
    {
        $worker = $this->worker();
        $dbForPlatform = $this->createMock(Database::class);
        $updates = [];
        $dbForPlatform
            ->expects($this->once())
            ->method('withTransaction')
            ->willReturnCallback(fn (callable $callback): mixed => $callback());
        $dbForPlatform
            ->expects($this->once())
            ->method('getDocument')
            ->willReturn(new Document([
                '$id' => 'schedule-id',
                'active' => true,
                'data' => ['functionId' => 'function-id'],
            ]));
        $dbForPlatform
            ->expects($this->exactly(2))
            ->method('updateDocument')
            ->willReturnCallback(function (string $collection, string $id, Document $schedule) use (&$updates): Document {
                $updates[] = $schedule->getAttribute('active');
                return new Document(['$id' => $id, 'active' => $schedule->getAttribute('active')]);
            });
        $dbForPlatform->expects($this->never())->method('deleteDocument');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Queue unavailable');

        try {
            $worker->schedule(
                $dbForPlatform,
                new Document(['$id' => 'project-id', 'accessedAt' => DateTime::now()]),
                new Document(['$id' => 'execution-id', 'scheduleId' => 'schedule-id']),
                '',
                fn () => throw new \RuntimeException('Queue unavailable'),
            );
        } finally {
            $this->assertSame([false, true], $updates);
        }
    }

    public function testScheduledRunIsNotRepeatedWhenItsUpdateFailsToPublish(): void
    {
        $executor = new CountingExecutor(fn () => ['statusCode' => 200, 'headers' => [], 'logs' => '', 'errors' => '']);
        $publisher = new FailingPublisher('v1-functions');
        $realtime = new CountingRealtime();

        $attempts = $this->deliver(
            new FunctionMessage(project: $this->project(), functionId: 'function-a', type: 'schedule', platform: self::PLATFORM),
            $executor,
            $this->database(['function-a' => $this->deployment('function-a')]),
            $publisher,
            $realtime,
        );

        $this->assertSame(1, $executor->calls, 'A redelivery ran the function again');
        $this->assertSame(1, $attempts);
        $this->assertCount(1, $publisher->getEvents('v1-webhooks') ?? [], 'The webhook must go out even though the functions publish failed');
        $this->assertSame(1, $realtime->triggers, 'Realtime must be notified even though the functions publish failed');
    }

    public function testFailedExecutionIsRecordedOnceInsteadOfRetried(): void
    {
        $executor = new CountingExecutor(fn () => throw new ExecutorTimeout('Executor request timed out after 30 seconds'));
        $completed = [];

        $attempts = $this->deliver(
            new FunctionMessage(
                project: $this->project(),
                functionId: 'function-a',
                execution: new Document(['$id' => 'execution-id']),
                type: 'http',
                platform: self::PLATFORM,
            ),
            $executor,
            $this->database(['function-a' => $this->deployment('function-a')]),
            new MockPublisher(),
            new CountingRealtime(),
            $completed,
        );

        $this->assertSame(1, $executor->calls, 'A timed-out function may have run, so it must not be run again');
        $this->assertSame(1, $attempts);
        $this->assertCount(1, $completed);
        $this->assertSame('execution-id', $completed[0]['$id']);
        $this->assertSame('failed', $completed[0]['status']);
        $this->assertNotSame('', $completed[0]['errors']);
    }

    public function testFailureBeforeTheExecutorIsRetried(): void
    {
        $executor = new CountingExecutor(fn () => ['statusCode' => 200, 'headers' => [], 'logs' => '', 'errors' => '']);
        $reads = 0;
        $dbForProject = $this->database(['function-a' => function () use (&$reads): Document {
            // The first delivery loses the database; the retry finds it again.
            if ($reads++ === 0) {
                throw new \PDOException('MySQL server has gone away');
            }
            return $this->deployment('function-a');
        }]);

        $attempts = $this->deliver(
            new FunctionMessage(project: $this->project(), functionId: 'function-a', type: 'schedule', platform: self::PLATFORM),
            $executor,
            $dbForProject,
            new MockPublisher(),
            new CountingRealtime(),
        );

        $this->assertSame(2, $attempts, 'A failure before the executor must be retried');
        $this->assertSame(1, $executor->calls);
    }

    public function testEventIsNotRedeliveredToSubscribersThatAlreadyRan(): void
    {
        $executor = new CountingExecutor(fn () => ['statusCode' => 200, 'headers' => [], 'logs' => '', 'errors' => '']);
        $reads = 0;
        $flaky = function () use (&$reads): Document {
            if ($reads++ === 0) {
                throw new \PDOException('MySQL server has gone away');
            }
            return $this->deployment('function-b');
        };

        $attempts = $this->deliver(
            $this->event(),
            $executor,
            $this->database([
                'function-a' => $this->deployment('function-a'),
                'function-b' => $flaky,
            ]),
            new MockPublisher(),
            new CountingRealtime(),
        );

        $this->assertSame(2, $attempts, 'The subscriber that failed must get its retry');
        $this->assertSame(2, $executor->calls, 'Each subscriber runs once: function-a on the first delivery, function-b on the retry');
    }

    public function testASubscriberThatKeepsFailingDoesNotRunTheOthersAgain(): void
    {
        $executor = new CountingExecutor(fn () => ['statusCode' => 200, 'headers' => [], 'logs' => '', 'errors' => '']);

        $this->deliver(
            $this->event(),
            $executor,
            $this->database([
                'function-a' => $this->deployment('function-a'),
                'function-b' => fn () => throw new \PDOException('MySQL server has gone away'),
            ]),
            new MockPublisher(),
            new CountingRealtime(),
        );

        $this->assertSame(1, $executor->calls, 'Every redelivery ran function-a again');
    }

    public function testEventIsRetriedWhileNoSubscriberHasRun(): void
    {
        $executor = new CountingExecutor(fn () => ['statusCode' => 200, 'headers' => [], 'logs' => '', 'errors' => '']);
        $reads = 0;
        $flaky = function () use (&$reads): Document {
            if ($reads++ === 0) {
                throw new \PDOException('MySQL server has gone away');
            }
            return $this->deployment('function-b');
        };

        $attempts = $this->deliver(
            $this->event(),
            $executor,
            $this->database([
                'function-a' => new Document(),
                'function-b' => $flaky,
            ]),
            new MockPublisher(),
            new CountingRealtime(),
        );

        $this->assertSame(2, $attempts, 'Nothing ran on the first delivery, so it must be retried');
        $this->assertSame(1, $executor->calls);
    }

    /**
     * @return \Iterator<string, array{array<string, string>, string, string}>
     */
    public static function functionIdProvider(): \Iterator
    {
        yield 'current schedule data' => [['functionId' => 'function-id'], 'legacy-function-id', 'function-id'];
        yield 'legacy execution resource' => [[], 'legacy-function-id', 'legacy-function-id'];
    }

    /**
     * @return \Iterator<string, array{\Utopia\Database\Document}>
     */
    public static function inactiveScheduleProvider(): \Iterator
    {
        yield 'inactive' => [new Document(['$id' => 'schedule-id', 'active' => false])];
        yield 'missing' => [new Document()];
    }

    private function worker(): TestFunctions
    {
        return new TestFunctions();
    }

    private const array PLATFORM = ['apiHostname' => 'localhost'];

    /**
     * Hands the message to the worker the way a redelivering broker does: a
     * handler that throws gets the same message again, up to maxDeliver.
     *
     * @param array<int, array<string, mixed>> $completed Executions the worker reported as completed
     * @return int Deliveries it took to settle the message
     */
    private function deliver(
        FunctionMessage $functionMessage,
        Executor $executor,
        Database $dbForProject,
        MockPublisher $publisher,
        Realtime $realtime,
        array &$completed = [],
    ): int {
        $bus = (new Bus())
            ->subscribe(new RecordingListener(function (ExecutionCompleted $event) use (&$completed): void {
                $completed[] = $event->execution;
            }))
            ->setResolver(fn (string $name) => throw new \LogicException("Unexpected injection: {$name}"));

        $cache = new Cache(new MemoryCache());
        $maxDeliver = 5;
        for ($attempt = 1; $attempt <= $maxDeliver; $attempt++) {
            $message = new Message([
                'pid' => 'message-id',
                'queue' => 'v1-functions',
                'timestamp' => \time(),
                'payload' => $functionMessage->toArray(),
                'attempts' => $attempt - 1,
            ]);

            try {
                $this->worker()->action(
                    project: $this->project(),
                    message: $message,
                    dbForProject: $dbForProject,
                    dbForPlatform: $this->createStub(Database::class),
                    queueForWebhooks: new Webhook($publisher),
                    publisherForFunctions: new FunctionPublisher($publisher, new Queue('v1-functions')),
                    queueForRealtime: $realtime,
                    queueForEvents: new Event($publisher),
                    bus: $bus,
                    executor: $executor,
                    getIsResourceBlocked: fn () => false,
                    locks: fn (string $key, int $ttl, callable $callback) => $callback(),
                    cache: $cache,
                );

                return $attempt;
            } catch (\Throwable) {
                continue;
            }
        }

        return $maxDeliver;
    }

    private function project(): Document
    {
        return new Document(['$id' => 'project-id', '$sequence' => 1, 'accessedAt' => DateTime::now()]);
    }

    private function event(): FunctionMessage
    {
        return new FunctionMessage(
            project: $this->project(),
            events: ['users.*.create'],
            payload: ['$id' => 'user-id'],
            platform: self::PLATFORM,
        );
    }

    private function deployment(string $functionId): Document
    {
        return new Document([
            '$id' => 'deployment-' . $functionId,
            '$sequence' => 1,
            'resourceId' => $functionId,
            'status' => 'ready',
            'buildPath' => '/storage/builds/' . $functionId . '.tar.gz',
            'entrypoint' => 'index.js',
        ]);
    }

    /**
     * A project database holding one function per key. The value is its
     * deployment, or a callable that produces it on each read.
     *
     * @param array<string, Document|callable(): Document> $deployments
     */
    private function database(array $deployments): Database
    {
        $functions = [];
        foreach (\array_keys($deployments) as $sequence => $functionId) {
            $functions[$functionId] = new Document([
                '$id' => $functionId,
                '$sequence' => $sequence + 1,
                'name' => $functionId,
                'events' => ['users.*.create'],
                'deploymentId' => 'deployment-' . $functionId,
                'runtime' => 'node-22',
                'version' => 'v5',
            ]);
        }

        $dbForProject = $this->createStub(Database::class);
        $dbForProject
            ->method('find')
            ->willReturnCallback(fn (): array => \array_values($functions));
        $dbForProject
            ->method('getDocument')
            ->willReturnCallback(function (string $collection, string $id) use ($functions, $deployments): Document {
                return match ($collection) {
                    'functions' => $functions[$id] ?? new Document(),
                    'deployments' => (function () use ($deployments, $id): Document {
                        $deployment = $deployments[\substr($id, \strlen('deployment-'))] ?? new Document();
                        return \is_callable($deployment) ? $deployment() : $deployment;
                    })(),
                    default => new Document(),
                };
            });

        return $dbForProject;
    }
}

final class CountingExecutor extends Executor
{
    public int $calls = 0;

    /** @param \Closure(): array<string, mixed> $response */
    public function __construct(private readonly \Closure $response)
    {
    }

    public function createExecution(
        string $projectId,
        string $deploymentId,
        ?string $body,
        array $variables,
        int $timeout,
        string $image,
        string $source,
        string $entrypoint,
        string $version,
        string $path,
        string $method,
        array $headers,
        float $cpus,
        int $memory,
        bool $logging,
        string $runtimeEntrypoint = '',
        ?int $requestTimeout = null,
        string $responseFormat = self::RESPONSE_FORMAT_OBJECT_HEADERS
    ) {
        $this->calls++;

        return ($this->response)();
    }
}

final class FailingPublisher extends MockPublisher
{
    public function __construct(private readonly string $failing)
    {
    }

    public function publish(Queue $queue, array $payload): bool
    {
        if ($queue->name === $this->failing) {
            throw new \RuntimeException('Connection closed by server');
        }

        return parent::publish($queue, $payload);
    }
}

final class CountingRealtime extends Realtime
{
    public int $triggers = 0;

    public function trigger(): string|bool
    {
        $this->triggers++;

        return true;
    }
}

final class RecordingListener extends Listener
{
    public function __construct(callable $callback)
    {
        $this->callback($callback);
    }

    public static function getName(): string
    {
        return 'recording';
    }

    public static function getEvents(): array
    {
        return [ExecutionCompleted::class];
    }
}

final class TestFunctions extends Functions
{
    public int $projectAccessUpdates = 0;

    public function schedule(Database $dbForPlatform, Document $project, Document $execution, string $functionId, callable $enqueue): bool
    {
        return $this->enqueueScheduledExecution($dbForPlatform, $project, $execution, $functionId, $enqueue);
    }

    protected function updateProjectAccess(Document $project, Database $dbForPlatform): void
    {
        $this->projectAccessUpdates++;
    }
}
