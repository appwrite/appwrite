<?php

namespace Utopia\Database\Validator;

use Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Base;

class IndexedQueries extends Queries
{
    /**
     * @var array<Document>
     */
    protected array $attributes = [];

    /**
     * @var array<Document>
     */
    protected array $indexes = [];

    /**
     * Expression constructor
     *
     * This Queries Validator filters indexes for only available indexes
     *
     * @param array<Document> $attributes
     * @param array<Document> $indexes
     * @param array<Base> $validators
     * @throws Exception
     */
    public function __construct(array $attributes = [], array $indexes = [], array $validators = [])
    {
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

        foreach ($indexes as $index) {
            $this->indexes[] = $index;
        }

        parent::__construct($validators);
    }

    /**
     * Count vector queries across entire query tree
     *
     * @param array<Query> $queries
     * @return int
     */
    private function countVectorQueries(array $queries): int
    {
        $count = 0;

        foreach ($queries as $query) {
            if (in_array($query->getMethod(), Query::VECTOR_TYPES)) {
                $count++;
            }

            if ($query->isNested()) {
                $count += $this->countVectorQueries($query->getValues());
            }
        }

        return $count;
    }

    /**
     * @param mixed $value
     * @return bool
     * @throws Exception
     */
    public function isValid($value): bool
    {
        if (!parent::isValid($value)) {
            return false;
        }
        $queries = [];
        foreach ($value as $query) {
            if (! $query instanceof Query) {
                try {
                    $query = Query::parse($query);
                } catch (\Throwable $e) {
                    $this->message = 'Invalid query: '.$e->getMessage();

                    return false;
                }
            }

            if ($query->isNested()) {
                if (! self::isValid($query->getValues())) {
                    return false;
                }
            }

            $queries[] = $query;
        }

        $vectorQueryCount = $this->countVectorQueries($queries);
        if ($vectorQueryCount > 1) {
            $this->message = 'Cannot use multiple vector queries in a single request';
            return false;
        }

        $grouped = Query::groupByType($queries);
        $filters = $grouped['filters'];

        foreach ($filters as $filter) {
            if (
                $filter->getMethod() === Query::TYPE_SEARCH ||
                $filter->getMethod() === Query::TYPE_NOT_SEARCH
            ) {
                $matched = false;

                foreach ($this->indexes as $index) {
                    if (
                        $index->getAttribute('type') === Database::INDEX_FULLTEXT
                        && $index->getAttribute('attributes') === [$filter->getAttribute()]
                    ) {
                        $matched = true;
                    }
                }

                if (!$matched) {
                    $this->message = "Searching by attribute \"{$filter->getAttribute()}\" requires a fulltext index.";
                    return false;
                }
            }
        }

        return true;
    }
}
