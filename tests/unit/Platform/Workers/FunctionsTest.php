<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Delivery\Fanout;
use Appwrite\Event\Delivery\Receipt;
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
use Tests\Unit\Platform\Workers\Fixture\TestingFunctions;
use Utopia\Bus\Bus;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
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
