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
 * Counters are summed over the monitor's window. Gauges are the latest sample:
 * the periodic resource sweep writes them, so they have no window. Growth is
 * how far a gauge rose over the window, from its last sample before the window.
 */
class Meter
{
    /**
     * @var array<string, mixed>
     */
    protected readonly array $registry;

    /**
     * @param ?array<string, mixed> $registry the registry in `app/config/alerts.php`; the configured one when null
     */
    public function __construct(protected readonly Tenant $usage, ?array $registry = null)
    {
        $this->registry = $registry ?? Config::getParam('alerts', []);
    }

    /**
     * @param string $databaseType the engine the watched resource records its usage under, for a resource type with an `engine`
     * @throws \InvalidArgumentException when the monitor's metric or scope is not one this meter reads
     */
    public function measure(Document $monitor, \DateTimeImmutable $now, string $databaseType = ''): float
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

        $window = (int) $monitor->getAttribute('window', 0);
        $total = 0.0;
        foreach ($scope['sum'] ?? [$scope] as $series) {
            $queries = $this->scope($series, $monitor);
            foreach ($this->engines($series['metric'] ?? $metric, !empty($series['engines']), isset($series['id']), $databaseType) as $name) {
                $total += match ($definition['kind'] ?? '') {
                    'counter' => $this->sum($name, [...$this->since($window, $now), ...$queries]),
                    'gauge' => $this->latest($name, $queries),
                    'growth' => $this->latest($name, $queries) - $this->latest($name, [...$this->before($window, $now), ...$queries]),
                    default => throw new \InvalidArgumentException("Metric '{$metric}' has no kind this meter reads."),
                };
            }
        }

        return \max(0.0, $total);
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
        $start = $now->sub(new \DateInterval('PT' . \max(0, $window) . 'S'));

        return DateTime::format(\DateTime::createFromImmutable($start));
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
