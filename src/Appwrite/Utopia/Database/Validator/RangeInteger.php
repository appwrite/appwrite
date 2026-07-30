<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Validator\Range;

class RangeInteger extends Range
{
    /**
     * @param int|float $min
     * @param int|float $max
     */
    public function __construct(int|float $min, int|float $max)
    {
        parent::__construct($min, $max, Range::TYPE_INTEGER);
    }

    /**
     * Is valid
     *
     * @param mixed $value
     * @return bool
     */
    public function isValid(mixed $value): bool
    {
        if (!\is_int($value)) {
            return false;
        }

        return parent::isValid($value);
    }
}
