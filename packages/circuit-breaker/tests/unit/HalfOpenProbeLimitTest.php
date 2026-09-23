<?php

declare(strict_types=1);

namespace Utopia\Tests\unit;

use PHPUnit\Framework\TestCase;
use Utopia\CircuitBreaker\CircuitBreaker;
use Utopia\CircuitBreaker\CircuitState;

/**
 * How many callers a half-open circuit lets through at once.
 *
 * Half open asks one question — is the dependency back — and one probe answers
 * it. Letting every waiting caller ask at the same moment sends the dependency
 * the full load it just failed under, at the exact moment it is least able to
 * take it, which is how a recovering dependency is knocked over again.
 *
 * Concurrency is simulated by re-entering call() from inside the probe's own
 * callback: at that point the outer probe is still in flight, so a nested call is
 * exactly the second concurrent caller.
 */
final class HalfOpenProbeLimitTest extends TestCase
{
    /**
     * Opens the circuit and waits out the open timeout, so the next call probes.
     */
    private function halfOpen(int $permitted, int $successThreshold = 5): CircuitBreaker
    {
        $breaker = new CircuitBreaker(
            timeout: 1,
            successThreshold: $successThreshold,
            halfOpenPermittedCalls: $permitted,
        );

        $breaker->trip();
        sleep(2);

        return $breaker;
    }

    public function testASecondCallerIsRefusedWhileTheProbeIsStillRunning(): void
    {
        $breaker = $this->halfOpen(permitted: 1);
        $nested = null;

        $result = $breaker->call(
            open: static fn(): string => 'fallback',
            close: function () use ($breaker, &$nested): string {
                $this->assertSame(CircuitState::HALF_OPEN, $breaker->getState());

                $nested = $breaker->call(
                    open: static fn(): string => 'fallback',
                    close: static fn(): string => 'reached dependency',
                );

                return 'probe ran';
            },
        );

        $this->assertSame('probe ran', $result);
        $this->assertSame('fallback', $nested, 'A second caller must not reach a recovering dependency.');
    }

    public function testTheCapCountsConcurrentProbesNotTotalProbes(): void
    {
        $breaker = $this->halfOpen(permitted: 1);

        // One probe at a time is fine however many there are, because each has
        // finished before the next begins.
        for ($i = 0; $i < 3; $i++) {
            $result = $breaker->call(
                open: static fn(): string => 'fallback',
                close: static fn(): string => 'reached dependency',
            );

            $this->assertSame('reached dependency', $result);
        }
    }

    public function testTheCapIsConfigurable(): void
    {
        $breaker = $this->halfOpen(permitted: 2);
        $second = null;
        $third = null;

        $breaker->call(
            open: static fn(): string => 'fallback',
            close: function () use ($breaker, &$second, &$third): string {
                $second = $breaker->call(
                    open: static fn(): string => 'fallback',
                    close: function () use ($breaker, &$third): string {
                        $third = $breaker->call(
                            open: static fn(): string => 'fallback',
                            close: static fn(): string => 'reached dependency',
                        );

                        return 'reached dependency';
                    },
                );

                return 'reached dependency';
            },
        );

        $this->assertSame('reached dependency', $second, 'Two concurrent probes are permitted here.');
        $this->assertSame('fallback', $third, 'The third concurrent probe is not.');
    }

    /**
     * The cap belongs to half open alone. A closed circuit is not probing, and
     * throttling it would be a concurrency limiter wearing a breaker's clothes.
     */
    public function testAClosedCircuitIsNotCapped(): void
    {
        $breaker = new CircuitBreaker(halfOpenPermittedCalls: 1);
        $nested = null;

        $breaker->call(
            open: static fn(): string => 'fallback',
            close: function () use ($breaker, &$nested): string {
                $nested = $breaker->call(
                    open: static fn(): string => 'fallback',
                    close: static fn(): string => 'reached dependency',
                );

                return 'reached dependency';
            },
        );

        $this->assertSame('reached dependency', $nested);
    }

    public function testARefusedCallerDoesNotCountAsAProbeFailure(): void
    {
        $breaker = $this->halfOpen(permitted: 1);

        $breaker->call(
            open: static fn(): string => 'fallback',
            close: function () use ($breaker): string {
                $breaker->call(
                    open: static fn(): string => 'fallback',
                    close: static fn(): string => 'reached dependency',
                );

                return 'probe ran';
            },
        );

        // The refused caller took the fallback without executing anything, so it
        // is neither evidence of recovery nor of continued failure. Only the probe
        // counts, and it succeeded.
        $this->assertSame(CircuitState::HALF_OPEN, $breaker->getState());
        $this->assertSame(1, $breaker->getSuccessCount());
    }

    public function testHalfOpenPermittedCallsMustBeAtLeastOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Half-open permitted calls must be at least 1.');

        new CircuitBreaker(halfOpenPermittedCalls: 0);
    }
}
