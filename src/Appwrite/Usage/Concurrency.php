<?php

namespace Appwrite\Usage;

use Utopia\Console;
use Utopia\Query\Query as UsageFilter;
use Utopia\System\System;
use Utopia\Usage\Metric;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;

/**
 * Samples `realtime.connections` into a concurrency gauge of the same name,
 * one bucket at a time, as the net of the deltas inside a trailing window:
 *
 *   level(bucket) = max(0, sum(deltas in (bucket end − window, bucket end]))
 *
 * Carrying the level forward from the previous sample would be exact if every
 * open were matched by a close, but a realtime worker that stops takes its open
 * connections' closes with it, so a carried level only ever ratchets upward.
 * The window bounds that error to one window after a restart and needs no
 * state, at the price of not seeing connections older than the window.
 *
 * Shared by the self-hosted and Cloud `stats-resources` tasks so both editions
 * fold the same way; the two differ in how they schedule it and how they report
 * failures, not in the arithmetic.
 */
class Concurrency
{
    /** Must match REALTIME_CONCURRENCY_INTERVAL. */
    private const int INTERVAL_SECONDS = 300;

    /**
     * Trailing window the level is summed over. Calibrated against live
     * connection counts: shorter hides long-lived connections, longer carries a
     * killed worker's orphaned opens for longer.
     */
    private const int WINDOW_SECONDS = 21600;

    /** How far back to look for the newest sample when resuming. */
    private const int MAX_CATCHUP_HOURS = 168;

    /** Row cap per cross-tenant query, one row per (tenant, bucket). */
    private const int MAX_ROWS = 50_000;

    /** @param int $pageSize rows per event read; only tests need it smaller. */
    public function __construct(private readonly int $pageSize = self::MAX_ROWS)
    {
    }

    /**
     * Sample every whole bucket that has closed since the last sample.
     *
     * @return int Number of gauge samples written.
     */
    public function sample(Usage $usage, ?\DateTimeInterface $now = null): int
    {
        $window = self::WINDOW_SECONDS;

        // Stay behind the current bucket so in-flight deltas land first.
        $end = \DateTime::createFromInterface($now ?? new \DateTimeImmutable())
            ->setTimezone(new \DateTimeZone('UTC'))
            ->sub(new \DateInterval('PT' . REALTIME_CONCURRENCY_LAG_SECONDS . 'S'));

        // Whole buckets only: a partial trailing bucket would be sampled short,
        // and the next run resumes past it without re-reading.
        $end->setTimestamp(
            \intdiv($end->getTimestamp(), self::INTERVAL_SECONDS) * self::INTERVAL_SECONDS
        );

        $start = $this->since($this->lastSampleAt($usage), $end);

        if ($start >= $end) {
            return 0;
        }

        // Read one window ahead of the first emitted bucket so its level is
        // complete. Bucket labels are starts, so the earliest label inside the
        // window is one interval after `start − window`.
        $readFrom = (clone $start)->sub(new \DateInterval('PT' . ($window - self::INTERVAL_SECONDS) . 'S'));

        $deltas = $this->readDeltas($usage, $readFrom, $end);

        if ($deltas === null) {
            Console::warning('Realtime concurrency: a single bucket holds over ' . $this->pageSize . ' tenants; nothing sampled');

            return 0;
        }

        $samples = [];
        // Cast: a tenant is a project sequence, so PHP turned the array key into
        // an int, and the store requires a non-empty string.
        foreach ($deltas as $tenant => $byBucket) {
            $tenant = (string) $tenant;

            \ksort($byBucket);
            $buckets = \array_keys($byBucket);
            $count = \count($buckets);

            // Slide the window over the emitted buckets: `enter` admits buckets
            // up to the current one, `leave` retires those a full window old.
            $level = 0;
            $enter = 0;
            $leave = 0;
            for ($at = $start->getTimestamp(); $at < $end->getTimestamp(); $at += self::INTERVAL_SECONDS) {
                while ($enter < $count && $buckets[$enter] <= $at) {
                    $level += $byBucket[$buckets[$enter++]];
                }
                while ($leave < $enter && $buckets[$leave] <= $at - $window) {
                    $level -= $byBucket[$buckets[$leave++]];
                }

                // Only buckets the tenant had deltas in are written, as before:
                // the window changes the value, not how often it is sampled.
                if (!isset($byBucket[$at])) {
                    continue;
                }

                // Negative when the closes we see belong to opens we no longer do.
                $samples[] = [
                    'tenant' => $tenant,
                    'metric' => METRIC_REALTIME_CONNECTIONS,
                    'value' => \max(0, $level),
                    'time' => (new \DateTime('@' . $at))->setTimezone(new \DateTimeZone('UTC')),
                ];
            }
        }

        if ($samples === []) {
            return 0;
        }

        $usage->addBatch($samples, Usage::TYPE_GAUGE);

        return \count($samples);
    }

    /**
     * Every (tenant, bucket) delta in the range, read a page at a time so a
     * dense window is covered in full rather than silently truncated -- a
     * truncated read would sample levels that are missing deltas, and the
     * resume point would then advance past them for good.
     *
     * @return array<string, array<int, int>>|null tenant → bucket start → net
     *         delta, or null when one bucket alone fills a page, which reading
     *         further pages cannot get past.
     */
    private function readDeltas(Usage $usage, \DateTime $from, \DateTime $to): ?array
    {
        $deltas = [];
        $cursor = $from->getTimestamp();

        while ($cursor < $to->getTimestamp()) {
            $rows = $usage->findAcrossTenants([
                UsageFilter::equal('metric', [METRIC_REALTIME_CONNECTIONS]),
                UsageFilter::greaterThanEqual('time', \gmdate('Y-m-d H:i:s', $cursor)),
                UsageFilter::lessThan('time', $to->format('Y-m-d H:i:s')),
                UsageQuery::groupBy('tenant'),
                UsageQuery::groupByInterval('time', REALTIME_CONCURRENCY_INTERVAL),
                UsageFilter::orderAsc('time'),
                UsageFilter::limit($this->pageSize),
            ], Usage::TYPE_EVENT);

            // Rows are time-ascending, so a full page may have split its newest
            // bucket across the limit: drop that bucket and re-read it from the
            // next page, where it is whole.
            $partial = \count($rows) >= $this->pageSize ? $this->bucketOf(\end($rows)) : null;

            foreach ($rows as $row) {
                $tenant = $row->getTenant();
                if ($tenant === '' || $tenant === null) {
                    continue;
                }

                $bucket = $this->bucketOf($row);
                if ($partial !== null && $bucket >= $partial) {
                    continue;
                }

                $deltas[$tenant][$bucket] = ($deltas[$tenant][$bucket] ?? 0) + (int) $row->getValue();
            }

            if ($partial === null) {
                break;
            }

            // The page held nothing but that one bucket, so the next read would
            // return the same rows and the fold would never advance.
            if ($partial <= $cursor) {
                return null;
            }

            $cursor = $partial;
        }

        return $deltas;
    }

    private function bucketOf(Metric $row): int
    {
        $time = new \DateTime((string) $row->getAttribute('time'), new \DateTimeZone('UTC'));

        return \intdiv($time->getTimestamp(), self::INTERVAL_SECONDS) * self::INTERVAL_SECONDS;
    }

    /**
     * When the newest sample was taken, across every tenant. Ungrouped, so the
     * row keeps its `time`; every tenant advances through the same window each
     * run, so one row dates the whole series.
     */
    private function lastSampleAt(Usage $usage): ?\DateTime
    {
        $rows = $usage->findAcrossTenants([
            UsageFilter::equal('metric', [METRIC_REALTIME_CONNECTIONS]),
            UsageFilter::greaterThanEqual('time', $this->catchupFloor()),
            UsageFilter::orderDesc('time'),
            UsageFilter::limit(1),
        ], Usage::TYPE_GAUGE);

        $time = isset($rows[0]) ? (string) $rows[0]->getAttribute('time', '') : '';

        return $time === '' ? null : new \DateTime($time, new \DateTimeZone('UTC'));
    }

    private function catchupFloor(): string
    {
        return (new \DateTime('now', new \DateTimeZone('UTC')))
            ->sub(new \DateInterval('PT' . self::MAX_CATCHUP_HOURS . 'H'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * Resume one interval past the newest sample, so no bucket is counted
     * twice. With no samples at all, start one scheduling interval back rather
     * than replaying all of history -- floored at the bucket size, since a
     * shorter window than one bucket would sample nothing.
     */
    private function since(?\DateTime $latest, \DateTime $end): \DateTime
    {
        if ($latest !== null) {
            return (clone $latest)->add(new \DateInterval('PT' . self::INTERVAL_SECONDS . 'S'));
        }

        $window = \max(
            self::INTERVAL_SECONDS,
            (int) System::getEnv('_APP_STATS_RESOURCES_INTERVAL', 3600)
        );

        return (clone $end)->sub(new \DateInterval('PT' . $window . 'S'));
    }
}
