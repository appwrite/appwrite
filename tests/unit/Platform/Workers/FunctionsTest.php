<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Delivery\Fanout;
use Appwrite\Event\Delivery\Receipt;
use Appwrite\Event\Delivery\Sink;
use Appwrite\Event\Envelope;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Executor\Executor;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\CapturingAdapter;
use Tests\Unit\Event\MockPublisher;
use Tests\Unit\Platform\Workers\Fixture\CapturingExecutor;
use Tests\Unit\Platform\Workers\Fixture\TestingExecutionFunctions;
use Tests\Unit\Platform\Workers\Fixture\TestingFunctions;
use Utopia\Bus\Bus;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Logger\Log;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

require_once __DIR__ . '/../../../../app/init.php';

final class FunctionsTest extends TestCase
{
    public function testEnvelopeContextAndChildIdentity(): void
    {
        $worker = new TestingFunctions();

        $this->assertSame([
            'headers' => [],
            'variables' => [],
        ], $worker->envelopeContext(''));
        $this->assertSame([
            'headers' => ['x-appwrite-event-id' => 'envelope-1'],
            'variables' => ['APPWRITE_FUNCTION_EVENT_ID' => 'envelope-1'],
        ], $worker->envelopeContext('envelope-1'));
        $this->assertSame('', $worker->childEnvelope('', 'function-1', 'execution-1', 'completed'));
        $this->assertSame(
            Envelope::forOutcome(
                'envelope-1',
                'function:function-1:execution:execution-1:completed',
            ),
            $worker->childEnvelope('envelope-1', 'function-1', 'execution-1', 'completed'),
        );
    }

    public function testEnvelopeMetadataOverridesProjectAndFunctionVariablesAtExecutorBoundary(): void
    {
        $projectDatabase = $this->createProjectDatabase();
        $projectDatabase->createDocument('deployments', new Document([
            '$id' => 'deployment-1',
            '$sequence' => 'deployment-internal-1',
            'resourceId' => 'function-1',
            'status' => 'ready',
            'buildPath' => '',
            'entrypoint' => 'index.js',
            'type' => 'manual',
        ]));

        $runtime = \array_key_first(Config::getParam('runtimes-v2', []));
        $this->assertIsString($runtime);

        $function = new Document([
            '$id' => 'function-1',
            '$sequence' => 'function-internal-1',
            'name' => 'Event handler',
            'deploymentId' => 'deployment-1',
            'runtime' => $runtime,
            'runtimeSpecification' => APP_COMPUTE_SPECIFICATION_DEFAULT,
            'version' => 'v2',
            'timeout' => 15,
            'logging' => true,
            'scopes' => [],
            'varsProject' => [
                new Document([
                    'key' => 'APPWRITE_FUNCTION_EVENT_ID',
                    'value' => 'project-variable',
                ]),
            ],
            'vars' => [
                new Document([
                    'key' => 'APPWRITE_FUNCTION_EVENT_ID',
                    'value' => 'function-variable',
                ]),
            ],
        ]);
        $project = new Document([
            '$id' => 'project-1',
            '$sequence' => 'project-internal-1',
            'region' => 'fra',
        ]);
        $platformDatabase = $this->createReceiptDatabase();
        $delivery = new Fanout(new Receipt($platformDatabase));
        $publisher = new MockPublisher();
        $executor = new CapturingExecutor();
        $worker = new TestingExecutionFunctions();
        $bus = (new Bus())->setResolver(static fn (string $dependency): null => null);
        $originalKey = \getenv('_APP_OPENSSL_KEY_V1');
        \putenv('_APP_OPENSSL_KEY_V1=event-receipt-test-key');

        try {
            $worker->execute(
                log: new Log(),
                dbForProject: $projectDatabase,
                queueForWebhooks: new Webhook($publisher),
                publisherForFunctions: new FunctionPublisher($publisher, new Queue('v1-functions')),
                queueForRealtime: new Realtime(new CapturingAdapter(), $delivery),
                queueForEvents: new Event($publisher),
                bus: $bus,
                project: $project,
                function: $function,
                executor: $executor,
                trigger: 'event',
                path: '/',
                method: 'POST',
                headers: ['x-appwrite-event-id' => 'request-header'],
                platform: ['apiHostname' => 'cloud.appwrite.test'],
                event: 'databases.database-1.update',
                eventData: '{}',
                executionId: 'execution-1',
                envelopeId: 'envelope-1',
            );
        } finally {
            \putenv($originalKey === false ? '_APP_OPENSSL_KEY_V1' : "_APP_OPENSSL_KEY_V1={$originalKey}");
        }

        $this->assertSame('envelope-1', $executor->variables['APPWRITE_FUNCTION_EVENT_ID']);
        $this->assertSame('envelope-1', $executor->headers['x-appwrite-event-id']);
    }

    public function testRetrySkipsCompletedFunctionAndReusesDeterministicExecutionIdentity(): void
    {
        $projectDatabase = $this->createProjectDatabase();
        foreach (['function-1', 'function-2'] as $functionId) {
            $projectDatabase->createDocument('functions', new Document([
                '$id' => $functionId,
                'name' => $functionId,
                'events' => ['databases.database-1.update'],
            ]));
        }

        $platformDatabase = $this->createReceiptDatabase();
        $delivery = new Fanout(new Receipt($platformDatabase));
        $publisher = new MockPublisher();
        $worker = new TestingFunctions();
        $worker->failures['function-2'] = true;
        $project = new Document([
            '$id' => 'project-1',
            '$sequence' => 'project-internal-1',
        ]);
        $message = new Message([
            'pid' => 'message-1',
            'queue' => 'v1-functions',
            'timestamp' => \time(),
            'payload' => FunctionMessage::fromEvent(
                event: 'databases.[databaseId].update',
                params: ['databaseId' => 'database-1'],
                project: $project,
                payload: ['$id' => 'database-1'],
                envelopeId: 'envelope-1',
            )->toArray(),
        ]);
        $queueForWebhooks = new Webhook($publisher);
        $publisherForFunctions = new FunctionPublisher($publisher, new Queue('v1-functions'));
        $queueForRealtime = new Realtime(new CapturingAdapter(), $delivery);
        $queueForEvents = new Event($publisher);
        $bus = $this->createStub(Bus::class);
        $executor = $this->createStub(Executor::class);
        $blocked = static fn (): bool => false;

        try {
            $worker->action(
                $project,
                $message,
                $projectDatabase,
                $queueForWebhooks,
                $publisherForFunctions,
                $queueForRealtime,
                $queueForEvents,
                $bus,
                new Log(),
                $executor,
                $blocked,
                $delivery,
            );
            $this->fail('Expected the interrupted function delivery to propagate.');
        } catch (\Exception $error) {
            $this->assertSame('function delivery interrupted', $error->getMessage());
        }

        unset($worker->failures['function-2']);
        $worker->action(
            $project,
            $message,
            $projectDatabase,
            $queueForWebhooks,
            $publisherForFunctions,
            $queueForRealtime,
            $queueForEvents,
            $bus,
            new Log(),
            $executor,
            $blocked,
            $delivery,
        );
        $worker->action(
            $project,
            $message,
            $projectDatabase,
            $queueForWebhooks,
            $publisherForFunctions,
            $queueForRealtime,
            $queueForEvents,
            $bus,
            new Log(),
            $executor,
            $blocked,
            $delivery,
        );

        $this->assertSame(1, $worker->deliveries['function-1']);
        $this->assertSame(2, $worker->deliveries['function-2']);
        $this->assertSame(
            $worker->executionIds['function-2'][0],
            $worker->executionIds['function-2'][1]
        );
        $this->assertNotSame(
            $worker->executionIds['function-1'][0],
            $worker->executionIds['function-2'][0]
        );
        $this->assertSame(['envelope-1'], $worker->envelopes['function-1']);
        $this->assertSame(['envelope-1', 'envelope-1'], $worker->envelopes['function-2']);
    }

    public function testBlockedFunctionDoesNotCompleteReceiptBeforeUnblockedRetry(): void
    {
        $projectDatabase = $this->createProjectDatabase();
        $projectDatabase->createDocument('functions', new Document([
            '$id' => 'function-1',
            'name' => 'function-1',
            'events' => ['databases.database-1.update'],
        ]));

        $platformDatabase = $this->createReceiptDatabase();
        $delivery = new Fanout(new Receipt($platformDatabase));
        $publisher = new MockPublisher();
        $worker = new TestingFunctions();
        $project = new Document([
            '$id' => 'project-1',
            '$sequence' => 'project-internal-1',
        ]);
        $message = new Message([
            'pid' => 'message-1',
            'queue' => 'v1-functions',
            'timestamp' => \time(),
            'payload' => FunctionMessage::fromEvent(
                event: 'databases.[databaseId].update',
                params: ['databaseId' => 'database-1'],
                project: $project,
                payload: ['$id' => 'database-1'],
                envelopeId: 'envelope-1',
            )->toArray(),
        ]);
        $queueForWebhooks = new Webhook($publisher);
        $publisherForFunctions = new FunctionPublisher($publisher, new Queue('v1-functions'));
        $queueForRealtime = new Realtime(new CapturingAdapter(), $delivery);
        $queueForEvents = new Event($publisher);
        $bus = $this->createStub(Bus::class);
        $executor = $this->createStub(Executor::class);
        $blocked = true;
        $getIsResourceBlocked = static function () use (&$blocked): bool {
            return $blocked;
        };

        $worker->action(
            $project,
            $message,
            $projectDatabase,
            $queueForWebhooks,
            $publisherForFunctions,
            $queueForRealtime,
            $queueForEvents,
            $bus,
            new Log(),
            $executor,
            $getIsResourceBlocked,
            $delivery,
        );
        $this->assertArrayNotHasKey('function-1', $worker->deliveries);

        $blocked = false;
        $worker->action(
            $project,
            $message,
            $projectDatabase,
            $queueForWebhooks,
            $publisherForFunctions,
            $queueForRealtime,
            $queueForEvents,
            $bus,
            new Log(),
            $executor,
            $getIsResourceBlocked,
            $delivery,
        );
        $worker->action(
            $project,
            $message,
            $projectDatabase,
            $queueForWebhooks,
            $publisherForFunctions,
            $queueForRealtime,
            $queueForEvents,
            $bus,
            new Log(),
            $executor,
            $getIsResourceBlocked,
            $delivery,
        );

        $this->assertSame(1, $worker->deliveries['function-1']);
    }

    public function testDelayedFunctionEventDoesNotCrossRecreatedProjectGeneration(): void
    {
        $projectDatabase = $this->createProjectDatabase();
        $projectDatabase->createDocument('functions', new Document([
            '$id' => 'function-1',
            'name' => 'function-1',
            'events' => ['databases.database-1.update'],
        ]));

        $platformDatabase = $this->createReceiptDatabase();
        $delivery = new Fanout(new Receipt($platformDatabase));
        $publisher = new MockPublisher();
        $worker = new TestingFunctions();
        $sourceProject = new Document([
            '$id' => 'project-1',
            '$sequence' => 'project-internal-a',
        ]);
        $recreatedProject = new Document([
            '$id' => 'project-1',
            '$sequence' => 'project-internal-b',
        ]);
        $message = new Message([
            'pid' => 'message-1',
            'queue' => 'v1-functions',
            'timestamp' => \time(),
            'payload' => FunctionMessage::fromEvent(
                event: 'databases.[databaseId].update',
                params: ['databaseId' => 'database-1'],
                project: $sourceProject,
                payload: ['$id' => 'database-1'],
                envelopeId: 'envelope-1',
            )->toArray(),
        ]);

        $worker->action(
            $recreatedProject,
            $message,
            $projectDatabase,
            new Webhook($publisher),
            new FunctionPublisher($publisher, new Queue('v1-functions')),
            new Realtime(new CapturingAdapter(), $delivery),
            new Event($publisher),
            $this->createStub(Bus::class),
            new Log(),
            $this->createStub(Executor::class),
            static fn (): bool => false,
            $delivery,
        );

        $this->assertArrayNotHasKey('function-1', $worker->deliveries);
        $sourceIdentity = $delivery->getIdentity(
            'project-1',
            'project-internal-a',
            'envelope-1',
            Sink::Function,
            'function-1',
        );
        $recreatedIdentity = $delivery->getIdentity(
            'project-1',
            'project-internal-b',
            'envelope-1',
            Sink::Function,
            'function-1',
        );
        $this->assertTrue($platformDatabase->getDocument('eventReceipts', $sourceIdentity)->isEmpty());
        $this->assertTrue($platformDatabase->getDocument('eventReceipts', $recreatedIdentity)->isEmpty());

        $recreatedMessage = new Message([
            'pid' => 'message-2',
            'queue' => 'v1-functions',
            'timestamp' => \time(),
            'payload' => FunctionMessage::fromEvent(
                event: 'databases.[databaseId].update',
                params: ['databaseId' => 'database-1'],
                project: $recreatedProject,
                payload: ['$id' => 'database-1'],
                envelopeId: 'envelope-1',
            )->toArray(),
        ]);
        $worker->action(
            $recreatedProject,
            $recreatedMessage,
            $projectDatabase,
            new Webhook($publisher),
            new FunctionPublisher($publisher, new Queue('v1-functions')),
            new Realtime(new CapturingAdapter(), $delivery),
            new Event($publisher),
            $this->createStub(Bus::class),
            new Log(),
            $this->createStub(Executor::class),
            static fn (): bool => false,
            $delivery,
        );

        $this->assertSame(1, $worker->deliveries['function-1']);
        $this->assertFalse($platformDatabase->getDocument('eventReceipts', $recreatedIdentity)->isEmpty());
    }

    private function createProjectDatabase(): Database
    {
        $authorization = new Authorization();
        $authorization->disable();

        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('functionProjects')
            ->setNamespace('function_projects_' . \uniqid());
        $database->create();

        $collection = (require __DIR__ . '/../../../../app/config/collections.php')['projects']['functions'];
        $database->createCollection(
            'functions',
            \array_map(
                static fn (array $attribute): Document => new Document($attribute),
                $collection['attributes']
            ),
            \array_map(
                static fn (array $index): Document => new Document($index),
                $collection['indexes']
            ),
        );
        $database->createCollection('variables');
        $database->createAttribute('variables', 'resourceInternalId', Database::VAR_STRING, 255, true);
        $database->createAttribute('variables', 'resourceType', Database::VAR_STRING, 64, true);
        $database->createCollection('deployments');
        $database->disableValidation();

        return $database;
    }

    private function createReceiptDatabase(): Database
    {
        $authorization = new Authorization();
        $authorization->disable();

        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('functionReceipts')
            ->setNamespace('function_receipts_' . \uniqid());
        $database->create();

        $collection = (require __DIR__ . '/../../../../app/config/collections.php')['console']['eventReceipts'];
        $database->createCollection(
            'eventReceipts',
            \array_map(
                static fn (array $attribute): Document => new Document($attribute),
                $collection['attributes']
            ),
            \array_map(
                static fn (array $index): Document => new Document($index),
                $collection['indexes']
            ),
        );

        return $database;
    }
}
