<?php

declare(strict_types=1);

namespace Tests\E2E\General;

use Appwrite\Database\Factory;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Jobs as JobsMessage;
use Appwrite\Event\Publisher\Func;
use Appwrite\Event\Publisher\Screenshot;
use Appwrite\Event\Publisher\Usage;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Platform\Modules\Functions\Workers\Jobs;
use Appwrite\Tests\Queue\InMemoryConnection;
use Appwrite\Usage\Context;
use Appwrite\Vcs\Factory as VcsFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Bus\Bus;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;
use Utopia\Storage\Device\Local;

final class JobsTest extends TestCase
{
    #[DataProvider('callbacks')]
    public function testUpdateDuringDeletion(string $event, bool $extension, bool $delete): void
    {
        if (! \extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        $error = null;
        \Swoole\Coroutine\run(function () use ($event, $extension, $delete, &$error): void {
            try {
                $this->update($event, $extension, $delete);
            } catch (\Throwable $e) {
                $error = $e;
            }
        });

        if ($error !== null) {
            throw $error;
        }
    }

    private function update(string $event, bool $extension, bool $delete): void
    {
        global $register;

        $cache = new Cache(new Memory());
        $authorization = new Authorization();
        $authorization->disable();
        $db = (new Factory($register->get('pools'), $cache, $authorization))->platform();
        $db->setNamespace('jobs_' . ID::unique());
        $db->create();
        $collections = [Database::METADATA];

        try {
            $attributes = [];
            foreach (['resourceId', 'resourceType', 'status', 'buildLogs', 'buildEndedAt'] as $name) {
                $attributes[] = new Document([
                    '$id' => $name,
                    'type' => Database::VAR_STRING,
                    'size' => 512,
                    'required' => false,
                    'array' => false,
                ]);
            }
            $attributes[] = new Document([
                '$id' => 'buildDuration',
                'type' => Database::VAR_INTEGER,
                'size' => 4,
                'required' => false,
                'array' => false,
            ]);
            $db->createCollection('deployments', $attributes);
            $collections[] = 'deployments';
            $db->createCollection('functions');
            $collections[] = 'functions';

            $deploymentId = ID::unique();
            $resourceId = ID::unique();
            $project = new Document(['$id' => ID::unique()]);
            $db->createDocument('deployments', new Document([
                '$id' => $deploymentId,
                'resourceId' => $resourceId,
                'resourceType' => 'functions',
                'status' => 'building',
                'buildLogs' => '',
            ]));

            if ($delete && ! $extension) {
                // Force the API deletion between a worker write and its reread.
                $db->on(Database::EVENT_DOCUMENTS_UPDATE, 'delete', function () use ($db, $deploymentId): void {
                    $db->deleteDocument('deployments', $deploymentId);
                });
            }

            $connection = new InMemoryConnection();
            $publisher = new Redis($connection, $connection);
            $queue = new Queue('jobs-test');
            $realtime = (new Realtime())->setPaused(true);
            $events = new Event($publisher);
            $webhooks = new Webhook($publisher);
            $worker = $extension ? new DeletedDeploymentJobs() : new Jobs();

            $worker->action(
                message: (new Message())->setPayload((new JobsMessage(
                    project: $project,
                    id: ID::unique(),
                    event: $event,
                    data: ['meta' => ['deploymentId' => $deploymentId], 'lines' => ['build output'], 'exitCode' => 1],
                ))->toArray()),
                project: $project,
                dbForProject: $db,
                dbForPlatform: $db,
                queueForRealtime: $realtime,
                queueForEvents: $events,
                queueForWebhooks: $webhooks,
                publisherForFunctions: new Func($publisher, $queue),
                publisherForScreenshots: new Screenshot($publisher, $queue),
                publisherForUsage: new Usage($publisher, $queue),
                usage: new Context(),
                deviceForBuilds: new Local(),
                vcsFactory: new VcsFactory($cache),
                cache: $cache,
                locks: static fn (string $key, int $ttl, callable $callback) => $callback(),
                platform: [],
                plan: [],
                bus: new Bus(),
            );

            if ($delete) {
                // Test for FAILURE: deletion stays terminal and emits no update.
                $this->assertTrue($db->getDocument('deployments', $deploymentId)->isEmpty());
                $this->assertSame('', $realtime->getEvent());
                $this->assertSame([], $realtime->getPayload());
            } else {
                // Test for SUCCESS: a surviving deployment still streams logs.
                $deployment = $db->getDocument('deployments', $deploymentId);
                $this->assertSame('building', $deployment->getAttribute('status'));
                $this->assertSame("build output\n", $deployment->getAttribute('buildLogs'));
                $this->assertSame($deploymentId, $realtime->getPayload()['$id']);
            }

            $this->assertSame('', $events->getEvent());
            $this->assertSame(0, $publisher->getQueueSize($queue));
        } finally {
            foreach (\array_reverse($collections) as $collection) {
                $db->deleteCollection($collection);
            }
        }
    }

    public static function callbacks(): \Iterator
    {
        yield 'deleted while streaming logs' => ['orchestrator.job.log', false, true];
        yield 'deleted while finalizing' => ['orchestrator.job.exit', false, true];
        yield 'deleted by a downstream finalizer' => ['orchestrator.job.exit', true, true];
        yield 'surviving deployment' => ['orchestrator.job.log', false, false];
    }
}

/**
 * Reproduces a downstream finalizer refreshing a deployment after external work.
 */
final class DeletedDeploymentJobs extends Jobs
{
    protected function finalize(
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Document $deployment,
        bool $success,
        string $message,
        Context $usage,
        Usage $publisherForUsage,
        Screenshot $publisherForScreenshots,
        VcsFactory $vcsFactory,
        array $platform,
        Bus $bus,
        int $buildSize = 0,
    ): Document {
        $dbForProject->deleteDocument('deployments', $deployment->getId());
        $deployment = $dbForProject->getDocument('deployments', $deployment->getId());

        return parent::finalize($dbForProject, $dbForPlatform, $project, $deployment, $success, $message, $usage, $publisherForUsage, $publisherForScreenshots, $vcsFactory, $platform, $bus, $buildSize);
    }
}
