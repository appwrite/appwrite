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
        var_dump('=========================');
        var_dump($model);

        if (isset($content['queries'])){
            var_dump('=== queries ===');
            var_dump($content['queries']);
            $content = $this->convertSelectQueries($content);
        }

        var_dump('=========================');
        return $content;
    }

    private function convertSelectQueries(array $content): array
    {
        var_dump('convertQueries');

        if (!isset($content['queries'])) {
            return $content;
        }

        try {
            $parsed = Query::parseQueries($content['queries']);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $queries = [];

        foreach ($parsed as $query) {
            try {
                if ($query->getMethod() === 'select') {
                    foreach ($query->getValues() as $select) {
                        $queries[] = Query::select($select);
                    }
                } else {
                    $queries[] = $query;
                }

            } catch (\Throwable $th) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $th->getMessage());
            }
        }

        $resolvedQueries = [];

        foreach ($queries as $query) {
            $resolvedQueries[] = $query->toString();
        }

        $content['queries'] = $resolvedQueries;

        return $content;
    }
}
