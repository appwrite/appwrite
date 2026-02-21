<?php

namespace Appwrite\Auth\Validator;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Utopia\Validator;

/**
 * Phone.
 *
 * Validates a number for the E.164 format.
 */
class Phone extends Validator
{
    protected bool $allowEmpty;
    protected PhoneNumberUtil $helper;

    public function __construct(bool $allowEmpty = false)
    {
        $this->allowEmpty = $allowEmpty;
        $this->helper = PhoneNumberUtil::getInstance();
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
        return "Phone number must start with a '+' can have a maximum of fifteen digits.";
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
        if (!is_string($value)) {
            return false;
        }

        if ($this->allowEmpty && \strlen($value) === 0) {
            return true;
        }

        try {
            $this->helper->parse($value);
        } catch (NumberParseException $e) {
            return false;
        }

        return !!\preg_match('/^\+[1-9]\d{6,14}$/', $value);
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
        return self::TYPE_STRING;
    }
}
