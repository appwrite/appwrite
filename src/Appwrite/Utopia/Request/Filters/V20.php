<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Request\Filter;
use Utopia\Database\Database;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;

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
        if (!isset($content['queries'])) {
            $content['queries'] = [];
        }

        // Handle case where queries is an array but empty
        if (\is_array($content['queries'])) {
            $content['queries'] = \array_filter($content['queries'], function ($q) {
                if (\is_object($q) && empty((array)$q)) {
                    return false;
                }
                if (\is_string($q) && \trim($q) === '') {
                    return false;
                }
                if (empty($q)) {
                    return false;
                }
                return true;
            });
        }

        try {
            $parsed = Query::parseQueries($content['queries']);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $selections = Query::groupByType($parsed)['selections'] ?? [];

        // Check if we need to add wildcard + relationships
        // This happens when:
        // 1. No select queries exist, OR
        // 2. A wildcard select exists
        $needsRelationships = empty($selections);
        if (!$needsRelationships) {
            foreach ($selections as $select) {
                if (\in_array('*', $select->getValues(), true)) {
                    $needsRelationships = true;
                    break;
                }
            }
        }

        /**
         * Add wildcard and relationship selects for backward compatibility
         */
        if ($needsRelationships) {
            $relatedKeys = $this->getRelatedCollectionKeys();
            $selects = \array_values(\array_unique(\array_merge(['*'], $relatedKeys)));

            // Remove any existing select queries
            $parsed = \array_filter(
                $parsed,
                fn ($query) => $query->getMethod() !== Query::TYPE_SELECT
            );

            // Add wildcard + relationship(s) selects
            $parsed[] = Query::select($selects);
        }

        $resolvedQueries = [];
        foreach ($parsed as $query) {
            $resolvedQueries[] = $query->toString();
        }

        $content['queries'] = $resolvedQueries;

        return $content;
    }

    /**
     * Returns all relationship attribute keys in `key.*` format for use with `Query::select`.
     * Recursively includes nested relationships up to 3 levels deep.
     * Prevents infinite loops by tracking all visited collections in the current path.
     */
    private function getRelatedCollectionKeys(
        ?string $databaseId = null,
        ?string $collectionId = null,
        ?string $prefix = null,
        int $depth = 1,
        array $visited = []
    ): array {
        $databaseId ??= $this->getParamValue('databaseId');
        $collectionId ??= $this->getParamValue('collectionId');

        if (
            empty($databaseId) ||
            empty($collectionId) ||
            $depth > Database::RELATION_MAX_DEPTH
        ) {
            return [];
        }

        // Check if we've already visited this collection in the current path to prevent cycles
        if (in_array($collectionId, $visited)) {
            return [];
        }

        $visited[] = $collectionId;

        $dbForProject = $this->getDbForProject();
        if ($dbForProject === null) {
            return [];
        }

        try {
            $database = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument(
                'databases',
                $databaseId
            ));
            if ($database->isEmpty()) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        try {
            $collection = $database = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument(
                'database_' . $database->getSequence(),
                $collectionId
            ));
            if ($collection->isEmpty()) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        $attributes = $collection->getAttribute('attributes', []);
        $relationshipKeys = [];

        foreach ($attributes as $attr) {
            if (
                ($attr['type'] ?? null) !== Database::VAR_RELATIONSHIP ||
                $attr['status'] !== 'available'
            ) {
                continue;
            }

            $key = $attr['key'];
            $fullKey = $prefix ? $prefix . '.' . $key : $key;
            $relatedCollectionId = $attr['relatedCollection'] ?? null;

            // Skip this relationship entirely if it points to an already visited collection
            if ($relatedCollectionId && in_array($relatedCollectionId, $visited)) {
                continue;
            }

            // Add the wildcard select for this relationship
            $relationshipKeys[] = $fullKey . '.*';

            // Continue recursively if we have a related collection
            if ($relatedCollectionId) {
                $nestedKeys = $this->getRelatedCollectionKeys(
                    $databaseId,
                    $relatedCollectionId,
                    $fullKey,
                    $depth + 1,
                    $visited
                );

                $relationshipKeys = \array_merge($relationshipKeys, $nestedKeys);
            }
        }

        return \array_values(\array_unique($relationshipKeys));
    }
}
