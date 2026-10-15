<?php

namespace Utopia\Usage;

/**
 * In-memory metric accumulator.
 *
 * Buffers collect() calls and flushes them to a Usage instance in batches.
 * Events are summed per metric+tags; gauges use last-write-wins.
 *
 * The accumulator exposes raw signals — count() and elapsedSeconds() — so
 * callers can decide when to flush rather than baking a policy in here.
 */
class Accumulator
{
    private Usage $usage;

    /**
     * @var array<string, array{tenant: string, metric: string, value: int, type: string, tags: array<string, mixed>, allowNegative: bool, time?: \DateTime}>
     */
    private array $buffer = [];

    /** @var float Timestamp of the last flush */
    private float $flushedAt;

    /**
     * @param  Usage  $usage  The Usage instance to flush buffered metrics to
     */
    public function __construct(Usage $usage)
    {
        $this->usage = $usage;
        $this->flushedAt = microtime(true);
    }

    /**
     * Collect a metric into the in-memory buffer for deferred flushing.
     *
     * Events fold additively; earliest non-null time wins on merge.
     * Gauges use last-write-wins.
     *
     * Negative values are rejected by default for every metric, so a buggy
     * negative count/bandwidth is still caught. A caller that emits a genuine
     * signed delta (e.g. realtime connections `+1`/`-1`) opts in per call with
     * `$allowNegative = true`. The library stays generic — the decision lives
     * with the caller, not with any metric-name knowledge here.
     *
     * @param array<string,mixed> $tags
     * @param bool $allowNegative Permit a negative value for this metric (default: reject).
     */
    public function collect(string $tenant, string $metric, int $value, string $type, array $tags = [], ?\DateTime $time = null, bool $allowNegative = false): self
    {
        // Compare against '' rather than empty(): the string "0" is a valid
        // tenant/metric id but empty("0") is true in PHP.
        if ($tenant === '') {
            throw new \InvalidArgumentException('Tenant cannot be empty');
        }
        if ($metric === '') {
            throw new \InvalidArgumentException('Metric name cannot be empty');
        }
        if ($value < 0 && !$allowNegative) {
            throw new \InvalidArgumentException('Value cannot be negative');
        }
        if ($type !== Usage::TYPE_EVENT && $type !== Usage::TYPE_GAUGE) {
            throw new \InvalidArgumentException("Invalid metric type '{$type}'. Allowed: " . Usage::TYPE_EVENT . ', ' . Usage::TYPE_GAUGE);
        }

        // Hash the full identity so distinct (tenant, metric, type, tags)
        // tuples never collide on the key — a raw `:`-join would let
        // e.g. tenant "a"/metric "b:c" and tenant "a:b"/metric "c" share one
        // entry. Tags are sorted first so key order doesn't matter.
        $canonicalTags = $tags;
        ksort($canonicalTags);
        $key = md5(json_encode([$tenant, $metric, $type, $canonicalTags], JSON_THROW_ON_ERROR));

        if ($type === Usage::TYPE_EVENT && isset($this->buffer[$key])) {
            // earliest time wins on merge
            $this->buffer[$key]['value'] += $value;

            // The opt-in is a property of the folded row, not of whichever
            // call happened to create it. Folding a signed delta into an entry
            // opened by a plain positive must not leave the net row looking
            // unauthorised: it would be rejected at write time, and since a
            // failed batch keeps its entries buffered, every later flush would
            // retry the same rejection.
            $this->buffer[$key]['allowNegative'] = $this->buffer[$key]['allowNegative'] || $allowNegative;

            if ($time !== null && (!isset($this->buffer[$key]['time']) || $time < $this->buffer[$key]['time'])) {
                $this->buffer[$key]['time'] = $time;
            }
        } else {
            $entry = [
                'tenant' => $tenant,
                'metric' => $metric,
                'value' => $value,
                'type' => $type,
                'tags' => $tags,
                'allowNegative' => $allowNegative,
            ];
            if ($time !== null) {
                $entry['time'] = $time;
            }
            $this->buffer[$key] = $entry;
        }

        return $this;
    }

    /**
     * Flush the in-memory buffer to storage.
     *
     * Separates buffered metrics into events and gauges, then writes each batch
     * to the appropriate table via addBatch(). Only entries whose batch write
     * succeeds are removed from the buffer, so a partial failure preserves
     * the unwritten metrics for retry on the next flush.
     *
     * If addBatch() throws mid-flush, any earlier successful batches have
     * already been cleared from the buffer — the exception is allowed to
     * propagate so the caller can observe the failure.
     *
     * @return bool True if all batches succeeded (or buffer was empty)
     * @throws \Exception
     */
    public function flush(): bool
    {
        if (empty($this->buffer)) {
            $this->flushedAt = microtime(true);
            return true;
        }

        // Separate events and gauges; keep track of buffer keys so we can
        // selectively unset only the entries whose write succeeded.
        $eventKeys = [];
        $gaugeKeys = [];
        $events = [];
        $gauges = [];

        foreach ($this->buffer as $key => $entry) {
            if ($entry['type'] === Usage::TYPE_EVENT) {
                $events[] = $entry;
                $eventKeys[] = $key;
            } else {
                $gauges[] = $entry;
                $gaugeKeys[] = $key;
            }
        }

        $overallResult = true;

        // Flush events — clear buffer entries only on success.
        if (!empty($events)) {
            if ($this->usage->addBatch($events, Usage::TYPE_EVENT)) {
                foreach ($eventKeys as $key) {
                    unset($this->buffer[$key]);
                }
            } else {
                $overallResult = false;
            }
        }

        // Flush gauges — clear buffer entries only on success.
        if (!empty($gauges)) {
            if ($this->usage->addBatch($gauges, Usage::TYPE_GAUGE)) {
                foreach ($gaugeKeys as $key) {
                    unset($this->buffer[$key]);
                }
            } else {
                $overallResult = false;
            }
        }

        // Only restart the timer when nothing is left pending. On a partial
        // failure the retained entries keep aging so elapsedSeconds() reflects
        // how overdue they are instead of resetting to a fresh interval.
        if (empty($this->buffer)) {
            $this->flushedAt = microtime(true);
        }

        return $overallResult;
    }

    /**
     * Number of unique metric entries currently buffered.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->buffer);
    }

    /**
     * Seconds elapsed since the last flush.
     *
     * @return float
     */
    public function elapsedSeconds(): float
    {
        return microtime(true) - $this->flushedAt;
    }
}
