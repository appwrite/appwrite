<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Utopia\Database\Query;
use Utopia\Validator\Range;
use Utopia\Validator;

class LimitOffsetQuery extends Validator
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
     * Get Description.
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->message;
    }

    protected function isValidLimit($limit): bool
    {
        $validator = new Range(0, $this->maxLimit);
        if ($validator->isValid($limit)) {
            return true;
        }

        $this->message = 'Invalid limit: ' . $validator->getDescription();
        return false;
    }

    protected function isValidOffset($offset): bool
    {
        $validator = new Range(0, $this->maxOffset);
        if ($validator->isValid($offset)) {
            return true;
        }

        $this->message = 'Invalid offset: ' . $validator->getDescription();
        return false;
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

    /**
     * Is array
     *
     * Function will return true if object is array.
     *
     * @return bool
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_OBJECT;
    }
}
