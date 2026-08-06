<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Vcs\CheckRuns;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\GitHub;

final class CheckRunsTest extends TestCase
{
    private const string SHA = '60c0416257a9cbcdd96b2d370c38d8f8d150ccfb';

    public function testGitHubGetsACheckRunAndNoCommitStatus(): void
    {
        $adapter = $this->github();
        $adapter->expects($this->once())
            ->method('createCheckRun')
            ->willReturnCallback(function (...$arguments) {
                $this->assertSame('completed', $arguments[4]);
                $this->assertSame(CheckRuns::CONCLUSION_NEUTRAL, $arguments[5]);
                $this->assertSame('Deployment skipped', $arguments[6]);
                // The reason only reaches GitHub when title and summary are both set.
                $this->assertSame('Commit message matched a skip pattern.', $arguments[7]);

                return ['id' => 7];
            });
        $adapter->expects($this->never())->method('updateCommitStatus');

        $this->report(new CheckRuns(), $adapter);
    }

    public function testOtherProvidersGetTheCommitStatus(): void
    {
        $adapter = $this->createMock(Git::class);
        $adapter->expects($this->never())->method('createCheckRun');
        $adapter->expects($this->once())
            ->method('updateCommitStatus')
            ->with('repo', self::SHA, 'owner', 'success', 'Commit message matched a skip pattern.', '', 'my-function (my-project)');

        $this->report(new CheckRuns(), $adapter);
    }

    public function testACheckRunFailureFallsBackToTheCommitStatus(): void
    {
        $adapter = $this->github();
        $adapter->method('createCheckRun')->willThrowException(new \Exception('HTTP 500', 500));
        $adapter->expects($this->once())->method('updateCommitStatus');

        $this->report(new CheckRuns(), $adapter);
    }

    public function testAuthorizationIsReportedAsActionRequired(): void
    {
        $adapter = $this->github();
        $adapter->expects($this->once())
            ->method('createCheckRun')
            ->willReturnCallback(function (...$arguments) {
                $this->assertSame(CheckRuns::CONCLUSION_ACTION_REQUIRED, $arguments[5]);
                $this->assertSame('https://console.example.com/authorize', $arguments[12]);

                return ['id' => 8];
            });

        (new CheckRuns())->report(
            $adapter,
            'owner',
            'repo',
            self::SHA,
            'name',
            CheckRuns::CONCLUSION_ACTION_REQUIRED,
            'failure',
            'Authorization required',
            'A maintainer must approve this external contribution.',
            'https://console.example.com/authorize',
        );
    }

    public function testCommitWithoutHashReportsNothing(): void
    {
        $adapter = $this->github();
        $adapter->expects($this->never())->method('createCheckRun');
        $adapter->expects($this->never())->method('updateCommitStatus');

        $this->report(new CheckRuns(), $adapter, '');
    }

    public function testUnknownRepositoryReportsNothing(): void
    {
        $adapter = $this->github();
        $adapter->expects($this->never())->method('createCheckRun');
        $adapter->expects($this->never())->method('updateCommitStatus');

        (new CheckRuns())->report($adapter, '', '', self::SHA, 'name', CheckRuns::CONCLUSION_NEUTRAL, 'success', 'title', 'summary');
    }

    public function testOverlongNameIsTruncated(): void
    {
        $adapter = $this->github();
        $adapter->expects($this->once())
            ->method('createCheckRun')
            ->willReturnCallback(function (...$arguments) {
                $this->assertSame(255, \mb_strlen($arguments[3]));

                return ['id' => 1];
            });

        (new CheckRuns())->report($adapter, 'owner', 'repo', self::SHA, \str_repeat('a', 300), CheckRuns::CONCLUSION_NEUTRAL, 'success', 'title', 'summary');
    }

    public function testAProviderRejectingBothIsContained(): void
    {
        $adapter = $this->createStub(GitHub::class);
        $adapter->method('createCheckRun')->willThrowException(new \Exception('HTTP 500', 500));
        $adapter->method('updateCommitStatus')->willThrowException(new \Exception('HTTP 500', 500));

        $this->report(new CheckRuns(), $adapter);

        $this->expectNotToPerformAssertions();
    }

    public function testRepositoryRefusingIsAskedOnlyOnce(): void
    {
        $adapter = $this->createMock(Git::class);
        $adapter->expects($this->once())
            ->method('updateCommitStatus')
            ->willThrowException(new \Exception('HTTP 403', 403));

        $checkRuns = new CheckRuns();

        foreach (\range(1, 5) as $ignored) {
            $this->report($checkRuns, $adapter);
        }
    }

    public function testARejectedReportStaysRetryable(): void
    {
        // 422 complains about this report, not about access.
        $adapter = $this->createMock(Git::class);
        $adapter->expects($this->exactly(3))
            ->method('updateCommitStatus')
            ->willThrowException(new \Exception('HTTP 422', 422));

        $checkRuns = new CheckRuns();

        foreach (\range(1, 3) as $ignored) {
            $this->report($checkRuns, $adapter);
        }
    }

    private function report(CheckRuns $checkRuns, Git $adapter, string $commitHash = self::SHA): void
    {
        $checkRuns->report(
            $adapter,
            'owner',
            'repo',
            $commitHash,
            'my-function (my-project)',
            CheckRuns::CONCLUSION_NEUTRAL,
            'success',
            'Deployment skipped',
            'Commit message matched a skip pattern.',
        );
    }

    private function github(): GitHub&MockObject
    {
        return $this->createMock(GitHub::class);
    }
}
