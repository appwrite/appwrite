<?php

namespace Utopia\Query\Builder\Trait\PostgreSQL;

use Utopia\Query\Builder as BaseBuilder;
use Utopia\Query\Builder\MergeClause;
use Utopia\Query\Builder\PostgreSQL\MergeTarget;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Exception\ValidationException;

trait Merge
{
    #[\Override]
    public function mergeInto(string $target): static
    {
        $current = $this->mergeTarget;
        $this->mergeTarget = new MergeTarget(
            target: $target,
            source: $current === null ? null : $current->source,
            alias: $current === null ? '' : $current->alias,
            condition: $current === null ? '' : $current->condition,
            bindings: $current === null ? [] : $current->bindings,
        );

        return $this;
    }

    #[\Override]
    public function using(BaseBuilder $source, string $alias): static
    {
        $current = $this->mergeTarget;
        $this->mergeTarget = new MergeTarget(
            target: $current === null ? '' : $current->target,
            source: $source,
            alias: $alias,
            condition: $current === null ? '' : $current->condition,
            bindings: $current === null ? [] : $current->bindings,
        );

        return $this;
    }

    #[\Override]
    public function on(string $condition, mixed ...$bindings): static
    {
        $current = $this->mergeTarget;
        $this->mergeTarget = new MergeTarget(
            target: $current === null ? '' : $current->target,
            source: $current === null ? null : $current->source,
            alias: $current === null ? '' : $current->alias,
            condition: $condition,
            bindings: \array_values($bindings),
        );

        return $this;
    }

    #[\Override]
    public function whenMatched(string $action, mixed ...$bindings): static
    {
        $this->mergeClauses[] = new MergeClause($action, true, \array_values($bindings));

        return $this;
    }

    #[\Override]
    public function whenNotMatched(string $action, mixed ...$bindings): static
    {
        $this->mergeClauses[] = new MergeClause($action, false, \array_values($bindings));

        return $this;
    }

    #[\Override]
    public function executeMerge(): Statement
    {
        $merge = $this->mergeTarget;

        if ($merge === null || $merge->target === '') {
            throw new ValidationException('No merge target specified. Call mergeInto() before executeMerge().');
        }
        if ($merge->source === null) {
            throw new ValidationException('No merge source specified. Call using() before executeMerge().');
        }
        if ($merge->condition === '') {
            throw new ValidationException('No merge condition specified. Call on() before executeMerge().');
        }

        $this->bindings = [];

        $sourceResult = $merge->source->build();
        $this->addBindings($sourceResult->bindings);

        $sql = 'MERGE INTO ' . $this->quote($merge->target)
            . ' USING (' . $sourceResult->query . ') AS ' . $this->quote($merge->alias)
            . ' ON ' . $merge->condition;

        foreach ($merge->bindings as $binding) {
            $this->addBinding($binding);
        }

        foreach ($this->mergeClauses as $clause) {
            $keyword = $clause->matched ? 'WHEN MATCHED THEN' : 'WHEN NOT MATCHED THEN';
            $sql .= ' ' . $keyword . ' ' . $clause->action;
            $this->addBindings($clause->bindings);
        }

        return new Statement($sql, $this->getBindingValues(), executor: $this->executor);
    }
}
