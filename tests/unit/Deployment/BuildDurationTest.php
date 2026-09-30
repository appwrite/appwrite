<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\BuildDuration;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class BuildDurationTest extends TestCase
{
    private const float NOW = 1790000000.0;

    private function startedAgo(int $seconds): Document
    {
        return new Document([
            '$id' => 'dep-1',
            'status' => 'building',
            'buildStartedAt' => (new \DateTimeImmutable('@' . (int) (self::NOW - $seconds)))->format('Y-m-d H:i:s.v'),
        ]);
    }

    public function testAMeasuredDurationIsKeptEvenPastTheTimeout(): void
    {
        // Termination grace can legitimately run past the timeout.
        $this->assertSame(3600, (new BuildDuration())->of($this->startedAgo(7200), 3600.0, 2700, self::NOW));
    }

    public function testAnUnmeasuredExitReportedWeeksLateIsBoundedByTheTimeout(): void
    {
        // An exit that arrived 50 days after its build started is not 50 days of build.
        $duration = (new BuildDuration())->of($this->startedAgo(50 * 86400), null, 2700, self::NOW);

        $this->assertSame(2700 + 300, $duration);
    }

    public function testAnUnmeasuredExitWithinTheTimeoutUsesTheElapsedTime(): void
    {
        $this->assertSame(600, (new BuildDuration())->of($this->startedAgo(600), null, 2700, self::NOW));
    }

    public function testANonFiniteMeasurementFallsBackToTheBoundedElapsedTime(): void
    {
        $this->assertSame(900 + 300, (new BuildDuration())->of($this->startedAgo(50 * 86400), \NAN, 900, self::NOW));
    }

    public function testADurationAlreadyRecordedOnTheDeploymentIsKept(): void
    {
        $deployment = $this->startedAgo(50 * 86400)
            ->setAttribute('buildEndedAt', (new \DateTimeImmutable('@' . (int) self::NOW))->format('Y-m-d H:i:s.v'))
            ->setAttribute('buildDuration', 42);

        $this->assertSame(42, (new BuildDuration())->of($deployment, null, 900, self::NOW));
    }
}
