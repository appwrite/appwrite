<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Vcs\CheckRuns;
use PHPUnit\Framework\TestCase;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\GitHub;

final class CheckRunsTest extends TestCase
{
    private const SHA = '60c0416257a9cbcdd96b2d370c38d8f8d150ccfb';

    public function testGitHubGetsACheckRunAndNoCommitStatus(): void
    {
        $adapter = $this->createMock(GitHub::class);
        $adapter->expects($this->once())
            ->method('createCheckRun')
            ->willReturnCallback(function (...$arguments) {
                $this->assertSame('completed', $arguments[4]);
                $this->assertSame('neutral', $arguments[5]);
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

    public function testCheckRunFailureFallsBackToCommitStatus(): void
    {
        $adapter = $this->createMock(GitHub::class);
        // 403 is the App without checks:write — the case the fallback exists for.
        $adapter->method('createCheckRun')->willThrowException(new \Exception('HTTP 403', 403));
        $adapter->expects($this->once())->method('updateCommitStatus');

        $this->report(new CheckRuns(), $adapter);
    }

    public function testAuthorizationIsReportedAsActionRequired(): void
    {
        $adapter = $this->createMock(GitHub::class);
        $adapter->expects($this->once())
            ->method('createCheckRun')
            ->willReturnCallback(function (...$arguments) {
                $this->assertSame('action_required', $arguments[5]);
                $this->assertSame('https://console.example.com/authorize', $arguments[12]);

                return ['id' => 8];
            });

        (new CheckRuns())->report(
            $adapter,
            'owner',
            'repo',
            self::SHA,
            'name',
            'action_required',
            'failure',
            'Authorization required',
            'A maintainer must approve this external contribution.',
            'https://console.example.com/authorize',
        );
    }


    public function testUnknownRepositoryReportsNothing(): void
    {
        $adapter = $this->createMock(GitHub::class);
        $adapter->expects($this->never())->method('createCheckRun');
        $adapter->expects($this->never())->method('updateCommitStatus');

        (new CheckRuns())->report($adapter, '', '', self::SHA, 'name', 'neutral', 'success', 'title', 'summary');
    }

    public function testOverlongNameIsTruncated(): void
    {
        $adapter = $this->createMock(GitHub::class);
        $adapter->expects($this->once())
            ->method('createCheckRun')
            ->willReturnCallback(function (...$arguments) {
                $this->assertSame(255, \mb_strlen($arguments[3]));

                return ['id' => 1];
            });

        (new CheckRuns())->report($adapter, 'owner', 'repo', self::SHA, \str_repeat('a', 300), 'neutral', 'success', 'title', 'summary');
    }


    public function testRepositoryRefusingIsAskedOnlyOnce(): void
    {
        $adapter = $this->createMock(Git::class);
        $adapter->expects($this->once())
            ->method('updateCommitStatus')
            ->willThrowException(new \Exception('HTTP 403', 403));

        $checkRuns = new CheckRuns();

        for ($i = 0; $i < 5; $i++) {
            $this->report($checkRuns, $adapter);
        }
    }

    public function testRejectedReportStaysRetryable(): void
    {
        // 422 complains about this report, not about access.
        $adapter = $this->createMock(Git::class);
        $adapter->expects($this->exactly(3))
            ->method('updateCommitStatus')
            ->willThrowException(new \Exception('HTTP 422', 422));

        $checkRuns = new CheckRuns();

        for ($i = 0; $i < 3; $i++) {
            $this->report($checkRuns, $adapter);
        }
    }

    private function report(CheckRuns $checkRuns, Git $adapter): void
    {
        $checkRuns->report(
            $adapter,
            'owner',
            'repo',
            self::SHA,
            'my-function (my-project)',
            'neutral',
            'success',
            'Deployment skipped',
            'Commit message matched a skip pattern.',
        );
    }

}
