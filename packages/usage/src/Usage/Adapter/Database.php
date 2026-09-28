<?php

namespace Utopia\Usage\Adapter;

use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document;
use Exception;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Query as DatabaseQuery;
use Utopia\Usage\Metric;
use Utopia\Usage\Usage;
use Utopia\Query\Method;
use Utopia\Query\Query;

class Database extends SQL
{
    protected string $collection;

    private UtopiaDatabase $db;

    public function __construct(UtopiaDatabase $db)
    {
        $this->db = $db;
    }

    public function getName(): string
    {
        return 'Database';
    }

    /**
     * Check database connection health and collection existence.
     *
     * @return array{healthy: bool, database?: string, collection?: string, error?: string}
     */
    public function healthCheck(): array
    {
        try {
            $databaseName = $this->db->getDatabase();
            if (!$this->db->exists($databaseName)) {
                return [
                    'healthy' => false,
                    'error' => "Database '{$databaseName}' does not exist"
                ];
            }

            $collectionName = $this->collection ?? 'usage';
            if ($this->db->getCollection($collectionName)->isEmpty()) {
                return [
                    'healthy' => false,
                    'database' => $databaseName,
                    'collection' => $collectionName,
                    'error' => "Collection '{$collectionName}' is missing or empty in database '{$databaseName}'"
                ];
            }

            return [
                'healthy' => true,
                'database' => $databaseName,
                'collection' => $collectionName
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function setup(): void
    {
        $this->collection = 'usage';
        if (! $this->db->exists($this->db->getDatabase())) {
            throw new \Exception('You need to create the database before running Usage setup');
        }

        // Event schema is a superset of the gauge schema for the dimensions
        // that exist in both (resourceId, resourceInternalId, teamId,
        // teamInternalId), so a single Database collection backed by the
        // event schema works for both types. Gauge-only columns (ordinal)
        // are appended below.
        $attributes = $this->getAttributeDocuments('event');
        $indexDocs = $this->getIndexDocuments('event');

        // Append a `type` column so a single collection can disambiguate event vs gauge rows.
        // ClickHouse uses separate tables instead, so this lives in the Database adapter only.
        $attributes[] = new Document([
            '$id' => 'type',
            'type' => 'string',
            'size' => 16,
            'required' => false,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ]);
        $indexDocs[] = new Document([
            '$id' => 'index-type',
            'type' => 'key',
            'attributes' => ['type'],
        ]);

        // Gauge-only replica ordinal dimension.
        $attributes[] = new Document([
            '$id' => 'ordinal',
            'type' => 'string',
            'size' => 255,
            'required' => false,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ]);
        $indexDocs[] = new Document([
            '$id' => 'index-ordinal',
            'type' => 'key',
            'attributes' => ['ordinal'],
        ]);

        try {
            $this->db->createCollection(
                $this->collection,
                $attributes,
                $indexDocs
            );
        } catch (DuplicateException) {
            // Collection already exists
        }
    }

    /**
     * Get column definition for Database adapter (not used, but required by SQL parent)
     */
    protected function getColumnDefinition(string $id, string $type = 'event'): string
    {
        return '';
    }

    /**
     * Add metrics in batch (raw append).
     *
     * Database adapter uses a single collection for both types. The $type parameter
     * is stored as a field in each document for query-time differentiation.
     *
     * Each metric carries its own `tenant` (shared-tables mode), so a single
     * batch may span multiple tenants.
     *
     * @param array<array{tenant: string, metric: string, value: int, tags?: array<string,mixed>, allowNegative?: bool}> $metrics
     * @param string $type Metric type: 'event' or 'gauge'
     * @param int $batchSize
     * @return bool
     * @throws \Exception
     */
    public function addBatch(array $metrics, string $type, int $batchSize = 1000): bool
    {
        $this->db->getAuthorization()->skip(function () use ($metrics, $type, $batchSize) {
            $entries = [];
            foreach ($metrics as $metric) {
                if ($type !== Usage::TYPE_EVENT && $type !== Usage::TYPE_GAUGE) {
                    throw new \InvalidArgumentException("Invalid type '{$type}'. Allowed: event, gauge");
                }

                // Negatives are rejected by default so a buggy count or
                // bandwidth figure is still caught. A row carrying a genuine
                // signed delta opts in, the same contract the accumulator sets
                // and the ClickHouse adapter honours - an adapter that ignored
                // it would reject the row on every flush, and a failed batch
                // keeps its entries buffered.
                if ($metric['value'] < 0 && !($metric['allowNegative'] ?? false)) {
                    throw new \InvalidArgumentException('Value cannot be negative');
                }

                /** @var array<string,mixed> $tags */
                $tags = $metric['tags'] ?? [];

                $columns = Metric::extractColumns($tags, $type);

                $docData = array_merge([
                    '$id' => $this->generateId(),
                    '$permissions' => [],
                    'metric' => $metric['metric'],
                    'value' => $metric['value'],
                    'type' => $type,
                    'time' => (new \DateTime())->format('Y-m-d H:i:s.v'),
                ], $columns);

                $entries[] = ['tenant' => $metric['tenant'], 'doc' => new Document($docData)];
            }

            foreach (array_chunk($entries, max(1, $batchSize)) as $chunk) {
                foreach ($chunk as $entry) {
                    $this->setDbTenant($entry['tenant']);
                    $this->db->createDocument($this->collection, $entry['doc']);
                }
            }
        });

        return true;
    }

    /**
     * Get time series data for metrics.
     *
     * Stub implementation for Database adapter.
     *
     * @param string $tenant
     * @param array<string> $metrics
     * @param string $interval
     * @param string $startDate
     * @param string $endDate
     * @param array<Query> $queries
     * @param bool $zeroFill
     * @param string|null $type
     * @return array<string, array{total: float, data: array<array{value: float, date: string}>}>
     */
    public function getTimeSeries(string $tenant, array $metrics, string $interval, string $startDate, string $endDate, array $queries = [], bool $zeroFill = true, ?string $type = null): array
    {
        // Stub: Database adapter time series not yet implemented
        $output = [];
        foreach ($metrics as $metric) {
            $output[$metric] = ['total' => 0, 'data' => []];
        }
        return $output;
    }

    /**
     * Get total value for a single metric.
     *
     * Returns SUM for event metrics, latest value for gauge metrics.
     *
     * @param string $tenant
     * @param string $metric
     * @param array<Query> $queries
     * @param string|null $type
     * @return int
     */
    public function getTotal(string $tenant, string $metric, array $queries = [], ?string $type = null): int
    {
        $allQueries = array_merge($queries, [
            Query::equal('metric', [$metric]),
        ]);

        if ($type === Usage::TYPE_GAUGE) {
            // For gauge, return the most recent value by time. find() does
            // not guarantee any ordering, so we explicitly sort + limit
            // here instead of relying on insertion order.
            $gaugeQueries = array_merge($allQueries, [
                Query::orderDesc('time'),
                Query::limit(1),
            ]);
            /** @var array<Metric> $gaugeResults */
            $gaugeResults = $this->find($tenant, $gaugeQueries, $type);
            if (empty($gaugeResults)) {
                return 0;
            }
            return (int) ($gaugeResults[0]->getValue(0) ?? 0);
        }

        /** @var array<Metric> $results */
        $results = $this->find($tenant, $allQueries, $type);

        if (empty($results)) {
            return 0;
        }

        if ($type === Usage::TYPE_EVENT) {
            // For events, SUM all values
            $sum = 0;
            foreach ($results as $result) {
                $sum += (int) ($result->getValue(0) ?? 0);
            }
            return $sum;
        }

        // Type is null — partition results by stored type and reject ambiguous mixes.
        $eventResults = [];
        $gaugeResults = [];
        foreach ($results as $result) {
            if ($result->getType() === Usage::TYPE_GAUGE) {
                $gaugeResults[] = $result;
            } else {
                $eventResults[] = $result;
            }
        }

        if (!empty($eventResults) && !empty($gaugeResults)) {
            throw new \Exception(
                "Metric '{$metric}' exists as both event and gauge. "
                . "Specify \$type explicitly to avoid ambiguous aggregation."
            );
        }

        if (!empty($gaugeResults)) {
            // find() returns rows in unspecified order; sort by time so the
            // "latest" gauge sample is deterministic.
            usort(
                $gaugeResults,
                fn (Metric $a, Metric $b): int => strcmp($a->getTime() ?? '', $b->getTime() ?? '')
            );
            $lastResult = end($gaugeResults);
            return (int) ($lastResult->getValue(0) ?? 0);
        }

        $sum = 0;
        foreach ($eventResults as $result) {
            $sum += (int) ($result->getValue(0) ?? 0);
        }

        return $sum;
    }

    /**
     * Get totals for multiple metrics.
     *
     * @param string $tenant
     * @param array<string> $metrics
     * @param array<Query> $queries
     * @param string|null $type
     * @return array<string, int>
     */
    public function getTotalBatch(string $tenant, array $metrics, array $queries = [], ?string $type = null): array
    {
        if (empty($metrics)) {
            return [];
        }

        $totals = \array_fill_keys($metrics, 0);

        foreach ($metrics as $metric) {
            $totals[$metric] = $this->getTotal($tenant, $metric, $queries, $type);
        }

        return $totals;
    }

    /**
     * Sum metric values.
     *
     * Events-only by default — summing gauges is semantically meaningless.
     *
     * @param string $tenant
     * @param array<Query> $queries
     * @param string $attribute
     * @param string $type 'event' or 'gauge'
     * @return int
     */
    public function sum(string $tenant, array $queries = [], string $attribute = 'value', string $type = Usage::TYPE_EVENT): int
    {
        /** @var array<Metric> $results */
        $results = $this->find($tenant, $queries, $type);

        $sum = 0;
        foreach ($results as $result) {
            $sum += (int) ($result->getValue(0) ?? 0);
        }

        return $sum;
    }

    /**
     * Find from daily table — Database adapter falls back to regular find for events.
     *
     * @param string $tenant
     * @param array<Query> $queries
     * @return array<Metric>
     */
    public function findDaily(string $tenant, array $queries = []): array
    {
        return $this->find($tenant, $queries, Usage::TYPE_EVENT);
    }

    /**
     * Sum multiple metrics from daily table — falls back to individual sumDaily calls.
     *
     * @param string $tenant
     * @param array<\Utopia\Query\Query> $queries
     * @return array<string, int>
     */
    public function sumDailyBatch(string $tenant, array $metrics, array $queries = []): array
    {
        $totals = \array_fill_keys($metrics, 0);
        foreach ($metrics as $metric) {
            $metricQueries = array_merge($queries, [Query::equal('metric', [$metric])]);
            $totals[$metric] = $this->sumDaily($tenant, $metricQueries);
        }
        return $totals;
    }

    /**
     * Sum from daily table — Database adapter falls back to regular sum for events.
     *
     * @param string $tenant
     * @param array<Query> $queries
     * @param string $attribute
     * @return int
     */
    public function sumDaily(string $tenant, array $queries = [], string $attribute = 'value'): int
    {
        return $this->sum($tenant, $queries, $attribute, Usage::TYPE_EVENT);
    }

    /**
     * Convert Utopia\Query\Query to Utopia\Database\Query for use with the Database class.
     *
     * @param array<Query> $queries
     * @return array<DatabaseQuery>
     * @throws \Exception When a groupBy attribute is not a valid dimension column,
     *                    or when groupBy is used without groupByInterval.
     */
    private function convertQueriesToDatabase(array $queries): array
    {
        $this->validateGroupByQueries($queries);

        $dbQueries = [];
        foreach ($queries as $query) {
            $method = $query->getMethod();
            $attribute = $query->getAttribute();
            $values = $query->getValues();

            switch ($method) {
                case Method::Equal:
                    /** @var array<array<int|string, mixed>|bool|float|int|string> $values */
                    $dbQueries[] = DatabaseQuery::equal($attribute, $values);
                    break;
                case Method::GreaterThan:
                    if (!empty($values)) {
                        /** @var bool|float|int|string $value */
                        $value = $values[0];
                        $dbQueries[] = DatabaseQuery::greaterThan($attribute, $value);
                    }
                    break;
                case Method::LessThan:
                    if (!empty($values)) {
                        /** @var bool|float|int|string $value */
                        $value = $values[0];
                        $dbQueries[] = DatabaseQuery::lessThan($attribute, $value);
                    }
                    break;
                case Method::Between:
                    if (count($values) >= 2) {
                        /** @var bool|float|int|string $start */
                        $start = $values[0];
                        /** @var bool|float|int|string $end */
                        $end = $values[1];
                        $dbQueries[] = DatabaseQuery::between($attribute, $start, $end);
                    }
                    break;
                case Method::Contains:
                    /** @var array<array<int|string, mixed>|bool|float|int|string> $values */
                    $dbQueries[] = DatabaseQuery::contains($attribute, $values);
                    break;
                case Method::ContainsAny:
                    /** @var array<array<int|string, mixed>|bool|float|int|string> $values */
                    $dbQueries[] = DatabaseQuery::containsAny($attribute, $values);
                    break;
                case Method::IsNull:
                    // Takes no values, so it is not in VALUE_REQUIRED_METHODS.
                    $dbQueries[] = DatabaseQuery::isNull($attribute);
                    break;
                case Method::IsNotNull:
                    $dbQueries[] = DatabaseQuery::isNotNull($attribute);
                    break;
                case Method::NotEqual:
                    if (!empty($values)) {
                        /** @var bool|float|int|string $value */
                        $value = $values[0];
                        $dbQueries[] = DatabaseQuery::notEqual($attribute, $value);
                    }
                    break;
                case Method::NotContains:
                    /** @var array<array<int|string, mixed>|bool|float|int|string> $values */
                    $dbQueries[] = DatabaseQuery::notContains($attribute, $values);
                    break;
                case Method::NotBetween:
                    if (count($values) >= 2) {
                        /** @var bool|float|int|string $start */
                        $start = $values[0];
                        /** @var bool|float|int|string $end */
                        $end = $values[1];
                        $dbQueries[] = DatabaseQuery::notBetween($attribute, $start, $end);
                    }
                    break;
                case Method::StartsWith:
                    if (!empty($values) && is_string($values[0])) {
                        $dbQueries[] = DatabaseQuery::startsWith($attribute, $values[0]);
                    }
                    break;
                case Method::EndsWith:
                    if (!empty($values) && is_string($values[0])) {
                        $dbQueries[] = DatabaseQuery::endsWith($attribute, $values[0]);
                    }
                    break;
                case Method::LessThanEqual:
                    if (!empty($values)) {
                        /** @var bool|float|int|string $value */
                        $value = $values[0];
                        $dbQueries[] = DatabaseQuery::lessThanEqual($attribute, $value);
                    }
                    break;
                case Method::GreaterThanEqual:
                    if (!empty($values)) {
                        /** @var bool|float|int|string $value */
                        $value = $values[0];
                        $dbQueries[] = DatabaseQuery::greaterThanEqual($attribute, $value);
                    }
                    break;
                case Method::OrderDesc:
                    $dbQueries[] = DatabaseQuery::orderDesc($attribute);
                    break;
                case Method::OrderAsc:
                    $dbQueries[] = DatabaseQuery::orderAsc($attribute);
                    break;
                case Method::Limit:
                    if (!empty($values)) {
                        /** @var int|string $val */
                        $val = $values[0] ?? 0;
                        $dbQueries[] = DatabaseQuery::limit((int) $val);
                    }
                    break;
                case Method::Offset:
                    if (!empty($values)) {
                        /** @var int|string $val */
                        $val = $values[0] ?? 0;
                        $dbQueries[] = DatabaseQuery::offset((int) $val);
                    }
                    break;

                case Method::GroupByTimeBucket:
                case Method::GroupBy:
                case Method::Max:
                    // groupByInterval, groupBy and aggregate() are not pushed
                    // down to the Database adapter; callers get raw
                    // (non-aggregated) results. Validation runs in
                    // validateGroupByQueries() before this loop.
                    break;
                case Method::CursorAfter:
                case Method::CursorBefore:
                    // This adapter has never implemented cursor pagination, and
                    // DatabaseQuery's cursors take a Document rather than the
                    // value carried here. Refusing says so; the previous
                    // behaviour was to drop the cursor and return an unpaginated
                    // set, which reads as success.
                    throw new Exception('The Database adapter does not support cursor pagination; use the ClickHouse adapter or limit/offset.');
                default:
                    // A method with no arm used to fall out of this switch and
                    // contribute no WHERE fragment, so the filter silently did
                    // not apply and the caller got a wider result set than it
                    // asked for - a wrong answer that looks like a working
                    // query. Refuse instead: a method this adapter cannot push
                    // down has to be visible.
                    throw new Exception('Unsupported query method for the Database adapter: ' . $method->value);
            }
        }

        return $dbQueries;
    }

    /**
     * Validate groupBy / groupByInterval interactions in the supplied queries.
     *
     * Mirrors the ClickHouse adapter contract: groupBy attributes must exist on
     * the matching schema (event vs gauge — we default to the broader event set
     * for the Database adapter since both share one collection), and groupBy
     * does not push the aggregation hints down to SQL — it just validates them.
     *
     * @param array<Query> $queries
     * @throws \Exception
     */
    private function validateGroupByQueries(array $queries): void
    {
        $allowed = array_unique(array_merge(Metric::EVENT_COLUMNS, Metric::GAUGE_COLUMNS));

        foreach ($queries as $query) {
            if ($query->getMethod() !== Method::GroupBy) {
                continue;
            }

            $attribute = $query->getAttribute();

            if (!in_array($attribute, $allowed, true)) {
                throw new \Exception(
                    "Invalid groupBy attribute '{$attribute}'. Allowed: " . implode(', ', $allowed)
                );
            }
        }
    }

    /**
     * @param string $tenant
     * @param array<Query> $queries
     * @param string|null $type
     */
    public function purge(string $tenant, array $queries = [], ?string $type = null): bool
    {
        $this->setDbTenant($tenant);
        $queries = $this->withTypeFilter($queries, $type);

        $this->db->getAuthorization()->skip(function () use ($queries) {
            $dbQueries = $this->convertQueriesToDatabase($queries);
            $dbQueries[] = DatabaseQuery::limit(100);

            do {
                $documents = $this->db->find(
                    collection: $this->collection,
                    queries: $dbQueries,
                );

                foreach ($documents as $document) {
                    $this->db->deleteDocument($this->collection, $document->getId());
                }
            } while (! empty($documents));
        });

        return true;
    }

    /**
     * Find metrics using Query objects.
     *
     * When $type is non-null an additional `type = $type` filter is applied
     * so callers can isolate event vs gauge rows. When $type is null both
     * are returned (caller distinguishes via Metric::getType()).
     *
     * @param string $tenant
     * @param array<Query> $queries
     * @param string|null $type
     * @return array<Metric>
     */
    public function find(string $tenant, array $queries = [], ?string $type = null): array
    {
        $this->setDbTenant($tenant);
        $queries = $this->withTypeFilter($queries, $type);

        /** @var array<Document> $result */
        $result = $this->db->getAuthorization()->skip(function () use ($queries) {
            $dbQueries = $this->convertQueriesToDatabase($queries);
            return $this->db->find(
                collection: $this->collection,
                queries: $dbQueries,
            );
        });

        return \array_map(fn ($doc) => new Metric($doc->getArrayCopy()), $result);
    }

    /**
     * Count metrics using Query objects.
     *
     * When $max is non-null the count is bounded at the database level —
     * utopia-php/database accepts a `$max` argument that pushes the cap
     * down into the underlying SQL.
     *
     * @param string $tenant
     * @param array<Query> $queries
     * @param string|null $type
     * @param int|null $max Optional upper bound (inclusive) for the count
     * @return int
     */
    public function count(string $tenant, array $queries = [], ?string $type = null, ?int $max = null): int
    {
        $this->setDbTenant($tenant);
        $queries = $this->withTypeFilter($queries, $type);

        /** @var int $count */
        $count = $this->db->getAuthorization()->skip(function () use ($queries, $max) {
            $dbQueries = $this->convertQueriesToDatabase($queries);
            return $this->db->count(
                collection: $this->collection,
                queries: $dbQueries,
                max: $max
            );
        });

        return $count;
    }

    /**
     * Append a `type = $type` filter to the query list when $type is non-null.
     *
     * @param array<Query> $queries
     * @param string|null $type
     * @return array<Query>
     */
    private function withTypeFilter(array $queries, ?string $type): array
    {
        if ($type === null) {
            return $queries;
        }

        if ($type !== Usage::TYPE_EVENT && $type !== Usage::TYPE_GAUGE) {
            throw new \InvalidArgumentException("Invalid type '{$type}'. Allowed: " . Usage::TYPE_EVENT . ', ' . Usage::TYPE_GAUGE);
        }

        return array_merge($queries, [Query::equal('type', [$type])]);
    }

    /**
     * Scope the underlying Utopia\Database instance to a tenant for the next
     * operation. The Database adapter requires a numeric tenant ID.
     *
     * The adapter takes ownership of the injected db's tenant: every call
     * sets it and none restores it (Utopia\Database has no per-call tenant —
     * only the mutable instance tenant — and save/restore around each call is
     * its own footgun). Hand this adapter a db dedicated to usage, not one
     * whose tenant other code depends on between calls.
     *
     * @param string|null $tenant
     */
    private function setDbTenant(?string $tenant): void
    {
        if ($tenant !== null && !is_numeric($tenant)) {
            throw new \InvalidArgumentException(
                'Database adapter requires a numeric tenant ID, got: ' . $tenant
            );
        }

        $this->db->setTenant($tenant !== null ? (int) $tenant : null);
    }
}
