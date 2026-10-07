<?php

namespace Appwrite\Task\Validator;

use Utopia\Validator;

class Interval extends Validator
{
    /**
     * Seconds between scheduled runs. Zero disables the interval.
     */
    public const array VALUES = [60, 300, 900, 1800, 3600, 21600, 43200, 86400];

    public function getDescription(): string
    {
        return 'Value must be 0 or one of: ' . \implode(', ', self::VALUES);
    }

    public function isValid($value): bool
    {
        if (\is_string($value) && \ctype_digit($value)) {
            $value = (int) $value;
        }

        if (!\is_int($value)) {
            return false;
        }

        return $value === 0 || \in_array($value, self::VALUES, true);
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_INTEGER;
    }
}
