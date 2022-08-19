<?php

namespace Appwrite\Utopia\Database\Validator;

use Appwrite\Utopia\Database\Validator\Queries;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query as QueryValidator;

class IndexedQueries extends Queries
{
    /**
     * @var Document[]
     */
    protected $attributes = [];

    /**
     * @var Document[]
     */
    protected $indexes = [];

    /**
     * Expression constructor
     *
     * This Queries Validator filters indexes for only available indexes
     *
     * @param QueryValidator $validator
     * @param Document[] $attributes
     * @param Document[] $indexes
     * @param bool $strict
     */
    public function __construct($validator, $attributes = [], $indexes = [])
    {
        // Remove failed/stuck/processing indexes
        $availableIndexes = \array_filter($indexes, function ($index) {
            return $index->getAttribute('status') === 'available';
        });

        $this->attributes = $attributes;

        $this->indexes[] = new Document([
            'type' => Database::INDEX_UNIQUE,
            'attributes' => ['$id']
        ]);

        $this->indexes[] = new Document([
            'type' => Database::INDEX_KEY,
            'attributes' => ['$createdAt']
        ]);

        $this->indexes[] = new Document([
            'type' => Database::INDEX_KEY,
            'attributes' => ['$updatedAt']
        ]);

        foreach ($indexes ?? [] as $index) {
            $this->indexes[] = $index;
        }

        parent::__construct($validator);
    }

    /**
     * Check if indexed array $indexes matches $queries
     *
     * @param array $indexes
     * @param array $queries
     *
     * @return bool
     */
    protected function arrayMatch(array $indexes, array $queries): bool
    {
        // Check the count of indexes first for performance
        if (count($queries) !== count($indexes)) {
            return false;
        }

        // Sort them for comparison, the order is not important here anymore.
        sort($indexes, SORT_STRING);
        sort($queries, SORT_STRING);

        // Only matching arrays will have equal diffs in both directions
        if (array_diff_assoc($indexes, $queries) !== array_diff_assoc($queries, $indexes)) {
            return false;
        }

        return true;
    }

    /**
     * Is valid.
     *
     * Returns false if:
     * 1. any query in $value is invalid based on $validator
     * 2. there is no index with an exact match of the filters
     * 3. there is no index with an exact match of the order attributes
     *
     * Otherwise, returns true.
     *
     * @param mixed $value
     * @return bool
     */
    public function isValid($value): bool
    {
        if (!$this->isValid($value)) {
            return false;
        }

        $queries = Query::parseQueries($value);
        $grouped = Query::groupByType($queries);
        /** @var Query[] */ $filters = $grouped['filters'];
        /** @var string[] */ $orderAttributes = $grouped['orderAttributes'];

        // Check filter queries for exact index match
        if (count($filters) > 0) {
            $filtersByAttribute = [];
            foreach ($filters as $filter) {
                $filtersByAttribute[$filter->getAttribute()] = $filter->getMethod();
            }

            $found = null;

            foreach ($this->indexes as $index) {
                if ($this->arrayMatch($index->getAttribute('attributes'), array_keys($filtersByAttribute))) {
                    $found = $index;
                }
            }

            if (!$found) {
                $this->message = 'Index not found: ' . implode(",", array_keys($filtersByAttribute));
                return false;
            }

            // search method requires fulltext index
            if (in_array(Query::TYPE_SEARCH, array_values($filtersByAttribute)) && $found['type'] !== Database::INDEX_FULLTEXT) {
                $this->message = 'Search method requires fulltext index: ' . implode(",", array_keys($filtersByAttribute));
                return false;
            }
        }

        // Check order attributes for exact index match
        $validator = new OrderAttributes($this->attributes, $this->indexes, true);
        if (count($orderAttributes) > 0 && !$validator->isValid($orderAttributes)) {
            $this->message = $validator->getDescription();
            return false;
        }

        return true;
    }
}
