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
        return 'Header keys must be a string and not start with x-appwrite- prefix.';
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

        if (\is_string($value)) {
            $decoded = \json_decode($value, true);

            if (\json_last_error() == JSON_ERROR_NONE) {
                if (\is_array($decoded)) {
                    foreach ($decoded as $key => $val) {
                        if (0 === strpos($key, 'x-appwrite-')) {
                            return false;
                        }
                    }
                }
            }
            return \json_last_error() == JSON_ERROR_NONE;
        }

        return false;
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
