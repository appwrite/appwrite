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

        if (\json_last_error() == JSON_ERROR_NONE) {
            if (\is_array($value)) {
                foreach ($value as $key => $val) {
                    // Check for invalid characters in key and value
                    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $key)) {
                        return false;
                    }
                    // Check for x-appwrite- prefix
                    if (0 === strpos($key, 'x-appwrite-')) {
                        return false;
                    }
                }
            }
        }
        return true;
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
