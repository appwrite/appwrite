<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Request\Filter;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;

class V28 extends Filter
{
    // Convert 2.2.0 params to 2.3.0
    public function parse(array $content, string $model): array
    {
        return match ($model) {
            'functions.listExecutions',
            'sites.listLogs' => $this->parseTriggerQueries($content),
            default => $content,
        };
    }

    /**
     * Make a `trigger` filter on `http` cover `domain` as well.
     *
     * Domain-routed executions were stored as `http` until 2.3.0, so a client
     * pinned below that format expects them back from `equal('trigger',
     * ['http'])` and expects them gone from `notEqual('trigger', 'http')`. The
     * response filter renames the value on the way out, but the query runs
     * against stored values first, so without this the rows are already gone
     * before there is anything to rename.
     *
     * `equal` takes the extra value directly. `notEqual` accepts exactly one
     * value, so it gets a second query instead, which ands with the first.
     */
    protected function parseTriggerQueries(array $content): array
    {
        if (!\is_array($content['queries'] ?? null)) {
            return $content;
        }

        try {
            $parsed = Query::parseQueries($content['queries']);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $rewritten = false;
        $additional = [];

        foreach ($parsed as $query) {
            if ($query->getAttribute() !== 'trigger') {
                continue;
            }

            $values = $query->getValues();
            if (!\in_array('http', $values, true) || \in_array('domain', $values, true)) {
                continue;
            }

            switch ($query->getMethod()) {
                case Query::TYPE_EQUAL:
                    $values[] = 'domain';
                    $query->setValues(\array_values($values));
                    $rewritten = true;
                    break;
                case Query::TYPE_NOT_EQUAL:
                    $additional[] = Query::notEqual('trigger', 'domain');
                    $rewritten = true;
                    break;
            }
        }

        if (!$rewritten) {
            return $content;
        }

        $content['queries'] = \array_map(
            fn (Query $query) => $query->toString(),
            \array_merge($parsed, $additional)
        );

        return $content;
    }
}
