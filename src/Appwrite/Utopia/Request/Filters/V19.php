<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Query as AppwriteQuery;
use Appwrite\Utopia\Request\Filter;
use Utopia\Database\Database;
use Utopia\Database\Query as UtopiaQuery;
use Utopia\Database\Validator\Authorization;

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
        $isDatabaseModel = \str_starts_with($model, 'databases.');
        $isTargetOperation = \in_array($model, ['databases.listDocuments', 'databases.getDocument']);

        if (! $isDatabaseModel || ! $isTargetOperation) {
            return $content;
        }

        $hasWildcard = false;
        if (! isset($content['queries'])) {
            $hasWildcard = true;
            $content['queries'] = [AppwriteQuery::select(['*'])];
        }

        $parsed = UtopiaQuery::parseQueries($content['queries']);
        $selections = UtopiaQuery::groupByType($parsed)['selections'] ?? [];

        if (! $hasWildcard) {
            // check if any select includes a wildcard as we added one above
            $hasWildcard = \array_reduce($selections, fn (bool $carry, $select) =>
                $carry || \in_array('*', $select->getValues(), true), false);
        }

        if ($hasWildcard && $model === 'databases.listDocuments') {
            $relatedKeys = $this->getRelatedCollectionKeys();

            if (! empty($relatedKeys)) {
                $selects = \array_values(\array_unique(\array_merge(['*'], $relatedKeys)));

                // remove previous select queries
                $parsed = \array_filter(
                    $parsed,
                    fn ($query) =>
                    $query->getMethod() !== UtopiaQuery::TYPE_SELECT
                );

                // add wildcard + relationship(s) selects
                $parsed[] = AppwriteQuery::select($selects);
            }
        }

        $content['queries'] = $parsed;

        return $content;
    }

    private function getRelatedCollectionKeys(): array
    {
        $route = $this->getRoute();
        $dbForProject = $this->getDbForProject();

        if ($dbForProject === null || $route === null) {
            return [];
        }

        $params = $route->getParamsValues();
        $databaseId = $params['databaseId'] ?? '';
        $collectionId = $params['collectionId'] ?? '';

        if (empty($databaseId) || empty($collectionId)) {
            return [];
        }

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        $collection = $dbForProject->getDocument(
            'database_' . $database->getInternalId(),
            $collectionId
        );

        $attributes = $collection->getAttribute('attributes', []);

        return \array_values(\array_map(
            fn ($attr) => $attr['key'] . '.*',
            \array_filter(
                $attributes,
                fn ($attr) =>
                ($attr['type'] ?? null) === Database::VAR_RELATIONSHIP
            )
        ));
    }
}
