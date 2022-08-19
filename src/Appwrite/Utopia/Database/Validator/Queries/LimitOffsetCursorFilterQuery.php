<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\LimitOffsetCursorQuery;
use Utopia\Database\Database;
use Utopia\Database\Query;

class LimitOffsetCursorFilterQuery extends LimitOffsetCursorQuery
{
    /**
     * @var string
     */
    protected $message = 'Invalid query';

    /**
     * @var array
     */
    protected $schema = [];

    /**
     * Query constructor
     *
     * @param int $maxLimit
     * @param int $maxOffset
     * @param int $maxValuesCount
     */
    public function __construct(int $maxLimit = 100, int $maxOffset = 5000, array $attributes = [], int $maxValuesCount = 100)
    {
        foreach ($attributes as $attribute) {
            $this->schema[$attribute->getAttribute('key')] = $attribute->getArrayCopy();
        }

        $this->maxValuesCount = $maxValuesCount;

        parent::__construct($maxLimit, $maxOffset);
    }

    protected function isValidAttribute($attribute): bool
    {
        // Search for attribute in schema
        if (!isset($this->schema[$attribute])) {
            $this->message = 'Attribute not found in schema: ' . $attribute;
            return false;
        }

        return true;
    }

    protected function isValidAttributeAndValues(string $attribute, array $values): bool
    {
        if (!$this->isValidAttribute($attribute)) {
            return false;
        }

        $attributeSchema = $this->schema[$attribute];

        if (count($values) > $this->maxValuesCount) {
            $this->message = 'Query on attribute has greater than ' . $this->maxValuesCount . ' values: ' . $attribute;
            return false;
        }

        // Extract the type of desired attribute from collection $schema
        $attributeType = $attributeSchema['type'];

        foreach ($values as $value) {
            $condition = match ($attributeType) {
                Database::VAR_DATETIME => gettype($value) === Database::VAR_STRING,
                default => gettype($value) === $attributeType
            };

            if (!$condition) {
                $this->message = 'Query type does not match expected: ' . $attributeType;
                return false;
            }
        }

        return true;
    }

    protected function isValidContains(string $attribute, array $values): bool
    {
        if (!$this->isValidAttributeAndValues($attribute, $values)) {
            return false;
        }

        $attributeSchema = $this->schema[$attribute];

        // Contains method only supports array attributes
        if (!$attributeSchema['array']) {
            $this->message = 'Query method only supported on array attributes: ' . Query::TYPE_CONTAINS;
            return false;
        }

        return true;
    }

    /**
     * Is valid.
     *
     * Returns true if:
     * 1. method is limit or offset and values are within range
     * 2. method is cursorBefore or cursorAfter and value is not null
     * 3. method is a filter method, attribute exists, and value matches attribute type
     *
     * Otherwise, returns false
     *
     * @param Query $value
     *
     * @return bool
     */
    public function isValid($query): bool
    {
        // Validate method
        $method = $query->getMethod();
        $attribute = $query->getAttribute();

        switch ($method) {
            case Query::TYPE_CONTAINS:
                $values = $query->getValues();
                return $this->isValidContains($attribute, $values);

            case Query::TYPE_EQUAL:
            case Query::TYPE_NOTEQUAL:
            case Query::TYPE_LESSER:
            case Query::TYPE_LESSEREQUAL:
            case Query::TYPE_GREATER:
            case Query::TYPE_GREATEREQUAL:
            case Query::TYPE_SEARCH:
                $values = $query->getValues();
                return $this->isValidAttributeAndValues($attribute, $values);

            default:
                return parent::isValid($query);
        }
    }
}
