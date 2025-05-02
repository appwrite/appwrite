<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Query as AppwriteQuery;
use Appwrite\Utopia\Request\Filter;
use Utopia\Database\Query as UtopiaQuery;

class V19 extends Filter
{
    // Convert 1.6 params to 1.7
    public function parse(array $content, string $model): array
    {
        $content = $this->manageSelectQueries($content, $model);

        return $content;
    }

    protected function manageSelectQueries(array $content, string $model): array
    {
        if (!str_contains($model, 'databases.')) {
            return $content;
        }

        if ($model !== 'databases.listDocuments' && $model !== 'databases.getDocument') {
            return $content;
        }

        if (!isset($content['queries'])) {
            $content['queries'] = [AppwriteQuery::select(['*'])];
            return $content;
        }

        $parsed = UtopiaQuery::parseQueries($content['queries']);
        $selections = UtopiaQuery::groupByType($parsed)['selections'] ?? [];

        if (empty($selections)) {
            $parsed[] = AppwriteQuery::select(['*']);
            $content['queries'] = $parsed;
        }

        return $content;
    }
}
