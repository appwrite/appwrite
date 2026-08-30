<?php

namespace Appwrite\Auth\Validator;

use Utopia\Messaging\Adapter\SMS\GEOSMS\CallingCode;
use Utopia\Validator\Phone as UtopiaPhone;

/**
 * Phone.
 *
 * Validates a number for the E.164 format.
 */
class Phone extends UtopiaPhone
{
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
    public function isValid(mixed $value): bool
    {
        if (!parent::isValid($value)) {
            return false;
        }

        if ($this->allowEmpty && $value === '') {
            return true;
        }

        $value = $this->normalize ? self::normalize($value) : $value;

        return CallingCode::fromPhoneNumber($value) !== null;
    }
}
