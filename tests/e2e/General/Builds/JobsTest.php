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
    private Rules $rules;
    private Redis $broker;
    private Queue $queue;
    private JobsPublisher $publisher;
    private string $artifact;

    protected function setUp(): void
    {
        $this->database = new Database();
        $this->cache = new Cache(new Memory());
        $this->realtime = new Realtime();
        $this->rules = new Rules();
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
        $this->assertNoEvents();
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
        $this->assertNoEvents();
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
        $this->assertNoEvents();
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
        $this->assertNoEvents();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('resources')]
    public function testCompleteCurrentResource(string $collection): void
    {
        /**
         * Test for SUCCESS
         */
        $resource = $this->resource($collection);
        $deployment = $this->deployment($resource);
        $this->cache->save('jobs-exit-' . $deployment->getId(), true);
        $this->cache->save('jobs-manifest-' . $deployment->getId(), ['files' => ['index.html']]);

        $this->enqueue($deployment, 'complete');
        $this->runWorker();

        $current = $this->database->getDocument($collection, $resource->getId());
        $this->assertSame($resource->getSequence(), $current->getSequence());
        $this->assertSame($deployment->getId(), $current->getAttribute('deploymentId'));
        $this->assertSame($deployment->getId(), $current->getAttribute('latestDeploymentId'));
        $this->assertSame('ready', $current->getAttribute('latestDeploymentStatus'));
        $this->assertTrue($current->getAttribute('live'));
        $this->assertCount(1, $this->realtime->payloads);
        $this->assertSame('ready', $this->realtime->payloads[0]['status']);
        if ($collection === 'sites') {
            $this->assertSame('static', $current->getAttribute('adapter'));
        }
    }

    public static function resources(): \Iterator
    {
        yield 'function' => ['functions'];
        yield 'site' => ['sites'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('resources')]
    public function testCompleteRecreatedDuringArtifactRead(string $collection): void
    {
        /**
         * Test for SUCCESS
         */
        $resource = $this->resource($collection);
        $deployment = $this->deployment($resource);
        $this->cache->save('jobs-exit-' . $deployment->getId(), true);
        $this->cache->save('jobs-manifest-' . $deployment->getId(), ['files' => []]);
        $replacement = null;
        $device = new Artifact(function () use ($resource, &$replacement): void {
            $replacement = $this->replace($resource);
        });

        $this->enqueue($deployment, 'complete');
        $this->runWorker($device);

        $this->assertInstanceOf(Document::class, $replacement);
        $this->assertReplacement($replacement);
        $this->assertSame('building', $this->database->getDocument('deployments', $deployment->getId())->getAttribute('status'));
        $this->assertSame([], $this->realtime->payloads);
        $this->assertNoEvents();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('callbacks')]
    public function testCallbackForRecreatedOwner(string $collection, string $event, array $data): void
    {
        /**
         * Test for SUCCESS
         */
        $resource = $this->resource($collection);
        $deployment = $this->deployment($resource, ['status' => 'waiting', 'buildLogs' => 'original']);
        $replacement = $this->replace($resource);

        $this->enqueue($deployment, $event, $data);
        $this->runWorker();

        $this->assertReplacement($replacement);
        $current = $this->database->getDocument('deployments', $deployment->getId());
        $this->assertSame('waiting', $current->getAttribute('status'));
        $this->assertSame('original', $current->getAttribute('buildLogs'));
        $this->assertSame(1, $current->getAttribute('sourceSize'));
        $this->assertSame([], $this->realtime->payloads);
        $this->assertNoEvents();
    }

    public static function callbacks(): \Iterator
    {
        foreach (['functions', 'sites'] as $collection) {
            yield "$collection log" => [$collection, 'log', ['lines' => ['old output']]];
            yield "$collection source size" => [$collection, 'artifact', ['artifactId' => 'sourceSize', 'status' => 'success', 'content' => 900]];
            yield "$collection output failure" => [$collection, 'artifact', ['artifactId' => 'output', 'status' => 'failed', 'error' => 'old failure']];
            yield "$collection failed exit" => [$collection, 'exit', ['exitCode' => 1]];
            yield "$collection successful exit" => [$collection, 'exit', ['exitCode' => 0]];
            yield "$collection manifest" => [$collection, 'artifact', ['artifactId' => 'manifest', 'status' => 'success', 'content' => ['files' => ['index.html']]]];
        }
    }

    private function assertNoEvents(): void
    {
        foreach ([
            '_APP_FUNCTIONS_QUEUE_NAME' => \Appwrite\Event\Event::FUNCTIONS_QUEUE_NAME,
            '_APP_WEBHOOK_QUEUE_NAME' => \Appwrite\Event\Event::WEBHOOK_QUEUE_NAME,
            '_APP_SCREENSHOTS_QUEUE_NAME' => \Appwrite\Event\Event::SCREENSHOTS_QUEUE_NAME,
            '_APP_STATS_USAGE_QUEUE_NAME' => \Appwrite\Event\Event::STATS_USAGE_QUEUE_NAME,
        ] as $variable => $default) {
            $queue = new Queue(\Utopia\System\System::getEnv($variable, $default));
            $this->assertSame(0, $this->broker->getQueueSize($queue), $queue->name);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('resources')]
    public function testCompleteRecreatedDuringStatusUpdate(string $collection): void
    {
        /**
         * Test for SUCCESS
         */
        $resource = $this->resource($collection);
        $deployment = $this->deployment($resource);
        $this->cache->save('jobs-exit-' . $deployment->getId(), true);
        $this->cache->save('jobs-manifest-' . $deployment->getId(), ['files' => []]);
        $replacement = null;
        $this->database->on(Database::EVENT_DOCUMENTS_UPDATE, 'replace-owner', function (string $event, Document $updated) use ($resource, &$replacement): void {
            if ($updated->getCollection() !== 'deployments') {
                return;
            }
            $this->database->on(Database::EVENT_DOCUMENTS_UPDATE, 'replace-owner', null);
            $replacement = $this->replace($resource);
        });

        $this->enqueue($deployment, 'complete');
        $this->runWorker();

        $this->assertInstanceOf(Document::class, $replacement);
        $this->assertReplacement($replacement);
        $this->assertSame([], $this->realtime->payloads);
        $this->assertNoEvents();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('writes')]
    public function testCompleteRecreatedAfterWriteSelection(string $collection, string $field): void
    {
        /**
         * Test for SUCCESS
         */
        if (! $this->database->getAdapter() instanceof \Utopia\Database\Adapter\SQL) {
            $this->markTestSkipped('The controlled statement boundary requires a SQL adapter.');
        }
        $resource = $this->resource($collection);
        $deployment = $this->deployment($resource);
        $this->cache->save('jobs-exit-' . $deployment->getId(), true);
        $this->cache->save('jobs-manifest-' . $deployment->getId(), ['files' => $field === 'adapter' ? ['index.html'] : []]);
        $replacement = null;
        $this->database->before(Database::EVENT_DOCUMENTS_UPDATE, 'replace-owner', function (string $sql) use ($resource, $field, &$replacement): string {
            if (! str_contains($sql, $field)) {
                return $sql;
            }
            $this->database->before(Database::EVENT_DOCUMENTS_UPDATE, 'replace-owner', null);
            $replacement = $this->replace($resource);

            return $sql;
        });

        $this->enqueue($deployment, 'complete');
        $this->runWorker();

        $this->assertInstanceOf(Document::class, $replacement);
        $this->assertReplacement($replacement);
        $this->assertSame([], $this->realtime->payloads);
        $this->assertNoEvents();
        if ($collection === 'sites') {
            $this->assertEmpty($this->database->getDocument('sites', $replacement->getId())->getAttribute('adapter'));
        }
    }

    public static function writes(): \Iterator
    {
        yield 'site detection' => ['sites', 'adapter'];
        foreach (['functions', 'sites'] as $collection) {
            yield "$collection latest" => [$collection, 'latestDeploymentId'];
            yield "$collection active" => [$collection, 'deploymentCreatedAt'];
        }
    }

    public function testCompleteRecreatedManualRule(): void
    {
        /**
         * Test for SUCCESS
         */
        if (! $this->database->getAdapter() instanceof \Utopia\Database\Adapter\SQL) {
            $this->markTestSkipped('The controlled statement boundary requires a SQL adapter.');
        }
        $resource = $this->resource('functions');
        $deployment = $this->deployment($resource);
        $this->database->createDocument('rules', new Document([
            '$id' => 'new-rule', 'domain' => 'replacement.example.com', 'type' => 'deployment', 'trigger' => 'manual',
            'projectId' => 'console', 'projectInternalId' => '0', 'region' => 'default',
            'deploymentResourceType' => 'function', 'deploymentResourceId' => $resource->getId(), 'deploymentResourceInternalId' => $resource->getSequence(),
        ]));
        $this->cache->save('jobs-exit-' . $deployment->getId(), true);
        $replacement = null;
        $this->database->before(Database::EVENT_DOCUMENTS_UPDATE, 'replace-rule', function (string $sql) use ($resource, &$replacement): string {
            if (! str_contains($sql, '_rules')) {
                return $sql;
            }
            $this->database->before(Database::EVENT_DOCUMENTS_UPDATE, 'replace-rule', null);
            $this->database->deleteDocument('rules', 'new-rule');
            $replacement = $this->replace($resource);

            return $sql;
        });

        $this->enqueue($deployment, 'complete');
        $this->runWorker();

        $this->assertInstanceOf(Document::class, $replacement);
        $this->assertReplacement($replacement);
        $this->assertSame([], $this->realtime->payloads);
        $this->assertNoEvents();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('schedules')]
    public function testCompleteScheduledFunction(bool $replaceSchedule): void
    {
        /**
         * Test for SUCCESS
         */
        if ($replaceSchedule && ! $this->database->getAdapter() instanceof \Utopia\Database\Adapter\SQL) {
            $this->markTestSkipped('The controlled statement boundary requires a SQL adapter.');
        }
        $resource = $this->resource('functions', ['scheduleId' => 'schedule', 'schedule' => '* * * * *']);
        $deployment = $this->deployment($resource);
        $schedule = $this->database->createDocument('schedules', new Document([
            '$id' => 'schedule', 'region' => 'default', 'projectId' => 'console', 'projectInternalId' => '0', 'resourceId' => $resource->getId(), 'resourceInternalId' => $resource->getSequence(),
            'resourceType' => 'function', 'active' => false, 'schedule' => '* * * * *',
        ]));
        $this->database->updateDocument('functions', $resource->getId(), new Document(['scheduleInternalId' => $schedule->getSequence()]));
        $this->cache->save('jobs-exit-' . $deployment->getId(), true);
        if ($replaceSchedule) {
            $this->database->before(Database::EVENT_DOCUMENTS_UPDATE, 'replace-schedule', function (string $sql): string {
                if (! str_contains($sql, '_schedules')) {
                    return $sql;
                }
                $this->database->before(Database::EVENT_DOCUMENTS_UPDATE, 'replace-schedule', null);
                $this->database->deleteDocument('schedules', 'schedule');
                $this->database->createDocument('schedules', new Document([
                    '$id' => 'schedule', 'region' => 'default', 'projectId' => 'console', 'projectInternalId' => '0', 'resourceId' => 'another-function', 'resourceInternalId' => '900',
                    'resourceType' => 'function', 'active' => false, 'schedule' => '0 * * * *',
                ]));

                return $sql;
            });
        }

        $this->enqueue($deployment, 'complete');
        $this->runWorker();

        $current = $this->database->getDocument('schedules', 'schedule');
        $this->assertSame(! $replaceSchedule, $current->getAttribute('active'));
        $this->assertSame($replaceSchedule ? '0 * * * *' : '* * * * *', $current->getAttribute('schedule'));
        if ($replaceSchedule) {
            $this->assertNotSame($schedule->getSequence(), $current->getSequence());
        }
    }

    public static function schedules(): \Iterator
    {
        yield 'current schedule' => [false];
        yield 'recreated schedule' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('previews')]
    public function testCompleteBranchPreview(string $owner): void
    {
        /**
         * Test for SUCCESS
         */
        $resource = $this->resource('sites');
        $deployment = $this->deployment($resource, ['providerBranch' => 'main', 'installationId' => 'installation']);
        $this->cache->save('jobs-exit-' . $deployment->getId(), true);
        $this->cache->save('jobs-manifest-' . $deployment->getId(), ['files' => []]);
        $domain = (new \Appwrite\Filter\BranchDomain())->apply([
            'branch' => 'main', 'resourceId' => $resource->getId(), 'projectId' => 'console', 'sitesDomain' => 'sites.example.com',
        ]);
        $this->database->createDocument('rules', new Document([
            '$id' => md5($domain), 'domain' => $domain, 'type' => 'deployment', 'trigger' => 'deployment',
            'projectId' => $owner === 'other project' ? 'other' : 'console',
            'projectInternalId' => $owner === 'other project' ? '9' : '0', 'region' => 'default',
            'deploymentId' => 'existing-deployment', 'deploymentResourceType' => 'site',
            'deploymentResourceId' => $resource->getId(),
            'deploymentResourceInternalId' => $owner === 'other resource' ? '900' : $resource->getSequence(),
            'deploymentVcsProviderBranch' => 'main',
        ]));

        $this->enqueue($deployment, 'complete');
        $this->runWorker();

        $this->assertSame($owner === 'current' ? $deployment->getId() : 'existing-deployment', $this->database->getDocument('rules', md5($domain))->getAttribute('deploymentId'));
        $this->assertSame($deployment->getId(), $this->database->getDocument('sites', $resource->getId())->getAttribute('deploymentId'));
        $this->assertCount(1, $this->realtime->payloads);
    }

    public static function previews(): \Iterator
    {
        yield 'current owner' => ['current'];
        yield 'reused resource ID' => ['other resource'];
        yield 'other project' => ['other project'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignSchedules')]
    public function testCompleteForeignSchedule(array $owner): void
    {
        /**
         * Test for SUCCESS
         */
        $resource = $this->resource('functions', ['scheduleId' => 'schedule', 'schedule' => '* * * * *']);
        $deployment = $this->deployment($resource);
        $this->database->createDocument('schedules', new Document(array_merge([
            '$id' => 'schedule', 'region' => 'default', 'projectId' => 'console', 'projectInternalId' => '0',
            'resourceId' => $resource->getId(), 'resourceInternalId' => $resource->getSequence(),
            'resourceType' => SCHEDULE_RESOURCE_TYPE_FUNCTION, 'active' => false, 'schedule' => '0 * * * *',
        ], $owner)));
        $this->cache->save('jobs-exit-' . $deployment->getId(), true);

        $this->enqueue($deployment, 'complete');
        $this->runWorker();

        $schedule = $this->database->getDocument('schedules', 'schedule');
        $this->assertFalse($schedule->getAttribute('active'));
        $this->assertSame('0 * * * *', $schedule->getAttribute('schedule'));
    }

    public static function foreignSchedules(): \Iterator
    {
        yield 'other project' => [['projectInternalId' => '9']];
        yield 'reused public ID' => [['resourceInternalId' => '900']];
        yield 'other resource ID' => [['resourceId' => 'another-function']];
        yield 'other resource type' => [['resourceType' => 'execution']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unavailableDeployments')]
    public function testCallbackForUnavailableDeployment(bool $deleted): void
    {
        /**
         * Test for SUCCESS
         */
        $resource = $this->resource('functions');
        $deployment = $this->deployment($resource, ['status' => 'canceled']);
        if ($deleted) {
            $this->database->deleteDocument('deployments', $deployment->getId());
        }

        $this->enqueue($deployment, 'exit', ['exitCode' => 1]);
        $this->runWorker();

        $current = $this->database->getDocument('deployments', $deployment->getId());
        $this->assertSame($deleted, $current->isEmpty());
        if (! $deleted) {
            $this->assertSame('canceled', $current->getAttribute('status'));
        }
        $this->assertEmpty($this->database->getDocument('functions', $resource->getId())->getAttribute('deploymentId'));
        $this->assertSame([], $this->realtime->payloads);
        $this->assertNoEvents();
    }

    public static function unavailableDeployments(): \Iterator
    {
        yield 'canceled' => [false];
        yield 'deleted' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('currentCallbacks')]
    public function testCallbackForCurrentOwner(string $event, array $data, string $status): void
    {
        /**
         * Test for SUCCESS
         */
        $resource = $this->resource('functions');
        $deployment = $this->deployment($resource, ['status' => 'waiting']);

        $this->enqueue($deployment, $event, $data);
        $this->runWorker();

        $current = $this->database->getDocument('deployments', $deployment->getId());
        $this->assertSame($status, $current->getAttribute('status'));
        $this->assertNotEmpty($current->getAttribute('buildLogs'));
        $this->assertCount(1, $this->realtime->payloads);
        $this->assertSame($status, $this->realtime->payloads[0]['status']);
    }

    public static function currentCallbacks(): \Iterator
    {
        yield 'log' => ['log', ['lines' => ['current output']], 'building'];
        yield 'failed exit' => ['exit', ['exitCode' => 1], 'failed'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scheduleIdentities')]
    public function testCompleteScheduleIdentity(bool $legacy, bool $recreated): void
    {
        /**
         * Test for SUCCESS
         */
        $resource = $this->resource('functions', ['scheduleId' => 'schedule', 'schedule' => '* * * * *']);
        $deployment = $this->deployment($resource);
        $attributes = [
            '$id' => 'schedule', 'region' => 'default', 'projectId' => 'console', 'projectInternalId' => '0',
            'resourceId' => $resource->getId(), 'resourceInternalId' => $resource->getSequence(),
            'resourceType' => SCHEDULE_RESOURCE_TYPE_FUNCTION, 'active' => false, 'schedule' => '0 * * * *',
        ];
        $schedule = $this->database->createDocument('schedules', new Document($attributes));
        if (! $legacy) {
            $this->database->updateDocument('functions', $resource->getId(), new Document(['scheduleInternalId' => $schedule->getSequence()]));
        }
        if ($recreated) {
            $this->database->deleteDocument('schedules', 'schedule');
            $this->database->createDocument('schedules', new Document($attributes));
        }
        $this->cache->save('jobs-exit-' . $deployment->getId(), true);

        $this->enqueue($deployment, 'complete');
        $this->runWorker();

        $this->assertSame(! $recreated, $this->database->getDocument('schedules', 'schedule')->getAttribute('active'));
    }

    public static function scheduleIdentities(): \Iterator
    {
        yield 'legacy current owner' => [true, false];
        yield 'persisted schedule identity' => [false, false];
        yield 'reused schedule ID with same owner' => [false, true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scheduleChanges')]
    public function testCompleteChangedScheduleSelection(bool $reuseId): void
    {
        /**
         * Test for SUCCESS
         */
        $resource = $this->resource('functions', ['scheduleId' => 'schedule', 'schedule' => '* * * * *']);
        $deployment = $this->deployment($resource);
        $attributes = [
            'region' => 'default', 'projectId' => 'console', 'projectInternalId' => '0',
            'resourceId' => $resource->getId(), 'resourceInternalId' => $resource->getSequence(),
            'resourceType' => SCHEDULE_RESOURCE_TYPE_FUNCTION, 'active' => false, 'schedule' => '0 * * * *',
        ];
        $schedule = $this->database->createDocument('schedules', new Document(array_merge($attributes, ['$id' => 'schedule'])));
        $this->database->updateDocument('functions', $resource->getId(), new Document(['scheduleInternalId' => $schedule->getSequence()]));
        $selected = false;
        $this->database->on(Database::EVENT_DOCUMENT_FIND, 'switch-schedule', function (string $event, Document|array $documents) use ($resource, $attributes, $reuseId, &$selected): void {
            $document = $documents instanceof Document ? $documents : ($documents[0] ?? new Document());
            if ($document->getCollection() !== 'schedules') {
                return;
            }
            $this->database->on(Database::EVENT_DOCUMENT_FIND, 'switch-schedule', null);
            $selected = true;
            if ($reuseId) {
                $this->database->deleteDocument('schedules', 'schedule');
            }
            $replacement = $this->database->createDocument('schedules', new Document(array_merge($attributes, ['$id' => $reuseId ? 'schedule' : 'new-schedule'])));
            $this->database->updateDocument('functions', $resource->getId(), new Document([
                'scheduleId' => $replacement->getId(), 'scheduleInternalId' => $replacement->getSequence(),
            ]));
        });
        $this->cache->save('jobs-exit-' . $deployment->getId(), true);

        $this->enqueue($deployment, 'complete');
        $this->runWorker();

        $this->assertTrue($selected);
        $this->assertFalse($this->database->getDocument('schedules', 'schedule')->getAttribute('active'));
        if (! $reuseId) {
            $this->assertFalse($this->database->getDocument('schedules', 'new-schedule')->getAttribute('active'));
        }
    }

    public static function scheduleChanges(): \Iterator
    {
        yield 'changed schedule ID' => [false];
        yield 'changed schedule sequence' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('branches')]
    public function testCompleteActivatesMatchingManualRules(string $collection, string $branch): void
    {
        /**
         * Test for SUCCESS
         */
        $resource = $this->resource($collection);
        $deployment = $this->deployment($resource, ['providerBranch' => $branch]);
        $this->cache->save('jobs-exit-' . $deployment->getId(), true);
        $this->cache->save('jobs-manifest-' . $deployment->getId(), ['files' => []]);
        $rules = ['general' => '', 'branch' => 'main', 'other' => 'another-branch'];
        foreach ($rules as $id => $ruleBranch) {
            $this->database->createDocument('rules', new Document([
                '$id' => $id, 'domain' => $id . '.example.com', 'type' => 'deployment', 'trigger' => 'manual',
                'projectId' => 'console', 'projectInternalId' => '0', 'region' => 'default',
                'deploymentId' => 'existing-deployment', 'deploymentResourceType' => $collection === 'sites' ? 'site' : 'function',
                'deploymentResourceId' => $resource->getId(), 'deploymentResourceInternalId' => $resource->getSequence(),
                'deploymentVcsProviderBranch' => $ruleBranch,
            ]));
        }

        $otherCollection = $collection === 'functions' ? 'sites' : 'functions';
        $other = $this->resource($otherCollection);
        $this->assertSame($resource->getSequence(), $other->getSequence());
        $this->database->createDocument('rules', new Document([
            '$id' => 'other-type', 'domain' => 'other-type.example.com', 'type' => 'deployment', 'trigger' => 'manual',
            'projectId' => 'console', 'projectInternalId' => '0', 'region' => 'default',
            'deploymentId' => 'other-type-deployment', 'deploymentResourceType' => $otherCollection === 'sites' ? 'site' : 'function',
            'deploymentResourceId' => $other->getId(), 'deploymentResourceInternalId' => $other->getSequence(),
            'deploymentVcsProviderBranch' => $branch,
        ]));

        $this->enqueue($deployment, 'complete');
        $this->runWorker();

        $this->assertSame('other-type-deployment', $this->database->getDocument('rules', 'other-type')->getAttribute('deploymentId'));
        $expected = [];
        foreach ($rules as $id => $ruleBranch) {
            $matches = $ruleBranch === '' || $ruleBranch === $branch;
            $this->assertSame($matches ? $deployment->getId() : 'existing-deployment', $this->database->getDocument('rules', $id)->getAttribute('deploymentId'));
            if ($matches) {
                $expected[] = $id;
            }
        }
        $updated = array_column($this->rules->updated, '$id');
        sort($expected);
        sort($updated);
        $this->assertSame($expected, $updated);
        foreach ($this->rules->updated as $rule) {
            $this->assertSame($deployment->getId(), $rule['deploymentId']);
        }
    }

    public static function branches(): \Iterator
    {
        foreach (['functions', 'sites'] as $collection) {
            yield "$collection VCS build" => [$collection, 'main'];
            yield "$collection manual build" => [$collection, ''];
        }
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
        $resources->set('platform', fn () => array_merge(\Utopia\Config\Config::getParam('platform', []), ['sitesDomain' => 'sites.example.com']));
        $bus = (new Bus())->subscribe($this->rules);
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
