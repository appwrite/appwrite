<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;
use Utopia\Database\Database;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

class V20 extends Filter
{
    // Convert 1.7 params to 1.8
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            case 'databases.getDocument':
            case 'databases.listDocuments':
                $content = $this->manageSelectQueries($content);
                break;
        }
        return $content;
    }

    /**
     * From 1.8.x onward, related documents are no longer returned by default to improve performance.
     *
     * Use `Query::select(['related.*'])` for full documents or `Query::select(['related.key'])` for specific fields.
     *
     * This filter preserves 1.7.x behavior by including all related documents for backward compatibility with
     * `listDocuments` and `getDocument` calls.
     */
    protected function manageSelectQueries(array $content): array
    {
        $hasWildcard = false;
        if (!isset($content['queries'])) {
            $hasWildcard = true;
            // only query, make it json encoded!
            $content['queries'] = [Query::select(['*'])->toString()];
        }

        try {
            $parsed = Query::parseQueries($content['queries']);
        } catch (QueryException) {
            // don't crash!
            return $content;
        }

        $selections = Query::groupByType($parsed)['selections'] ?? [];

        // If there are no select queries at all, add wildcard
        if (empty($selections)) {
            $hasWildcard = true;
            $parsed[] = Query::select(['*']);
        } elseif (!$hasWildcard) {
            // check if any select includes a wildcard as we added one above
            foreach ($selections as $select) {
                if (\in_array('*', $select->getValues(), true)) {
                    $hasWildcard = true;
                    break;
                }
            }
        }

        /**
         * Add `keys.*` for all model types!
         */
        if ($hasWildcard) {
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

        $resolvedQueries = [];
        foreach ($parsed as $query) {
            // make em json encoded!
            $resolvedQueries[] = $query->toString();
        }

        $content['queries'] = $resolvedQueries;

        return $content;
    }

    /**
     * Returns all relationship attribute keys in `key.*` format for use with `Query::select`.
     */
    private function getRelatedCollectionKeys(
        ?string $databaseId = null,
        ?string $collectionId = null,
        ?string $prefix = null,
        int $depth = 1,
    ): array
    {
        $databaseId ??= $this->getParamValue('databaseId');
        $collectionId ??= $this->getParamValue('collectionId');

        if (
            empty($databaseId) ||
            empty($collectionId) ||
            $depth > Database::RELATION_MAX_DEPTH
        ) {
            return [];
        }

        $dbForProject = $this->getDbForProject();
        if ($dbForProject === null) {
            return [];
        }

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty()) {
            return [];
        }

        $collection = Authorization::skip(fn () => $dbForProject->getDocument(
            'database_' . $database->getSequence(),
            $collectionId
        ));
        if ($collection->isEmpty()) {
            return [];
        }

        $attributes = $collection->getAttribute('attributes', []);
        $relationshipKeys = [];

        foreach ($attributes as $attr) {
            if (($attr['type'] ?? null) !== Database::VAR_RELATIONSHIP) {
                continue;
            }

            $key = $attr['key'];
            $fullKey = $prefix ? $prefix . '.' . $key : $key;
            
            // Add the wildcard select for this relationship
            $relationshipKeys[] = $fullKey . '.*';

            // Get the related collection for nested relationships
            $relatedCollectionId = $attr['relatedCollection'] ?? null;
            
            if ($relatedCollectionId) {
                // Recursively get nested relationship keys
                $nestedKeys = $this->getRelatedCollectionKeys(
                    $databaseId,
                    $relatedCollectionId,
                    $fullKey,
                    $depth + 1,
                );
                
                $relationshipKeys = \array_merge($relationshipKeys, $nestedKeys);
            }
        }

        return \array_values(\array_unique($relationshipKeys));
    }
}
