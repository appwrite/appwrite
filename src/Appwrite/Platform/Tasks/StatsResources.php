<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\StatsResources as StatsResourcesMessage;
use Appwrite\Event\Publisher\StatsResources as StatsResourcesPublisher;
use Appwrite\Platform\Action;
use Appwrite\Usage\Connection;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Utopia\Query\Query as UsageFilter;
use Utopia\System\System;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;

class StatsResources extends Action
{
    private const CONCURRENCY_INTERVAL_SECONDS = 300;
    private const CONCURRENCY_MAX_CATCHUP_HOURS = 168;
    private const CONCURRENCY_MAX_ROWS = 50_000;
    public static function getName(): string
    {
        return 'stats-resources';
    }

    public function __construct()
    {
        $this
            ->desc('Schedule active projects for usage resource counts')
            ->inject('dbForPlatform')
            ->inject('publisherForStatsResources')
            ->inject('usageConnection')
            ->callback($this->action(...));
    }

    public function action(Database $dbForPlatform, StatsResourcesPublisher $publisherForStatsResources, Connection $usageConnection): void
    {
        if (!$usageConnection->isEnabled()) {
            Console::info('Usage statistics are disabled');
            return;
        }

        $this->disableSubqueries();
        $interval = max(60, (int) System::getEnv('_APP_STATS_RESOURCES_INTERVAL', 3600));

        Console::loop(function () use ($dbForPlatform, $publisherForStatsResources, $usageConnection): void {
            if (!$usageConnection->isReady()) {
                throw new \RuntimeException('Usage schema is not ready');
            }
            $this->sampleRealtimeConcurrency($usageConnection->getUsage());

            $last24Hours = (new \DateTime())->sub(new \DateInterval('P1D'));
            $this->foreachDocument($dbForPlatform, 'projects', [
                Query::greaterThanEqual('accessedAt', DateTime::format($last24Hours)),
                Query::equal('region', [System::getEnv('_APP_REGION', 'default')]),
            ], function ($project) use ($publisherForStatsResources): void {
                $publisherForStatsResources->enqueue(new StatsResourcesMessage(project: $project));
            });
        }, $interval);
    }

    private function sampleRealtimeConcurrency(Usage $usage): void
    {
        // Stay behind the current bucket so in-flight realtime deltas land first.
        $end = (new \DateTime())->sub(new \DateInterval('PT' . REALTIME_CONCURRENCY_LAG_SECONDS . 'S'));
        $end->setTimestamp(
            intdiv($end->getTimestamp(), self::CONCURRENCY_INTERVAL_SECONDS)
                * self::CONCURRENCY_INTERVAL_SECONDS
        );

        $levels = $this->lastConcurrencyLevels($usage);
        $start = $this->concurrencySince($this->lastConcurrencySampleAt($usage), $end);
        if ($start >= $end) {
            return;
        }

        $rows = $usage->findAcrossTenants([
            UsageFilter::equal('metric', [METRIC_REALTIME_CONNECTIONS]),
            UsageFilter::greaterThanEqual('time', $start->format('Y-m-d H:i:s')),
            UsageFilter::lessThan('time', $end->format('Y-m-d H:i:s')),
            UsageQuery::groupBy('tenant'),
            UsageQuery::groupByInterval('time', REALTIME_CONCURRENCY_INTERVAL),
            UsageFilter::orderAsc('time'),
            UsageFilter::limit(self::CONCURRENCY_MAX_ROWS),
        ], Usage::TYPE_EVENT);

        $samples = [];
        foreach ($rows as $row) {
            $tenant = $row->getTenant();
            if ($tenant === '') {
                continue;
            }

            $level = max(0, ($levels[$tenant] ?? 0) + (int) $row->getValue());
            $levels[$tenant] = $level;
            $samples[] = [
                'tenant' => $tenant,
                'metric' => METRIC_REALTIME_CONNECTIONS,
                'value' => $level,
                'time' => new \DateTime((string) $row->getAttribute('time')),
            ];
        }

        if ($samples !== []) {
            $usage->addBatch($samples, Usage::TYPE_GAUGE);
        }
    }

    /** @return array<string, int> */
    private function lastConcurrencyLevels(Usage $usage): array
    {
        $rows = $usage->findAcrossTenants([
            UsageFilter::equal('metric', [METRIC_REALTIME_CONNECTIONS]),
            UsageFilter::greaterThanEqual('time', (new \DateTime())
                ->sub(new \DateInterval('PT' . self::CONCURRENCY_MAX_CATCHUP_HOURS . 'H'))
                ->format('Y-m-d H:i:s')),
            UsageQuery::groupBy('tenant'),
            UsageFilter::limit(self::CONCURRENCY_MAX_ROWS),
        ], Usage::TYPE_GAUGE);

        $levels = [];
        foreach ($rows as $row) {
            if ($row->getTenant() !== '') {
                $levels[$row->getTenant()] = (int) $row->getValue();
            }
        }
        return $levels;
    }

    private function lastConcurrencySampleAt(Usage $usage): ?\DateTime
    {
        $rows = $usage->findAcrossTenants([
            UsageFilter::equal('metric', [METRIC_REALTIME_CONNECTIONS]),
            UsageFilter::greaterThanEqual('time', (new \DateTime())
                ->sub(new \DateInterval('PT' . self::CONCURRENCY_MAX_CATCHUP_HOURS . 'H'))
                ->format('Y-m-d H:i:s')),
            UsageFilter::orderDesc('time'),
            UsageFilter::limit(1),
        ], Usage::TYPE_GAUGE);

        $time = isset($rows[0]) ? (string) $rows[0]->getAttribute('time', '') : '';
        return $time === '' ? null : new \DateTime($time);
    }

    private function concurrencySince(?\DateTime $latest, \DateTime $end): \DateTime
    {
        if ($latest === null) {
            return (clone $end)->sub(new \DateInterval(
                'PT' . max(self::CONCURRENCY_INTERVAL_SECONDS, (int) System::getEnv('_APP_STATS_RESOURCES_INTERVAL', 3600)) . 'S'
            ));
        }

        return (clone $latest)->add(new \DateInterval('PT' . self::CONCURRENCY_INTERVAL_SECONDS . 'S'));
    }
}
