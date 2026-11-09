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
    /** @var array<string, array{queue: string, queueEnv?: string, coroutines?: int}> */
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

    public function testResolveReapOptionsDefaultValues(): void
    {
        $options = Jobs::resolveReapOptions($this->env([]));

        $this->assertSame(300, $options['reapAfter']);
        $this->assertNull($options['reapMaxAttempts']);
        $this->assertNull($options['reapMaxAge']);
    }

    public function testResolveReapOptionsCustomValues(): void
    {
        $options = Jobs::resolveReapOptions($this->env([
            '_APP_QUEUE_REAP_AFTER' => '600',
            '_APP_QUEUE_REAP_MAX_ATTEMPTS' => '5',
            '_APP_QUEUE_REAP_MAX_AGE' => '1200',
        ]));

        $this->assertSame(600, $options['reapAfter']);
        $this->assertSame(5, $options['reapMaxAttempts']);
        $this->assertSame(1200, $options['reapMaxAge']);
    }

    public function testResolveReapOptionsBoundsMinimumReapAfter(): void
    {
        $options = Jobs::resolveReapOptions($this->env([
            '_APP_QUEUE_REAP_AFTER' => '-10',
        ]));

        $this->assertSame(1, $options['reapAfter']);
    }

    public function testResolveReapOptionsIgnoresInvalidMaxAttempts(): void
    {
        $optionsZero = Jobs::resolveReapOptions($this->env([
            '_APP_QUEUE_REAP_MAX_ATTEMPTS' => '0',
        ]));
        $optionsString = Jobs::resolveReapOptions($this->env([
            '_APP_QUEUE_REAP_MAX_ATTEMPTS' => 'not-a-number',
        ]));

        $this->assertNull($optionsZero['reapMaxAttempts']);
        $this->assertNull($optionsString['reapMaxAttempts']);
    }

    public function testResolveReapOptionsIgnoresMaxAgeAtOrBelowReapAfter(): void
    {
        $optionsEqual = Jobs::resolveReapOptions($this->env([
            '_APP_QUEUE_REAP_AFTER' => '300',
            '_APP_QUEUE_REAP_MAX_AGE' => '300',
        ]));
        $optionsBelow = Jobs::resolveReapOptions($this->env([
            '_APP_QUEUE_REAP_AFTER' => '300',
            '_APP_QUEUE_REAP_MAX_AGE' => '250',
        ]));

        $this->assertNull($optionsEqual['reapMaxAge']);
        $this->assertNull($optionsBelow['reapMaxAge']);
    }

    public function testResolveReapOptionsWithRealSystemGetEnvCallable(): void
    {
        $options = Jobs::resolveReapOptions(\Utopia\System\System::getEnv(...));

        $this->assertIsInt($options['reapAfter']);
        $this->assertSame(300, $options['reapAfter']);
        $this->assertNull($options['reapMaxAttempts']);
        $this->assertNull($options['reapMaxAge']);
    }

    /**
     * @param array<string, string> $values
     * @return callable(string, ?string=): ?string
     */
    private function env(array $values): callable
    {
        return static function (string $key, ?string $default = null) use ($values): ?string {
            if (\array_key_exists($key, $values)) {
                return $values[$key];
            }

            return $default;
        };
    }
}
