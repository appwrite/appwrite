<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Functions\Workers;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Screenshot as ScreenshotPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Platform\Modules\Functions\Workers\Jobs;
use Appwrite\Usage\Context as UsageContext;
use Appwrite\Vcs\Factory as VcsFactory;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Bus\Bus;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Message;
use Utopia\Queue\PermanentFailure;
use Utopia\Queue\Queue;
use Utopia\Storage\Device\Local;

require_once __DIR__ . '/../../../../../../app/init.php';

/**
 * Drives jobs-service callbacks through the worker the way the queue does, and
 * checks what the deployment ends up holding and whether the callback is
 * reported as a platform failure (a PermanentFailure reaches Sentry).
 */
final class ArtifactFailuresTest extends TestCase
{
    private const string DEPLOYMENT = 'deployment-1';

    private Database $dbForProject;

    private Cache $cache;

    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->cache = new Cache(new MemoryCache());
        $this->dbForProject = $this->createProjectDatabase();
    }

    public function testOutputMissingAfterAFailedBuildIsNotReported(): void
    {
        $this->createDeployment('manual');

        $this->assertNull($this->deliver($this->exit(2, 'job_exit_nonzero')));
        $this->assertNull($this->deliver($this->outputMissing()));

        $deployment = $this->dbForProject->getDocument('deployments', self::DEPLOYMENT);
        $this->assertSame('failed', $deployment->getAttribute('status'));
        $this->assertStringContainsString('Build failed with exit code 2.', $deployment->getAttribute('buildLogs'));
    }

    public function testOutputMissingBeforeAFailedBuildExitIsNotReported(): void
    {
        $this->createDeployment('manual');

        $this->assertNull($this->deliver($this->outputMissing()));
        $this->assertNull($this->deliver($this->exit(137, 'job_oom')));
    }

    public function testOutputMissingAfterACleanExitIsReported(): void
    {
        $this->createDeployment('manual');

        $this->assertNull($this->deliver($this->exit(0)));
        $failure = $this->deliver($this->outputMissing());

        $this->assertInstanceOf(PermanentFailure::class, $failure);
        $this->assertStringContainsString("Build artifact 'archive' failed", $failure->getMessage());
        $this->assertStringContainsString('Internal server error.', $this->buildLogs());
    }

    public function testOutputMissingIsReportedWhenACleanExitArrivesLater(): void
    {
        $this->createDeployment('manual');

        $this->assertNull($this->deliver($this->outputMissing()));
        $failure = $this->deliver($this->exit(0));

        $this->assertInstanceOf(PermanentFailure::class, $failure);
        $this->assertStringContainsString("Build artifact 'archive' failed", $failure->getMessage());
    }

    public function testMissingRepositoryIsShownToTheUser(): void
    {
        $this->createDeployment('vcs');

        $this->assertNull($this->deliver($this->sourceStatus(404)));
        $this->assertNull($this->deliver($this->exit(-1, 'job_failed')));

        $this->assertSame('failed', $this->dbForProject->getDocument('deployments', self::DEPLOYMENT)->getAttribute('status'));
        $this->assertStringContainsString('The repository, branch or commit could not be found.', $this->buildLogs());
        $this->assertStringNotContainsString('Internal server error.', $this->buildLogs());
    }

    public function testDeniedRepositoryIsShownToTheUser(): void
    {
        $this->createDeployment('vcs');

        $this->assertNull($this->deliver($this->sourceStatus(403)));
        $this->assertNull($this->deliver($this->exit(-1, 'job_failed')));

        $this->assertStringContainsString('Access to the repository was denied.', $this->buildLogs());
    }

    public function testUploadedSourceNotFoundStaysInternal(): void
    {
        $this->createDeployment('manual');

        $this->assertNull($this->deliver($this->sourceStatus(404)));
        $failure = $this->deliver($this->exit(-1, 'job_failed'));

        $this->assertInstanceOf(PermanentFailure::class, $failure);
        $this->assertStringContainsString("Build artifact 'source' failed", $failure->getMessage());
        $this->assertStringContainsString('Internal server error.', $this->buildLogs());
    }

    private function deliver(array $callback): ?PermanentFailure
    {
        $publisher = new MockPublisher();

        try {
            (new Jobs())->action(
                message: new Message([
                    'pid' => 'pid',
                    'queue' => 'v1-jobs',
                    'timestamp' => \time(),
                    'payload' => [
                        'project' => ['$id' => 'project-1', '$sequence' => '1'],
                        'id' => 'event-' . ++$this->sequence,
                        'event' => $callback['event'],
                        'data' => $callback['data'] + ['jobId' => 'job-1', 'meta' => ['deploymentId' => self::DEPLOYMENT]],
                    ],
                ]),
                project: new Document(['$id' => 'project-1', '$sequence' => '1']),
                dbForProject: $this->dbForProject,
                dbForPlatform: $this->dbForProject,
                queueForRealtime: (new Realtime())->setPaused(true),
                queueForEvents: new Event($publisher),
                queueForWebhooks: new Webhook($publisher),
                publisherForFunctions: new FunctionPublisher($publisher, new Queue('v1-functions')),
                publisherForScreenshots: new ScreenshotPublisher($publisher, new Queue('v1-screenshots')),
                publisherForUsage: new UsagePublisher($publisher, new Queue('v1-usage')),
                usage: new UsageContext(),
                deviceForBuilds: new Local(\sys_get_temp_dir()),
                vcsFactory: new VcsFactory($this->cache),
                cache: $this->cache,
                locks: static fn (string $key, int $ttl, callable $callback): mixed => $callback(),
                platform: [],
                plan: [],
                bus: new Bus(),
            );
        } catch (PermanentFailure $failure) {
            return $failure;
        }

        return null;
    }

    private function exit(int $code, ?string $error = null): array
    {
        $data = ['exitCode' => $code, 'image' => 'openruntimes/node:v5-22', 'durationSeconds' => 3.0];
        if ($error !== null) {
            $data['error'] = ['code' => $error, 'message' => "job exited with code {$code}"];
        }

        return ['event' => 'orchestrator.job.exit', 'data' => $data];
    }

    private function outputMissing(): array
    {
        return $this->artifactFailure('archive', 'archive', 'artifact_timeout', 'output did not appear within 10.001s of worker exit: context deadline exceeded');
    }

    private function sourceStatus(int $status): array
    {
        return $this->artifactFailure('source', 'download', 'download_http_error', "download failed with status {$status}");
    }

    private function artifactFailure(string $id, string $type, string $code, string $message): array
    {
        return [
            'event' => 'orchestrator.job.artifact',
            'data' => [
                'artifactId' => $id,
                'artifactType' => $type,
                'status' => 'failed',
                'error' => ['code' => $code, 'message' => $message],
            ],
        ];
    }

    private function buildLogs(): string
    {
        return $this->dbForProject->getDocument('deployments', self::DEPLOYMENT)->getAttribute('buildLogs', '');
    }

    private function createDeployment(string $type): void
    {
        $this->dbForProject->createDocument('deployments', new Document([
            '$id' => self::DEPLOYMENT,
            'type' => $type,
            'status' => 'waiting',
            'resourceType' => 'functions',
            'resourceId' => 'function-1',
            'buildLogs' => '',
        ]));
    }

    private function createProjectDatabase(): Database
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::any()->toString());

        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('jobsTests')
            ->setNamespace('jobs_' . \uniqid());
        $database->create();

        $permissions = [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];

        $database->createCollection('functions', [], [], $permissions, false);
        $database->createCollection('deployments', [], [], $permissions, false);
        foreach (['type', 'status', 'resourceType', 'resourceId', 'buildPath', 'buildStartedAt', 'buildEndedAt'] as $attribute) {
            $database->createAttribute('deployments', $attribute, Database::VAR_STRING, 255, false);
        }
        $database->createAttribute('deployments', 'buildLogs', Database::VAR_STRING, 1_000_000, false);
        foreach (['buildDuration', 'buildSize', 'sourceSize', 'totalSize'] as $attribute) {
            $database->createAttribute('deployments', $attribute, Database::VAR_INTEGER, 0, false);
        }

        return $database;
    }
}
