<?php

namespace Utopia\Query\Builder;

use stdClass;
use Utopia\Query\Builder as BaseBuilder;
use Utopia\Query\Builder\Feature\FullTextSearch;
use Utopia\Query\Builder\Feature\InsertOrIgnore;
use Utopia\Query\Builder\Feature\MongoDB\ArrayPushModifiers;
use Utopia\Query\Builder\Feature\MongoDB\AtlasSearch;
use Utopia\Query\Builder\Feature\MongoDB\ConditionalArrayUpdates;
use Utopia\Query\Builder\Feature\MongoDB\FieldUpdates;
use Utopia\Query\Builder\Feature\MongoDB\PipelineStages;
use Utopia\Query\Builder\Feature\TableSampling;
use Utopia\Query\Builder\Feature\Upsert;
use Utopia\Query\Builder\MongoDB\Operation;
use Utopia\Query\Builder\MongoDB\PipelineStage;
use Utopia\Query\Builder\MongoDB\UpdateOperator;
use Utopia\Query\Exception\UnsupportedException;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Hook;
use Utopia\Query\Hook\Filter;
use Utopia\Query\Hook\Join\Filter as JoinFilter;
use Utopia\Query\Method;
use Utopia\Query\Query;

class MongoDB extends BaseBuilder implements
    Upsert,
    InsertOrIgnore,
    FullTextSearch,
    TableSampling,
    FieldUpdates,
    ArrayPushModifiers,
    ConditionalArrayUpdates,
    PipelineStages,
    AtlasSearch
{
    use Trait\MongoDB\ArrayPushModifiers;
    use Trait\MongoDB\AtlasSearch;
    use Trait\MongoDB\ConditionalArrayUpdates;
    use Trait\MongoDB\FieldUpdates;
    use Trait\MongoDB\PipelineStages;

    /**
     * Update operations keyed by UpdateOperator->value.
     * Each entry maps field-name => payload (value, modifier dict, rename target, etc.).
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $updateOperations = [];

    protected ?string $textSearchTerm = null;

    protected ?float $sampleSize = null;

    /** @var list<array<string, mixed>> */
    protected array $arrayFilters = [];

    /** @var array<string, mixed>|null */
    protected ?array $bucketStage = null;

    /** @var array<string, mixed>|null */
    protected ?array $bucketAutoStage = null;

    /** @var array<string, array{pipeline: list<array<string, mixed>>, bindings: list<mixed>}>|null */
    protected ?array $facetStages = null;

    /** @var array<string, mixed>|null */
    protected ?array $graphLookupStage = null;

    /** @var array<string, mixed>|null */
    protected ?array $mergeStage = null;

    /** @var array<string, mixed>|null */
    protected ?array $outStage = null;

    protected ?string $replaceRootExpr = null;

    /** @var array<string, mixed>|null */
    protected ?array $searchStage = null;

    /** @var array<string, mixed>|null */
    protected ?array $searchMetaStage = null;

    /** @var array<string, mixed>|null */
    protected ?array $vectorSearchStage = null;

    /** @var string|array<string, int>|null */
    protected string|array|null $indexHint = null;

    #[\Override]
    protected function quote(string $identifier): string
    {
        return $identifier;
    }

    #[\Override]
    protected function compileRandom(): string
    {
        return '$rand';
    }

    /**
     * @param array<mixed> $values
     */
    #[\Override]
    protected function compileRegex(string $attribute, array $values, ?string $column = null): string
    {
        $this->addBinding($values[0], $column);

        return $attribute . ' REGEX ?';
    }

    private function validateFieldName(string $field): void
    {
        if ($field === '' || \str_starts_with($field, '$')) {
            throw new ValidationException('Invalid MongoDB field name: ' . $field);
        }
    }

    private function setUpdateField(UpdateOperator $operator, string $field, mixed $payload): void
    {
        $this->updateOperations[$operator->value][$field] = $payload;
    }

    #[\Override]
    public function set(array $row): static
    {
        foreach (\array_keys($row) as $field) {
            $this->validateFieldName((string) $field);
        }

        return parent::set($row);
    }

    public function push(string $field, mixed $value): static
    {
        $this->validateFieldName($field);
        $this->setUpdateField(UpdateOperator::Push, $field, $value);

        return $this;
    }

    public function pull(string $field, mixed $value): static
    {
        $this->validateFieldName($field);
        $this->setUpdateField(UpdateOperator::Pull, $field, $value);

        return $this;
    }

    public function addToSet(string $field, mixed $value): static
    {
        $this->validateFieldName($field);
        $this->setUpdateField(UpdateOperator::AddToSet, $field, $value);

        return $this;
    }

    public function increment(string $field, int|float $amount = 1): static
    {
        $this->validateFieldName($field);
        $this->setUpdateField(UpdateOperator::Increment, $field, $amount);

        return $this;
    }

    public function unsetFields(string ...$fields): static
    {
        foreach ($fields as $field) {
            $this->validateFieldName($field);
            $this->setUpdateField(UpdateOperator::Unset, $field, '');
        }

        return $this;
    }

    #[\Override]
    public function filterSearch(string $attribute, string $value): static
    {
        $this->textSearchTerm = $value;

        return $this;
    }

    #[\Override]
    public function tablesample(float $percent, string $method = 'BERNOULLI'): static
    {
        $this->sampleSize = $percent;

        return $this;
    }

    /**
     * @param string|array<string, int> $hint
     */
    public function hint(string|array $hint): static
    {
        $this->indexHint = $hint;

        return $this;
    }

    #[\Override]
    public function reset(): static
    {
        parent::reset();
        $this->updateOperations = [];
        $this->textSearchTerm = null;
        $this->sampleSize = null;
        $this->arrayFilters = [];
        $this->bucketStage = null;
        $this->bucketAutoStage = null;
        $this->facetStages = null;
        $this->graphLookupStage = null;
        $this->mergeStage = null;
        $this->outStage = null;
        $this->replaceRootExpr = null;
        $this->searchStage = null;
        $this->searchMetaStage = null;
        $this->vectorSearchStage = null;
        $this->indexHint = null;

        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * Hook\Filter and Hook\Join\Filter return a {@see Condition}: a raw SQL
     * expression plus bindings. A MongoDB operation document has nowhere to put
     * one, so they are rejected rather than accepted and dropped -- silently
     * ignoring a Hook\Filter\Tenant would leave every query unscoped.
     *
     * Hook\Attribute and Hook\Write are dialect-neutral and still apply.
     */
    #[\Override]
    public function addHook(Hook $hook): static
    {
        if ($hook instanceof Filter || $hook instanceof JoinFilter) {
            throw new UnsupportedException(
                'Filter hooks are not supported on the MongoDB builder: '
                . Filter::class . ' returns a SQL expression, which has no place in an '
                . 'operation document. Express the constraint as a Query passed to filter() instead.'
            );
        }

        return parent::addHook($hook);
    }

    #[\Override]
    public function build(): Statement
    {
        $this->bindings = [];

        foreach ($this->beforeBuildCallbacks as $callback) {
            $callback($this);
        }

        $this->validateTable();

        $grouped = Query::groupByType($this->pendingQueries);

        if ($this->needsAggregation($grouped)) {
            $result = $this->buildAggregate($grouped);
        } else {
            $result = $this->buildFind($grouped);
        }

        foreach ($this->afterBuildCallbacks as $callback) {
            $result = $callback($result);
        }

        return $result;
    }

    #[\Override]
    public function insert(): Statement
    {
        $this->bindings = [];
        $this->validateTable();
        $this->validateRows('insert');

        $documents = [];
        foreach ($this->rows as $row) {
            $doc = [];
            foreach ($row as $col => $value) {
                $this->addBinding($value);
                $doc[$col] = '?';
            }
            $documents[] = $doc;
        }

        $operation = [
            'collection' => $this->table,
            'operation' => Operation::InsertMany->value,
            'documents' => $documents,
        ];

        return new Statement(
            \json_encode($operation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $this->getBindingValues(),
            executor: $this->executor,
        );
    }

    #[\Override]
    public function update(): Statement
    {
        $this->bindings = [];
        $this->validateTable();

        $grouped = Query::groupByType($this->pendingQueries);
        $filter = $this->buildFilter($grouped);

        $update = $this->buildUpdate();

        if (empty($update)) {
            throw new ValidationException('No update operations specified. Call set() before update().');
        }

        $operation = [
            'collection' => $this->table,
            'operation' => Operation::UpdateMany->value,
            'filter' => ! empty($filter) ? $filter : new stdClass(),
            'update' => $update,
        ];

        if (! empty($this->arrayFilters)) {
            $operation['options'] = ['arrayFilters' => $this->arrayFilters];
        }

        return new Statement(
            \json_encode($operation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $this->getBindingValues(),
            executor: $this->executor,
        );
    }

    #[\Override]
    public function delete(): Statement
    {
        $this->bindings = [];
        $this->validateTable();

        $grouped = Query::groupByType($this->pendingQueries);
        $filter = $this->buildFilter($grouped);

        $operation = [
            'collection' => $this->table,
            'operation' => Operation::DeleteMany->value,
            'filter' => ! empty($filter) ? $filter : new stdClass(),
        ];

        return new Statement(
            \json_encode($operation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $this->getBindingValues(),
            executor: $this->executor,
        );
    }

    public function upsert(): Statement
    {
        $this->bindings = [];
        $this->validateTable();
        $this->validateRows('upsert');

        $row = $this->rows[0];

        $filter = [];
        foreach ($this->conflictKeys as $key) {
            if (! isset($row[$key])) {
                throw new ValidationException("Conflict key '{$key}' not found in row data.");
            }
            $this->addBinding($row[$key]);
            $filter[$key] = '?';
        }

        $updateColumns = $this->conflictUpdateColumns;
        if (empty($updateColumns)) {
            $updateColumns = \array_diff(\array_keys($row), $this->conflictKeys);
        }

        $setDoc = [];
        foreach ($updateColumns as $col) {
            $this->addBinding($row[$col] ?? null);
            $setDoc[$col] = '?';
        }

        $operation = [
            'collection' => $this->table,
            'operation' => Operation::UpdateOne->value,
            'filter' => $filter,
            'update' => [UpdateOperator::Set->value => $setDoc],
            'options' => ['upsert' => true],
        ];

        return new Statement(
            \json_encode($operation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $this->getBindingValues(),
            executor: $this->executor,
        );
    }

    #[\Override]
    public function insertOrIgnore(): Statement
    {
        // Build the operation descriptor directly instead of round-tripping through
        // insert() + json_decode(): a round-trip would coerce empty stdClass values
        // (used elsewhere to encode `{}`) into `[]`.
        $this->bindings = [];
        $this->validateTable();
        $this->validateRows('insert');

        $documents = [];
        foreach ($this->rows as $row) {
            $document = [];
            foreach ($row as $column => $value) {
                $this->addBinding($value);
                $document[$column] = '?';
            }
            $documents[] = $document;
        }

        $operation = [
            'collection' => $this->table,
            'operation' => Operation::InsertMany->value,
            'documents' => $documents,
            'options' => ['ordered' => false],
        ];

        return new Statement(
            \json_encode($operation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $this->getBindingValues(),
            executor: $this->executor,
        );
    }

    private function needsAggregation(ParsedQuery $grouped): bool
    {
        if (! empty(Query::getByType($this->pendingQueries, [Method::OrderRandom], false))) {
            return true;
        }

        return $this->hasPipelineOnlyFeature($grouped)
            || $this->hasSubqueryFeature($grouped);
    }

    private function hasPipelineOnlyFeature(ParsedQuery $grouped): bool
    {
        return ! empty($grouped->aggregations)
            || ! empty($grouped->groupBy)
            || ! empty($grouped->having)
            || ! empty($this->windowSelects)
            || $grouped->distinct
            || $this->textSearchTerm !== null
            || $this->sampleSize !== null
            || $this->bucketStage !== null
            || $this->bucketAutoStage !== null
            || $this->facetStages !== null
            || $this->graphLookupStage !== null
            || $this->mergeStage !== null
            || $this->outStage !== null
            || $this->replaceRootExpr !== null
            || $this->searchStage !== null
            || $this->searchMetaStage !== null
            || $this->vectorSearchStage !== null;
    }

    private function hasSubqueryFeature(ParsedQuery $grouped): bool
    {
        return ! empty($grouped->joins)
            || ! empty($this->unions)
            || ! empty($this->ctes)
            || ! empty($this->subSelects)
            || ! empty($this->rawSelects)
            || ! empty($this->lateralJoins)
            || ! empty($this->whereInSubqueries)
            || ! empty($this->existsSubqueries);
    }

    private function buildFind(ParsedQuery $grouped): Statement
    {
        $filter = $this->buildFilter($grouped);
        $projection = $this->buildProjection($grouped);
        $sort = $this->buildSort();

        $operation = [
            'collection' => $this->table,
            'operation' => Operation::Find->value,
        ];

        if (! empty($filter)) {
            $operation['filter'] = $filter;
        }

        if (! empty($projection)) {
            $operation['projection'] = $projection;
        }

        if (! empty($sort)) {
            $operation['sort'] = $sort;
        }

        if ($grouped->offset !== null) {
            $operation['skip'] = $grouped->offset;
        }

        if ($grouped->limit !== null) {
            $operation['limit'] = $grouped->limit;
        }

        if ($this->indexHint !== null) {
            $operation['hint'] = $this->indexHint;
        }

        return new Statement(
            \json_encode($operation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $this->getBindingValues(),
            readOnly: true,
            executor: $this->executor,
        );
    }

    private function buildAggregate(ParsedQuery $grouped): Statement
    {
        $pipeline = [];

        // $searchMeta short-circuits: returns metadata only, no further stages.
        if ($this->searchMetaStage !== null) {
            $pipeline[] = [PipelineStage::SearchMeta->value => $this->searchMetaStage];

            return $this->buildAggregateStatement($pipeline);
        }

        $this->appendSearchStages($pipeline);
        $this->appendJoinStages($pipeline, $grouped);
        $this->appendFilterStages($pipeline, $grouped);
        $this->appendGroupingStages($pipeline, $grouped);
        $this->appendWindowStages($pipeline);
        $this->appendProjectionStage($pipeline, $grouped);
        $this->appendUnionStages($pipeline);
        $this->appendOrderingStages($pipeline);
        $this->appendPaginationStages($pipeline, $grouped);
        $this->appendOutputStages($pipeline);

        return $this->buildAggregateStatement($pipeline);
    }

    /**
     * @param  list<array<string, mixed>>  $pipeline
     */
    private function buildAggregateStatement(array $pipeline): Statement
    {
        $operation = [
            'collection' => $this->table,
            'operation' => Operation::Aggregate->value,
            'pipeline' => $pipeline,
        ];

        if ($this->indexHint !== null) {
            $operation['hint'] = $this->indexHint;
        }

        return new Statement(
            \json_encode($operation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $this->getBindingValues(),
            readOnly: true,
            executor: $this->executor,
        );
    }

    /**
     * Atlas $search, $vectorSearch, full-text $match, and $sample stages.
     * Atlas search stages must be first in the pipeline.
     *
     * @param  list<array<string, mixed>>  $pipeline
     */
    private function appendSearchStages(array &$pipeline): void
    {
        if ($this->searchStage !== null) {
            $pipeline[] = [PipelineStage::Search->value => $this->searchStage];
        }

        if ($this->vectorSearchStage !== null) {
            $pipeline[] = [PipelineStage::VectorSearch->value => $this->vectorSearchStage];
        }

        if ($this->textSearchTerm !== null) {
            $this->addBinding($this->textSearchTerm);
            $pipeline[] = [PipelineStage::Match->value => [PipelineStage::Text->value => ['$search' => '?']]];
        }

        if ($this->sampleSize !== null) {
            $size = (int) \ceil($this->sampleSize);
            $pipeline[] = [PipelineStage::Sample->value => ['size' => $size]];
        }
    }

    /**
     * $lookup stages for JOINs and $graphLookup for recursive traversal.
     *
     * @param  list<array<string, mixed>>  $pipeline
     */
    private function appendJoinStages(array &$pipeline, ParsedQuery $grouped): void
    {
        foreach ($grouped->joins as $joinQuery) {
            foreach ($this->buildJoinStages($joinQuery) as $stage) {
                $pipeline[] = $stage;
            }
        }

        if ($this->graphLookupStage !== null) {
            $pipeline[] = [PipelineStage::GraphLookup->value => $this->graphLookupStage];
        }
    }

    /**
     * Subquery $lookups (WHERE IN / EXISTS) and the main $match stage for WHERE filters.
     *
     * @param  list<array<string, mixed>>  $pipeline
     */
    private function appendFilterStages(array &$pipeline, ParsedQuery $grouped): void
    {
        foreach ($this->whereInSubqueries as $idx => $sub) {
            foreach ($this->buildWhereInSubquery($sub, $idx) as $stage) {
                $pipeline[] = $stage;
            }
        }

        foreach ($this->existsSubqueries as $idx => $sub) {
            foreach ($this->buildExistsSubquery($sub, $idx) as $stage) {
                $pipeline[] = $stage;
            }
        }

        $filter = $this->buildFilter($grouped);
        if (! empty($filter)) {
            $pipeline[] = [PipelineStage::Match->value => $filter];
        }
    }

    /**
     * DISTINCT, $bucket/$bucketAuto, $group (with reshape $project),
     * $replaceRoot, and HAVING $match.
     *
     * @param  list<array<string, mixed>>  $pipeline
     */
    private function appendGroupingStages(array &$pipeline, ParsedQuery $grouped): void
    {
        if ($grouped->distinct && empty($grouped->groupBy) && empty($grouped->aggregations)) {
            foreach ($this->buildDistinct($grouped) as $stage) {
                $pipeline[] = $stage;
            }
        }

        if ($this->bucketStage !== null) {
            $pipeline[] = [PipelineStage::Bucket->value => $this->bucketStage];
        } elseif ($this->bucketAutoStage !== null) {
            $pipeline[] = [PipelineStage::BucketAuto->value => $this->bucketAutoStage];
        } elseif (! empty($grouped->groupBy) || ! empty($grouped->aggregations)) {
            $pipeline[] = [PipelineStage::Group->value => $this->buildGroup($grouped)];

            $reshape = $this->buildProjectFromGroup($grouped);
            if (! empty($reshape)) {
                $pipeline[] = [PipelineStage::Project->value => $reshape];
            }
        }

        if ($this->replaceRootExpr !== null) {
            $pipeline[] = [PipelineStage::ReplaceRoot->value => ['newRoot' => $this->replaceRootExpr]];
        }

        if (! empty($grouped->having) || ! empty($this->rawHavings)) {
            $havingFilter = $this->buildHaving($grouped);
            if (! empty($havingFilter)) {
                $pipeline[] = [PipelineStage::Match->value => $havingFilter];
            }
        }
    }

    /**
     * $setWindowFields stages for window functions.
     *
     * @param  list<array<string, mixed>>  $pipeline
     */
    private function appendWindowStages(array &$pipeline): void
    {
        if (empty($this->windowSelects)) {
            return;
        }

        foreach ($this->buildWindowFunctions() as $stage) {
            $pipeline[] = $stage;
        }
    }

    /**
     * SELECT $project stage (only applies when no group/distinct/bucket stage
     * has already reshaped the document).
     *
     * @param  list<array<string, mixed>>  $pipeline
     */
    private function appendProjectionStage(array &$pipeline, ParsedQuery $grouped): void
    {
        if (! empty($grouped->groupBy) || ! empty($grouped->aggregations) || $grouped->distinct) {
            return;
        }
        if ($this->bucketStage !== null || $this->bucketAutoStage !== null) {
            return;
        }

        $projection = $this->buildProjection($grouped);
        if (empty($projection)) {
            return;
        }

        // Preserve window function output aliases in the projection.
        foreach ($this->windowSelects as $win) {
            $projection[$win->alias] = 1;
        }
        $pipeline[] = [PipelineStage::Project->value => $projection];
    }

    /**
     * $facet stage and $unionWith stages for UNIONs.
     *
     * @param  list<array<string, mixed>>  $pipeline
     */
    private function appendUnionStages(array &$pipeline): void
    {
        if ($this->facetStages !== null) {
            $facetDoc = [];
            foreach ($this->facetStages as $name => $data) {
                $facetDoc[$name] = $data['pipeline'];
                foreach ($data['bindings'] as $binding) {
                    $this->addBinding($binding);
                }
            }
            $pipeline[] = [PipelineStage::Facet->value => $facetDoc];
        }

        foreach ($this->unions as $union) {
            /** @var array<string, mixed>|null $subOp */
            $subOp = \json_decode($union->query, true);
            if ($subOp === null) {
                throw new ValidationException('Cannot parse union query for MongoDB.');
            }

            $subPipeline = $this->operationToPipeline($subOp);
            $unionWith = ['coll' => $subOp['collection']];
            if (! empty($subPipeline)) {
                $unionWith['pipeline'] = $subPipeline;
            }
            $pipeline[] = [PipelineStage::UnionWith->value => $unionWith];
            $this->addBindings($union->bindings);
        }
    }

    /**
     * ORDER BY $sort stage (including random ordering via $addFields + $unset).
     *
     * @param  list<array<string, mixed>>  $pipeline
     */
    private function appendOrderingStages(array &$pipeline): void
    {
        $hasRandomOrder = ! empty(Query::getByType($this->pendingQueries, [Method::OrderRandom], false));
        if ($hasRandomOrder) {
            $pipeline[] = [PipelineStage::AddFields->value => ['_rand' => ['$rand' => new stdClass()]]];
        }

        $sort = $this->buildSort();
        if ($hasRandomOrder) {
            $sort['_rand'] = 1;
        }
        if (! empty($sort)) {
            $pipeline[] = [PipelineStage::Sort->value => $sort];
        }

        if ($hasRandomOrder) {
            $pipeline[] = [PipelineStage::Unset->value => '_rand'];
        }
    }

    /**
     * OFFSET $skip and LIMIT $limit stages.
     *
     * @param  list<array<string, mixed>>  $pipeline
     */
    private function appendPaginationStages(array &$pipeline, ParsedQuery $grouped): void
    {
        if ($grouped->offset !== null) {
            $pipeline[] = [PipelineStage::Skip->value => $grouped->offset];
        }

        if ($grouped->limit !== null) {
            $pipeline[] = [PipelineStage::Limit->value => $grouped->limit];
        }
    }

    /**
     * Terminal $merge or $out stage (only one of the two is emitted).
     *
     * @param  list<array<string, mixed>>  $pipeline
     */
    private function appendOutputStages(array &$pipeline): void
    {
        if ($this->mergeStage !== null) {
            $pipeline[] = [PipelineStage::Merge->value => $this->mergeStage];

            return;
        }

        if ($this->outStage !== null) {
            if (isset($this->outStage['db'])) {
                $pipeline[] = [PipelineStage::Out->value => $this->outStage];
            } else {
                $pipeline[] = [PipelineStage::Out->value => $this->outStage['coll']];
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFilter(ParsedQuery $grouped): array
    {
        $conditions = [];

        foreach ($grouped->filters as $filter) {
            $conditions[] = $this->buildFilterQuery($filter);
        }

        if (\count($conditions) === 0) {
            return [];
        }

        if (\count($conditions) === 1) {
            return $conditions[0];
        }

        return ['$and' => $conditions];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFilterQuery(Query $query): array
    {
        $method = $query->getMethod();
        $attribute = $this->resolveAttribute($query->getAttribute());
        $values = $query->getValues();

        return match ($method) {
            Method::Equal => $this->buildIn($attribute, $values),
            Method::NotEqual => $this->buildNotIn($attribute, $values),
            Method::LessThan => $this->buildComparison($attribute, '$lt', $values),
            Method::LessThanEqual => $this->buildComparison($attribute, '$lte', $values),
            Method::GreaterThan => $this->buildComparison($attribute, '$gt', $values),
            Method::GreaterThanEqual => $this->buildComparison($attribute, '$gte', $values),
            Method::Between => $this->buildBetween($attribute, $values, false),
            Method::NotBetween => $this->buildBetween($attribute, $values, true),
            Method::StartsWith => $this->buildRegexFilter($attribute, $values, '^', ''),
            Method::NotStartsWith => $this->buildNotRegexFilter($attribute, $values, '^', ''),
            Method::EndsWith => $this->buildRegexFilter($attribute, $values, '', '$'),
            Method::NotEndsWith => $this->buildNotRegexFilter($attribute, $values, '', '$'),
            Method::Contains => $this->buildContains($attribute, $values),
            Method::ContainsAny => $query->onArray()
                ? $this->buildIn($attribute, $values)
                : $this->buildContains($attribute, $values),
            Method::ContainsAll => $this->buildContainsAll($attribute, $values),
            Method::NotContains => $this->buildNotContains($attribute, $values),
            Method::Regex => $this->buildUserRegex($attribute, $values),
            Method::IsNull => [$attribute => null],
            Method::IsNotNull => [$attribute => ['$ne' => null]],
            Method::And => $this->buildLogical($query, '$and'),
            Method::Or => $this->buildLogical($query, '$or'),
            Method::Exists => $this->buildFieldExists($query, true),
            Method::NotExists => $this->buildFieldExists($query, false),
            default => throw new UnsupportedException('Unsupported filter type for MongoDB: ' . $method->value),
        };
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private function buildIn(string $attribute, array $values): array
    {
        $nonNulls = [];
        $hasNull = false;

        foreach ($values as $value) {
            if ($value === null) {
                $hasNull = true;
            } else {
                $nonNulls[] = $value;
            }
        }

        if ($hasNull && empty($nonNulls)) {
            return [$attribute => null];
        }

        if (\count($nonNulls) === 1 && ! $hasNull) {
            $this->addBinding($nonNulls[0]);

            return [$attribute => '?'];
        }

        $placeholders = [];
        foreach ($nonNulls as $value) {
            $this->addBinding($value);
            $placeholders[] = '?';
        }

        if ($hasNull) {
            return ['$or' => [
                [$attribute => ['$in' => $placeholders]],
                [$attribute => null],
            ]];
        }

        return [$attribute => ['$in' => $placeholders]];
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private function buildNotIn(string $attribute, array $values): array
    {
        $nonNulls = [];
        $hasNull = false;

        foreach ($values as $value) {
            if ($value === null) {
                $hasNull = true;
            } else {
                $nonNulls[] = $value;
            }
        }

        if ($hasNull && empty($nonNulls)) {
            return [$attribute => ['$ne' => null]];
        }

        if (\count($nonNulls) === 1 && ! $hasNull) {
            $this->addBinding($nonNulls[0]);

            return [$attribute => ['$ne' => '?']];
        }

        $placeholders = [];
        foreach ($nonNulls as $value) {
            $this->addBinding($value);
            $placeholders[] = '?';
        }

        if ($hasNull) {
            return ['$and' => [
                [$attribute => ['$nin' => $placeholders]],
                [$attribute => ['$ne' => null]],
            ]];
        }

        return [$attribute => ['$nin' => $placeholders]];
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private function buildComparison(string $attribute, string $operator, array $values): array
    {
        $this->addBinding($values[0]);

        return [$attribute => [$operator => '?']];
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private function buildBetween(string $attribute, array $values, bool $not): array
    {
        $this->addBinding($values[0]);
        $this->addBinding($values[1]);

        if ($not) {
            return ['$or' => [
                [$attribute => ['$lt' => '?']],
                [$attribute => ['$gt' => '?']],
            ]];
        }

        return [$attribute => ['$gte' => '?', '$lte' => '?']];
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private function buildRegexFilter(string $attribute, array $values, string $prefix, string $suffix): array
    {
        /** @var string $rawVal */
        $rawVal = $values[0];
        $pattern = $prefix . \preg_quote($rawVal, '/') . $suffix;
        $this->addBinding($pattern);

        return [$attribute => ['$regex' => '?']];
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private function buildNotRegexFilter(string $attribute, array $values, string $prefix, string $suffix): array
    {
        /** @var string $rawVal */
        $rawVal = $values[0];
        $pattern = $prefix . \preg_quote($rawVal, '/') . $suffix;
        $this->addBinding($pattern);

        return [$attribute => ['$not' => ['$regex' => '?']]];
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private function buildContains(string $attribute, array $values): array
    {
        /** @var array<string> $values */
        if (\count($values) === 1) {
            $pattern = \preg_quote((string) $values[0], '/');
            $this->addBinding($pattern);

            return [$attribute => ['$regex' => '?']];
        }

        $conditions = [];
        foreach ($values as $value) {
            $pattern = \preg_quote((string) $value, '/');
            $this->addBinding($pattern);
            $conditions[] = [$attribute => ['$regex' => '?']];
        }

        return ['$or' => $conditions];
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private function buildContainsAll(string $attribute, array $values): array
    {
        /** @var array<string> $values */
        $conditions = [];
        foreach ($values as $value) {
            $pattern = \preg_quote((string) $value, '/');
            $this->addBinding($pattern);
            $conditions[] = [$attribute => ['$regex' => '?']];
        }

        return ['$and' => $conditions];
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private function buildNotContains(string $attribute, array $values): array
    {
        /** @var array<string> $values */
        if (\count($values) === 1) {
            $pattern = \preg_quote((string) $values[0], '/');
            $this->addBinding($pattern);

            return [$attribute => ['$not' => ['$regex' => '?']]];
        }

        $conditions = [];
        foreach ($values as $value) {
            $pattern = \preg_quote((string) $value, '/');
            $this->addBinding($pattern);
            $conditions[] = [$attribute => ['$not' => ['$regex' => '?']]];
        }

        return ['$and' => $conditions];
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private function buildUserRegex(string $attribute, array $values): array
    {
        /** @var string $rawVal */
        $rawVal = $values[0];
        $this->addBinding($rawVal);

        return [$attribute => ['$regex' => '?']];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLogical(Query $query, string $operator): array
    {
        $conditions = [];
        foreach ($query->getValues() as $subQuery) {
            /** @var Query $subQuery */
            $conditions[] = $this->buildFilterQuery($subQuery);
        }

        if (empty($conditions)) {
            return $operator === '$or' ? ['$expr' => false] : [];
        }

        return [$operator => $conditions];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFieldExists(Query $query, bool $exists): array
    {
        // SQL parity: IS NULL is true only for an explicit NULL value; IS NOT NULL is
        // true only for a non-null present value. Missing fields are neither IS NULL nor
        // IS NOT NULL under that reading.
        //
        // MongoDB:
        //   - {field: {$type: 10}} matches documents where the field EXISTS and is
        //     explicitly BSON null — mirrors SQL IS NULL.
        //   - {field: {$exists: true, $ne: null}} matches documents where the field
        //     EXISTS and is non-null — mirrors SQL IS NOT NULL.
        $conditions = [];
        foreach ($query->getValues() as $attr) {
            /** @var string $attr */
            $field = $this->resolveAttribute($attr);
            if ($exists) {
                $conditions[] = [$field => ['$exists' => true, '$ne' => null]];
            } else {
                $conditions[] = [$field => ['$type' => 10]];
            }
        }

        if (\count($conditions) === 1) {
            return $conditions[0];
        }

        return ['$and' => $conditions];
    }

    /**
     * @return array<string, int>
     */
    private function buildProjection(ParsedQuery $grouped): array
    {
        if (empty($grouped->selections)) {
            return [];
        }

        $projection = [];
        /** @var array<string> $values */
        $values = $grouped->selections[0]->getValues();

        foreach ($values as $field) {
            $resolved = $this->resolveAttribute($field);
            $projection[$resolved] = 1;
        }

        if (! isset($projection['_id'])) {
            $projection['_id'] = 0;
        }

        return $projection;
    }

    /**
     * @return array<string, int>
     */
    private function buildSort(): array
    {
        $sort = [];

        $orderQueries = Query::getByType($this->pendingQueries, [
            Method::OrderAsc,
            Method::OrderDesc,
        ], false);

        foreach ($orderQueries as $query) {
            $attr = $this->resolveAttribute($query->getAttribute());
            match ($query->getMethod()) {
                Method::OrderAsc => $sort[$attr] = 1,
                Method::OrderDesc => $sort[$attr] = -1,
                default => null,
            };
        }

        return $sort;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildGroup(ParsedQuery $grouped): array
    {
        $group = [];

        if (! empty($grouped->groupBy)) {
            if (\count($grouped->groupBy) === 1) {
                $group['_id'] = '$' . $grouped->groupBy[0];
            } else {
                $id = [];
                foreach ($grouped->groupBy as $col) {
                    $id[$col] = '$' . $col;
                }
                $group['_id'] = $id;
            }
        } else {
            $group['_id'] = null;
        }

        foreach ($grouped->aggregations as $agg) {
            $method = $agg->getMethod();
            $attr = $agg->getAttribute();
            /** @var string $alias */
            $alias = $agg->getValue('');
            if ($alias === '') {
                $alias = $method->value;
            }

            $group[$alias] = match ($method) {
                Method::Count => ['$sum' => 1],
                Method::Sum => ['$sum' => '$' . $attr],
                Method::Avg => ['$avg' => '$' . $attr],
                Method::Min => ['$min' => '$' . $attr],
                Method::Max => ['$max' => '$' . $attr],
                default => throw new UnsupportedException('Unsupported aggregation for MongoDB: ' . $method->value),
            };
        }

        return $group;
    }

    /**
     * After $group, reshape the output to flatten the _id fields back to top-level.
     *
     * @return array<string, mixed>
     */
    private function buildProjectFromGroup(ParsedQuery $grouped): array
    {
        $project = ['_id' => 0];

        if (! empty($grouped->groupBy)) {
            if (\count($grouped->groupBy) === 1) {
                $project[$grouped->groupBy[0]] = '$_id';
            } else {
                foreach ($grouped->groupBy as $col) {
                    $project[$col] = '$_id.' . $col;
                }
            }
        }

        foreach ($grouped->aggregations as $agg) {
            /** @var string $alias */
            $alias = $agg->getValue('');
            if ($alias === '') {
                $alias = $agg->getMethod()->value;
            }
            $project[$alias] = 1;
        }

        return $project;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildJoinStages(Query $joinQuery): array
    {
        $table = $joinQuery->getAttribute();
        $values = $joinQuery->getValues();
        $stages = [];

        if ($joinQuery->getMethod() === Method::CrossJoin || $joinQuery->getMethod() === Method::NaturalJoin) {
            throw new UnsupportedException(
                'Cross and natural joins cannot be expressed as a MongoDB $lookup. '
                . 'Reached via queries([Query::crossJoin(...)]); the MongoDB builder does not implement Feature\\CrossJoins.'
            );
        }

        if (empty($values)) {
            throw new ValidationException('Join query must have values.');
        }

        /** @var string $leftCol */
        $leftCol = $values[0];
        /** @var string $operator */
        $operator = $values[1] ?? '=';
        /** @var string $rightCol */
        $rightCol = $values[2];
        /** @var string $alias */
        $alias = $values[3] ?? $table;

        if ($operator !== '=') {
            throw new UnsupportedException(
                'MongoDB $lookup in localField/foreignField form only supports equality joins. '
                . "Got operator '{$operator}'. Use a pipeline-form \$lookup via a raw stage for non-equality joins."
            );
        }

        $localField = $this->stripTablePrefix($leftCol);
        $foreignField = $this->stripTablePrefix($rightCol);

        $stages[] = [PipelineStage::Lookup->value => [
            'from' => $table,
            'localField' => $localField,
            'foreignField' => $foreignField,
            'as' => $alias,
        ]];

        $isLeftJoin = $joinQuery->getMethod() === Method::LeftJoin;

        if ($isLeftJoin) {
            $stages[] = [PipelineStage::Unwind->value => ['path' => '$' . $alias, 'preserveNullAndEmptyArrays' => true]];
        } else {
            $stages[] = [PipelineStage::Unwind->value => '$' . $alias];
        }

        return $stages;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildDistinct(ParsedQuery $grouped): array
    {
        $stages = [];

        if (! empty($grouped->selections)) {
            /** @var array<string> $fields */
            $fields = $grouped->selections[0]->getValues();

            // Resolve each attribute once — attribute hooks can be O(hooks) per call.
            $resolved = [];
            foreach ($fields as $field) {
                $resolved[$field] = $this->resolveAttribute($field);
            }

            $id = [];
            foreach ($fields as $field) {
                $id[$resolved[$field]] = '$' . $resolved[$field];
            }

            $stages[] = [PipelineStage::Group->value => ['_id' => $id]];

            $project = ['_id' => 0];
            foreach ($fields as $field) {
                $project[$resolved[$field]] = '$_id.' . $resolved[$field];
            }
            $stages[] = [PipelineStage::Project->value => $project];
        }

        return $stages;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHaving(ParsedQuery $grouped): array
    {
        $conditions = [];

        if (! empty($grouped->having)) {
            foreach ($grouped->having as $havingQuery) {
                foreach ($havingQuery->getValues() as $subQuery) {
                    /** @var Query $subQuery */
                    $conditions[] = $this->buildFilterQuery($subQuery);
                }
            }
        }

        if (\count($conditions) === 0) {
            return [];
        }

        if (\count($conditions) === 1) {
            return $conditions[0];
        }

        return ['$and' => $conditions];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildWindowFunctions(): array
    {
        $stages = [];

        foreach ($this->windowSelects as $win) {
            $output = [];
            $func = \strtolower(\trim($win->function, '()'));

            $mongoFunc = match ($func) {
                'row_number' => '$documentNumber',
                'rank' => '$rank',
                'dense_rank' => '$denseRank',
                default => null,
            };

            $isRankingFunction = $mongoFunc !== null;

            if ($isRankingFunction) {
                // MongoDB's $rank/$denseRank/$documentNumber require a sortBy at runtime;
                // surface this as a build-time error for a clearer failure mode.
                if ($win->orderBy === null || $win->orderBy === []) {
                    throw new ValidationException(
                        "Window function '{$win->function}' requires an ORDER BY clause on MongoDB."
                    );
                }

                $output[$win->alias] = [$mongoFunc => new stdClass()];
            } else {
                if (\preg_match('/^(\w+)\((.+)\)$/i', $win->function, $matches)) {
                    $argument = \trim($matches[2]);

                    // Reject multi-argument window functions (e.g. COVAR(a, b)) — the
                    // localField/pipeline $setWindowFields shape only accepts a single expression.
                    if (\str_contains($argument, ',')) {
                        throw new UnsupportedException(
                            "Multi-argument window functions are not supported on MongoDB: {$win->function}"
                        );
                    }

                    $aggFunc = \strtolower($matches[1]);
                    $aggCol = $argument;
                    $mongoAggFunc = match ($aggFunc) {
                        'sum' => '$sum',
                        'avg' => '$avg',
                        'min' => '$min',
                        'max' => '$max',
                        'count' => '$sum',
                        default => throw new UnsupportedException("Unsupported window function: {$win->function}"),
                    };
                    $output[$win->alias] = [
                        $mongoAggFunc => $aggFunc === 'count' ? 1 : '$' . $aggCol,
                        'window' => ['documents' => ['unbounded', 'current']],
                    ];
                } else {
                    throw new UnsupportedException("Unsupported window function: {$win->function}");
                }
            }

            $stage = [PipelineStage::SetWindowFields->value => ['output' => $output]];

            if ($win->partitionBy !== null && $win->partitionBy !== []) {
                if (\count($win->partitionBy) === 1) {
                    $stage[PipelineStage::SetWindowFields->value]['partitionBy'] = '$' . $win->partitionBy[0];
                } else {
                    $partitionBy = [];
                    foreach ($win->partitionBy as $col) {
                        $partitionBy[$col] = '$' . $col;
                    }
                    $stage[PipelineStage::SetWindowFields->value]['partitionBy'] = $partitionBy;
                }
            }

            if ($win->orderBy !== null && $win->orderBy !== []) {
                $sortBy = [];
                foreach ($win->orderBy as $col) {
                    if (\str_starts_with($col, '-')) {
                        $sortBy[\substr($col, 1)] = -1;
                    } else {
                        $sortBy[$col] = 1;
                    }
                }
                $stage[PipelineStage::SetWindowFields->value]['sortBy'] = $sortBy;
            }

            $stages[] = $stage;
        }

        return $stages;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildWhereInSubquery(WhereInSubquery $sub, int $idx): array
    {
        $stages = [];
        $subResult = $sub->subquery->build();

        /** @var array<string, mixed>|null $subOp */
        $subOp = \json_decode($subResult->query, true);
        if ($subOp === null) {
            throw new ValidationException('Cannot parse subquery for MongoDB WHERE IN.');
        }

        $this->addBindings($subResult->bindings);

        $subCollection = $subOp['collection'] ?? '';
        $subPipeline = $this->operationToPipeline($subOp);

        $subField = $this->extractProjectionField($subPipeline);
        $lookupAlias = '_sub_' . $idx;

        $stages[] = [PipelineStage::Lookup->value => [
            'from' => $subCollection,
            'pipeline' => $subPipeline,
            'as' => $lookupAlias,
        ]];

        $stages[] = [PipelineStage::AddFields->value => [
            '_sub_ids_' . $idx => ['$map' => [
                'input' => '$' . $lookupAlias,
                'as' => 's',
                'in' => '$$s.' . $subField,
            ]],
        ]];

        $column = $this->resolveAttribute($sub->column);

        if ($sub->not) {
            $stages[] = [PipelineStage::Match->value => [
                '$expr' => ['$not' => ['$in' => ['$' . $column, '$_sub_ids_' . $idx]]],
            ]];
        } else {
            $stages[] = [PipelineStage::Match->value => [
                '$expr' => ['$in' => ['$' . $column, '$_sub_ids_' . $idx]],
            ]];
        }

        $stages[] = [PipelineStage::Unset->value => [$lookupAlias, '_sub_ids_' . $idx]];

        return $stages;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildExistsSubquery(ExistsSubquery $sub, int $idx): array
    {
        $stages = [];
        $subResult = $sub->subquery->build();

        /** @var array<string, mixed>|null $subOp */
        $subOp = \json_decode($subResult->query, true);
        if ($subOp === null) {
            throw new ValidationException('Cannot parse subquery for MongoDB EXISTS.');
        }

        $this->addBindings($subResult->bindings);

        $subCollection = $subOp['collection'] ?? '';
        $subPipeline = $this->operationToPipeline($subOp);

        // Ensure limit 1 for exists checks
        $hasLimit = false;
        foreach ($subPipeline as $stage) {
            if (isset($stage[PipelineStage::Limit->value])) {
                $hasLimit = true;
                break;
            }
        }
        if (! $hasLimit) {
            $subPipeline[] = [PipelineStage::Limit->value => 1];
        }

        $lookupAlias = '_exists_' . $idx;

        $stages[] = [PipelineStage::Lookup->value => [
            'from' => $subCollection,
            'pipeline' => $subPipeline,
            'as' => $lookupAlias,
        ]];

        if ($sub->not) {
            $stages[] = [PipelineStage::Match->value => [$lookupAlias => ['$size' => 0]]];
        } else {
            $stages[] = [PipelineStage::Match->value => [$lookupAlias => ['$ne' => []]]];
        }

        $stages[] = [PipelineStage::Unset->value => $lookupAlias];

        return $stages;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUpdate(): array
    {
        $update = [];

        if (! empty($this->rows)) {
            $setDoc = [];
            foreach ($this->rows[0] as $col => $value) {
                $this->addBinding($value);
                $setDoc[$col] = '?';
            }
            $update[UpdateOperator::Set->value] = $setDoc;
        }

        foreach ($this->updateOperations as $operatorValue => $fields) {
            if (empty($fields)) {
                continue;
            }

            $update[$operatorValue] = $this->emitUpdateOperator($operatorValue, $fields);
        }

        return $update;
    }

    /**
     * Shape a single update operator's field map into the final payload (with bindings).
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>|array<string, array<mixed>>
     */
    private function emitUpdateOperator(string $operatorValue, array $fields): array
    {
        return match ($operatorValue) {
            UpdateOperator::Push->value => $this->emitPushFields($fields),
            UpdateOperator::Pull->value,
            UpdateOperator::AddToSet->value,
            UpdateOperator::Min->value,
            UpdateOperator::Max->value => $this->emitBoundValues($fields),
            UpdateOperator::PullAll->value => $this->emitPullAllFields($fields),
            UpdateOperator::Increment->value,
            UpdateOperator::Multiply->value,
            UpdateOperator::Unset->value,
            UpdateOperator::Rename->value,
            UpdateOperator::Pop->value,
            UpdateOperator::CurrentDate->value => $fields,
            default => $fields,
        };
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function emitPushFields(array $fields): array
    {
        $pushDoc = [];
        foreach ($fields as $field => $value) {
            if (\is_array($value) && \array_key_exists('__each', $value)) {
                /** @var array{values: list<mixed>, position?: int, slice?: int, sort?: mixed} $modifier */
                $modifier = $value['__each'];
                $eachValues = [];
                foreach ($modifier['values'] as $val) {
                    $this->addBinding($val);
                    $eachValues[] = '?';
                }
                $eachDoc = ['$each' => $eachValues];
                if (isset($modifier['position'])) {
                    $eachDoc['$position'] = $modifier['position'];
                }
                if (isset($modifier['slice'])) {
                    $eachDoc['$slice'] = $modifier['slice'];
                }
                if (isset($modifier['sort'])) {
                    $eachDoc['$sort'] = $modifier['sort'];
                }
                $pushDoc[$field] = $eachDoc;
            } else {
                $this->addBinding($value);
                $pushDoc[$field] = '?';
            }
        }

        return $pushDoc;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, string>
     */
    private function emitBoundValues(array $fields): array
    {
        $doc = [];
        foreach ($fields as $field => $value) {
            $this->addBinding($value);
            $doc[$field] = '?';
        }

        return $doc;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, array<string>>
     */
    private function emitPullAllFields(array $fields): array
    {
        $doc = [];
        foreach ($fields as $field => $values) {
            /** @var array<mixed> $values */
            $placeholders = [];
            foreach ($values as $val) {
                $this->addBinding($val);
                $placeholders[] = '?';
            }
            $doc[$field] = $placeholders;
        }

        return $doc;
    }

    private function stripTablePrefix(string $field): string
    {
        $parts = \explode('.', $field);

        return \count($parts) > 1 ? $parts[\count($parts) - 1] : $field;
    }

    /**
     * Convert a MongoDB operation descriptor to a pipeline.
     * For `aggregate` operations, returns the pipeline as-is.
     * For `find` operations, converts filter/projection/sort/skip/limit to pipeline stages.
     *
     * @param array<string, mixed> $op
     * @return list<array<string, mixed>>
     */
    private function operationToPipeline(array $op): array
    {
        if (($op['operation'] ?? '') === Operation::Aggregate->value) {
            /** @var list<array<string, mixed>> */
            return $op['pipeline'] ?? [];
        }

        $pipeline = [];

        if (! empty($op['filter'])) {
            $pipeline[] = [PipelineStage::Match->value => $op['filter']];
        }
        if (! empty($op['projection'])) {
            $pipeline[] = [PipelineStage::Project->value => $op['projection']];
        }
        if (! empty($op['sort'])) {
            $pipeline[] = [PipelineStage::Sort->value => $op['sort']];
        }
        if (isset($op['skip'])) {
            $pipeline[] = [PipelineStage::Skip->value => $op['skip']];
        }
        if (isset($op['limit'])) {
            $pipeline[] = [PipelineStage::Limit->value => $op['limit']];
        }

        return $pipeline;
    }

    /**
     * Extract the first projected field name from a pipeline's $project stage.
     *
     * @param list<array<string, mixed>> $pipeline
     */
    private function extractProjectionField(array $pipeline): string
    {
        foreach ($pipeline as $stage) {
            if (isset($stage[PipelineStage::Project->value])) {
                /** @var array<string, mixed> $projection */
                $projection = $stage[PipelineStage::Project->value];
                foreach ($projection as $field => $value) {
                    if ($field !== '_id' && $value === 1) {
                        return $field;
                    }
                }
            }
        }

        return '_id';
    }
}
