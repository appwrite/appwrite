<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\QueryContext;

class Base extends Types
{
    protected Document $collection;

    protected array $types = [
        Query::TYPE_LIMIT,
        Query::TYPE_OFFSET,
        Query::TYPE_CURSOR_AFTER,
        Query::TYPE_CURSOR_BEFORE,
        Query::TYPE_ORDER_ASC,
        Query::TYPE_ORDER_DESC,
        Query::TYPE_ORDER_RANDOM,
        Query::TYPE_EQUAL,
        Query::TYPE_NOT_EQUAL,
        Query::TYPE_LESSER,
        Query::TYPE_LESSER_EQUAL,
        Query::TYPE_GREATER,
        Query::TYPE_GREATER_EQUAL,
        Query::TYPE_SEARCH,
        Query::TYPE_NOT_SEARCH,
        Query::TYPE_IS_NULL,
        Query::TYPE_IS_NOT_NULL,
        Query::TYPE_BETWEEN,
        Query::TYPE_NOT_BETWEEN,
        Query::TYPE_STARTS_WITH,
        Query::TYPE_NOT_STARTS_WITH,
        Query::TYPE_ENDS_WITH,
        Query::TYPE_NOT_ENDS_WITH,
        Query::TYPE_CONTAINS,
        Query::TYPE_NOT_CONTAINS,
        Query::TYPE_AND,
        Query::TYPE_OR,
        Query::TYPE_CROSSES,
        Query::TYPE_NOT_CROSSES,
        Query::TYPE_DISTANCE_EQUAL,
        Query::TYPE_DISTANCE_NOT_EQUAL,
        Query::TYPE_DISTANCE_GREATER_THAN,
        Query::TYPE_DISTANCE_LESS_THAN,
        Query::TYPE_INTERSECTS,
        Query::TYPE_NOT_INTERSECTS,
        Query::TYPE_OVERLAPS,
        Query::TYPE_NOT_OVERLAPS,
        Query::TYPE_TOUCHES,
        Query::TYPE_NOT_TOUCHES,
        Query::TYPE_VECTOR_DOT,
        Query::TYPE_VECTOR_COSINE,
        Query::TYPE_VECTOR_EUCLIDEAN
    ];

    /**
     * Expression constructor
     *
     * @param string $collection
     * @param string[] $allowedAttributes
     * @throws \Exception
     */
    public function __construct(string $collection, array $allowedAttributes)
    {
        $config = Config::getParam('collections', []);

        $collections = \array_merge(
            $config['projects'],
            $config['buckets'],
            $config['databases'],
            $config['console'],
            $config['logs']
        );

        $collection = $collections[$collection];

        $this->collection = new Document();
        $this->collection->setAttribute('$id', $collection['$id']);

        foreach ($collection['attributes'] as $attribute) {
            $attr = new Document([
                'key' => $attribute['$id'],
                'type' => $attribute['type'],
                'array' => $attribute['array'],
            ]);

            $this->collection->setAttribute('attributes', $attr, Document::SET_TYPE_APPEND);
        }

        $internalAttributes = [
            new Document([
                'key' => '$id',
                'type' => Database::VAR_STRING,
                'array' => false,
            ]),
            new Document([
                'key' => '$createdAt',
                'type' => Database::VAR_DATETIME,
                'array' => false,
            ]),
            new Document([
                'key' => '$updatedAt',
                'type' => Database::VAR_DATETIME,
                'array' => false,
            ]),
            new Document([
                'key' => '$sequence',
                'type' => Database::VAR_INTEGER,
                'array' => false,
            ])
        ];

        foreach ($internalAttributes as $attribute) {
            $this->collection->setAttribute('attributes', $attribute, Document::SET_TYPE_APPEND);
            $allowedAttributes[] = $attribute['key'];
        }

        if ($this->isSelectQueryAllowed()) {
            $this->types[] = Query::TYPE_SELECT;
        }

        $context = new QueryContext();
        $context->add($this->collection);

        parent::__construct($this->types, $context, $allowedAttributes);
    }

    public function isSelectQueryAllowed(): bool
    {
        return false;
    }
}
