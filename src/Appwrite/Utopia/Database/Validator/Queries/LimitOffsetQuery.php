<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Utopia\Database\Query;
use Utopia\Database\Validator\Query as QueryValidator;

class LimitOffsetQuery extends QueryValidator
{
    /**
     * @var string
     */
    protected $message = 'Invalid query';

    protected int $maxLimit;
    protected int $maxOffset;

    /**
     * Query constructor
     *
     * @param int $maxLimit
     * @param int $maxOffset
     * @param int $maxValuesCount
     */
    public function __construct(int $maxLimit = 100, int $maxOffset = 5000)
    {
        $this->maxLimit = $maxLimit;
        $this->maxOffset = $maxOffset;
    }

    /**
     * Is valid.
     *
     * Returns true if method is limit or offset and values are within range.
     * 
     * @param Query $value
     *
     * @return bool
     */
    public function isValid($query): bool
    {
        // Validate method
        $method = $query->getMethod();
        switch ($method) {
            case Query::TYPE_LIMIT:
                $limit = $query->getValue();
                return $this->isValidLimit($limit);

            case Query::TYPE_OFFSET:
                $offset = $query->getValue();
                return $this->isValidOffset($offset);

            default:
                $this->message = 'Query method invalid: ' . $method;
                return false;
        }
    }
}
