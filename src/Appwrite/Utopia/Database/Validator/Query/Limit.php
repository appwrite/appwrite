<?php

namespace Appwrite\Utopia\Database\Validator\Query;

use Appwrite\Utopia\Database\Validator\Query\Base;
use Utopia\Database\Query;
use Utopia\Http\Validator\Range;

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

    protected function isValidLimit($limit): bool
    {
        $validator = new Range(0, $this->maxLimit);
        if ($validator->isValid($limit)) {
            return true;
        }

        $this->message = 'Invalid limit: ' . $validator->getDescription();
        return false;
    }

    /**
     * Is valid.
     *
     * Returns true if method is limit values are within range.
     *
     * @param Query $value
     *
     * @return bool
     */
    public function isValid($query): bool
    {
        // Validate method
        $method = $query->getMethod();

        if ($method !== Query::TYPE_LIMIT) {
            $this->message = 'Query method invalid: ' . $method;
            return false;
        }

        $limit = $query->getValue();
        return $this->isValidLimit($limit);
    }

    public function getMethodType(): string
    {
        return self::METHOD_TYPE_LIMIT;
    }
}
