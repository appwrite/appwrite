<?php

declare(strict_types=1);

namespace Tests\Unit\Functions;

use Appwrite\Platform\Modules\Functions\Workers\Jobs;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Utopia\Database\Document;

final class JobsTest extends TestCase
{
    private Jobs $jobs;

    public function setUp(): void
    {
        $this->jobs = new Jobs();
    }

    public function testDurationRoundsSubSecondBuildUpToOneSecond(): void
    {
        $deployment = new Document([
            'buildStartedAt' => (new \DateTimeImmutable('-500 milliseconds'))->format('Y-m-d H:i:s.v'),
        ]);

        $this->assertSame(1, $this->callJobs('duration', $deployment));
    }

    public function testDurationFallsBackToCreationTimeWhenStartMissing(): void
    {
        $deployment = new Document([
            '$createdAt' => (new \DateTimeImmutable('-4500 milliseconds'))->format('Y-m-d H:i:s.v'),
        ]);

        $this->assertSame(5, $this->callJobs('duration', $deployment));
    }

    public function testDurationIsZeroWithoutAnyTimestamp(): void
    {
        $this->assertSame(0, $this->callJobs('duration', new Document([])));
    }

    public function testDurationClampsFutureStartToZero(): void
    {
        $deployment = new Document([
            'buildStartedAt' => (new \DateTimeImmutable('+10 seconds'))->format('Y-m-d H:i:s.v'),
        ]);

        $this->assertSame(0, $this->callJobs('duration', $deployment));
    }

    public function testDurationIsZeroForUnparseableStart(): void
    {
        $deployment = new Document([
            'buildStartedAt' => 'not-a-date',
        ]);

        $this->assertSame(0, $this->callJobs('duration', $deployment));
    }

    private function callJobs(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($this->jobs, $method))->invoke($this->jobs, ...$arguments);
    }
}
