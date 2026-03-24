<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\Query\Filter;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\Query\Order;
use Utopia\Database\Validator\Query\Select;

class Base extends Queries
{
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

        $allowedAttributesLookup = [];
        foreach ($allowedAttributes as $attribute) {
            $allowedAttributesLookup[$attribute] = true;
        }

        $allAttributes = [];
        $attributes = [];
        foreach ($collection['attributes'] as $attribute) {
            $key = $attribute['$id'];

            $attributeDocument = new Document([
                'key' => $key,
                'type' => $attribute['type'],
                'array' => $attribute['array'],
            ]);

            $allAttributes[] = $attributeDocument;

            if (isset($allowedAttributesLookup[$key])) {
                $attributes[] = $attributeDocument;
            }
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
            $attributes[] = $attribute;
            $allAttributes[] = $attribute;
        }

        $validators = [
            new Limit(),
            new Offset(),
            new Cursor(),
            new Filter($attributes, APP_DATABASE_QUERY_MAX_VALUES),
            new Order($attributes),
        ];

        if ($this->isSelectQueryAllowed()) {
            $validators[] = new Select($allAttributes);
        }

        parent::__construct($validators);
    }

    public function isSelectQueryAllowed(): bool
    {
        return false;
    }
}
