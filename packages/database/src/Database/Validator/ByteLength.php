<?php

namespace Utopia\Database\Validator;

use Utopia\Validator;

/**
 * ByteLength
 *
 * Validate that a string's byte length does not exceed a maximum. Unlike the
 * character-based Text validator, this measures the actual stored size, which
 * is what byte-capacity columns (e.g. MySQL/MariaDB TEXT family) are limited by.
 */
class ByteLength extends Validator
{
    protected int $max;

    /**
     * @param int $max Maximum allowed byte length. Use 0 for unlimited.
     */
    public function __construct(int $max)
    {
        $this->max = $max;
    }

    public function getDescription(): string
    {
        return 'Value must be a valid string no longer than ' . $this->max . ' bytes';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }

    public function isValid(mixed $value): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        if ($this->max !== 0 && \strlen($value) > $this->max) {
            return false;
        }

        return true;
    }
}
