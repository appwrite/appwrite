<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Functions\Workers;

use Appwrite\Event\Publisher\Screenshot as ScreenshotPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Platform\Modules\Functions\Workers\Jobs;
use Appwrite\Usage\Context as UsageContext;
use Appwrite\Vcs\Factory as VcsFactory;
use OpenRuntimes\Orchestrator\Callback\JobExit;
use PHPUnit\Framework\TestCase;
use Utopia\Bus\Bus;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Storage\Device;

/**
 * The Jobs worker with its exit handler exposed.
 */
final class BuildDurationTestJobs extends Jobs
{
    public function exposeOnExit(Database $dbForProject, Document $deployment, JobExit $exit, BuildDurationTest $test): Document
    {
        return $this->onExit(
            $dbForProject,
            $test->stub(Database::class),
            new Document(['$id' => 'project-1', '$sequence' => '7']),
            $deployment,
            $exit,
            $test->stub(UsageContext::class),
            $test->stub(UsagePublisher::class),
            $test->stub(ScreenshotPublisher::class),
            $test->stub(Device::class),
            $test->stub(VcsFactory::class),
            $test->stub(Cache::class),
            [],
            [],
            $test->stub(Bus::class),
        );
    }
}

/**
 * The build duration an exit records is what usage bills as build compute. Driven through
 * the worker's exit handler with a project database that records what it's asked to write,
 * then reports the deployment finalized so the handler stops there.
 */
final class BuildDurationTest extends TestCase
{
    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function stub(string $class): object
    {
        return $this->createStub($class);
    }

    /**
     * @param array<string, mixed> $meta
     * @return int the buildDuration the exit handler wrote
     */
    private function exit(?float $durationSeconds, array $meta, string $startedAgo): int
    {
        $written = null;
        $deployment = new Document([
            '$id' => 'dep-1',
            '$sequence' => '55',
            'status' => 'building',
            'buildStartedAt' => (new \DateTimeImmutable($startedAgo))->format('Y-m-d H:i:s.v'),
        ]);

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('updateDocuments')->willReturnCallback(static function (string $collection, Document $changes) use (&$written): int {
            $written = $changes->getAttribute('buildDuration');

            return 1;
        });
        $dbForProject->method('getDocument')->willReturn(new Document(['$id' => 'dep-1', 'status' => 'failed']));

        $exit = new JobExit(
            jobId: 'job-1',
            exitCode: 1,
            reason: null,
            image: 'openruntimes/node:v5-22',
            durationSeconds: $durationSeconds,
            error: null,
            meta: ['projectId' => 'project-1', 'deploymentId' => 'dep-1', ...$meta],
        );

        (new BuildDurationTestJobs())->exposeOnExit($dbForProject, $deployment, $exit, $this);

        $this->assertIsInt($written, 'the exit handler must record a build duration');

        return $written;
    }

    public function testAnUnmeasuredExitReportedWeeksLateIsBoundedByTheBuildTimeout(): void
    {
        // The case that billed 4,910 GB-hours: the exit arrived 50 days after the build started.
        $duration = $this->exit(null, ['timeoutSeconds' => 2700], '-50 days');

        $this->assertLessThanOrEqual(2700 + 300, $duration);
        $this->assertGreaterThanOrEqual(2700, $duration);
    }

    public function testAMeasuredDurationPastTheTimeoutIsKept(): void
    {
        // Termination grace can legitimately run past the timeout; a measurement is trusted.
        $duration = $this->exit(3600.0, ['timeoutSeconds' => 2700], '-2 hours');

        $this->assertSame(3600, $duration);
    }

    public function testAnUnmeasuredExitFromBeforeTheTimeoutWasEchoedIsStillBounded(): void
    {
        // Jobs submitted before the timeout was echoed in meta fall back to the global timeout.
        $duration = $this->exit(null, [], '-50 days');

        $this->assertLessThan(86400, $duration);
    }
}
