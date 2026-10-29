<?php

namespace Appwrite\Alert;

use Utopia\Database\Query;

/**
 * A usage monitor's rules: query strings that must all hold for it to fire. In
 * this version that is one comparison of a metric with a threshold, optionally
 * scoped to a resource with `equal('resourceType', …)` and `equal('resourceId', …)`,
 * which filter the usage the metric is read from as the usage endpoint's queries
 * do. Without a scope the monitor watches the whole project.
 */
readonly class Rules
{
    public const string RESOURCE_TYPE = 'resourceType';
    public const string RESOURCE_ID = 'resourceId';

    /**
     * @param list<string> $rules the rules, normalized to their JSON form
     */
    public function __construct(
        public array $rules,
        public string $metric,
        public Comparator $comparator,
        public float $threshold,
        public string $resourceType,
        public string $resourceId,
    ) {
    }

    /**
     * @param array<string> $queries
     * @param array{resources?: array<string, mixed>, metrics?: array<string, array<string, mixed>>} $registry the metric registry in `app/config/alerts.php`
     * @throws \InvalidArgumentException naming the rule that is not accepted and why
     */
    public static function parse(array $queries, array $registry): self
    {
        $metrics = $registry['metrics'] ?? [];
        $resources = $registry['resources'] ?? [];

        $comparison = null;
        $scope = [];
        foreach ($queries as $index => $query) {
            try {
                $query = Query::parse($query);
            } catch (\Throwable $th) {
                throw new \InvalidArgumentException("Rule {$index} is not a valid query: {$th->getMessage()}");
            }

            $method = $query->getMethod()->value;
            $attribute = $query->getAttribute();
            $values = $query->getValues();

            if ($attribute === self::RESOURCE_TYPE || $attribute === self::RESOURCE_ID) {
                if ($method !== 'equal' || \count($values) !== 1 || !\is_string($values[0]) || $values[0] === '') {
                    throw new \InvalidArgumentException("Rule {$index} must be equal('{$attribute}', [<value>]) with one value.");
                }
                if (isset($scope[$attribute])) {
                    throw new \InvalidArgumentException("Only one rule may set '{$attribute}'.");
                }
                $scope[$attribute] = $values[0];
                continue;
            }

            $comparator = Comparator::tryFrom($method);
            if ($comparator === null) {
                throw new \InvalidArgumentException("Rule {$index} uses '{$method}'. A metric is compared with " . \implode(', ', Comparator::values()) . '.');
            }
            if (!isset($metrics[$attribute])) {
                throw new \InvalidArgumentException("Rule {$index} compares '{$attribute}', which is not a metric. Metrics: " . \implode(', ', \array_keys($metrics)) . '.');
            }
            if (\count($values) !== 1 || !\is_int($values[0]) && !\is_float($values[0])) {
                throw new \InvalidArgumentException("Rule {$index} must compare '{$attribute}' with one number.");
            }
            if ($comparison !== null) {
                throw new \InvalidArgumentException('A monitor takes one metric comparison.');
            }
            $comparison = [$attribute, $comparator, $values[0]];
        }

        if ($comparison === null) {
            throw new \InvalidArgumentException('A monitor needs one rule comparing a metric with a threshold, such as greaterThan(\'bandwidth.outbound\', [5000]).');
        }

        [$metric, $comparator, $threshold] = $comparison;
        $resourceType = $scope[self::RESOURCE_TYPE] ?? 'project';
        $resourceId = $scope[self::RESOURCE_ID] ?? '';

        if (!isset($resources[$resourceType])) {
            throw new \InvalidArgumentException("Resource type '{$resourceType}' cannot be monitored. Resource types: " . \implode(', ', \array_keys($resources)) . '.');
        }
        if ($resourceId !== '' && !isset($scope[self::RESOURCE_TYPE])) {
            throw new \InvalidArgumentException("A rule on 'resourceId' needs a rule on 'resourceType'.");
        }
        if (!\in_array($resourceType, $metrics[$metric]['resources'] ?? [], true)) {
            throw new \InvalidArgumentException("Metric '{$metric}' is not recorded for resource type '{$resourceType}'.");
        }

        $query = match ($comparator) {
            Comparator::GreaterThan => Query::greaterThan($metric, $threshold),
            Comparator::GreaterThanEqual => Query::greaterThanEqual($metric, $threshold),
            Comparator::LessThan => Query::lessThan($metric, $threshold),
            Comparator::LessThanEqual => Query::lessThanEqual($metric, $threshold),
        };
        $rules = [$query->toString()];
        foreach ([self::RESOURCE_TYPE, self::RESOURCE_ID] as $attribute) {
            if (isset($scope[$attribute])) {
                $rules[] = Query::equal($attribute, [$scope[$attribute]])->toString();
            }
        }

        return new self($rules, $metric, $comparator, (float) $threshold, $resourceType, $resourceId);
    }
}
