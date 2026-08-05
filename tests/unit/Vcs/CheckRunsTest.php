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

    public function testOtherProvidersReportNothing(): void
    {
        $adapter = $this->createMock(Git::class);
        $adapter->expects($this->never())->method('createCheckRun');

        $checkRuns = new CheckRuns();

        $this->assertFalse($checkRuns->supports($adapter));
        $this->assertFalse($this->skip($checkRuns, $adapter));
    }

    public function testSkipReportsItsReasonAsNeutral(): void
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

        $this->assertTrue($this->skip(new CheckRuns(), $adapter));
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

        $reported = (new CheckRuns())->conclude(
            $adapter,
            'owner',
            'repo',
            self::SHA,
            'name',
            CheckRuns::CONCLUSION_ACTION_REQUIRED,
            'Authorization required',
            'A maintainer must approve this external contribution.',
            'https://console.example.com/authorize',
        );

        $this->assertTrue($reported);
    }

    public function testDeploymentWithoutACommitReportsNothing(): void
    {
        $adapter = $this->github();
        $adapter->expects($this->never())->method('createCheckRun');

        $checkRuns = new CheckRuns();

        $this->assertFalse($this->skip($checkRuns, $adapter, ''));
    }

    public function testAnUnknownRepositoryReportsNothing(): void
    {
        $adapter = $this->github();
        $adapter->expects($this->never())->method('createCheckRun');

        $this->assertFalse((new CheckRuns())->conclude($adapter, '', '', self::SHA, 'name', CheckRuns::CONCLUSION_NEUTRAL, 'title', 'summary'));
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

        (new CheckRuns())->conclude($adapter, 'owner', 'repo', self::SHA, \str_repeat('a', 300), CheckRuns::CONCLUSION_NEUTRAL, 'title', 'summary');
    }

    public function testAProviderFailureIsContained(): void
    {
        $adapter = $this->createStub(GitHub::class);
        $adapter->method('createCheckRun')->willThrowException(new \Exception('HTTP 500', 500));

        $this->assertFalse($this->skip(new CheckRuns(), $adapter));
    }

    public function testAnInstallationThatRefusesIsAskedOnlyOnce(): void
    {
        $adapter = $this->github();
        $adapter->expects($this->once())
            ->method('createCheckRun')
            ->willThrowException(new \Exception('HTTP 403', 403));

        $checkRuns = new CheckRuns();

        foreach (\range(1, 5) as $ignored) {
            $this->assertFalse($this->skip($checkRuns, $adapter));
        }
    }

    private function skip(CheckRuns $checkRuns, Git $adapter, string $commitHash = self::SHA): bool
    {
        return $checkRuns->conclude(
            $adapter,
            'owner',
            'repo',
            $commitHash,
            'my-function (my-project)',
            CheckRuns::CONCLUSION_NEUTRAL,
            'Deployment skipped',
            'Commit message matched a skip pattern.',
        );
    }

    private function github(): GitHub&MockObject
    {
        return $this->createMock(GitHub::class);
    }
}
