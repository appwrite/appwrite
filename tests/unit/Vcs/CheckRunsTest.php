<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Vcs\CheckRuns;
use PHPUnit\Framework\TestCase;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\GitHub;

final class CheckRunsTest extends TestCase
{
    private const string SHA = '60c0416257a9cbcdd96b2d370c38d8f8d150ccfb';

    public function testOtherProvidersReportNothing(): void
    {
        // Gitea and GitLab inherit a throwing default, so the caller has to be
        // told to keep using the commit status instead.
        $adapter = $this->createMock(Git::class);
        $adapter->expects($this->never())->method('createCheckRun');

        $checkRuns = new CheckRuns();

        $this->assertFalse($checkRuns->supports($adapter));
        $this->assertSame(0, $checkRuns->open($adapter, 'owner', 'repo', self::SHA, 'name', 'Starting...'));
        $this->assertSame(0, $checkRuns->conclude($adapter, 'owner', 'repo', self::SHA, 'name', CheckRuns::CONCLUSION_NEUTRAL, 'title', 'summary'));
        $this->assertFalse($checkRuns->close($adapter, 'owner', 'repo', 1, CheckRuns::CONCLUSION_SUCCESS, 'title', 'summary'));
    }

    public function testOpenReturnsTheRunId(): void
    {
        $adapter = $this->github();
        $adapter->expects($this->once())
            ->method('createCheckRun')
            ->with(
                'owner',
                'repo',
                self::SHA,
                'my-function (my-project)',
                'in_progress',
                '',
                'Deployment queued',
                'Starting...',
                '',
                [],
                [],
                [],
                'https://console.example.com/build',
                'deployment-1',
            )
            ->willReturn(['id' => 4242]);

        $checkRunId = (new CheckRuns())->open(
            $adapter,
            'owner',
            'repo',
            self::SHA,
            'my-function (my-project)',
            'Starting...',
            'https://console.example.com/build',
            'deployment-1',
        );

        $this->assertSame(4242, $checkRunId);
    }

    public function testSkippedDeploymentReportsItsReason(): void
    {
        $adapter = $this->github();
        $adapter->expects($this->once())
            ->method('createCheckRun')
            ->willReturnCallback(function (...$arguments) {
                // A conclusion completes the run in a single call, and the
                // reason only reaches GitHub when title and summary are both set.
                $this->assertSame('completed', $arguments[4]);
                $this->assertSame(CheckRuns::CONCLUSION_NEUTRAL, $arguments[5]);
                $this->assertSame('Deployment skipped', $arguments[6]);
                $this->assertSame('Commit message matched a skip pattern.', $arguments[7]);

                return ['id' => 7];
            });

        $checkRunId = (new CheckRuns())->conclude(
            $adapter,
            'owner',
            'repo',
            self::SHA,
            'name',
            CheckRuns::CONCLUSION_NEUTRAL,
            'Deployment skipped',
            'Commit message matched a skip pattern.',
        );

        $this->assertSame(7, $checkRunId);
    }

    public function testDeploymentWithoutACommitReportsNothing(): void
    {
        $adapter = $this->github();
        $adapter->expects($this->never())->method('createCheckRun');

        $checkRuns = new CheckRuns();

        $this->assertSame(0, $checkRuns->open($adapter, 'owner', 'repo', '', 'name', 'Starting...'));
        $this->assertSame(0, $checkRuns->open($adapter, 'owner', 'repo', 'not-a-sha', 'name', 'Starting...'));
    }

    public function testAnUnknownRepositoryReportsNothing(): void
    {
        $adapter = $this->github();
        $adapter->expects($this->never())->method('createCheckRun');

        $this->assertSame(0, (new CheckRuns())->open($adapter, '', '', self::SHA, 'name', 'Starting...'));
    }

    public function testAnOverlongNameIsTruncated(): void
    {
        $adapter = $this->github();
        $adapter->expects($this->once())
            ->method('createCheckRun')
            ->willReturnCallback(function (...$arguments) {
                $this->assertSame(255, \mb_strlen($arguments[3]));

                return ['id' => 1];
            });

        (new CheckRuns())->open($adapter, 'owner', 'repo', self::SHA, \str_repeat('a', 300), 'Starting...');
    }

    public function testAProviderFailureIsContained(): void
    {
        $adapter = $this->createStub(GitHub::class);
        $adapter->method('createCheckRun')->willThrowException(new \Exception('HTTP 500', 500));
        $adapter->method('updateCheckRun')->willThrowException(new \Exception('HTTP 500', 500));

        $checkRuns = new CheckRuns();

        // Never propagates: a report that fails must not fail the deployment.
        $this->assertSame(0, $checkRuns->open($adapter, 'owner', 'repo', self::SHA, 'name', 'Starting...'));
        $this->assertFalse($checkRuns->close($adapter, 'owner', 'repo', 1, CheckRuns::CONCLUSION_SUCCESS, 'title', 'summary'));
    }

    public function testAnInstallationThatRefusesIsAskedOnlyOnce(): void
    {
        // A webhook fans out to every resource linked to the repository. An
        // installation predating the permission would otherwise pay one
        // rejected call per resource.
        $adapter = $this->github();
        $adapter->expects($this->once())
            ->method('createCheckRun')
            ->willThrowException(new \Exception('HTTP 403', 403));

        $checkRuns = new CheckRuns();

        foreach (\range(1, 5) as $ignored) {
            $this->assertSame(0, $checkRuns->open($adapter, 'owner', 'repo', self::SHA, 'name', 'Starting...'));
        }
    }

    public function testCloseCompletesTheOpenedRun(): void
    {
        $adapter = $this->github();
        $adapter->expects($this->once())
            ->method('updateCheckRun')
            ->with('owner', 'repo', 4242, '', '', CheckRuns::CONCLUSION_SUCCESS, 'Deployment ready', 'Build succeeded.', '', [], [], [], 'https://console.example.com/build')
            ->willReturn(['id' => 4242]);

        $closed = (new CheckRuns())->close(
            $adapter,
            'owner',
            'repo',
            4242,
            CheckRuns::CONCLUSION_SUCCESS,
            'Deployment ready',
            'Build succeeded.',
            'https://console.example.com/build',
        );

        $this->assertTrue($closed);
    }

    public function testCloseWithoutARunReportsNothing(): void
    {
        $adapter = $this->github();
        $adapter->expects($this->never())->method('updateCheckRun');

        $this->assertFalse((new CheckRuns())->close($adapter, 'owner', 'repo', 0, CheckRuns::CONCLUSION_SUCCESS, 'title', 'summary'));
    }

    private function github(): GitHub
    {
        return $this->createMock(GitHub::class);
    }
}
