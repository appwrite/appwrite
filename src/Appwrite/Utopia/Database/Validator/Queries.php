<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Database\Document;
use Utopia\Database\Validator\Queries as ValidatorQueries;

class Queries extends ValidatorQueries
{
    /**
     * Expression constructor
     *
     * @param Document[] $attributes
     * @param Document[] $indexes
     * @param bool $strict
     */
    public function __construct($attributes, $indexes, $strict)
    {
        // Remove failed/stuck/processing indexes
        $indexes = \array_filter($indexes, function ($index) {
            return $index->getAttribute('status') === 'available';
        });

        parent::__construct($attributes, $indexes, $strict);
    }
}
