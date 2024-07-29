<?php

namespace Appwrite\Functions\Validator;

use Utopia\Validator;

/**
 * Header.
 *
 * Validates user provided headers
 */
class Header extends Validator
{
    protected bool $allowEmpty;

    public function __construct(bool $allowEmpty = false)
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
        return 'Header must be a string and not start with x-appwrite- prefix.';
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
        if (!\is_string($value)) {
            return false;
        }

        if (\strlen($value) > 256) {
            return false;
        }

        if (0 === strpos($value, 'x-appwrite-')) {
            return false;
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
        return true;
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
        return self::TYPE_ARRAY;
    }
}
