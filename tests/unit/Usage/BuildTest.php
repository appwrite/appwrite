<?php

declare(strict_types=1);

namespace Tests\Unit\Usage;

use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Platform\Modules\Compute\Specification;
use Appwrite\Usage\Build;
use Appwrite\Usage\Context;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Database\Document;
use Utopia\Queue\Queue;

require_once __DIR__ . '/../../../app/init.php';

final class BuildTest extends TestCase
{
    private const MEMORY = 2048;
    private const CPUS = 2;
    private const DURATION = 7;
    private const SIZE = 4096;

    /**
     * @param array<string, mixed> $deployment
     * @return array<string, int> metric key => value
     */
    private function publish(array $deployment): array
    {
        $publisher = new MockPublisher();

        Build::publish(
            new Context(),
            new Document([
                '$id' => 'function',
                '$sequence' => '1',
                'buildSpecification' => Specification::S_2VCPU_2GB,
            ]),
            new Document($deployment),
            new Document(['$id' => 'project', '$sequence' => '1']),
            new UsagePublisher($publisher, new Queue('usage')),
        );

        $events = $publisher->getEvents('usage');
        $this->assertCount(1, $events);

        $metrics = [];
        foreach ($events[0]['metrics'] as $metric) {
            $metrics[$metric['key']] = $metric['value'];
        }

        return $metrics;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function deployment(array $overrides = []): array
    {
        return \array_merge([
            '$id' => 'deployment',
            '$sequence' => '1',
            '$createdAt' => '2026-08-31T10:00:00.000+00:00',
            'resourceType' => 'functions',
            'status' => 'ready',
            'buildDuration' => self::DURATION,
            'buildSize' => self::SIZE,
        ], $overrides);
    }

    public function testBuildStartedAtStampedBillsMemoryTimesDurationTimesCpus(): void
    {
        $metrics = $this->publish($this->deployment([
            'buildStartedAt' => '2026-08-31T10:00:05.000+00:00',
        ]));

        $expectedMbSeconds = self::MEMORY * self::DURATION * self::CPUS;
        $expectedCompute = self::DURATION * 1000;

        $this->assertSame($expectedMbSeconds, $metrics[METRIC_BUILDS_MB_SECONDS]);
        $this->assertSame($expectedMbSeconds, $metrics['functions.builds.mbSeconds']);
        $this->assertSame($expectedCompute, $metrics[METRIC_BUILDS_COMPUTE]);
        $this->assertSame($expectedCompute, $metrics[METRIC_BUILDS_COMPUTE_SUCCESS]);
        $this->assertSame($expectedCompute, $metrics['functions.builds.compute']);
        $this->assertSame(1, $metrics[METRIC_BUILDS]);
        $this->assertSame(self::SIZE, $metrics[METRIC_BUILDS_STORAGE]);
    }

    public function testMissingBuildStartedAtDropsComputeButKeepsCounts(): void
    {
        $metrics = $this->publish($this->deployment());

        $this->assertSame(0, $metrics[METRIC_BUILDS_MB_SECONDS]);
        $this->assertSame(0, $metrics['functions.builds.mbSeconds']);
        $this->assertSame(0, $metrics[METRIC_BUILDS_COMPUTE]);
        $this->assertSame(0, $metrics[METRIC_BUILDS_COMPUTE_SUCCESS]);
        $this->assertSame(0, $metrics['functions.builds.compute']);
        $this->assertSame(0, $metrics['functions.builds.compute.success']);

        $this->assertSame(1, $metrics[METRIC_BUILDS]);
        $this->assertSame(1, $metrics[METRIC_BUILDS_SUCCESS]);
        $this->assertSame(self::SIZE, $metrics[METRIC_BUILDS_STORAGE]);
    }

    public function testNullBuildStartedAtDropsComputeOnFailedBuilds(): void
    {
        $metrics = $this->publish($this->deployment([
            'status' => 'failed',
            'buildStartedAt' => null,
        ]));

        $this->assertSame(0, $metrics[METRIC_BUILDS_MB_SECONDS]);
        $this->assertSame(0, $metrics[METRIC_BUILDS_COMPUTE]);
        $this->assertSame(0, $metrics[METRIC_BUILDS_COMPUTE_FAILED]);
        $this->assertSame(0, $metrics['functions.builds.compute.failed']);

        $this->assertSame(1, $metrics[METRIC_BUILDS]);
        $this->assertSame(1, $metrics[METRIC_BUILDS_FAILED]);
        $this->assertSame(self::SIZE, $metrics[METRIC_BUILDS_STORAGE]);
    }
}
