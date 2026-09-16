<?php

namespace Utopia\CircuitBreaker;

use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Gauge;

class CircuitBreaker
{
    private const STATE_FIELD = 'state';
    private const FAILURES_FIELD = 'failures';
    private const SUCCESSES_FIELD = 'successes';
    private const OPENED_AT_FIELD = 'opened_at';

    private CircuitState $state = CircuitState::CLOSED;
    private int $failures = 0;
    private int $successes = 0;
    private ?int $openedAt = null;

    /**
     * Probes executing right now.
     *
     * Deliberately per-process and not mirrored to the cache adapter. This bounds
     * the herd one process can send at a recovering dependency, which is the part
     * a process can see and act on; a cross-process count would need a lease and a
     * timeout to survive a crashed holder, and would still be a guess.
     */
    private int $halfOpenInFlight = 0;
    private int $activeCalls = 0;

    private ?Telemetry $telemetry = null;

    /**
     * The breakers each telemetry adapter observes, by metric prefix. One
     * observation callback per adapter and prefix is registered the first time
     * a breaker binds to that adapter, and it walks the live breakers in this
     * map at collection. The keys are weak, so a discarded breaker drops out of
     * collection and is not kept alive by the callback, and a breaker that
     * rebinds moves rather than registering a second time.
     *
     * @var \WeakMap<Telemetry, array<string, \WeakMap<CircuitBreaker, true>>>|null
     */
    private static ?\WeakMap $observed = null;

    /**
     * The rolling window, kept in this process and not mirrored to the cache
     * adapter.
     *
     * What is worth sharing between processes is the verdict — the state and when
     * it was reached — and those still are. The tally behind it is a measurement of
     * recent local traffic, and persisting it would mean writing counters on every
     * call, including the successes: a cost on the hot path, paid to share a number
     * each process can compute for itself. Any process that sees a bad enough rate
     * opens the shared circuit for all of them.
     *
     * Which $window-wide bucket the current counts belong to.
     */
    private int $windowSlot = 0;
    private int $windowFailures = 0;
    private int $windowCalls = 0;
    private int $windowPreviousFailures = 0;
    private int $windowPreviousCalls = 0;
    private ?Counter $calls = null;
    private ?Counter $callbackFailures = null;
    private ?Counter $fallbacks = null;
    private ?Counter $transitions = null;
    private ?Gauge $eventTimestamp = null;

    /**
     * @param  int  $window seconds of history the failure rate is judged over.
     *                      A rate, not a count, because a count answers "how many
     *                      calls failed" — which on any shared connection or pool
     *                      is partly a fact about how many calls were in flight
     *                      when something broke, not about how unhealthy the
     *                      dependency is. One fault fails everything queued behind
     *                      it, so the same fault costs three failures on an idle
     *                      caller and ninety on a busy one. A rate is the same
     *                      number either way. Counting consecutive failures also
     *                      cannot see a steady trickle at all, because the
     *                      successes interleaved with it keep resetting the tally.
     * @param  float  $failureRatio share of calls in the window that must fail to
     *                              open the circuit, between 0 and 1.
     * @param  int  $minimumThroughput calls required in the window before the ratio
     *                                 is trusted, so a single failure among two
     *                                 calls does not read as 50% unhealthy.
     * @param  int  $halfOpenPermittedCalls probes allowed to run at the same time
     *                                      while half open. The rest take the
     *                                      fallback, as they would while open. One
     *                                      probe answers the only question half
     *                                      open asks — is the dependency back —
     *                                      and sending a thousand concurrent
     *                                      callers to find out is how a recovering
     *                                      dependency is knocked over again.
     */
    public function __construct(
        private readonly int $timeout = 30,
        private readonly int $successThreshold = 2,
        private readonly ?Adapter $cache = null,
        private readonly string $key = 'default',
        ?Telemetry $telemetry = null,
        private readonly string $metricPrefix = '',
        private readonly int $window = 10,
        private readonly float $failureRatio = 0.5,
        private readonly int $minimumThroughput = 20,
        private readonly int $halfOpenPermittedCalls = 1,
    ) {
        if ($this->cache instanceof \Utopia\CircuitBreaker\Adapter && $this->key === '') {
            throw new \InvalidArgumentException('Key must not be empty when a cache adapter is configured.');
        }

        if ($this->window <= 0) {
            throw new \InvalidArgumentException('Window must be greater than 0 seconds.');
        }

        if ($this->failureRatio <= 0 || $this->failureRatio > 1) {
            throw new \InvalidArgumentException('Failure ratio must be greater than 0 and at most 1.');
        }

        if ($this->minimumThroughput < 1) {
            throw new \InvalidArgumentException('Minimum throughput must be at least 1.');
        }

        if ($this->halfOpenPermittedCalls < 1) {
            throw new \InvalidArgumentException('Half-open permitted calls must be at least 1.');
        }

        if ($telemetry instanceof \Utopia\Telemetry\Adapter) {
            $this->setTelemetry($telemetry);
        }
        $this->syncFromCache();
    }

    public function setTelemetry(Telemetry $telemetry): void
    {
        $this->calls = $telemetry->createCounter($this->metricName('breaker.calls'), '{call}');

        // State, counts and calls in flight are read when the adapter collects, not written on
        // every call: a breaker in front of the cache is called tens of times per request, and
        // recording four gauges each time cost more CPU than the cache read it guarded.
        if ($this->telemetry instanceof \Utopia\Telemetry\Adapter && $this->telemetry !== $telemetry) {
            $registry = self::$observed[$this->telemetry] ?? [];
            if (isset($registry[$this->metricPrefix])) {
                unset($registry[$this->metricPrefix][$this]);
            }
        }
        $this->telemetry = $telemetry;
        self::$observed ??= new \WeakMap();
        $registry = self::$observed[$telemetry] ?? [];
        if (! isset($registry[$this->metricPrefix])) {
            /** @var \WeakMap<CircuitBreaker, true> $breakers */
            $breakers = new \WeakMap();
            $registry[$this->metricPrefix] = $breakers;
            self::$observed[$telemetry] = $registry;
            $this->observe($telemetry, $this->metricPrefix, $breakers);
        }
        $registry[$this->metricPrefix][$this] = true;

        $this->callbackFailures = $telemetry->createCounter($this->metricName('breaker.callback_failures'), '{failure}');
        $this->fallbacks = $telemetry->createCounter($this->metricName('breaker.fallbacks'), '{fallback}');
        $this->transitions = $telemetry->createCounter($this->metricName('breaker.transitions'), '{transition}');
        $this->eventTimestamp = $telemetry->createGauge($this->metricName('breaker.event.timestamp'), 's');
    }

    public function call(callable $open, callable $close, ?callable $halfOpen = null): mixed
    {
        $initialState = $this->state;
        $outcome = 'unknown';
        $exceptionType = null;
        $entered = false;
        $probing = false;

        try {
            $this->updateState();
            $initialState = $this->state;
            $this->activeCalls++;
            $entered = true;

            if ($this->state === CircuitState::OPEN) {
                $outcome = 'short_circuit';
                $this->fallbacks?->add(1, $this->telemetryAttributes([
                    'circuit_breaker.reason' => 'open',
                    'circuit_breaker.state' => $this->state->value,
                ]));

                return $open();
            }

            if ($this->state === CircuitState::HALF_OPEN) {
                if ($this->halfOpenInFlight >= $this->halfOpenPermittedCalls) {
                    // A probe is already asking the question this call would ask.
                    // Waiting for its answer costs nothing; joining it would turn
                    // the recovery attempt into the load that broke the dependency.
                    $outcome = 'short_circuit';
                    $this->fallbacks?->add(1, $this->telemetryAttributes([
                        'circuit_breaker.reason' => 'half_open_saturated',
                        'circuit_breaker.state' => $this->state->value,
                    ]));

                    return $open();
                }

                $probing = true;
                $this->halfOpenInFlight++;
            }

            // Determine which callback to use
            $callback = ($this->state === CircuitState::HALF_OPEN && $halfOpen !== null)
                ? $halfOpen
                : $close;

            try {
                $result = $callback();
                $this->onSuccess();
                $outcome = 'success';
                return $result;
            } catch (\Throwable $e) {
                $exceptionType = $e::class;
                $this->callbackFailures?->add(1, $this->telemetryAttributes([
                    'exception.type' => $exceptionType,
                    'circuit_breaker.state' => $this->state->value,
                ]));
                $this->onFailure();
                $this->fallbacks?->add(1, $this->telemetryAttributes([
                    'circuit_breaker.reason' => 'failure',
                    'circuit_breaker.state' => $this->state->value,
                ]));
                $outcome = 'fallback';
                return $open();
            }
        } catch (\Throwable $e) {
            $exceptionType ??= $e::class;
            $outcome = $outcome === 'unknown' ? 'exception' : $outcome . '_exception';
            throw $e;
        } finally {
            if ($probing) {
                $this->halfOpenInFlight--;
            }

            $attributes = $this->telemetryAttributes([
                'circuit_breaker.initial_state' => $initialState->value,
                'circuit_breaker.state' => $this->state->value,
                'circuit_breaker.outcome' => $outcome,
            ]);

            if ($exceptionType !== null) {
                $attributes['exception.type'] = $exceptionType;
            }

            $this->calls?->add(1, $attributes);
            if ($initialState === CircuitState::HALF_OPEN) {
                $this->recordEvent('probe', 'probe: ' . $outcome, [
                    'circuit_breaker.outcome' => $outcome,
                ]);
            }
            if ($entered) {
                $this->activeCalls--;
            }
        }
    }

    private function updateState(): void
    {
        $this->syncFromCache();

        if ($this->state === CircuitState::OPEN && $this->hasTimedOut()) {
            $this->transitionToHalfOpen();
        }
    }

    private function onSuccess(): void
    {
        if ($this->state === CircuitState::HALF_OPEN) {
            $successes = $this->incrementSuccesses();

            if ($successes >= $this->successThreshold) {
                $this->transitionToClosed();
            }

            return;
        }

        if ($this->state !== CircuitState::CLOSED) {
            return;
        }

        // A data point, not an all-clear. The window decides what is stale; wiping
        // the tally on every success is what would let a steady trickle of failures
        // hide behind the successes interleaved with it.
        $this->recordWindowOutcome(failed: false);
    }

    private function onFailure(): void
    {
        if ($this->state === CircuitState::HALF_OPEN) {
            // Immediately reopen on failure in half-open state
            $this->incrementFailures();
            $this->transitionToOpen();

            return;
        }

        [$failures, $calls] = $this->recordWindowOutcome(failed: true);

        if ($calls >= $this->minimumThroughput && ($failures / $calls) >= $this->failureRatio) {
            $this->transitionToOpen();
        }
    }

    /**
     * Add one outcome to the rolling window and return the failure and call totals
     * it now holds.
     *
     * Two buckets, each $window wide: the one being filled and the one before it.
     * Totalling both covers somewhere between one and two windows depending on
     * where the current bucket started, which is the usual trade for not keeping a
     * timestamp per call — and it only ever errs by remembering slightly too much,
     * never by forgetting a failure early.
     *
     * @return array{int, int} failures and total calls now in the window
     */
    private function recordWindowOutcome(bool $failed): array
    {
        $slot = intdiv(time(), $this->window);

        if ($slot !== $this->windowSlot) {
            $elapsed = $slot - $this->windowSlot;

            // One slot on: what was current becomes the previous bucket. More than
            // that and both buckets predate the window entirely.
            $this->setWindowCounts(
                previousFailures: $elapsed === 1 ? $this->windowFailures : 0,
                previousCalls: $elapsed === 1 ? $this->windowCalls : 0,
                failures: 0,
                calls: 0,
                slot: $slot,
            );
        }

        $this->setWindowCounts(
            previousFailures: $this->windowPreviousFailures,
            previousCalls: $this->windowPreviousCalls,
            failures: $this->windowFailures + ($failed ? 1 : 0),
            calls: $this->windowCalls + 1,
            slot: $slot,
        );

        return [
            $this->windowFailureCount(),
            $this->windowCalls + $this->windowPreviousCalls,
        ];
    }

    /**
     * Failures the window is currently holding.
     */
    private function windowFailureCount(): int
    {
        return $this->windowFailures + $this->windowPreviousFailures;
    }

    private function setWindowCounts(
        int $previousFailures,
        int $previousCalls,
        int $failures,
        int $calls,
        int $slot,
    ): void {
        $this->windowPreviousFailures = $previousFailures;
        $this->windowPreviousCalls = $previousCalls;
        $this->windowFailures = $failures;
        $this->windowCalls = $calls;
        $this->windowSlot = $slot;
    }

    private function resetWindow(): void
    {
        $this->setWindowCounts(
            previousFailures: 0,
            previousCalls: 0,
            failures: 0,
            calls: 0,
            slot: intdiv(time(), $this->window),
        );
    }

    private function hasTimedOut(): bool
    {
        return $this->openedAt !== null && (time() - $this->openedAt) >= $this->timeout;
    }

    private function transitionToOpen(): void
    {
        $from = $this->state;
        $this->setOpenedAt(time());
        $this->setSuccesses(0);
        $this->setState(CircuitState::OPEN);
        $this->recordTransition($from, CircuitState::OPEN);
    }

    private function transitionToHalfOpen(): void
    {
        $from = $this->state;
        $this->setFailures(0);
        $this->setSuccesses(0);
        // Clearing here, rather than on opening, is what keeps the tally that
        // opened the circuit readable while it is open — and it is enough, because
        // half open is the only way out of open, so no probe can ever re-open on
        // the evidence that opened it the first time.
        $this->resetWindow();
        $this->setState(CircuitState::HALF_OPEN);
        $this->recordTransition($from, CircuitState::HALF_OPEN);
    }

    private function transitionToClosed(): void
    {
        $from = $this->state;
        $this->setFailures(0);
        $this->setSuccesses(0);
        $this->resetWindow();
        $this->setOpenedAt(null);
        $this->setState(CircuitState::CLOSED);
        $this->recordTransition($from, CircuitState::CLOSED);
    }

    private function syncFromCache(): void
    {
        if (!$this->cache instanceof \Utopia\CircuitBreaker\Adapter) {
            return;
        }

        $this->state = $this->loadState();
        $this->failures = $this->loadInteger(self::FAILURES_FIELD);
        $this->successes = $this->loadInteger(self::SUCCESSES_FIELD);
        $this->openedAt = $this->loadNullableInteger(self::OPENED_AT_FIELD);
    }

    private function setState(CircuitState $state): void
    {
        $this->state = $state;
        $this->cache?->set($this->cacheField(self::STATE_FIELD), $state->value);
    }

    private function setFailures(int $failures): void
    {
        $this->failures = $failures;
        $this->cache?->set($this->cacheField(self::FAILURES_FIELD), $failures);
    }

    private function incrementFailures(): int
    {
        if (!$this->cache instanceof \Utopia\CircuitBreaker\Adapter) {
            return ++$this->failures;
        }

        return $this->failures = $this->cache->increment($this->cacheField(self::FAILURES_FIELD));
    }

    private function setSuccesses(int $successes): void
    {
        $this->successes = $successes;
        $this->cache?->set($this->cacheField(self::SUCCESSES_FIELD), $successes);
    }

    private function incrementSuccesses(): int
    {
        if (!$this->cache instanceof \Utopia\CircuitBreaker\Adapter) {
            return ++$this->successes;
        }

        return $this->successes = $this->cache->increment($this->cacheField(self::SUCCESSES_FIELD));
    }

    private function setOpenedAt(?int $openedAt): void
    {
        $this->openedAt = $openedAt;

        if (!$this->cache instanceof \Utopia\CircuitBreaker\Adapter) {
            return;
        }

        $field = $this->cacheField(self::OPENED_AT_FIELD);
        if ($openedAt === null) {
            $this->cache->delete($field);
            return;
        }

        $this->cache->set($field, $openedAt);
    }

    private function loadState(): CircuitState
    {
        if (!$this->cache instanceof \Utopia\CircuitBreaker\Adapter) {
            return $this->state;
        }

        $value = $this->cache->get($this->cacheField(self::STATE_FIELD));
        if (!\is_string($value)) {
            return CircuitState::CLOSED;
        }

        return CircuitState::tryFrom($value) ?? CircuitState::CLOSED;
    }

    private function loadInteger(string $field): int
    {
        if (!$this->cache instanceof \Utopia\CircuitBreaker\Adapter) {
            return 0;
        }

        $value = $this->cache->get($this->cacheField($field));

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function loadNullableInteger(string $field): ?int
    {
        if (!$this->cache instanceof \Utopia\CircuitBreaker\Adapter) {
            return null;
        }

        $value = $this->cache->get($this->cacheField($field));

        return is_numeric($value) ? (int) $value : null;
    }

    private function cacheField(string $field): string
    {
        return $this->key . ':' . $field;
    }

    private function metricName(string $name): string
    {
        $prefix = trim($this->metricPrefix, '.');

        return $prefix === '' ? $name : $prefix . '.' . $name;
    }

    /**
     * Register the gauges that every breaker bound to $telemetry with $prefix
     * reports through, reading each live breaker when the adapter collects.
     * The shared verdict (state, failures and successes while half-open) is
     * refreshed from the cache adapter first, so an idle breaker reports a
     * circuit tripped or recovered by another process; the open timeout is not
     * applied here, because collecting telemetry must not move the circuit.
     *
     * @param \WeakMap<CircuitBreaker, true> $breakers
     */
    private function observe(Telemetry $telemetry, string $prefix, \WeakMap $breakers): void
    {
        $prefix = trim($prefix, '.');
        $gauge = static function (string $name, ?string $unit, \Closure $value) use ($telemetry, $prefix, $breakers): void {
            $telemetry->createObservableGauge($prefix === '' ? $name : $prefix . '.' . $name, $unit)
                ->observe(static function (callable $observe) use ($breakers, $value): void {
                    foreach ($breakers as $breaker => $_) {
                        $observe($value($breaker), $breaker->telemetryAttributes());
                    }
                });
        };

        $gauge('breaker.active_calls', '{call}', static fn(self $breaker): int => $breaker->activeCalls);
        $gauge('breaker.state', null, static function (self $breaker): int {
            $breaker->syncFromCache();

            return $breaker->stateValue();
        });
        $gauge('breaker.failures', '{failure}', static function (self $breaker): int {
            $breaker->syncFromCache();

            return $breaker->state === CircuitState::HALF_OPEN ? $breaker->failures : $breaker->windowFailureCount();
        });
        $gauge('breaker.successes', '{success}', static function (self $breaker): int {
            $breaker->syncFromCache();

            return $breaker->successes;
        });
    }

    /**
     * @param array<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
     * @return array<non-empty-string, array<mixed>|bool|float|int|string|null>
     */
    private function telemetryAttributes(array $attributes = []): array
    {
        return ['circuit_breaker.name' => $this->key] + $attributes;
    }

    private function recordTransition(CircuitState $from, CircuitState $to): void
    {
        if ($from === $to) {
            return;
        }

        $this->transitions?->add(1, $this->telemetryAttributes([
            'circuit_breaker.from_state' => $from->value,
            'circuit_breaker.to_state' => $to->value,
        ]));
        $this->recordEvent('transition', $from->value . ' -> ' . $to->value, [
            'circuit_breaker.from_state' => $from->value,
            'circuit_breaker.to_state' => $to->value,
        ]);
    }

    /**
     * @param array<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
     */
    private function recordEvent(string $type, string $name, array $attributes = []): void
    {
        $this->eventTimestamp?->record(microtime(true), $this->telemetryAttributes([
            'circuit_breaker.event' => $type,
            'circuit_breaker.event_name' => $name,
        ] + $attributes));
    }

    private function stateValue(): int
    {
        return match ($this->state) {
            CircuitState::CLOSED => 0,
            CircuitState::OPEN => 1,
            CircuitState::HALF_OPEN => 2,
        };
    }

    public function getState(): CircuitState
    {
        $this->updateState();
        return $this->state;
    }

    /**
     * Failures the circuit is currently judging on: the window's tally while
     * closed, and the probe failures while half open.
     */
    public function getFailureCount(): int
    {
        $this->syncFromCache();

        return $this->state === CircuitState::HALF_OPEN
            ? $this->failures
            : $this->windowFailureCount();
    }

    public function getSuccessCount(): int
    {
        $this->syncFromCache();

        return $this->successes;
    }

    public function isOpen(): bool
    {
        return $this->getState() === CircuitState::OPEN;
    }

    public function isClosed(): bool
    {
        return $this->getState() === CircuitState::CLOSED;
    }

    public function isHalfOpen(): bool
    {
        return $this->getState() === CircuitState::HALF_OPEN;
    }

    /**
     * Force the breaker into the open state. Idempotent: re-tripping refreshes
     * openedAt but does not record a transition.
     */
    public function trip(): void
    {
        $this->syncFromCache();
        $this->transitionToOpen();
    }
}
