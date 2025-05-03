<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

class V19 extends Filter
{
    // Convert 1.6 params to 1.7
    public function parse(array $content, string $model): array
    {
        $content = $this->manageSelectQueries($content, $model);

        return $content;
    }

    /**
     * From 1.7.x onward, related documents are no longer returned by default to improve performance.
     *
     * Use `Query::select(['related.*'])` for full documents or `Query::select(['related.key'])` for specific fields.
     *
     * This filter preserves 1.6.x behavior by including all related documents for backward compatibility with
     * `listDocuments` and `getDocument` calls.
     */
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
            $content['queries'] = [Query::select(['*'])];
        }

        $parsed = Query::parseQueries($content['queries']);
        $selections = Query::groupByType($parsed)['selections'] ?? [];

        if (! $hasWildcard) {
            // check if any select includes a wildcard as we added one above
            foreach ($selections as $select) {
                if (\in_array('*', $select->getValues(), true)) {
                    $hasWildcard = true;
                    break;
                }
            }
        }

        if ($hasWildcard && $model === 'databases.listDocuments') {
            $relatedKeys = $this->getRelatedCollectionKeys();

            if (! empty($relatedKeys)) {
                $selects = \array_values(\array_unique(\array_merge(['*'], $relatedKeys)));

                // remove previous select queries
                $parsed = \array_filter(
                    $parsed,
                    fn ($query) => $query->getMethod() !== Query::TYPE_SELECT
                );

                // add wildcard + relationship(s) selects
                $parsed[] = Query::select($selects);
            }
        }

        $content['queries'] = $parsed;

        return $content;
    }

    /**
     * Returns all relationship attribute keys in `key.*` format for use with `Query::select`.
     */
    private function getRelatedCollectionKeys(): array
    {
        $dbForProject = $this->getDbForProject();

        if ($dbForProject === null) {
            return [];
        }

        $databaseId = $this->getParamValue('databaseId');
        $collectionId = $this->getParamValue('collectionId');

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
                fn ($attr) => ($attr['type'] ?? null) === Database::VAR_RELATIONSHIP
            )
        ));
    }
}
