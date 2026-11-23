<?php

declare(strict_types=1);

namespace Utopia\CircuitBreaker\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\CircuitBreaker\Adapter;
use Utopia\CircuitBreaker\CircuitBreaker;
use Utopia\CircuitBreaker\CircuitState;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

/**
 * A dependency that answers slowly is not broken. Slow calls never count
 * toward the failure ratio; the slow ratio, with its own minimum, judges every
 * call that was slow or failed, and a window with no success opens on the
 * failure minimum. A slow half-open probe neither reopens nor
 * closes the circuit until the slow minimum of them in one half-open period
 * reopens it.
 */
final class SlowCallTest extends TestCase
{
    private function breaker(
        float $failureRatio = 0.5,
        float $slowRatio = 0.9,
        int $minimumThroughput = 20,
        ?int $slowMinimumThroughput = null,
        int $window = 60,
        int $timeout = 30,
        int $successThreshold = 2,
        ?TestTelemetry $telemetry = null,
        ?Adapter $cache = null,
    ): CircuitBreaker {
        return new CircuitBreaker(
            timeout: $timeout,
            successThreshold: $successThreshold,
            cache: $cache,
            telemetry: $telemetry,
            window: $window,
            failureRatio: $failureRatio,
            minimumThroughput: $minimumThroughput,
            slow: [\RuntimeException::class],
            slowRatio: $slowRatio,
            slowMinimumThroughput: $slowMinimumThroughput,
        );
    }

    private function slowOnce(CircuitBreaker $breaker): mixed
    {
        return $breaker->call(
            open: static fn (): string => 'fallback',
            close: static function (): never {
                throw new \RuntimeException('read deadline expired');
            },
        );
    }

    private function failOnce(CircuitBreaker $breaker): mixed
    {
        return $breaker->call(
            open: static fn (): string => 'fallback',
            close: static function (): never {
                throw new \LogicException('failed');
            },
        );
    }

    private function succeedOnce(CircuitBreaker $breaker): mixed
    {
        return $breaker->call(
            open: static fn (): string => 'fallback',
            close: static fn (): string => 'ok',
        );
    }

    private function repeat(int $times, callable $call): void
    {
        for ($i = 0; $i < $times; $i++) {
            $call();
        }
    }

    private function awaitSlotStart(int $window): void
    {
        $start = time();
        while (time() === $start || time() % $window !== 0) {
            usleep(10_000);
        }
    }

    private function halfOpen(CircuitBreaker $breaker): void
    {
        $breaker->trip();
        sleep(3);
        $this->assertSame(CircuitState::HALF_OPEN, $breaker->getState());
    }

    public function testMostlySlowTrafficBelowTheSlowRatioStaysClosed(): void
    {
        $breaker = $this->breaker();

        $this->repeat(5, fn (): mixed => $this->succeedOnce($breaker));
        $this->repeat(20, fn (): mixed => $this->slowOnce($breaker));

        $this->assertSame(CircuitState::CLOSED, $breaker->getState());
        $this->assertSame(0, $breaker->getFailureCount());
        $this->assertSame(20, $breaker->getSlowCount());
    }

    public function testAllSlowTrafficOpensOnceMinimumThroughputIsReached(): void
    {
        $breaker = $this->breaker();

        $this->repeat(19, fn (): mixed => $this->slowOnce($breaker));
        $this->assertSame(CircuitState::CLOSED, $breaker->getState());

        $this->slowOnce($breaker);
        $this->assertSame(CircuitState::OPEN, $breaker->getState());
    }

    public function testSlowCallsDoNotCountTowardTheFailureRatio(): void
    {
        $breaker = $this->breaker(failureRatio: 0.5, slowRatio: 1.0);

        $this->succeedOnce($breaker);
        $this->repeat(19, fn (): mixed => $this->slowOnce($breaker));

        $this->assertSame(CircuitState::CLOSED, $breaker->getState());
        $this->assertSame(0, $breaker->getFailureCount());
        $this->assertSame(19, $breaker->getSlowCount());
    }

    public function testMostlySlowOrFailedTrafficOpensWhenItsLastCallFails(): void
    {
        $breaker = $this->breaker(failureRatio: 0.5, slowRatio: 0.9, minimumThroughput: 3, slowMinimumThroughput: 20);

        $this->succeedOnce($breaker);
        $this->repeat(13, fn (): mixed => $this->slowOnce($breaker));
        $this->repeat(5, fn (): mixed => $this->failOnce($breaker));
        $this->assertSame(CircuitState::CLOSED, $breaker->getState());

        $this->failOnce($breaker);
        $this->assertSame(CircuitState::OPEN, $breaker->getState());
        $this->assertSame(6, $breaker->getFailureCount());
        $this->assertSame(13, $breaker->getSlowCount());
    }

    public function testMostlySlowOrFailedTrafficOpensWhenItsLastCallIsSlow(): void
    {
        $breaker = $this->breaker(failureRatio: 0.5, slowRatio: 0.9, minimumThroughput: 3, slowMinimumThroughput: 20);

        $this->succeedOnce($breaker);
        $this->repeat(6, function () use ($breaker): void {
            $this->slowOnce($breaker);
            $this->slowOnce($breaker);
            $this->failOnce($breaker);
        });
        $this->assertSame(CircuitState::CLOSED, $breaker->getState());

        $this->slowOnce($breaker);
        $this->assertSame(CircuitState::OPEN, $breaker->getState());
        $this->assertSame(6, $breaker->getFailureCount());
        $this->assertSame(13, $breaker->getSlowCount());
    }

    public function testADependencyThatNeverSucceedsOpensOnTheFailureMinimumWhenAllSlow(): void
    {
        $breaker = $this->breaker(failureRatio: 0.5, slowRatio: 0.9, minimumThroughput: 3, slowMinimumThroughput: 20);

        $this->repeat(2, fn (): mixed => $this->slowOnce($breaker));
        $this->assertSame(CircuitState::CLOSED, $breaker->getState());

        $this->slowOnce($breaker);
        $this->assertSame(CircuitState::OPEN, $breaker->getState());
        $this->assertSame(3, $breaker->getSlowCount());
    }

    public function testADependencyThatNeverSucceedsOpensOnTheFailureMinimumWhenItsLastCallFails(): void
    {
        $breaker = $this->breaker(failureRatio: 0.5, slowRatio: 0.9, minimumThroughput: 3, slowMinimumThroughput: 20);

        $this->repeat(2, fn (): mixed => $this->slowOnce($breaker));
        $this->assertSame(CircuitState::CLOSED, $breaker->getState());

        $this->failOnce($breaker);
        $this->assertSame(CircuitState::OPEN, $breaker->getState());
        $this->assertSame(1, $breaker->getFailureCount());
        $this->assertSame(2, $breaker->getSlowCount());
    }

    public function testOneSuccessAmongSlowCallsKeepsASparseWindowClosed(): void
    {
        $breaker = $this->breaker(failureRatio: 0.5, slowRatio: 0.9, minimumThroughput: 3, slowMinimumThroughput: 20);

        $this->repeat(2, fn (): mixed => $this->slowOnce($breaker));
        $this->succeedOnce($breaker);

        $this->assertSame(CircuitState::CLOSED, $breaker->getState());
        $this->assertSame(2, $breaker->getSlowCount());
    }

    public function testOneSuccessKeepsASparseWindowBelowBothRules(): void
    {
        $breaker = $this->breaker(failureRatio: 0.5, slowRatio: 0.9, minimumThroughput: 3, slowMinimumThroughput: 20);

        $this->succeedOnce($breaker);
        $this->repeat(15, fn (): mixed => $this->slowOnce($breaker));
        $this->repeat(3, fn (): mixed => $this->failOnce($breaker));

        $this->assertSame(CircuitState::CLOSED, $breaker->getState());
        $this->assertSame(3, $breaker->getFailureCount());
        $this->assertSame(15, $breaker->getSlowCount());
    }

    public function testPartlySlowTrafficBelowTheSlowRatioStaysClosedWithFailures(): void
    {
        $breaker = $this->breaker(failureRatio: 0.5, slowRatio: 0.9, minimumThroughput: 3, slowMinimumThroughput: 20);

        $this->repeat(4, fn (): mixed => $this->succeedOnce($breaker));
        $this->repeat(14, fn (): mixed => $this->slowOnce($breaker));
        $this->repeat(2, fn (): mixed => $this->failOnce($breaker));

        $this->assertSame(CircuitState::CLOSED, $breaker->getState());
        $this->assertSame(2, $breaker->getFailureCount());
        $this->assertSame(14, $breaker->getSlowCount());
    }

    public function testSlowOptionsWithoutSlowExceptionsLeaveTheFailureRuleAlone(): void
    {
        $breaker = new CircuitBreaker(
            window: 60,
            failureRatio: 0.5,
            minimumThroughput: 3,
            slowRatio: 0.3,
            slowMinimumThroughput: 3,
        );

        $this->succeedOnce($breaker);
        $this->succeedOnce($breaker);
        $this->failOnce($breaker);

        $this->assertSame(CircuitState::CLOSED, $breaker->getState());
        $this->assertSame(1, $breaker->getFailureCount());
    }

    public function testTheFailureRuleOpensOnFailuresOverAllCallsIncludingSlowOnes(): void
    {
        $breaker = $this->breaker(failureRatio: 0.6, slowRatio: 1.0, minimumThroughput: 5);

        $this->slowOnce($breaker);
        $this->slowOnce($breaker);
        $this->failOnce($breaker);
        $this->failOnce($breaker);
        $this->assertSame(CircuitState::CLOSED, $breaker->getState());

        $this->failOnce($breaker);
        $this->assertSame(CircuitState::OPEN, $breaker->getState());
    }

    public function testTheFailureRuleIgnoresTheSlowCount(): void
    {
        $breaker = $this->breaker(failureRatio: 0.6, slowRatio: 1.0, minimumThroughput: 5);

        $this->succeedOnce($breaker);
        $this->repeat(3, fn (): mixed => $this->slowOnce($breaker));
        $this->failOnce($breaker);

        $this->assertSame(CircuitState::CLOSED, $breaker->getState());
        $this->assertSame(1, $breaker->getFailureCount());
        $this->assertSame(3, $breaker->getSlowCount());
    }

    public function testSubclassesOfASlowExceptionAreSlow(): void
    {
        $breaker = $this->breaker();

        $result = $breaker->call(
            open: static fn (): string => 'fallback',
            close: static function (): never {
                throw new \UnexpectedValueException('read deadline expired');
            },
        );

        $this->assertSame('fallback', $result);
        $this->assertSame(1, $breaker->getSlowCount());
        $this->assertSame(0, $breaker->getFailureCount());
    }

    public function testOtherExceptionsStillOpenThroughTheFailureRule(): void
    {
        $breaker = $this->breaker(minimumThroughput: 1);

        $this->failOnce($breaker);

        $this->assertSame(CircuitState::OPEN, $breaker->getState());
        $this->assertSame(1, $breaker->getFailureCount());
        $this->assertSame(0, $breaker->getSlowCount());
    }

    public function testFailuresOpenOnTheirOwnMinimumBelowTheSlowMinimum(): void
    {
        $breaker = $this->breaker(minimumThroughput: 3, slowMinimumThroughput: 20);

        $this->repeat(3, fn (): mixed => $this->failOnce($breaker));

        $this->assertSame(CircuitState::OPEN, $breaker->getState());
        $this->assertSame(3, $breaker->getFailureCount());
    }

    public function testSlowCallsWaitForTheSlowMinimum(): void
    {
        $breaker = $this->breaker(minimumThroughput: 3, slowMinimumThroughput: 20);

        $this->succeedOnce($breaker);
        $this->repeat(18, fn (): mixed => $this->slowOnce($breaker));
        $this->assertSame(CircuitState::CLOSED, $breaker->getState());
        $this->assertSame(18, $breaker->getSlowCount());

        $this->slowOnce($breaker);
        $this->assertSame(CircuitState::OPEN, $breaker->getState());
        $this->assertSame(19, $breaker->getSlowCount());
    }

    public function testASlowMinimumBelowTheFailureMinimumOpensOnSlowCallsSooner(): void
    {
        $breaker = $this->breaker(minimumThroughput: 20, slowMinimumThroughput: 3);

        $this->repeat(2, fn (): mixed => $this->slowOnce($breaker));
        $this->assertSame(CircuitState::CLOSED, $breaker->getState());

        $this->slowOnce($breaker);
        $this->assertSame(CircuitState::OPEN, $breaker->getState());
    }

    public function testASlowProbeLeavesTheCircuitHalfOpenAndReleasesItsSlot(): void
    {
        $breaker = $this->breaker(timeout: 2, successThreshold: 2);
        $this->halfOpen($breaker);

        $this->assertSame('fallback', $this->slowOnce($breaker));
        $this->assertSame(CircuitState::HALF_OPEN, $breaker->getState());
        $this->assertSame(0, $breaker->getSuccessCount());
        $this->assertSame(0, $breaker->getFailureCount());

        $this->assertSame('ok', $this->succeedOnce($breaker));
        $this->assertSame(CircuitState::HALF_OPEN, $breaker->getState());
        $this->assertSame(1, $breaker->getSuccessCount());
    }

    public function testTwoSuccessesCloseTheCircuitAfterSlowProbes(): void
    {
        $breaker = $this->breaker(timeout: 2, successThreshold: 2);
        $this->halfOpen($breaker);

        $this->repeat(3, fn (): mixed => $this->slowOnce($breaker));
        $this->assertSame(CircuitState::HALF_OPEN, $breaker->getState());

        $this->succeedOnce($breaker);
        $this->assertSame(CircuitState::HALF_OPEN, $breaker->getState());

        $this->succeedOnce($breaker);
        $this->assertSame(CircuitState::CLOSED, $breaker->getState());
    }

    public function testAFailingProbeStillReopensAfterSlowProbes(): void
    {
        $breaker = $this->breaker(timeout: 2, successThreshold: 2);
        $this->halfOpen($breaker);

        $this->slowOnce($breaker);
        $this->assertSame('fallback', $this->failOnce($breaker));

        $this->assertSame(CircuitState::OPEN, $breaker->getState());
    }

    public function testTheSlowMinimumOfSlowProbesReopensTheCircuit(): void
    {
        $breaker = $this->breaker(slowMinimumThroughput: 3, timeout: 2);
        $this->halfOpen($breaker);

        $this->repeat(2, fn (): mixed => $this->slowOnce($breaker));
        $this->assertSame(CircuitState::HALF_OPEN, $breaker->getState());

        $this->assertSame('fallback', $this->slowOnce($breaker));
        $this->assertSame(CircuitState::OPEN, $breaker->getState());
        $this->assertSame('fallback', $this->succeedOnce($breaker));
    }

    public function testTheSlowProbeCountResetsEachHalfOpenPeriod(): void
    {
        $breaker = $this->breaker(slowMinimumThroughput: 3, timeout: 2);
        $this->halfOpen($breaker);

        $this->repeat(2, fn (): mixed => $this->slowOnce($breaker));
        $this->failOnce($breaker);
        $this->assertSame(CircuitState::OPEN, $breaker->getState());

        sleep(3);
        $this->assertSame(CircuitState::HALF_OPEN, $breaker->getState());

        $this->repeat(2, fn (): mixed => $this->slowOnce($breaker));
        $this->assertSame(CircuitState::HALF_OPEN, $breaker->getState());
    }

    public function testTheSlowProbeCountResetsWhenAnotherProcessStartsTheNextPeriod(): void
    {
        $cache = $this->createArrayAdapter();
        $prober = $this->breaker(slowMinimumThroughput: 3, timeout: 2, cache: $cache);
        $other = $this->breaker(slowMinimumThroughput: 3, timeout: 2, cache: $cache);
        $this->halfOpen($prober);

        $this->repeat(2, fn (): mixed => $this->slowOnce($prober));
        $this->failOnce($other);
        $this->assertSame(CircuitState::OPEN, $other->getState());

        sleep(3);
        $this->assertSame(CircuitState::HALF_OPEN, $other->getState());

        $this->repeat(2, fn (): mixed => $this->slowOnce($prober));
        $this->assertSame(CircuitState::HALF_OPEN, $prober->getState());
    }

    public function testASlowTailBelowTheSlowMinimumStillCloses(): void
    {
        $breaker = $this->breaker(slowMinimumThroughput: 3, timeout: 2, successThreshold: 2);
        $this->halfOpen($breaker);

        $this->slowOnce($breaker);
        $this->succeedOnce($breaker);
        $this->slowOnce($breaker);
        $this->assertSame(CircuitState::HALF_OPEN, $breaker->getState());

        $this->succeedOnce($breaker);
        $this->assertSame(CircuitState::CLOSED, $breaker->getState());
    }

    public function testSlowCallsFromThePreviousSlotStillCount(): void
    {
        $breaker = $this->breaker(window: 2);

        $this->awaitSlotStart(2);
        $this->repeat(18, fn (): mixed => $this->slowOnce($breaker));
        sleep(2);
        $this->repeat(2, fn (): mixed => $this->slowOnce($breaker));

        $this->assertSame(CircuitState::OPEN, $breaker->getState());
        $this->assertSame(20, $breaker->getSlowCount());
    }

    public function testSlowCallsOlderThanTheWindowAreForgotten(): void
    {
        $breaker = $this->breaker(window: 1);

        $this->repeat(19, fn (): mixed => $this->slowOnce($breaker));
        sleep(3);
        $this->repeat(2, fn (): mixed => $this->slowOnce($breaker));

        $this->assertSame(CircuitState::CLOSED, $breaker->getState());
        $this->assertSame(2, $breaker->getSlowCount());
    }

    public function testASlowCallIsAFallbackAndNotACallbackFailure(): void
    {
        $telemetry = new TestTelemetry();
        $breaker = $this->breaker(telemetry: $telemetry);

        $this->slowOnce($breaker);

        $this->assertSame([1], $telemetry->counters['breaker.calls']->values);
        $this->assertSame([1], $telemetry->counters['breaker.fallbacks']->values);
        $this->assertArrayNotHasKey('breaker.callback_failures', $telemetry->counters);
        $this->assertSame([1], $this->observeGauge($telemetry, 'breaker.slow'));
        $this->assertSame([0], $this->observeGauge($telemetry, 'breaker.failures'));
    }

    public function testAThrowingFallbackOnTheSlowPathStillRecordsTheSlowCall(): void
    {
        $breaker = $this->breaker();

        try {
            $breaker->call(
                open: static function (): never {
                    throw new \DomainException('no fallback');
                },
                close: static function (): never {
                    throw new \RuntimeException('read deadline expired');
                },
            );
            $this->fail('The fallback exception must propagate');
        } catch (\DomainException $exception) {
            $this->assertSame('no fallback', $exception->getMessage());
        }

        $this->assertSame(CircuitState::CLOSED, $breaker->getState());
        $this->assertSame(1, $breaker->getSlowCount());
        $this->assertSame(0, $breaker->getFailureCount());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSlowClasses(): iterable
    {
        yield 'not throwable' => ['stdClass'];
        yield 'missing' => ['Utopia\CircuitBreaker\Tests\MissingException'];
    }

    #[DataProvider('invalidSlowClasses')]
    public function testSlowEntriesMustBeThrowableClassNames(string $class): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Slow exceptions must be Throwable class names.');

        (new \ReflectionClass(CircuitBreaker::class))->newInstanceArgs(['slow' => [$class]]);
    }

    /** @return iterable<string, array{float}> */
    public static function invalidSlowRatios(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-0.1];
        yield 'above one' => [1.01];
    }

    #[DataProvider('invalidSlowRatios')]
    public function testSlowRatioMustBeAProportion(float $ratio): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Slow ratio must be greater than 0 and at most 1.');

        new CircuitBreaker(slowRatio: $ratio);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidSlowMinimums(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('invalidSlowMinimums')]
    public function testSlowMinimumThroughputMustBeAtLeastOne(int $minimum): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Slow minimum throughput must be at least 1.');

        new CircuitBreaker(slowMinimumThroughput: $minimum);
    }

    public function testASlowRatioOfOneIsAccepted(): void
    {
        $breaker = new CircuitBreaker(slow: [\RuntimeException::class], slowRatio: 1.0);

        $this->assertSame(CircuitState::CLOSED, $breaker->getState());
    }

    private function createArrayAdapter(): Adapter
    {
        return new class () implements Adapter {
            /** @var array<string, int|string> */
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
                $next = ((int) ($this->values[$key] ?? 0)) + $by;
                $this->values[$key] = $next;

                return $next;
            }

            public function delete(string $key): void
            {
                unset($this->values[$key]);
            }
        };
    }

    /**
     * @return list<float|int> the value each breaker reported to the gauge
     */
    private function observeGauge(TestTelemetry $telemetry, string $name): array
    {
        $this->assertArrayHasKey($name, $telemetry->observableGauges);

        /** @var list<\Closure(\Closure(float|int, iterable<string, mixed>): void): void> $callbacks */
        $callbacks = $telemetry->observableGauges[$name]->callbacks;
        $values = [];
        foreach ($callbacks as $callback) {
            $callback(static function (float|int $value) use (&$values): void {
                $values[] = $value;
            });
        }

        return $values;
    }
}
