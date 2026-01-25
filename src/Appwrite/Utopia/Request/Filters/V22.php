<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Request\Filter;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;

class V22 extends Filter
{
    public function parse(array $content, string $model): array
    {
        if (isset($content['queries'])) {
            $content = $this->convertSelectQueries($content);
        }

        return $content;
    }

    private function convertSelectQueries(array $content): array
    {
        if (!isset($content['queries'])) {
            return $content;
        }

        try {
            $parsed = Query::parseQueries($content['queries']);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $queries = [];
        $internals = false;
        $values = [];

        foreach ($parsed as $query) {
            try {
                if ($query->getMethod() === 'select') {
                    foreach ($query->getValues() as $select) {
                        $queries[] = Query::select($select);
                        $values[] = $select;
                    }
                } else {
                    $queries[] = $query;
                }

            } catch (\Throwable $th) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $th->getMessage());
            }
        }

        if (count($values)) {
            $queries[] = Query::select('$sequence');
            $queries[] = Query::select('$id');
            $queries[] = Query::select('$updatedAt');
            $queries[] = Query::select('$createdAt');
            $queries[] = Query::select('$permissions');
        }

        $resolvedQueries = [];

        foreach ($queries as $query) {
            $resolvedQueries[] = $query->toString();
        }

        $content['queries'] = $resolvedQueries;

        return $content;
    }
}
