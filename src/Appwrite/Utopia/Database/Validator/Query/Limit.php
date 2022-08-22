<?php

namespace Appwrite\Utopia\Database\Validator\Query;

use Utopia\Database\Query;
use Utopia\Validator\Range;
use Utopia\Validator;

class Limit extends Validator
{
    /**
     * @var string
     */
    protected $message = 'Invalid query';

    protected int $maxLimit;

    /**
     * Query constructor
     *
     * @param int $maxLimit
     */
    public function __construct(int $maxLimit = 100)
    {
        $this->maxLimit = $maxLimit;
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

        if ($method !== Query::LIMIT) {
            $this->message = 'Query method invalid: ' . $method;
            return false;
        }

        $limit = $query->getValue();
        return $this->isValidLimit($limit);
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
