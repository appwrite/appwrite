<?php

namespace Appwrite\Alert;

use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Query\Query;
use Utopia\Usage\Tenant;
use Utopia\Usage\Usage;

/**
 * Reads the value an alert rule compares, from its project's usage tenant.
 *
 * Counters are summed over the rule's window. Gauges are the latest sample:
 * storage is written by the periodic resource sweep, so it has no window.
 *
 * DocumentsDB and VectorsDB databases report under prefixed names, so a
 * project-wide database rule adds the three engines and a rule on one
 * database reads its engine's series.
 */
class Meter
{
    public function __construct(protected readonly Tenant $usage)
    {
    }

    /**
     * @param string $databaseType the watched database's type, for a rule on one database
     * @throws \InvalidArgumentException when the rule's metric is not one this meter reads
     */
    public function measure(Document $rule, \DateTimeImmutable $now, string $databaseType = ''): float
    {
        $metric = (string) $rule->getAttribute('metric', '');
        $resourceType = (string) $rule->getAttribute('resourceType', '');
        $resourceId = (string) $rule->getAttribute('resourceId', '');
        $since = $this->since((int) $rule->getAttribute('window', 0), $now);

        return match ($metric) {
            'bandwidth.inbound' => $this->sum(METRIC_NETWORK_INBOUND, [...$since, Query::notEqual('resourceType', 'site')]),
            'bandwidth.outbound' => $this->sum(METRIC_NETWORK_OUTBOUND, [...$since, Query::notEqual('resourceType', 'site')]),
            'databases.reads' => \array_sum(\array_map(
                fn (string $name): float => $this->sum($name, [...$since, ...$this->database($resourceType, $resourceId)]),
                $this->engines(METRIC_DATABASES_OPERATIONS_READS, $resourceType, $databaseType),
            )),
            'databases.writes' => \array_sum(\array_map(
                fn (string $name): float => $this->sum($name, [...$since, ...$this->database($resourceType, $resourceId)]),
                $this->engines(METRIC_DATABASES_OPERATIONS_WRITES, $resourceType, $databaseType),
            )),
            'databases.storage' => \array_sum(\array_map(
                fn (string $name): float => $resourceType === 'database'
                    ? $this->latest($name, [Query::equal('resourceType', ['database']), Query::equal('resourceId', [$resourceId])])
                    : $this->latest($name, [Query::equal('resourceType', ['project'])]),
                $this->engines(METRIC_DATABASES_STORAGE, $resourceType, $databaseType),
            )),
            'files.storage' => $resourceType === 'bucket'
                ? $this->latest(METRIC_STORAGE, [Query::equal('resourceType', ['bucket']), Query::equal('resourceId', [$resourceId])])
                : $this->latest(METRIC_FILES_STORAGE, [Query::equal('resourceType', ['project'])]),
            default => throw new \InvalidArgumentException("Metric '{$metric}' cannot be measured."),
        };
    }

    /**
     * @return list<Query>
     */
    protected function since(int $window, \DateTimeImmutable $now): array
    {
        $start = $now->sub(new \DateInterval('PT' . \max(0, $window) . 'S'));

        return [Query::greaterThanEqual('time', DateTime::format(\DateTime::createFromImmutable($start)))];
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

    /**
     * @return list<Query>
     */
    private function database(string $resourceType, string $resourceId): array
    {
        return $resourceType === 'database' ? [Query::equal('resourceId', [$resourceId])] : [];
    }

    /**
     * The usage names a database metric is reported under: every engine's for a
     * project-wide rule, the watched database's engine's for a rule on one.
     *
     * @return list<string>
     */
    private function engines(string $metric, string $resourceType, string $databaseType): array
    {
        $engines = [
            DATABASE_TYPE_DOCUMENTSDB => DATABASE_TYPE_DOCUMENTSDB . '.' . $metric,
            DATABASE_TYPE_VECTORSDB => DATABASE_TYPE_VECTORSDB . '.' . $metric,
        ];

        if ($resourceType === 'database') {
            return [$engines[$databaseType] ?? $metric];
        }

        return [$metric, ...\array_values($engines)];
    }
}
