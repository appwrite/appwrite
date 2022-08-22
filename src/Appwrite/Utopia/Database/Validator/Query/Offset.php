<?php

namespace Appwrite\Utopia\Database\Validator\Query;

use Utopia\Database\Query;
use Utopia\Validator\Range;
use Utopia\Validator;

class Offset extends Validator
{
    /**
     * @var string
     */
    protected $message = 'Invalid query';

    protected int $maxOffset;

    /**
     * Query constructor
     *
     * @param int $maxOffset
     */
    public function __construct(int $maxOffset = 5000)
    {
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
     * Returns true if method is offset and values are within range.
     *
     * @param Query $value
     *
     * @return bool
     */
    public function isValid($query): bool
    {
        // Validate method
        $method = $query->getMethod();
        
        if ($method !== Query::TYPE_OFFSET) {
            $this->message = 'Query method invalid: ' . $method;
            return false;
        }

        $offset = $query->getValue();
        return $this->isValidOffset($offset);
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
