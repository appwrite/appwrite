<?php

declare(strict_types=1);

namespace Tests\Unit\Workers;

use Appwrite\Workers\Jobs;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;

/**
 * Proves job resolution for combined and dedicated worker modes keeps the
 * databases queue at maxCoroutines=1 — parallel schema jobs risk deadlocks.
 */
final class JobsTest extends TestCase
{
    /** @var array<string, array{queue: string, queueEnv?: string, maxCoroutines?: int}> */
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

        $this->assertSame(1, $jobs['databases']['maxCoroutines']);
        $this->assertSame(8, $jobs['functions']['maxCoroutines']);
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
        $this->assertSame(1, $jobs['databases']['maxCoroutines']);
    }

    public function testDedicatedNonDatabasesAllowsGlobalOverride(): void
    {
        $jobs = Jobs::resolve(
            ['functions'],
            $this->config,
            $this->env(['_APP_WORKER_MAX_COROUTINES' => '99']),
        );

        $this->assertSame(99, $jobs['functions']['maxCoroutines']);
    }

    public function testDedicatedDatabasesWithoutOverrideStaysAtOne(): void
    {
        $jobs = Jobs::resolve(
            ['databases'],
            $this->config,
            $this->env([]),
        );

        $this->assertSame(1, $jobs['databases']['maxCoroutines']);
    }

    public function testPartialCombinedStillPinsDatabases(): void
    {
        $jobs = Jobs::resolve(
            ['databases', 'functions'],
            $this->config,
            $this->env(['_APP_WORKER_MAX_COROUTINES' => '50']),
        );

        $this->assertSame(1, $jobs['databases']['maxCoroutines']);
        $this->assertSame(8, $jobs['functions']['maxCoroutines']);
    }

    public function testQueueEnvOverrideStillApplies(): void
    {
        $jobs = Jobs::resolve(
            ['databases'],
            $this->config,
            $this->env(['_APP_QUEUE_NAME' => 'database_db_custom']),
        );

        $this->assertSame('database_db_custom', $jobs['databases']['queue']);
        $this->assertSame(1, $jobs['databases']['maxCoroutines']);
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
