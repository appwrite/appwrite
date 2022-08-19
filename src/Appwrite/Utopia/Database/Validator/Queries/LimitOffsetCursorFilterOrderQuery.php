<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\LimitOffsetCursorFilterQuery;
use Utopia\Database\Query;

class LimitOffsetCursorFilterOrderQuery extends LimitOffsetCursorFilterQuery
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

    /**
     * Is valid.
     *
     * Returns true if:
     * 1. method is limit or offset and values are within range
     * 2. method is cursorBefore or cursorAfter and value is not null
     * 3. method is a filter method, attribute exists, and value matches attribute type
     * 4. method is orderAsc or orderDesc and attribute exists or is empty string
     *
     * Otherwise, returns false
     *
     * @param Query $value
     *
     * @return bool
     */
    public function isValid($query): bool
    {
        $method = $query->getMethod();
        $attribute = $query->getAttribute();

        if ($method === Query::TYPE_ORDERASC || $method === Query::TYPE_ORDERDESC) {
            if ($attribute === '') {
                return true;
            }
            return $this->isValidAttribute($attribute);
        }

        return parent::isValid($query);
    }
}
