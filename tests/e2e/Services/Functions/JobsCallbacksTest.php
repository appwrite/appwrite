<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Functions;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Jobs as JobsMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Screenshot as ScreenshotPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Platform\Modules\Functions\Workers\Jobs;
use Appwrite\Usage\Context;
use Appwrite\Vcs\Factory;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Bus\Bus;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Queue\Message;
use Utopia\Queue\PermanentFailure;
use Utopia\Storage\Device\Local;
use Utopia\Storage\DeviceType;

/** Exercises the public worker callback path against persisted documents and stored output. */
final class JobsCallbacksTest extends TestCase
{
    private PDO $pdo;
    private Jobs $worker;
    private Database $database;
    private Cache $cache;
    private RemoteBuildStorage $storage;
    private string $root;
    private Document $project;

    protected function setUp(): void
    {
        $this->worker = new Jobs();
        $this->cache = new Cache(new Memory());
        $this->pdo = new PDO('sqlite::memory:');
        $this->database = new Database(new SQLite($this->pdo), $this->cache);
        $this->database->setDatabase('jobs')->setNamespace('callbacks');
        $this->database->getAuthorization()->disable();
        $this->database->create();
        foreach (['variables', 'sites', 'deployments'] as $id) {
            $collection = Config::getParam('collections')['projects'][$id];
            $this->database->createCollection(Collection::create(
                id: $id,
                attributes: $collection['attributes'],
                indexes: $collection['indexes'],
            ));
        }
        $site = $this->database->createDocument('sites', new Document([
            '$id' => 'site', 'name' => 'Site', 'enabled' => true, 'live' => true,
            'logging' => true, 'buildRuntime' => 'static-1', 'framework' => 'other',
        ]));
        $this->root = \sys_get_temp_dir() . '/jobs-' . \bin2hex(\random_bytes(8));
        \mkdir($this->root);
        $this->storage = new RemoteBuildStorage($this->root);
        $this->database->createDocument('deployments', new Document([
            '$id' => 'deployment', 'resourceId' => $site->getId(),
            'resourceInternalId' => $site->getSequence(), 'resourceType' => 'sites',
            'type' => 'manual', 'status' => 'building',
            'buildPath' => $this->root . '/output.tar.gz',
        ]));
        $this->project = new Document(['$id' => 'project', '$sequence' => '1']);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->root . '/*') ?: [] as $path) {
            \unlink($path);
        }
        \rmdir($this->root);
    }

    public static function callbackOrders(): \Iterator
    {
        yield 'complete last' => [['exit', 'output', 'manifest', 'complete']];
        yield 'exit last' => [['output', 'complete', 'manifest', 'exit']];
        yield 'manifest last' => [['exit', 'output', 'complete', 'manifest']];
        yield 'output callback lost' => [['exit', 'manifest', 'complete']];
        yield 'output callback late' => [['exit', 'manifest', 'complete', 'output']];
    }

    #[DataProvider('callbackOrders')]
    public function testCacheLossDoesNotStrandSuccessfulBuild(array $order): void
    {
        \file_put_contents($this->root . '/output.tar.gz', 'build output');
        foreach ($order as $callback) {
            $this->send($callback);
            $this->cache->flush();
        }

        $deployment = $this->database->getDocument('deployments', 'deployment');
        $this->assertSame('ready', $deployment->getAttribute('status'));
        $this->assertSame(12, $deployment->getAttribute('buildSize'));
        $this->assertSame(7, $deployment->getAttribute('buildDuration'));
        $this->assertSame('static', $this->database->getDocument('sites', 'site')->getAttribute('adapter'));
    }

    public function testManifestDoesNotPinSiteBeforeSuccess(): void
    {
        $this->send('manifest');
        $this->cache->flush();
        $this->send('manifest');

        $deployment = $this->database->getDocument('deployments', 'deployment');
        $this->assertSame('building', $deployment->getAttribute('status'));
        $this->assertSame('static', $deployment->getAttribute('adapter'));
        $this->assertSame(['manifest'], $deployment->getAttribute('callbacks'));
        $this->assertSame('', $this->database->getDocument('sites', 'site')->getAttribute('adapter'));
    }

    public function testManifestWriteFailureDoesNotSuppressRedelivery(): void
    {
        $table = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE '%deployments'")->fetchColumn();
        $this->pdo->exec('CREATE TRIGGER fail_manifest BEFORE UPDATE ON "' . $table . '" BEGIN SELECT RAISE(ABORT, \'transient write failure\'); END');
        try {
            $this->send('manifest');
            $this->fail('The manifest write must fail before retrying.');
        } catch (DatabaseException) {
        } finally {
            $this->pdo->exec('DROP TRIGGER fail_manifest');
        }

        // Redeliver the same event without clearing its deduplication cache.
        $this->send('manifest');
        $deployment = $this->database->getDocument('deployments', 'deployment');
        $this->assertSame(['manifest'], $deployment->getAttribute('callbacks'));
        $this->assertSame('static', $deployment->getAttribute('adapter'));
    }

    public function testDuplicateCompleteDoesNotReplaceManifestProgress(): void
    {
        \file_put_contents($this->root . '/output.tar.gz', 'build output');
        $this->send('complete');
        $this->send('manifest');
        $this->cache->flush();
        $this->send('complete');
        $this->send('exit');

        $deployment = $this->database->getDocument('deployments', 'deployment');
        $this->assertSame('ready', $deployment->getAttribute('status'));
        $this->assertSame(['complete', 'manifest'], $deployment->getAttribute('callbacks'));
    }

    public function testFailedManifestStillAllowsSuccessfulBuild(): void
    {
        \file_put_contents($this->root . '/output.tar.gz', 'build output');
        $this->send('manifest', ['status' => 'failed', 'content' => null]);
        $this->cache->flush();
        $this->send('exit');
        $this->cache->flush();
        $this->send('complete');

        $this->assertSame('ready', $this->database->getDocument('deployments', 'deployment')->getAttribute('status'));
        $this->assertSame('', $this->database->getDocument('sites', 'site')->getAttribute('adapter'));
    }

    public function testFailedOutputCannotBecomeReadyAfterCacheLoss(): void
    {
        $this->send('exit');
        $this->send('manifest');
        try {
            $this->send('output', ['status' => 'failed']);
            $this->fail('A failed output must be rejected.');
        } catch (PermanentFailure) {
        }
        $this->cache->flush();
        $this->send('complete');

        $this->assertSame('failed', $this->database->getDocument('deployments', 'deployment')->getAttribute('status'));
        $this->assertSame('', $this->database->getDocument('sites', 'site')->getAttribute('adapter'));
    }

    public function testMissingOutputFailsAfterBoundedReconciliation(): void
    {
        $this->send('exit');
        $this->send('manifest');
        $this->cache->flush();
        $this->send('complete');

        $deployment = $this->database->getDocument('deployments', 'deployment');
        $this->assertSame('failed', $deployment->getAttribute('status'));
        $this->assertSame(7, $deployment->getAttribute('buildDuration'));
        $this->assertSame('', $this->database->getDocument('sites', 'site')->getAttribute('adapter'));
    }

    public function testSitePinnedAfterManifestRejectsMismatchedBuild(): void
    {
        $this->send('manifest');
        $this->database->updateDocument('sites', 'site', new Document(['adapter' => 'ssr']));
        \file_put_contents($this->root . '/output.tar.gz', 'build output');
        $this->send('exit');
        $this->cache->flush();
        $this->send('complete');

        $this->assertSame('failed', $this->database->getDocument('deployments', 'deployment')->getAttribute('status'));
        $this->assertSame('ssr', $this->database->getDocument('sites', 'site')->getAttribute('adapter'));
    }

    public function testSitePinnedAfterReadinessChecksRejectsMismatchedBuild(): void
    {
        $this->worker = new PinningJobs();
        \file_put_contents($this->root . '/output.tar.gz', 'build output');
        $this->send('manifest');
        $this->send('exit');
        $this->send('complete');

        $this->assertSame('failed', $this->database->getDocument('deployments', 'deployment')->getAttribute('status'));
        $this->assertSame('ssr', $this->database->getDocument('sites', 'site')->getAttribute('adapter'));
    }

    public function testFailedExitIsNotTreatedAsSuccessfulRuntime(): void
    {
        $this->send('manifest');
        $this->send('exit', [
            'exitCode' => 1,
            'error' => ['code' => 'job_exit_nonzero', 'message' => 'Build command failed'],
        ]);
        \file_put_contents($this->root . '/output.tar.gz', 'partial output');
        $this->cache->flush();
        $this->send('complete');

        $deployment = $this->database->getDocument('deployments', 'deployment');
        $this->assertSame('failed', $deployment->getAttribute('status'));
        $this->assertSame(7, $deployment->getAttribute('buildDuration'));
        $this->assertSame('', $this->database->getDocument('sites', 'site')->getAttribute('adapter'));
    }

    public function testCanceledBuildIgnoresLateCallbacks(): void
    {
        $this->send('manifest');
        $this->database->updateDocument('deployments', 'deployment', new Document(['status' => 'canceled']));
        \file_put_contents($this->root . '/output.tar.gz', 'build output');
        foreach (['exit', 'output', 'complete'] as $callback) {
            $this->cache->flush();
            $this->send($callback);
        }

        $this->assertSame('canceled', $this->database->getDocument('deployments', 'deployment')->getAttribute('status'));
        $this->assertSame('', $this->database->getDocument('sites', 'site')->getAttribute('adapter'));
    }

    private function send(string $callback, array $override = []): void
    {
        $data = match ($callback) {
            'exit' => ['exitCode' => 0, 'image' => 'openruntimes/static:v5-1', 'durationSeconds' => 7],
            'manifest' => ['artifactId' => 'manifest', 'artifactType' => 'read', 'status' => 'success', 'content' => ['files' => ['index.html']]],
            'output' => ['artifactId' => 'output', 'artifactType' => 'upload', 'status' => 'success'],
            default => [],
        };
        $event = new JobsMessage(
            project: $this->project,
            id: 'event-' . $callback,
            event: 'orchestrator.job.' . (\in_array($callback, ['output', 'manifest'], true) ? 'artifact' : $callback),
            data: \array_replace($data, [
                'jobId' => 'build',
                'meta' => ['projectId' => 'project', 'deploymentId' => 'deployment'],
            ], $override),
        );
        $this->worker->action(
            message: (new Message())->setPayload($event->toArray()),
            project: $this->project,
            dbForProject: $this->database,
            dbForPlatform: $this->createStub(Database::class),
            queueForRealtime: $this->createStub(Realtime::class),
            queueForEvents: $this->createStub(Event::class),
            queueForWebhooks: $this->createStub(Webhook::class),
            publisherForFunctions: $this->createStub(FunctionPublisher::class),
            publisherForScreenshots: $this->createStub(ScreenshotPublisher::class),
            publisherForUsage: $this->createStub(UsagePublisher::class),
            usage: new Context(),
            deviceForBuilds: $this->storage,
            deviceForFunctions: $this->storage,
            deviceForSites: $this->storage,
            vcsFactory: $this->createStub(Factory::class),
            cache: $this->cache,
            locks: static fn (string $key, int $ttl, callable $action) => $action(),
            platform: ['sitesDomain' => 'sites.localhost'],
            plan: [],
            bus: new Bus(),
        );
    }
}

/** Real filesystem artifacts with the remote-device callback path. */
final class RemoteBuildStorage extends Local
{
    public function getType(): DeviceType
    {
        return DeviceType::S3;
    }
}

/** Models another deployment pinning the site while Cloud distributes this build. */
final class PinningJobs extends Jobs
{
    protected function onVerified(Database $dbForProject, Document $project, Document $deployment, Cache $cache): void
    {
        $dbForProject->updateDocument('sites', $deployment->getAttribute('resourceId'), new Document(['adapter' => 'ssr']));
    }
}
