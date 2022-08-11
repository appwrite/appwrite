<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Database\Document;
use Utopia\Database\Validator\Queries as ValidatorQueries;

class Queries extends ValidatorQueries
{
    /**
     * Expression constructor
     *
     * @param QueryValidator $validator
     * @param Document $collection
     * @param bool $strict
     */
    public function __construct($validator, $collection, $strict)
    {
        $filteredCollection = new Document();

        // Remove failed/stuck/processing indexes
        $indexes = \array_filter($collection->getAttribute('indexes'), function ($index) {
            return $index->getAttribute('status') === 'available';
        });

        $filteredCollection->setAttribute('indexes', $indexes);
        $filteredCollection->setAttribute('attributes', $collection->getAttribute('attributes'));

        parent::__construct($validator, $filteredCollection, $strict);
    }
}
