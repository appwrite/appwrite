<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Queries as QueriesValidator;
use Utopia\Database\Validator\Query as QueryValidator;

class Collection extends QueriesValidator
{
    /**
     * Expression constructor
     *
     * @param string $collection
     * @param string[] $allowedAttributes
     */
    public function __construct(string $collection, array $allowedAttributes)
    {
        $collection = Config::getParam('collections', [])[$collection];
        // array for constant lookup time
        $allowedAttributesLookup = [];
        foreach ($allowedAttributes as $attribute) {
            $allowedAttributesLookup[$attribute] = true;
        }

        $attributes = [];
        foreach ($collection['attributes'] as $attribute) {
            $key = $attribute['$id'];
            if (!isset($allowedAttributesLookup[$key])) {
                continue;
            }

            $attributes[] = new Document([
                'key' => $key,
                'type' => $attribute['type'],
                'array' => $attribute['array'],
            ]);
        }

        $indexes = [];
        foreach ($allowedAttributes as $attribute) {
            $indexes[] = new Document([
                'status' => 'available',
                'type' => Database::INDEX_KEY,
                'attributes' => [$attribute]
            ]);
        }
        $indexes[] = new Document([
            'status' => 'available',
            'type' => Database::INDEX_FULLTEXT,
            'attributes' => ['search']
        ]);

        parent::__construct(new QueryValidator($attributes), $attributes, $indexes, true);
    }
}