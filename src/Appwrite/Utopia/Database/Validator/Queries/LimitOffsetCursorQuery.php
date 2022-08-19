<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Utopia\Database\Query;
use Utopia\Database\Validator\UID;

class LimitOffsetCursorQuery extends LimitOffsetQuery
{
    /**
     * @var string
     */
    protected $message = 'Invalid query';

    /**
     * Query constructor
     *
     * @param int $maxLimit
     * @param int $maxOffset
     * @param int $maxValuesCount
     */
    public function __construct(int $maxLimit = 100, int $maxOffset = 5000)
    {
        parent::__construct($maxLimit, $maxOffset);
    }

    protected function isValidCursor($cursor): bool
    {
        $validator = new UID();

        if ($validator->isValid($cursor)) {
            return true;
        }

        $this->message = 'Invalid cursor: ' . $validator->getDescription();
        return false;
    }

    /**
     * Is valid.
     *
     * Returns true if:
     * 1. method is limit or offset and values are within range
     * 2. method is cursorBefore or cursorAfter and value is not null.
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

        if ($method === Query::TYPE_CURSORAFTER || $method === Query::TYPE_CURSORBEFORE) {
            $cursor = $query->getValue();
            return $this->isValidCursor($cursor);
        }

        return parent::isValid($query);
    }
}
