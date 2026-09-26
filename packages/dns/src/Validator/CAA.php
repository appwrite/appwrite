<?php

declare(strict_types=1);

namespace Utopia\DNS\Validator;

use Utopia\Validator;

class CAA extends Validator
{
    public const int CAA_FLAG_MIN = 0;

    public const int CAA_FLAG_MAX = 255;

    public const string FAILURE_REASON_INVALID_FLAGS = 'Flags must be a number between 0 and 255';

    public const string FAILURE_REASON_INVALID_TAG = 'Tag must be a non-empty string';

    public const string FAILURE_REASON_INVALID_VALUE = 'Value must be a non-empty string and must be enclosed in quotes';

    public const string FAILURE_REASON_INVALID_FORMAT = 'CAA record must be in the format <flags> <tag> "<value>"';

    public string $reason = '';

    /**
     * Check if the provided value matches the CAA record format
     */
    public function isValid(mixed $data): bool
    {
        if (!\is_string($data)) {
            $this->reason = self::FAILURE_REASON_INVALID_FORMAT;
            return false;
        }

        $parts = explode(' ', $data, 3);

        if (\count($parts) !== 3) {
            $this->reason = self::FAILURE_REASON_INVALID_FORMAT;
            return false;
        }

        $flags = $parts[0];
        $tag = $parts[1];
        $value = $parts[2];

        // Check flags is a number
        if (!is_numeric($flags)) {
            $this->reason = self::FAILURE_REASON_INVALID_FLAGS;
            return false;
        }

        $flags = (int) $flags;

        // Check flags is within the allowed range
        if ($flags < self::CAA_FLAG_MIN || $flags > self::CAA_FLAG_MAX) {
            $this->reason = self::FAILURE_REASON_INVALID_FLAGS;
            return false;
        }

        // Check tag is not empty
        if ($tag === '') {
            $this->reason = self::FAILURE_REASON_INVALID_TAG;
            return false;
        }

        // Check value is not empty and starts with " and ends with "
        if ($value === '' || $value[0] !== '"' || $value[\strlen($value) - 1] !== '"') {
            $this->reason = self::FAILURE_REASON_INVALID_VALUE;
            return false;
        }

        $value = substr($value, 1, \strlen($value) - 2);

        // Check value is not empty after removing the quotes
        if ($value === '') {
            $this->reason = self::FAILURE_REASON_INVALID_VALUE;
            return false;
        }

        // All checks passed
        return true;
    }

    public function getDescription(): string
    {
        if ($this->reason !== '' && $this->reason !== '0') {
            return $this->reason;
        }

        return self::FAILURE_REASON_INVALID_FORMAT;
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     */
    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
