<?php

namespace Appwrite\Functions\Validator;

use Utopia\Validator;

/**
 * Headers.
 *
 * Validates user provided headers
 */
class Headers extends Validator
{
    protected bool $allowEmpty;

    public function __construct(bool $allowEmpty = true)
    {
        $this->allowEmpty = $allowEmpty;
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
        return 'Invalid header format. Header keys can only contain alphanumeric characters, underscores, and hyphens. Header keys cannot start with "x-appwrite-" prefix.';
    }

    /**
     * Is valid.
     *
     * @param mixed $value
     *
     * @return bool
     */
    public function isValid($value): bool
    {
        if ($this->allowEmpty && empty($value)) {
            return true;
        }

        if (\is_string($value)) {
            $value = \json_decode($value, true);
        }

        if (!\is_array($value)) {
            return false;
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        } else {
            if (\is_array($value)) {
                foreach ($value as $key => $val) {
                    // Reject non-string keys
                    if (!\is_string($key) || \strlen($key) === 0) {
                        return false;
                    }

                    // Check if the key is a single character and ensure it is an alphabetic character
                    if (\strlen($key) === 1 && !preg_match('/^[a-zA-Z]$/', $key)) {
                        return false;
                    }

                    // Check for invalid characters in keys longer than one character
                    if (\strlen($key) > 1 && !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]*[a-zA-Z0-9]$/', $key)) {
                        return false;
                    }
                    // Check for x-appwrite- prefix
                    if (str_starts_with($key, 'x-appwrite-')) {
                        return false;
                    }
                }
            }
            return true;
        }
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
