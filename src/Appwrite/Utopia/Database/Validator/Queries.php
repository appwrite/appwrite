<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Database\Document;
use Utopia\Database\Validator\Queries as ValidatorQueries;

class Queries extends ValidatorQueries
{
    /**
     * Expression constructor
     *
     * This Queries Validator that filters indexes for only available indexes
     *
     * @param QueryValidator $validator
     * @param Document[] $attributes
     * @param Document[] $indexes
     * @param bool $strict
     */
    public function __construct($validator, $attributes = [], $indexes = [], $strict = true)
    {
        // Remove failed/stuck/processing indexes
        $availableIndexes = \array_filter($indexes, function ($index) {
            return $index->getAttribute('status') === 'available';
        });

        parent::__construct($validator, $attributes, $availableIndexes, $strict);
    }
}
