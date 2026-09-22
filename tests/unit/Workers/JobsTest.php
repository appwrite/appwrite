<?php

declare(strict_types=1);

namespace Tests\Unit\Workers;

use Appwrite\Workers\Jobs;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;

/**
 * Proves job resolution for combined and dedicated worker modes keeps the
 * configured concurrency in combined mode and accepts dedicated overrides.
 */
final class JobsTest extends TestCase
{
    /** @var array<string, array{queue: string, queueEnv?: string, coroutines?: int}> */
    private array $config;

    protected function setUp(): void
    {
        $this->config = Config::getParam('workers');
    }

    public function testCombinedModeKeepsQueueCapsDespiteGlobalOverride(): void
    {
        $jobs = Jobs::resolve(
            \array_keys($this->config),
            $this->config,
            $this->env(['_APP_WORKER_MAX_COROUTINES' => '61']),
        );

        $this->assertSame(8, $jobs['databases']['coroutines']);
        $this->assertSame(8, $jobs['functions']['coroutines']);
        $this->assertSame('v1-database', $jobs['databases']['queue']);
    }

    public function testDedicatedDatabasesAllowsGlobalOverride(): void
    {
        $jobs = Jobs::resolve(
            ['databases'],
            $this->config,
            $this->env(['_APP_WORKER_MAX_COROUTINES' => '99']),
        );

        $this->assertCount(1, $jobs);
        $this->assertSame(99, $jobs['databases']['coroutines']);
    }

    public function testDedicatedNonDatabasesAllowsGlobalOverride(): void
    {
        $jobs = Jobs::resolve(
            ['functions'],
            $this->config,
            $this->env(['_APP_WORKER_MAX_COROUTINES' => '99']),
        );

        $this->assertSame(99, $jobs['functions']['coroutines']);
    }

    public function testDedicatedDatabasesUsesConfiguredConcurrency(): void
    {
        $jobs = Jobs::resolve(
            ['databases'],
            $this->config,
            $this->env([]),
        );

        $this->assertSame(8, $jobs['databases']['coroutines']);
    }

    public function testPartialCombinedKeepsQueueCaps(): void
    {
        $jobs = Jobs::resolve(
            ['databases', 'functions'],
            $this->config,
            $this->env(['_APP_WORKER_MAX_COROUTINES' => '50']),
        );

        $this->assertSame(8, $jobs['databases']['coroutines']);
        $this->assertSame(8, $jobs['functions']['coroutines']);
    }

    public function testQueueEnvOverrideStillApplies(): void
    {
        $jobs = Jobs::resolve(
            ['databases'],
            $this->config,
            $this->env(['_APP_DATABASE_QUEUE_NAME' => 'custom-ddl']),
        );

        $this->assertSame('custom-ddl', $jobs['databases']['queue']);
        $this->assertSame(8, $jobs['databases']['coroutines']);
    }

    public function testMultipleDatabaseProcessesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Jobs::resolve(['databases'], $this->config, $this->env(['_APP_WORKERS_NUM' => '2']));
    }

    /**
     * @param array<string, string> $values
     * @return callable(string, mixed=): mixed
     */
    private function env(array $values): callable
    {
        return static function (string $key, mixed $default = null) use ($values): mixed {
            if (\array_key_exists($key, $values)) {
                return $values[$key];
            }

            return $default;
        };
    }
}
