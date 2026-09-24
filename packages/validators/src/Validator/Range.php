<?php

declare(strict_types=1);

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * Range
 *
 * Validates that an number is in range.
 */
class Range extends Numeric
{
    public function __construct(protected int|float $min, protected int|float $max, protected string $format = self::TYPE_INTEGER) {}

    /**
     * Get Range Minimum Value
     */
    public function getMin(): int|float
    {
        return $this->min;
    }

    /**
     * Get Range Maximum Value
     */
    public function getMax(): int|float
    {
        return $this->max;
    }

    /**
     * Get Range Format
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * Get Description
     *
     * Returns validator description
     */
    #[\Override]
    public function getDescription(): string
    {
        return 'Value must be a valid range between ' . number_format($this->min) . ' and ' . number_format($this->max);
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     */
    #[\Override]
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     */
    #[\Override]
    public function getType(): string
    {
        return $this->format;
    }

    /**
     * Is valid
     *
     * Validation will pass when $value number is bigger or equal than $min number and lower or equal than $max.
     * Not strict, considers any valid integer to be a valid float
     * Considers infinity to be a valid integer
     */
    #[\Override]
    public function isValid(mixed $value): bool
    {
        if (!parent::isValid($value)) {
            return false;
        }

        switch ($this->format) {
            case self::TYPE_INTEGER:
                // Accept infinity as an integer
                // Since gettype(INF) === TYPE_FLOAT
                if ($value === INF || $value === -INF) {
                    break; // move to check if value is within range
                }
                $value += 0;
                if (!\is_int($value)) {
                    return false;
                }
                break;
            case self::TYPE_FLOAT:
                if (!is_numeric($value)) {
                    return false;
                }
                $value += 0.0;
                break;
            default:
                return false;
        }
        return $this->min <= $value && $this->max >= $value;
    }
}
