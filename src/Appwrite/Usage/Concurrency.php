<?php

namespace Appwrite\Usage;

use Utopia\Query\Query as UsageFilter;
use Utopia\System\System;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;

/**
 * Folds `realtime.connections` deltas into a concurrency level and samples it
 * into a gauge of the same name, one bucket at a time:
 *
 *   level(bucket) = level(previous bucket) + sum(deltas in bucket)
 *
 * Deriving this per request would mean re-reading every delta since the project
 * began, so the gauge is its own state and each run resumes from the newest
 * sample. The caller's loop interval controls freshness only -- one run emits
 * every whole bucket in the elapsed window.
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
     * How far back to look for a tenant's carried level. The gauge is only
     * written when a project has deltas, so this must outlast a quiet spell.
     */
    private const int MAX_CATCHUP_HOURS = 168;

    /** Row cap per cross-tenant query, one row per (tenant, bucket). */
    private const int MAX_ROWS = 50_000;

    /**
     * Sample every whole bucket that has closed since the last sample.
     *
     * @return int Number of gauge samples written.
     */
    public function sample(Usage $usage): int
    {
        // Stay behind the current bucket so in-flight deltas land first.
        $end = (new \DateTime())->sub(new \DateInterval('PT' . REALTIME_CONCURRENCY_LAG_SECONDS . 'S'));

        // Whole buckets only: a partial trailing bucket would be sampled short,
        // and the next run resumes past it without re-reading.
        $end->setTimestamp(
            \intdiv($end->getTimestamp(), self::INTERVAL_SECONDS) * self::INTERVAL_SECONDS
        );

        $levels = $this->lastLevels($usage);
        $start = $this->since($this->lastSampleAt($usage), $end);

        if ($start >= $end) {
            return 0;
        }

        // One query covers every tenant; no baseline is needed, since the level
        // is carried from the previous sample.
        $rows = $usage->findAcrossTenants([
            UsageFilter::equal('metric', [METRIC_REALTIME_CONNECTIONS]),
            UsageFilter::greaterThanEqual('time', $start->format('Y-m-d H:i:s')),
            UsageFilter::lessThan('time', $end->format('Y-m-d H:i:s')),
            UsageQuery::groupBy('tenant'),
            UsageQuery::groupByInterval('time', REALTIME_CONCURRENCY_INTERVAL),
            UsageFilter::orderAsc('time'),
            UsageFilter::limit(self::MAX_ROWS),
        ], Usage::TYPE_EVENT);

        $samples = [];
        foreach ($rows as $row) {
            $tenant = $row->getTenant();
            if ($tenant === '' || $tenant === null) {
                continue;
            }

            // Rows are time-ascending, so folding in order is correct. A
            // negative total means deltas were lost; a level cannot be < 0.
            $level = \max(0, ($levels[$tenant] ?? 0) + (int) $row->getValue());
            $levels[$tenant] = $level;

            $samples[] = [
                'tenant' => $tenant,
                'metric' => METRIC_REALTIME_CONNECTIONS,
                'value' => $level,
                'time' => new \DateTime((string) $row->getAttribute('time')),
            ];
        }

        if ($samples === []) {
            return 0;
        }

        $usage->addBatch($samples, Usage::TYPE_GAUGE);

        return \count($samples);
    }

    /**
     * Latest level per tenant. Values only: a grouped read returns the
     * aggregate and its grouping columns, not `time`, so the resume point comes
     * from {@see lastSampleAt()}.
     *
     * @return array<string, int>
     */
    private function lastLevels(Usage $usage): array
    {
        $rows = $usage->findAcrossTenants([
            UsageFilter::equal('metric', [METRIC_REALTIME_CONNECTIONS]),
            UsageFilter::greaterThanEqual('time', $this->catchupFloor()),
            UsageQuery::groupBy('tenant'),
            UsageFilter::limit(self::MAX_ROWS),
        ], Usage::TYPE_GAUGE);

        $levels = [];
        foreach ($rows as $row) {
            $tenant = $row->getTenant();
            if ($tenant !== '' && $tenant !== null) {
                $levels[$tenant] = (int) $row->getValue();
            }
        }

        return $levels;
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

        return $time === '' ? null : new \DateTime($time);
    }

    private function catchupFloor(): string
    {
        return (new \DateTime())
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
