<?php

declare(strict_types=1);

namespace Tests\E2E\General\Builds;

use Appwrite\Event\Message\Jobs as JobsMessage;
use Appwrite\Event\Publisher\Jobs as JobsPublisher;
use Appwrite\Tests\Queue\InMemoryConnection;
use PHPUnit\Framework\TestCase;
use Utopia\Bus\Bus;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Database\Document;
use Utopia\Platform\Service;
use Utopia\Pools\Group;
use Utopia\Queue\Adapter\KubernetesJob;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Queue;
use Utopia\Queue\Server;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;

final class JobsTest extends TestCase
{
    private Database $database;
    private Cache $cache;
    private Realtime $realtime;
    private Redis $broker;
    private Queue $queue;
    private JobsPublisher $publisher;
    private string $artifact;

    protected function setUp(): void
    {
        $this->database = new Database();
        $this->cache = new Cache(new Memory());
        $this->realtime = new Realtime();
        $connection = new InMemoryConnection();
        $this->broker = new Redis($connection, $connection);
        $this->queue = new Queue('v1-jobs', 'build-test-' . bin2hex(random_bytes(6)));
        $this->publisher = new JobsPublisher($this->broker, $this->queue);
        $this->artifact = tempnam(sys_get_temp_dir(), 'build-test-');
        file_put_contents($this->artifact, 'build');
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->delete();
        }
        if (isset($this->artifact) && file_exists($this->artifact)) {
            unlink($this->artifact);
        }
    }

    public function testCompleteRecreatedFunction(): void
    {
        /**
         * Test for SUCCESS
         */
        $resource = $this->resource('functions');
        $deployment = $this->deployment($resource);
        $replacement = $this->replace($resource);
        $this->cache->save('jobs-exit-' . $deployment->getId(), true);

        $this->enqueue($deployment, 'complete');
        $this->runWorker();

        $this->assertReplacement($replacement);
        $this->assertSame('building', $this->database->getDocument('deployments', $deployment->getId())->getAttribute('status'));
        $this->assertSame([], $this->realtime->payloads);
    }

    public function testCompleteRecreatedSite(): void
    {
        /**
         * Test for SUCCESS
         */
        $site = $this->resource('sites');
        $deployment = $this->deployment($site);
        $replacement = $this->replace($site);
        $this->cache->save('jobs-exit-' . $deployment->getId(), true);
        $this->cache->save('jobs-manifest-' . $deployment->getId(), ['files' => ['index.html']]);

        $this->enqueue($deployment, 'complete');
        $this->runWorker();

        $this->assertReplacement($replacement);
        $site = $this->database->getDocument('sites', $replacement->getId());
        $this->assertEmpty($site->getAttribute('adapter'));
        $this->assertEmpty($site->getAttribute('fallbackFile'));
        $this->assertSame([], $this->realtime->payloads);
    }

    public function testCompleteDeletedResource(): void
    {
        /**
         * Test for SUCCESS
         */
        $resource = $this->resource('functions');
        $deployment = $this->deployment($resource);
        $this->database->deleteDocument('functions', $resource->getId());
        $this->cache->save('jobs-exit-' . $deployment->getId(), true);

        $this->enqueue($deployment, 'complete');
        $this->runWorker();

        $this->assertTrue($this->database->getDocument('functions', $resource->getId())->isEmpty());
        $this->assertSame('building', $this->database->getDocument('deployments', $deployment->getId())->getAttribute('status'));
        $this->assertSame([], $this->realtime->payloads);
    }

    public function testCompleteWithoutOwnerSequence(): void
    {
        /**
         * Test for FAILURE
         */
        $resource = $this->resource('functions');
        $deployment = $this->deployment($resource, ['resourceInternalId' => null]);
        $this->cache->save('jobs-exit-' . $deployment->getId(), true);

        $this->enqueue($deployment, 'complete');
        $this->runWorker();

        $this->assertEmpty($this->database->getDocument('functions', $resource->getId())->getAttribute('deploymentId'));
        $this->assertSame('building', $this->database->getDocument('deployments', $deployment->getId())->getAttribute('status'));
        $this->assertSame([], $this->realtime->payloads);
    }

    private function project(): Document
    {
        return new Document(['$id' => 'console', '$sequence' => '0', 'region' => 'default']);
    }

    private function resource(string $collection, array $attributes = []): Document
    {
        return $this->database->createDocument($collection, new Document(array_merge([
            '$id' => 'same-id', 'name' => 'Build owner', 'enabled' => true, 'live' => false, 'logging' => true,
            $collection === 'sites' ? 'buildRuntime' : 'runtime' => 'node-22',
        ], $attributes)));
    }

    private function deployment(Document $resource, array $attributes = []): Document
    {
        return $this->database->createDocument('deployments', new Document(array_merge([
            '$id' => 'old-deployment', 'type' => 'manual',
            'resourceType' => $resource->getCollection(), 'resourceId' => $resource->getId(), 'resourceInternalId' => $resource->getSequence(),
            'activate' => true, 'status' => 'building', 'buildPath' => $this->artifact, 'sourceSize' => 1,
        ], $attributes)));
    }

    private function replace(Document $resource): Document
    {
        $this->database->deleteDocument($resource->getCollection(), $resource->getId());
        $replacement = $this->resource($resource->getCollection(), ['deploymentId' => 'new-deployment']);
        $this->assertNotSame($resource->getSequence(), $replacement->getSequence());
        $deployment = $this->deployment($replacement, ['$id' => 'new-deployment', 'status' => 'ready']);
        $this->database->createDocument('rules', new Document([
            '$id' => 'new-rule', 'domain' => 'replacement.example.com', 'type' => 'deployment', 'trigger' => 'manual',
            'projectId' => 'console', 'projectInternalId' => '0', 'region' => 'default',
            'deploymentId' => $deployment->getId(), 'deploymentInternalId' => $deployment->getSequence(),
            'deploymentResourceType' => $resource->getCollection() === 'sites' ? 'site' : 'function',
            'deploymentResourceId' => $replacement->getId(), 'deploymentResourceInternalId' => $replacement->getSequence(),
        ]));

        return $replacement;
    }

    private function assertReplacement(Document $replacement): void
    {
        $resource = $this->database->getDocument($replacement->getCollection(), $replacement->getId());
        $this->assertSame($replacement->getSequence(), $resource->getSequence());
        $this->assertSame('new-deployment', $resource->getAttribute('deploymentId'));
        $this->assertSame('', $resource->getAttribute('latestDeploymentId', ''));
        $this->assertFalse($resource->getAttribute('live'));
        $this->assertSame('new-deployment', $this->database->getDocument('rules', 'new-rule')->getAttribute('deploymentId'));
    }

    private function enqueue(Document $deployment, string $event, array $data = []): void
    {
        $this->assertNotFalse($this->publisher->enqueue(new JobsMessage(
            $this->project(),
            bin2hex(random_bytes(8)),
            'orchestrator.job.' . $event,
            array_merge($data, ['meta' => ['projectId' => 'console', 'deploymentId' => $deployment->getId()]]),
        )));
    }

    private function runWorker(?Device $device = null): void
    {
        global $container;

        // Exercise the production service and message resources with persisted
        // collections. Only queue, lock and outbound realtime transports are local.
        $resources = clone $container;
        $resources->set('publisher', fn () => $this->broker);
        $resources->set('pools', fn () => new Group());
        $resources->set('cache', fn () => $this->cache);
        $resources->set('locks', fn () => new Lock());
        $resources->set('plan', fn () => []);
        $bus = new Bus();
        $worker = new Server(new KubernetesJob($this->broker, 1, $this->queue->namespace, $resources));
        $register = require __DIR__ . '/../../../../app/init/worker/message.php';
        $worker->init()->action(function () use ($worker, $register, $bus, $device): void {
            $context = $worker->context();
            $register($context);
            $context->set('dbForPlatform', fn () => $this->database);
            $context->set('dbForProject', fn () => $this->database);
            $context->set('queueForRealtime', fn () => $this->realtime);
            $context->set('deviceForBuilds', fn () => $device ?? new Local(sys_get_temp_dir()));
            $context->set('bus', fn () => $bus->setResolver($context->get(...)));
        });
        $platform = new Platform(new Module());
        $platform->setWorker($worker);
        $platform->init(Service::TYPE_WORKER, [
            'workerName' => 'jobs',
            'jobs' => ['jobs' => ['queue' => $this->queue->name, 'maxCoroutines' => 1]],
        ]);
        $processed = 0;
        $error = null;
        $worker->shutdown()->action(function () use ($worker, &$processed): void {
            $processed++;
            $worker->stop();
        });
        $worker->error()->inject('error')->action(function (\Throwable $failure) use ($worker, &$error): void {
            $error = $failure;
            $worker->stop();
        });
        $worker->start();
        if ($error !== null) {
            throw $error;
        }
        $this->assertSame(1, $processed);
        $this->assertSame(0, $this->broker->getQueueSize($this->queue));
        $this->assertSame(0, $this->broker->getQueueSize($this->queue, failedJobs: true));
    }
}
