<?php

declare(strict_types=1);

namespace Utopia\Tests\unit;

use PHPUnit\Framework\TestCase;
use Utopia\CircuitBreaker\Adapter;
use Utopia\CircuitBreaker\CircuitBreaker;
use Utopia\CircuitBreaker\CircuitState;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

final class CircuitBreakerTest extends TestCase
{
    public function testUsesInMemoryStateByDefault(): void
    {
        $breaker = new CircuitBreaker(timeout: 30, successThreshold: 1, minimumThroughput: 2);

        $first = $breaker->call(
            open: static fn(): string => 'fallback',
            close: static function (): never {
                throw new \RuntimeException('failed');
            },
        );
        $second = $breaker->call(
            open: static fn(): string => 'fallback',
            close: static function (): never {
                throw new \RuntimeException('failed');
            },
        );

        $this->assertSame('fallback', $first);
        $this->assertSame('fallback', $second);
        $this->assertSame(CircuitState::OPEN, $breaker->getState());
        $this->assertSame(2, $breaker->getFailureCount());
    }

    public function testCachedStateIsSharedAcrossBreakerInstances(): void
    {
        $cache = $this->createArrayAdapter();
        $first = new CircuitBreaker(timeout: 30, successThreshold: 1, cache: $cache, key: 'users-api', minimumThroughput: 2);
        $second = new CircuitBreaker(timeout: 30, successThreshold: 1, cache: $cache, key: 'users-api', minimumThroughput: 2);

        $first->call(
            open: static fn(): string => 'fallback',
            close: static function (): never {
                throw new \RuntimeException('failed');
            },
        );
        $first->call(
            open: static fn(): string => 'fallback',
            close: static function (): never {
                throw new \RuntimeException('failed');
            },
        );

        $this->assertTrue($second->isOpen());

        // The verdict is shared; the tally behind it is measured per process, so
        // the second instance did not inherit failures it never saw.
        $this->assertSame(0, $second->getFailureCount());

        $result = $second->call(
            open: static fn(): string => 'shared fallback',
            close: function (): never {
                self::fail('Closed callback should not run while the shared circuit is open.');
            },
        );

        $this->assertSame('shared fallback', $result);
    }

    public function testClosedSuccessDoesNotWriteZeroFailuresWhenAlreadyZero(): void
    {
        $cache = new class implements Adapter {
            /**
             * @var list<array{string, string, int|string|null}>
             */
            public array $writes = [];

            public function get(string $key): int|string|null
            {
                return null;
            }

            public function set(string $key, int|string $value): void
            {
                $this->writes[] = ['set', $key, $value];
            }

            public function increment(string $key, int $by = 1): int
            {
                $this->writes[] = ['increment', $key, $by];

                return $by;
            }

            public function delete(string $key): void
            {
                $this->writes[] = ['delete', $key, null];
            }
        };
        $breaker = new CircuitBreaker(timeout: 30, successThreshold: 1, cache: $cache, key: 'users-api', minimumThroughput: 1);

        $this->assertSame('ok', $breaker->call(
            open: static fn(): string => 'fallback',
            close: static fn(): string => 'ok',
        ));

        $this->assertSame([], $cache->writes);
    }

    public function testCachedTransitionsWriteStateLast(): void
    {
        $cache = new class implements Adapter {
            /**
             * @var array<string, int|string>
             */
            private array $values = [];

            /**
             * @var list<array{string, string, int|string|null}>
             */
            public array $writes = [];

            public function get(string $key): int|string|null
            {
                return $this->values[$key] ?? null;
            }

            public function set(string $key, int|string $value): void
            {
                $this->writes[] = ['set', $key, $value];
                $this->values[$key] = $value;
            }

            public function increment(string $key, int $by = 1): int
            {
                $value = (int) ($this->values[$key] ?? 0);
                $value += $by;
                $this->writes[] = ['increment', $key, $by];
                $this->values[$key] = $value;

                return $value;
            }

            public function delete(string $key): void
            {
                $this->writes[] = ['delete', $key, null];
                unset($this->values[$key]);
            }
        };
        $breaker = new CircuitBreaker(timeout: 30, successThreshold: 1, cache: $cache, key: 'users-api', minimumThroughput: 1);

        $breaker->call(
            open: static fn(): string => 'fallback',
            close: static function (): never {
                throw new \RuntimeException('failed');
            },
        );

        $setWrites = array_values(array_filter(
            $cache->writes,
            static fn(array $write): bool => $write[0] === 'set',
        ));

        $this->assertSame(['set', 'users-api:state', CircuitState::OPEN->value], $setWrites[array_key_last($setWrites)]);
    }

    public function testHalfOpenSuccessesCloseTheCircuit(): void
    {
        $breaker = new CircuitBreaker(timeout: 0, successThreshold: 2, minimumThroughput: 1);

        $breaker->call(
            open: static fn(): string => 'fallback',
            close: static function (): never {
                throw new \RuntimeException('failed');
            },
        );

        $this->assertSame('probe-1', $breaker->call(
            open: static fn(): string => 'fallback',
            close: static fn(): string => 'closed',
            halfOpen: static fn(): string => 'probe-1',
        ));
        $this->assertTrue($breaker->isHalfOpen());
        $this->assertSame(1, $breaker->getSuccessCount());

        $this->assertSame('probe-2', $breaker->call(
            open: static fn(): string => 'fallback',
            close: static fn(): string => 'closed',
            halfOpen: static fn(): string => 'probe-2',
        ));

        $this->assertTrue($breaker->isClosed());
        $this->assertSame(0, $breaker->getFailureCount());
        $this->assertSame(0, $breaker->getSuccessCount());
    }

    public function testRecordsTelemetryForCallsFallbacksAndTransitions(): void
    {
        $telemetry = new TestTelemetry();
        $breaker = new CircuitBreaker(timeout: 30, successThreshold: 1, telemetry: $telemetry, minimumThroughput: 1);

        $result = $breaker->call(
            open: static fn(): string => 'fallback',
            close: static function (): never {
                throw new \RuntimeException('failed');
            },
        );

        $this->assertSame('fallback', $result);
        $this->assertSame([1], $telemetry->counters['breaker.calls']->values);
        $this->assertSame([1], $telemetry->counters['breaker.callback_failures']->values);
        $this->assertSame([1], $telemetry->counters['breaker.fallbacks']->values);
        $this->assertSame([1], $telemetry->counters['breaker.transitions']->values);
        $this->assertSame([
            'breaker.active_calls' => 0,
            'breaker.state' => 1,
            'breaker.failures' => 1,
            'breaker.successes' => 0,
        ], $this->observe($telemetry));
        $this->assertCount(1, $telemetry->gauges['breaker.event.timestamp']->values);
    }

    public function testPrefixesTelemetryMetricNames(): void
    {
        $telemetry = new TestTelemetry();
        $breaker = new CircuitBreaker(timeout: 30, successThreshold: 1, metricPrefix: '.edge.', minimumThroughput: 1);
        $breaker->setTelemetry($telemetry);

        $result = $breaker->call(
            open: static fn(): string => 'fallback',
            close: static function (): never {
                throw new \RuntimeException('failed');
            },
        );

        $this->assertSame('fallback', $result);
        $this->assertSame([1], $telemetry->counters['edge.breaker.calls']->values);
        $this->assertSame([1], $telemetry->counters['edge.breaker.callback_failures']->values);
        $this->assertSame([1], $telemetry->counters['edge.breaker.fallbacks']->values);
        $this->assertSame([1], $telemetry->counters['edge.breaker.transitions']->values);
        $this->assertSame([
            'edge.breaker.active_calls' => 0,
            'edge.breaker.state' => 1,
            'edge.breaker.failures' => 1,
            'edge.breaker.successes' => 0,
        ], $this->observe($telemetry));
        $this->assertCount(1, $telemetry->gauges['edge.breaker.event.timestamp']->values);
        $this->assertArrayNotHasKey('breaker.calls', $telemetry->counters);
    }

    public function testInspectionMethodsDoNotEmitTelemetry(): void
    {
        $telemetry = new TestTelemetry();
        $breaker = new CircuitBreaker(telemetry: $telemetry);

        $this->assertSame(CircuitState::CLOSED, $breaker->getState());
        $this->assertSame(0, $breaker->getFailureCount());
        $this->assertSame(0, $breaker->getSuccessCount());
        $this->assertArrayNotHasKey('breaker.event.timestamp', $telemetry->gauges);
        $this->assertArrayNotHasKey('breaker.calls', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.callback_failures', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.fallbacks', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.transitions', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.event.timestamp', $telemetry->gauges);
    }

    public function testRareTelemetryInstrumentsAreCreatedOnFirstRecord(): void
    {
        $telemetry = new TestTelemetry();
        $breaker = new CircuitBreaker(telemetry: $telemetry);

        $this->assertArrayNotHasKey('breaker.callback_failures', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.fallbacks', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.transitions', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.event.timestamp', $telemetry->gauges);

        $breaker->trip();

        $this->assertArrayNotHasKey('breaker.callback_failures', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.fallbacks', $telemetry->counters);
        $this->assertSame([1], $telemetry->counters['breaker.transitions']->values);
        $this->assertCount(1, $telemetry->gauges['breaker.event.timestamp']->values);
    }

    public function testSuccessfulCallsDoNotCreateRareTelemetryInstruments(): void
    {
        $telemetry = new TestTelemetry();
        $breaker = new CircuitBreaker(telemetry: $telemetry);

        $this->assertSame('ok', $breaker->call(
            open: static fn(): string => 'fallback',
            close: static fn(): string => 'ok',
        ));

        $this->assertSame([1], $telemetry->counters['breaker.calls']->values);
        $this->assertArrayNotHasKey('breaker.callback_failures', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.fallbacks', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.transitions', $telemetry->counters);
        $this->assertArrayNotHasKey('breaker.event.timestamp', $telemetry->gauges);
    }

    /**
     * A breaker whose open timeout has elapsed moves to half-open on the next call; the state
     * observed while that probe runs must be the post-update one, with the probe in flight.
     */
    public function testStateObservedDuringACallIsThePostUpdateState(): void
    {
        $telemetry = new TestTelemetry();
        $cache = new class implements Adapter {
            /**
             * @var array<string, int|string>
             */
            private array $values = [
                'users-api:state' => 'open',
                'users-api:failures' => 1,
                'users-api:successes' => 0,
            ];

            public function __construct()
            {
                $this->values['users-api:opened_at'] = time() - 10;
            }

            public function get(string $key): int|string|null
            {
                return $this->values[$key] ?? null;
            }

            public function set(string $key, int|string $value): void
            {
                $this->values[$key] = $value;
            }

            public function increment(string $key, int $by = 1): int
            {
                $value = (int) ($this->values[$key] ?? 0);
                $value += $by;
                $this->values[$key] = $value;

                return $value;
            }

            public function delete(string $key): void
            {
                unset($this->values[$key]);
            }
        };
        $breaker = new CircuitBreaker(
            timeout: 0,
            successThreshold: 1,
            cache: $cache,
            key: 'users-api',
            telemetry: $telemetry,
            minimumThroughput: 1,
        );

        $observed = null;
        $result = $breaker->call(
            open: static fn(): string => 'fallback',
            close: static fn(): string => 'closed',
            halfOpen: function () use (&$observed, $telemetry): string {
                $observed = $this->observe($telemetry);

                return 'probe';
            },
        );

        $this->assertSame('probe', $result);
        $this->assertSame(2, $observed['breaker.state']);
        $this->assertSame(1, $observed['breaker.active_calls']);
    }

    public function testRejectsEmptyCacheKeyWhenCacheIsConfigured(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CircuitBreaker(cache: $this->createArrayAdapter(), key: '');
    }

    public function testTripTransitionsToOpen(): void
    {
        $breaker = new CircuitBreaker();

        $this->assertSame(CircuitState::CLOSED, $breaker->getState());

        $breaker->trip();

        $this->assertSame(CircuitState::OPEN, $breaker->getState());
        $this->assertTrue($breaker->isOpen());
    }

    public function testTrippedBreakerShortCircuitsCalls(): void
    {
        $breaker = new CircuitBreaker(timeout: 30, successThreshold: 1, minimumThroughput: 100);
        $breaker->trip();

        $result = $breaker->call(
            open: static fn(): string => 'fallback',
            close: function (): never {
                self::fail('Closed callback should not run when the breaker has been tripped.');
            },
        );

        $this->assertSame('fallback', $result);
        $this->assertTrue($breaker->isOpen());
    }

    public function testTripIsIdempotent(): void
    {
        $breaker = new CircuitBreaker();

        $breaker->trip();
        $breaker->trip();
        $breaker->trip();

        $this->assertSame(CircuitState::OPEN, $breaker->getState());
    }

    public function testTripPersistsStateThroughCacheAdapter(): void
    {
        $cache = $this->createArrayAdapter();
        $first = new CircuitBreaker(cache: $cache, key: 'users-api');
        $first->trip();

        $second = new CircuitBreaker(cache: $cache, key: 'users-api');

        $this->assertTrue($second->isOpen());
    }

    public function testTripEmitsTransitionTelemetry(): void
    {
        $telemetry = new TestTelemetry();
        $breaker = new CircuitBreaker(telemetry: $telemetry);

        $breaker->trip();

        $this->assertSame([1], $telemetry->counters['breaker.transitions']->values);
        $this->assertSame(1, $this->observe($telemetry)['breaker.state']);
    }

    /**
     * A breaker that receives no calls must still report its state on every collection,
     * and a call in flight must be visible while it runs and gone once it returns.
     */
    public function testGaugesAreObservedAtCollectionTime(): void
    {
        $telemetry = new TestTelemetry();
        $breaker = new CircuitBreaker(telemetry: $telemetry, minimumThroughput: 1);

        $this->assertSame([
            'breaker.active_calls' => 0,
            'breaker.state' => 0,
            'breaker.failures' => 0,
            'breaker.successes' => 0,
        ], $this->observe($telemetry));
        $this->assertArrayNotHasKey('breaker.calls', $telemetry->counters);

        $inFlight = null;
        $breaker->call(
            open: static fn(): string => 'fallback',
            close: function () use (&$inFlight, $telemetry): string {
                $inFlight = $this->observe($telemetry)['breaker.active_calls'];

                return 'ok';
            },
        );

        $this->assertSame(1, $inFlight);
        $this->assertSame(0, $this->observe($telemetry)['breaker.active_calls']);
        $this->assertSame([1], $telemetry->counters['breaker.calls']->values);
    }

    /**
     * A discarded breaker must not be kept alive by its observation, and must
     * stop reporting; a breaker bound twice to the same adapter, or moved to
     * another one, must report exactly once, on the adapter it is bound to.
     */
    public function testObservationsFollowTheBreakerLifecycle(): void
    {
        $first = new TestTelemetry();
        $second = new TestTelemetry();
        $kept = new CircuitBreaker(key: 'kept', telemetry: $first);

        $discarded = new CircuitBreaker(key: 'discarded', telemetry: $first);
        $reference = \WeakReference::create($discarded);
        unset($discarded);
        gc_collect_cycles();

        $this->assertNotInstanceOf(\Utopia\CircuitBreaker\CircuitBreaker::class, $reference->get());
        $this->assertSame(['kept' => 0], $this->observeSeries($first)['breaker.state']);
        $this->assertCount(1, $first->observableGauges['breaker.state']->callbacks);

        $kept->setTelemetry($first);
        $this->assertSame(['kept' => 0], $this->observeSeries($first)['breaker.state']);
        $this->assertCount(1, $first->observableGauges['breaker.state']->callbacks);

        $kept->setTelemetry($second);
        $this->assertSame([], $this->observeSeries($first)['breaker.state']);
        $this->assertSame(['kept' => 0], $this->observeSeries($second)['breaker.state']);
    }

    /**
     * The verdict is shared through the cache adapter. A breaker that receives
     * no calls must still report a circuit another process tripped, and must
     * not move an open circuit to half-open merely because it was collected.
     */
    public function testIdleBreakerObservesTheSharedState(): void
    {
        $cache = $this->createArrayAdapter();
        $telemetry = new TestTelemetry();
        $idle = new CircuitBreaker(timeout: 30, cache: $cache, key: 'users-api', telemetry: $telemetry);
        $active = new CircuitBreaker(timeout: 30, cache: $cache, key: 'users-api');

        $this->assertSame(0, $this->observe($telemetry)['breaker.state']);

        $active->trip();

        $this->assertSame(1, $this->observe($telemetry)['breaker.state']);
        $this->assertSame(1, $this->observe($telemetry)['breaker.state']);
        $this->assertTrue($idle->isOpen());
    }

    /**
     * @return array<string, float|int> gauge name => last observed value
     */
    private function observe(TestTelemetry $telemetry): array
    {
        return array_map(static fn(array $series): float|int => end($series), array_filter($this->observeSeries($telemetry)));
    }

    /**
     * @return array<string, array<string, float|int>> gauge name => breaker name => observed value
     */
    private function observeSeries(TestTelemetry $telemetry): array
    {
        $observed = [];
        foreach ($telemetry->observableGauges as $name => $gauge) {
            $observed[$name] = [];
            foreach ($gauge->callbacks as $callback) {
                $callback(function (float|int $value, iterable $attributes = []) use (&$observed, $name): void {
                    $attributes = iterator_to_array($attributes);
                    $observed[$name][$attributes['circuit_breaker.name']] = $value;
                });
            }
        }

        return $observed;
    }

    private function createArrayAdapter(): Adapter
    {
        return new class implements Adapter {
            /**
             * @var array<string, int|string>
             */
            private array $values = [];

            public function get(string $key): int|string|null
            {
                return $this->values[$key] ?? null;
            }

            public function set(string $key, int|string $value): void
            {
                $this->values[$key] = $value;
            }

            public function increment(string $key, int $by = 1): int
            {
                $value = (int) ($this->values[$key] ?? 0);
                $value += $by;
                $this->values[$key] = $value;

                return $value;
            }

            public function delete(string $key): void
            {
                unset($this->values[$key]);
            }
        };
    }
}
