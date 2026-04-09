<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Request\Filter;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;

class V22 extends Filter
{
    /**
     * @var string[]
     */
    private array $internalAttributes = [
        '$id',
        '$sequence',
        '$permissions',
        '$createdAt',
        '$updatedAt',
    ];

    public function parse(array $content, string $model): array
    {
        if (!isset($content['queries'])) {
            return $content;
        }

        return $this->convertSelectQueries($content);
    }

    private function convertSelectQueries(array $content): array
    {
        try {
            $parsed = Query::parseQueries($content['queries']);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $queries = [];
        $selects = [];

        foreach ($parsed as $query) {
            if ($query->getMethod() === 'select') {
                foreach ($query->getValues() as $val) {
                    $selects[] = $val;
                }
            } else {
                $queries[] = $query;
            }
        }

        if (!empty($selects)) {
            $queries = array_merge($queries, $this->expandSelects($selects));
        }

        // Convert all queries to string
        $resolvedQueries = [];
        foreach ($queries as $query) {
            $resolvedQueries[] = $query->toString();
        }

        $content['queries'] = $resolvedQueries;

        return $content;
    }

    private function expandSelects(array $selects): array
    {
        $expanded = [];
        $addedInternalForLevel = [];

        foreach ($selects as $select) {
            $expanded[] = Query::select($select);

            if ($select === '*') {
                continue;
            }

            $parts = explode('.', $select);
            $prefix = implode('.', array_slice($parts, 0, -1)); // empty string for top level

            if (!isset($addedInternalForLevel[$prefix])) {
                foreach ($this->internalAttributes as $attr) {
                    $expanded[] = Query::select($prefix ? "$prefix.$attr" : $attr);
                }
                $addedInternalForLevel[$prefix] = true;
            }
        }

        return $expanded;
    }
}
