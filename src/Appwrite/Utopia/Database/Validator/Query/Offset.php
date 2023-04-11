<?php

namespace Appwrite\Utopia\Database\Validator\Query;

use Utopia\Database\Query;
use Utopia\Validator\Range;

class Offset extends Base
{
    protected int $maxOffset;

    /**
     * Query constructor
     *
     * @param  int  $maxOffset
     */
    public function __construct(int $maxOffset = 5000)
    {
        $this->maxOffset = $maxOffset;
    }

    protected function isValidOffset($offset): bool
    {
        $validator = new Range(0, $this->maxOffset);
        if ($validator->isValid($offset)) {
            return true;
        }

        $this->message = 'Invalid offset: '.$validator->getDescription();

        return false;
    }

    /**
     * Is valid.
     *
     * Returns true if method is offset and values are within range.
     *
     * @param  Query  $value
     * @return bool
     */
    public function isValid($query): bool
    {
        // Validate method
        $method = $query->getMethod();

        if ($method !== Query::TYPE_OFFSET) {
            $this->message = 'Query method invalid: '.$method;

            return false;
        }

        $offset = $query->getValue();

        return $this->isValidOffset($offset);
    }

    public function getMethodType(): string
    {
        return self::METHOD_TYPE_OFFSET;
    }
}
