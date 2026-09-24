<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

final class LoadResultsTelemetryTest extends TestCase
{
    public function testLoadEmitsHitAndMissCounts(): void
    {
        $cache = new Cache(new Memory());
        $telemetry = new TestTelemetry();
        $cache->setTelemetry($telemetry);

        $cache->load('missing', 60);
        $cache->save('present', 'value');
        $cache->load('present', 60);
        $cache->load('present', 60);

        $this->assertArrayHasKey('cache.load.total', $telemetry->counters);
        /** @phpstan-ignore-next-line property.notFound */
        $this->assertCount(3, $telemetry->counters['cache.load.total']->values);
    }

    public function testHitMissAttributesAreRecorded(): void
    {
        $captured = [];

        $cache = new Cache(new Memory());
        $telemetry = new class ($captured) extends TestTelemetry {
            /**
             * @param  array<int, array<string, mixed>>  $captured
             */
            public function __construct(public array &$captured) {}

            public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): \Utopia\Telemetry\Counter
            {
                if ($name !== 'cache.load.total') {
                    return parent::createCounter($name, $unit, $description, $advisory);
                }
                $captured = &$this->captured;

                return $this->counters[$name] = new class ($captured) extends \Utopia\Telemetry\Counter {
                    /**
                     * @param  array<int, array<string, mixed>>  $captured
                     */
                    public function __construct(public array &$captured) {}

                    public function add(float|int $amount, iterable $attributes = []): void
                    {
                        $this->captured[] = \is_array($attributes) ? $attributes : iterator_to_array($attributes);
                    }
                };
            }
        };
        $cache->setTelemetry($telemetry);

        $cache->load('absent', 60);
        $cache->save('here', 'value');
        $cache->load('here', 60);

        $results = array_column($captured, 'result');
        $this->assertSame(['miss', 'hit'], $results);
    }

    public function testNullReturnIsTreatedAsHit(): void
    {
        $captured = [];

        $adapter = new class extends Memory {
            public function load(string $key, int $ttl, string $hash = ''): mixed
            {
                return null;
            }
        };

        $cache = new Cache($adapter);
        $telemetry = new class ($captured) extends TestTelemetry {
            /**
             * @param  array<int, array<string, mixed>>  $captured
             */
            public function __construct(public array &$captured) {}

            public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): \Utopia\Telemetry\Counter
            {
                if ($name !== 'cache.load.total') {
                    return parent::createCounter($name, $unit, $description, $advisory);
                }
                $captured = &$this->captured;

                return $this->counters[$name] = new class ($captured) extends \Utopia\Telemetry\Counter {
                    /**
                     * @param  array<int, array<string, mixed>>  $captured
                     */
                    public function __construct(public array &$captured) {}

                    public function add(float|int $amount, iterable $attributes = []): void
                    {
                        $this->captured[] = \is_array($attributes) ? $attributes : iterator_to_array($attributes);
                    }
                };
            }
        };
        $cache->setTelemetry($telemetry);

        $cache->load('any', 60);

        $this->assertSame('hit', $captured[0]['result']);
    }
}
