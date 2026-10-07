<?php

namespace Utopia\Database\Validator\Query;

use Utopia\Database\Query;
use Utopia\Validator\Numeric;
use Utopia\Validator\Range;

class Limit extends Base
{
    protected int $maxLimit;

    /**
     * Query constructor
     *
     * @param int $maxLimit
     */
    public function __construct(int $maxLimit = PHP_INT_MAX)
    {
        $this->maxLimit = $maxLimit;
    }

    /**
     * Is valid.
     *
     * Returns true if method is limit values are within range.
     *
     * @param Query $value
     * @return bool
     */
    public function isValid($value): bool
    {
        if (!$value instanceof Query) {
            return false;
        }

        if ($value->getMethod() !== Query::TYPE_LIMIT) {
            $this->message = 'Invalid query method: ' . $value->getMethod();
            return false;
        }

        $limit = $value->getValue();

        $validator = new Numeric();
        if (!$validator->isValid($limit)) {
            $this->message = 'Invalid limit: ' . $validator->getDescription();
            return false;
        }

        $validator = new Range(1, $this->maxLimit);
        if (!$validator->isValid($limit)) {
            $this->message = 'Invalid limit: ' . $validator->getDescription();
            return false;
        }

        return true;
    }

    public function getMethodType(): string
    {
        return self::METHOD_TYPE_LIMIT;
    }
}
