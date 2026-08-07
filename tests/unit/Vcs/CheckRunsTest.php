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
    private const REASON = 'Commit message matched a skip pattern.';

    public function testGitHubGetsACheckRunAndNoCommitStatus(): void
    {
        $adapter = $this->createMock(GitHub::class);
        // The reason only reaches GitHub when title and summary are both set.
        $adapter->expects($this->once())
            ->method('createCheckRun')
            ->with('owner', 'repo', self::SHA, 'my-function (my-project)', 'completed', 'neutral', 'Deployment skipped', self::REASON);
        $adapter->expects($this->never())->method('updateCommitStatus');

        $this->report(new CheckRuns(), $adapter);
    }

    public function testOtherProvidersGetTheCommitStatus(): void
    {
        $adapter = $this->createMock(Git::class);
        $adapter->expects($this->never())->method('createCheckRun');
        $adapter->expects($this->once())
            ->method('updateCommitStatus')
            ->with('repo', self::SHA, 'owner', 'success', self::REASON, '', 'my-function (my-project)');

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
            ->willReturnCallback(function (
                string $owner,
                string $repositoryName,
                string $headSha,
                string $name,
                string $status,
                string $conclusion,
                string $title,
                string $summary,
                string $text = '',
                array $annotations = [],
                array $images = [],
                array $actions = [],
                string $detailsUrl = '',
            ) {
                $this->assertSame('action_required', $conclusion);
                $this->assertSame('https://console.example.com/authorize', $detailsUrl);

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
            ->with('owner', 'repo', self::SHA, \str_repeat('a', 252) . '...');

        (new CheckRuns())->report($adapter, 'owner', 'repo', self::SHA, \str_repeat('a', 300), 'neutral', 'success', 'title', 'summary');
    }

    public function testOverlongDescriptionIsTruncated(): void
    {
        // A commit status description over 140 characters is rejected outright.
        $adapter = $this->createMock(Git::class);
        $adapter->expects($this->once())
            ->method('updateCommitStatus')
            ->with('repo', self::SHA, 'owner', 'success', \str_repeat('b', 137) . '...');

        (new CheckRuns())->report($adapter, 'owner', 'repo', self::SHA, 'name', 'neutral', 'success', 'title', \str_repeat('b', 200));
    }

    public function testCheckRunRefusalIsAskedOnlyOnce(): void
    {
        // A webhook fans out to every linked resource, and the repository is the same
        // for all of them — but the fallback must still run for each.
        $adapter = $this->createMock(GitHub::class);
        $adapter->expects($this->once())
            ->method('createCheckRun')
            ->willThrowException(new \Exception('HTTP 403', 403));
        $adapter->expects($this->exactly(5))->method('updateCommitStatus');

        $checkRuns = new CheckRuns();

        for ($i = 0; $i < 5; $i++) {
            $this->report($checkRuns, $adapter);
        }
    }

    public function testRejectedCheckRunStaysRetryable(): void
    {
        // 422 complains about this report, not about access.
        $adapter = $this->createMock(GitHub::class);
        $adapter->expects($this->exactly(3))
            ->method('createCheckRun')
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
            self::REASON,
        );
    }
}
