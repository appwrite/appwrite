<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Filter;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\Query\Order;

/**
 * Queries validator for list endpoints whose dataset is materialized in memory rather
 * than backed by a database collection. Pairs with {@see \Appwrite\Utopia\Database\InMemoryQuery}
 * to apply the validated queries.
 */
class BaseInMemory extends Queries
{
    /**
     * @param array<string, string> $allowedAttributes Map of attribute key to Database::VAR_* type
     */
    public function __construct(array $allowedAttributes)
    {
        $attributes = [];
        foreach ($allowedAttributes as $key => $type) {
            $attributes[] = new Document([
                'key' => $key,
                'type' => $type,
                'array' => false,
            ]);
        }

        parent::__construct([
            new Limit(),
            new Offset(),
            new Filter($attributes, Database::VAR_STRING, APP_DATABASE_QUERY_MAX_VALUES),
            new Order($attributes),
        ]);
    }
}
