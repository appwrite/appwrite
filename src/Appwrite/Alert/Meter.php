<?php

namespace Appwrite\Alert;

use Utopia\Config\Config;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Query\Query;
use Utopia\Usage\Tenant;
use Utopia\Usage\Usage;

/**
 * Reads the value a usage monitor compares, from its project's usage tenant,
 * where `metrics` in `app/config/alerts.php` says the metric's writer records
 * it for the monitor's scope.
 *
 * Usage is read `lag` seconds behind now, so events still on their way to the
 * store are counted by a later evaluation rather than missed by this one.
 * Counters are summed over the window ending there. Gauges are the latest
 * sample at or before it, no older than ALERTS_GAUGE_MAX_AGE_SECONDS: the
 * periodic resource sweep writes them, so they have no window. Growth is how
 * far a gauge rose over the window, from its last sample before the window.
 *
 * A metric with no rows to read has no value: null, not 0, so a monitor on an
 * idle project, or on a stalled usage pipeline, is not compared at all.
 */
class Meter
{
    /**
     * @var array<string, mixed>
     */
    protected readonly array $registry;

    /**
     * @param ?array<string, mixed> $registry the registry in `app/config/alerts.php`; the configured one when null
     * @param int $lag seconds behind now the usage is read
     */
    public function __construct(protected readonly Tenant $usage, ?array $registry = null, protected readonly int $lag = 0)
    {
        $this->registry = $registry ?? Config::getParam('alerts', []);
    }

    /**
     * @param string $databaseType the engine the watched resource records its usage under, for a resource type with an `engine`
     * @return ?float the value, or null when the metric has no rows to read
     * @throws \InvalidArgumentException when the monitor's metric or scope is not one this meter reads
     */
    public function measure(Document $monitor, \DateTimeImmutable $now, string $databaseType = ''): ?float
    {
        $metric = (string) $monitor->getAttribute('metric', '');
        $resourceType = (string) $monitor->getAttribute('resourceType', '');
        $definition = $this->registry['metrics'][$metric] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException("Metric '{$metric}' cannot be measured.");
        }
        $scope = $definition['resources'][$resourceType] ?? null;
        if ($scope === null) {
            throw new \InvalidArgumentException("Metric '{$metric}' is not recorded for resource type '{$resourceType}'.");
        }

        $kind = $definition['kind'] ?? '';
        if (!\in_array($kind, ['counter', 'gauge', 'growth'], true)) {
            throw new \InvalidArgumentException("Metric '{$metric}' has no kind this meter reads.");
        }

        $end = $now->sub(new \DateInterval('PT' . \max(0, $this->lag) . 'S'));
        $window = (int) $monitor->getAttribute('window', 0);
        $total = 0.0;
        $read = false;
        foreach ($scope['sum'] ?? [$scope] as $series) {
            $queries = $this->scope($series, $monitor);
            foreach ($this->engines($series['metric'] ?? $metric, !empty($series['engines']), isset($series['id']), $databaseType) as $name) {
                if ($kind === 'counter') {
                    $range = [...$this->since($window, $end), Query::lessThan('time', $this->format($end)), ...$queries];
                    if ($this->recorded($name, $range, Usage::TYPE_EVENT)) {
                        $read = true;
                        $total += $this->sum($name, $range);
                    }
                    continue;
                }

                $current = [
                    Query::lessThanEqual('time', $this->format($end)),
                    ...$this->since(ALERTS_GAUGE_MAX_AGE_SECONDS, $end),
                    ...$queries,
                ];
                if (!$this->recorded($name, $current, Usage::TYPE_GAUGE)) {
                    continue;
                }
                $read = true;
                $total += $this->latest($name, $current);
                if ($kind === 'growth') {
                    $total -= $this->latest($name, [...$this->before($window, $end), ...$queries]);
                }
            }
        }

        return $read ? \max(0.0, $total) : null;
    }

    /**
     * @return list<Query>
     */
    protected function since(int $window, \DateTimeImmutable $now): array
    {
        return [Query::greaterThanEqual('time', $this->start($window, $now))];
    }

    /**
     * @return list<Query>
     */
    protected function before(int $window, \DateTimeImmutable $now): array
    {
        return [Query::lessThan('time', $this->start($window, $now))];
    }

    /**
     * Whether the series has any row the filters select, which a total of 0 cannot tell apart from none.
     *
     * @param list<Query> $queries
     */
    protected function recorded(string $metric, array $queries, string $type): bool
    {
        return $this->usage->count([Query::equal('metric', [$metric]), ...$queries], $type, 1) > 0;
    }

    /**
     * @param list<Query> $queries
     */
    protected function sum(string $metric, array $queries): float
    {
        return (float) \max(0, $this->usage->getTotal($metric, $queries, Usage::TYPE_EVENT));
    }

    /**
     * @param list<Query> $queries
     */
    protected function latest(string $metric, array $queries): float
    {
        return (float) \max(0, $this->usage->getTotal($metric, $queries, Usage::TYPE_GAUGE));
    }

    private function start(int $window, \DateTimeImmutable $now): string
    {
        return $this->format($now->sub(new \DateInterval('PT' . \max(0, $window) . 'S')));
    }

    private function format(\DateTimeImmutable $time): string
    {
        return DateTime::format(\DateTime::createFromImmutable($time));
    }

    /**
     * The filters that select one series' rows for the monitor's scope, in the
     * dimensions its writer records.
     *
     * @param array<string, mixed> $series
     * @return list<Query>
     */
    private function scope(array $series, Document $monitor): array
    {
        $queries = [];
        if (isset($series['resourceType'])) {
            $queries[] = Query::equal('resourceType', [$series['resourceType']]);
        }
        if (isset($series['id'])) {
            $queries[] = Query::equal($series['id'], [(string) $monitor->getAttribute($series['id'], '')]);
        }

        return $queries;
    }

    /**
     * The usage names a series is recorded under: its own, plus every engine's
     * for a project-wide monitor, or the watched resource's engine's alone.
     *
     * @return list<string>
     */
    private function engines(string $metric, bool $engines, bool $single, string $databaseType): array
    {
        if (!$engines) {
            return [$metric];
        }

        $names = [];
        foreach ($this->registry['engines'] ?? [] as $engine) {
            $names[$engine] = $engine . '.' . $metric;
        }

        if ($single) {
            return [$names[$databaseType] ?? $metric];
        }

        return [$metric, ...\array_values($names)];
    }
}
