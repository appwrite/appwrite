<?php

declare(strict_types=1);

namespace Tests\Unit\Workers;

use Appwrite\Workers\Jobs;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;

/**
 * Proves job resolution for combined and dedicated worker modes keeps the
 * databases queue at coroutines=1 — parallel schema jobs risk deadlocks.
 */
final class JobsTest extends TestCase
{
    /** @var array<string, array{queue: string, queueEnv?: string, coroutines?: int, prefetch?: int, maxInFlight?: int}> */
    private array $config;

    protected function setUp(): void
    {
        $this->config = Config::getParam('workers');
    }

    public function testCombinedModeKeepsDatabasesAtOneDespiteGlobalOverride(): void
    {
        $jobs = Jobs::resolve(
            \array_keys($this->config),
            $this->config,
            $this->env(['_APP_WORKER_MAX_COROUTINES' => '61']),
        );

        $this->assertSame(1, $jobs['databases']['coroutines']);
        $this->assertSame(1, $this->config['videos']['coroutines']);
        $this->assertSame(1, $jobs['videos']['coroutines']);
        $this->assertSame(8, $jobs['functions']['coroutines']);
        $this->assertSame('database_db_main', $jobs['databases']['queue']);
    }

    public function testDedicatedDatabasesIgnoresGlobalOverride(): void
    {
        $jobs = Jobs::resolve(
            ['databases'],
            $this->config,
            $this->env(['_APP_WORKER_MAX_COROUTINES' => '99']),
        );

        $this->assertCount(1, $jobs);
        $this->assertSame(1, $jobs['databases']['coroutines']);
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

    public function testDedicatedDatabasesWithoutOverrideStaysAtOne(): void
    {
        $jobs = Jobs::resolve(
            ['databases'],
            $this->config,
            $this->env([]),
        );

        $this->assertSame(1, $jobs['databases']['coroutines']);
    }

    public function testPartialCombinedStillPinsDatabases(): void
    {
        $jobs = Jobs::resolve(
            ['databases', 'functions'],
            $this->config,
            $this->env(['_APP_WORKER_MAX_COROUTINES' => '50']),
        );

        $this->assertSame(1, $jobs['databases']['coroutines']);
        $this->assertSame(8, $jobs['functions']['coroutines']);
    }

    public function testQueueEnvOverrideStillApplies(): void
    {
        $jobs = Jobs::resolve(
            ['databases'],
            $this->config,
            $this->env(['_APP_QUEUE_NAME' => 'database_db_custom']),
        );

        $this->assertSame('database_db_custom', $jobs['databases']['queue']);
        $this->assertSame(1, $jobs['databases']['coroutines']);
    }

    public function testDedicatedWorkerResolvesIndependentLimits(): void
    {
        $jobs = Jobs::resolve(['functions'], $this->config, $this->env([
            '_APP_WORKER_MAX_COROUTINES' => '30',
            '_APP_WORKER_PREFETCH' => '8',
            '_APP_WORKER_MAX_IN_FLIGHT' => '90',
        ]));

        $this->assertSame(30, $jobs['functions']['coroutines']);
        $this->assertSame(8, $jobs['functions']['prefetch']);
        $this->assertSame(90, $jobs['functions']['maxInFlight']);
    }

    public function testCombinedWorkerKeepsPerQueueLimits(): void
    {
        $this->config['functions']['prefetch'] = 3;
        $this->config['functions']['maxInFlight'] = 20;
        $jobs = Jobs::resolve(['databases', 'functions'], $this->config, $this->env([
            '_APP_WORKER_PREFETCH' => '100',
            '_APP_WORKER_MAX_IN_FLIGHT' => '200',
        ]));

        $this->assertSame(3, $jobs['functions']['prefetch']);
        $this->assertSame(20, $jobs['functions']['maxInFlight']);
        $this->assertSame(1, $jobs['databases']['prefetch']);
        $this->assertSame(2, $jobs['databases']['maxInFlight']);
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
