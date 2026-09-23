<?php

declare(strict_types=1);

namespace Utopia\Tests\unit;

use PHPUnit\Framework\TestCase;
use Utopia\CircuitBreaker\Adapter;
use Utopia\CircuitBreaker\CircuitBreaker;
use Utopia\CircuitBreaker\CircuitState;

/**
 * Judging health by failure rate instead of by a consecutive count.
 *
 * The distinction these tests draw is between a dependency that is unhealthy and
 * a dependency that merely failed several calls at once. A count cannot tell them
 * apart, because how many calls a single fault takes down is a property of how
 * many were in flight — one broken multiplexed connection fails everything queued
 * on it, which on a busy worker is dozens of calls from one fault.
 */
final class FailureRateWindowTest extends TestCase
{
    private function failOnce(CircuitBreaker $breaker): mixed
    {
        return $breaker->call(
            open: static fn(): string => 'fallback',
            close: static function (): never {
                throw new \RuntimeException('failed');
            },
        );
    }

    private function succeedOnce(CircuitBreaker $breaker): mixed
    {
        return $breaker->call(
            open: static fn(): string => 'fallback',
            close: static fn(): string => 'ok',
        );
    }

    /**
     * The behaviour this rule exists for. Counting consecutive failures would open
     * on the third one however healthy the dependency is overall; a rate sees five
     * failures in a thousand calls for what they are.
     */
    public function testABurstAmongHealthyTrafficDoesNotOpenTheCircuit(): void
    {
        $breaker = new CircuitBreaker(
            timeout: 30,
            window: 60,
            failureRatio: 0.5,
            minimumThroughput: 20,
        );

        for ($i = 0; $i < 1000; $i++) {
            $this->succeedOnce($breaker);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->failOnce($breaker);
        }

        $this->assertSame(CircuitState::CLOSED, $breaker->getState());
    }

    public function testSustainedFailureStillOpensTheCircuit(): void
    {
        $breaker = new CircuitBreaker(
            timeout: 30,
            window: 60,
            failureRatio: 0.5,
            minimumThroughput: 20,
        );

        for ($i = 0; $i < 20; $i++) {
            $this->failOnce($breaker);
        }

        $this->assertSame(CircuitState::OPEN, $breaker->getState());
    }

    public function testTheRatioIsNotTrustedBelowMinimumThroughput(): void
    {
        $breaker = new CircuitBreaker(
            timeout: 30,
            window: 60,
            failureRatio: 0.5,
            minimumThroughput: 20,
        );

        // Every call so far has failed, but nineteen calls is not enough evidence.
        for ($i = 0; $i < 19; $i++) {
            $this->failOnce($breaker);
        }
        $this->assertSame(CircuitState::CLOSED, $breaker->getState());

        $this->failOnce($breaker);
        $this->assertSame(CircuitState::OPEN, $breaker->getState());
    }

    public function testFailuresOlderThanTheWindowAreForgotten(): void
    {
        $breaker = new CircuitBreaker(
            timeout: 30,
            window: 1,
            failureRatio: 0.5,
            minimumThroughput: 4,
        );

        for ($i = 0; $i < 3; $i++) {
            $this->failOnce($breaker);
        }
        $this->assertSame(CircuitState::CLOSED, $breaker->getState());

        // Two windows on, both buckets predate the window.
        sleep(3);

        for ($i = 0; $i < 3; $i++) {
            $this->failOnce($breaker);
        }

        // Six lifetime failures, but never more than three inside the window, so
        // minimum throughput is never reached. A counter that did not forget
        // would have opened on the fourth.
        $this->assertSame(CircuitState::CLOSED, $breaker->getState());
    }

    public function testSuccessesDoNotWipeTheWindow(): void
    {
        $breaker = new CircuitBreaker(
            timeout: 30,
            window: 60,
            failureRatio: 0.5,
            minimumThroughput: 10,
        );

        // Alternating outcomes: a consecutive counter never reaches two, while the
        // real failure rate is 50%.
        for ($i = 0; $i < 10; $i++) {
            $this->failOnce($breaker);
            $this->succeedOnce($breaker);
        }

        $this->assertSame(CircuitState::OPEN, $breaker->getState());
    }

    /**
     * The window is measured per process; the verdict it reaches is shared.
     */
    public function testTheVerdictIsSharedThroughTheCacheAdapterButTheWindowIsNot(): void
    {
        $cache = $this->createArrayAdapter();
        $arguments = [
            'timeout' => 30,
            'cache' => $cache,
            'key' => 'users-api',
            'window' => 60,
            'failureRatio' => 0.5,
            'minimumThroughput' => 10,
        ];

        $first = new CircuitBreaker(...$arguments);
        for ($i = 0; $i < 5; $i++) {
            $this->failOnce($first);
        }

        // A second instance starts with its own empty window rather than
        // inheriting five failures it never saw.
        $second = new CircuitBreaker(...$arguments);
        $this->assertSame(0, $second->getFailureCount());
        $this->assertSame(CircuitState::CLOSED, $second->getState());

        // Once either of them reaches the threshold, both see an open circuit.
        for ($i = 0; $i < 5; $i++) {
            $this->failOnce($first);
        }

        $this->assertTrue($first->isOpen());
        $this->assertTrue($second->isOpen());
    }

    public function testReopeningDoesNotReuseTheTallyThatOpenedIt(): void
    {
        $breaker = new CircuitBreaker(
            timeout: 1,
            successThreshold: 1,
            window: 60,
            failureRatio: 0.5,
            minimumThroughput: 10,
        );

        for ($i = 0; $i < 10; $i++) {
            $this->failOnce($breaker);
        }
        $this->assertSame(CircuitState::OPEN, $breaker->getState());

        // Once the open timeout has passed the next call probes, and a single
        // success closes the circuit.
        sleep(2);
        $this->succeedOnce($breaker);
        $this->assertSame(CircuitState::CLOSED, $breaker->getState());

        // A single failure now must not re-open on the ten that are already spent.
        $this->failOnce($breaker);
        $this->assertSame(CircuitState::CLOSED, $breaker->getState());
    }

    public function testWindowMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Window must be greater than 0 seconds.');

        new CircuitBreaker(window: 0);
    }

    public function testFailureRatioMustBeAProportion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Failure ratio must be greater than 0 and at most 1.');

        new CircuitBreaker(window: 60, failureRatio: 1.5);
    }

    public function testMinimumThroughputMustBeAtLeastOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Minimum throughput must be at least 1.');

        new CircuitBreaker(window: 60, minimumThroughput: 0);
    }

    private function createArrayAdapter(): Adapter
    {
        return new class implements Adapter {
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
}
