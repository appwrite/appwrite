<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Publisher\Screenshot as ScreenshotPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Platform\Modules\Functions\Workers\Jobs;
use Appwrite\Usage\Context as UsageContext;
use Appwrite\Vcs\Factory as VcsFactory;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Bus\Bus;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Queue;

require_once __DIR__ . '/../../../../app/init.php';

/**
 * A build that never streamed a log line has no buildStartedAt, so the billed
 * duration falls back to the deployment's creation time and measures the whole
 * queue wait. The jobs-service will not let a job outlive
 * _APP_COMPUTE_BUILD_TIMEOUT, so anything past it was not build time.
 */
final class JobsTest extends TestCase
{
    private ?array $specifications = null;

    protected function setUp(): void
    {
        $this->specifications = Config::getParam('specifications');

        // The spec the customer's timed-out builds ran on: 2048 MB, 2 cpus.
        Config::setParam('specifications', [
            's-2vcpu-2gb' => ['cpus' => 2, 'memory' => 2048],
        ]);

        \putenv('_APP_COMPUTE_BUILD_TIMEOUT=900');
        $_SERVER['_APP_COMPUTE_BUILD_TIMEOUT'] = '900';
    }

    protected function tearDown(): void
    {
        Config::setParam('specifications', $this->specifications);

        \putenv('_APP_COMPUTE_BUILD_TIMEOUT');
        unset($_SERVER['_APP_COMPUTE_BUILD_TIMEOUT']);
    }

    public function testStarvedBuildIsBilledNoLongerThanTheBuildTimeout(): void
    {
        $database = $this->createProjectDatabase();

        $function = $database->createDocument('functions', new Document([
            '$id' => 'function-1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'buildSpecification' => 's-2vcpu-2gb',
            'scheduleId' => '',
            'schedule' => '',
            'deploymentId' => '',
            'latestDeploymentId' => '',
            'latestDeploymentInternalId' => '',
            'latestDeploymentCreatedAt' => '',
            'latestDeploymentStatus' => '',
        ]));

        // Created three hours ago, still 'building', and no buildStartedAt: the
        // jobs-service never streamed a log line, so the build never ran.
        $database->setPreserveDates(true);
        $deployment = $database->createDocument('deployments', new Document([
            '$id' => 'deployment-1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            '$createdAt' => DateTime::addSeconds(new \DateTime(), -10800),
            'resourceId' => $function->getId(),
            'resourceInternalId' => $function->getSequence(),
            'resourceType' => 'functions',
            'status' => 'building',
            'buildStartedAt' => null,
            'buildEndedAt' => null,
            'buildDuration' => null,
            'buildLogs' => '',
            'buildSize' => 0,
            'sourceSize' => 0,
            'totalSize' => 0,
            'providerCommitHash' => '',
            'providerCommentId' => '',
            'activate' => false,
        ]));
        $database->setPreserveDates(false);

        $this->assertSame(10800, \time() - (new \DateTime($deployment->getCreatedAt()))->getTimestamp(), 'fixture must be a three-hour-old deployment');

        $publisher = new MockPublisher();
        $worker = new TestableJobs();

        $finalized = $worker->finalizeBuild(
            dbForProject: $database,
            dbForPlatform: $database,
            project: new Document(['$id' => 'project-1', '$sequence' => '1', 'database' => 'db']),
            deployment: $deployment,
            usage: new UsageContext(),
            publisherForUsage: new UsagePublisher($publisher, new Queue('v1-usage')),
            publisherForScreenshots: new ScreenshotPublisher($publisher, new Queue('v1-screenshots')),
            vcsFactory: new VcsFactory(new Cache(new NoCache())),
            bus: new Bus(),
        );

        $this->assertSame('failed', $finalized->getAttribute('status'));
        $this->assertSame(900, $finalized->getAttribute('buildDuration'));
        $this->assertSame(900, $database->getDocument('deployments', 'deployment-1')->getAttribute('buildDuration'));

        // 2048 MB x 900 s x 2 cpus. The three-hour queue wait is not build time.
        $this->assertSame(3686400, $this->metric($publisher, 'builds.mbSeconds'));
        $this->assertSame(3686400, $this->metric($publisher, 'functions.builds.mbSeconds'));
        $this->assertSame(900000, $this->metric($publisher, 'builds.compute.failed'));
    }

    /**
     * Sum the published values for one metric key across the usage queue.
     */
    private function metric(MockPublisher $publisher, string $key): int
    {
        $total = 0;
        foreach ($publisher->getEvents('v1-usage') ?? [] as $event) {
            foreach ($event['metrics'] ?? [] as $metric) {
                if (($metric['key'] ?? '') === $key) {
                    $total += (int) $metric['value'];
                }
            }
        }

        return $total;
    }

    private function createProjectDatabase(): Database
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::any()->toString());

        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('jobsTests')
            ->setNamespace('jobs');
        $database->create();

        $permissions = [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];

        $database->createCollection('functions', [], [], $permissions, false);
        foreach (['buildSpecification', 'scheduleId', 'schedule', 'deploymentId', 'latestDeploymentId', 'latestDeploymentInternalId', 'latestDeploymentCreatedAt', 'latestDeploymentStatus'] as $attribute) {
            $database->createAttribute('functions', $attribute, Database::VAR_STRING, 255, false);
        }

        $database->createCollection('deployments', [], [], $permissions, false);
        foreach (['resourceId', 'resourceInternalId', 'resourceType', 'status', 'buildStartedAt', 'buildEndedAt', 'buildLogs', 'providerCommitHash', 'providerCommentId'] as $attribute) {
            $database->createAttribute('deployments', $attribute, Database::VAR_STRING, 16384, false);
        }
        foreach (['buildDuration', 'buildSize', 'sourceSize', 'totalSize'] as $attribute) {
            $database->createAttribute('deployments', $attribute, Database::VAR_INTEGER, 8, false);
        }
        $database->createAttribute('deployments', 'activate', Database::VAR_BOOLEAN, 0, false);

        return $database;
    }
}

/**
 * finalize() is the protected extension point cloud already overrides; this
 * exposes it with the collaborators a failed function build actually reaches.
 */
final class TestableJobs extends Jobs
{
    public function finalizeBuild(
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Document $deployment,
        UsageContext $usage,
        UsagePublisher $publisherForUsage,
        ScreenshotPublisher $publisherForScreenshots,
        VcsFactory $vcsFactory,
        Bus $bus,
    ): Document {
        return $this->finalize(
            $dbForProject,
            $dbForPlatform,
            $project,
            $deployment,
            false,
            'Build failed with exit code -1.',
            $usage,
            $publisherForUsage,
            $publisherForScreenshots,
            $vcsFactory,
            [],
            $bus,
        );
    }
}
