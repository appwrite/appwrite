<?php

namespace Utopia\Database\Validator\Query;

use Utopia\Database\Query;
use Utopia\Validator\Numeric;
use Utopia\Validator\Range;

class Offset extends Base
{
    protected int $maxOffset;

    /**
     * @param int $maxOffset
     */
    public function __construct(int $maxOffset = PHP_INT_MAX)
    {
        $this->maxOffset = $maxOffset;
    }

    /**
     * @param Query $value
     * @return bool
     */
    public function isValid($value): bool
    {
        if (!$value instanceof Query) {
            return false;
        }

        $method = $value->getMethod();

        if ($method !== Query::TYPE_OFFSET) {
            $this->message = 'Query method invalid: ' . $method;
            return false;
        }

        $offset = $value->getValue();

        $validator = new Numeric();
        if (!$validator->isValid($offset)) {
            $this->message = 'Invalid limit: ' . $validator->getDescription();
            return false;
        }

        $validator = new Range(0, $this->maxOffset);
        if (!$validator->isValid($offset)) {
            $this->message = 'Invalid offset: ' . $validator->getDescription();
            return false;
        }

        return true;
    }

    public function getMethodType(): string
    {
        return self::METHOD_TYPE_OFFSET;
    }
}
