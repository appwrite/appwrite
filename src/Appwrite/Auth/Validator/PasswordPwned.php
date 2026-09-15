<?php

namespace Appwrite\Auth\Validator;

use Appwrite\Extend\Exception;

/**
 * Validates that a password has not been exposed in a known data breach.
 *
 * Whether to ask at all is the caller's decision, read from the project's
 * `passwordPwned` policy, the same way the personal data and history checks
 * work. Subclasses differ in who answers the question and in what leaves the
 * server, so read the one you are configuring. `_APP_PWNED_PASSWORDS_DSN` picks
 * it, and the DSN scheme matches the subclass.
 */
abstract class PasswordPwned extends Password
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
        return 'Password must not have been exposed in a known data breach.';
    }

    /**
     * Is valid.
     *
     * @param mixed $value
     *
     * @return bool
     * @throws Exception when the breach service cannot be reached
     */
    public function isValid($value): bool
    {
        if (!parent::isValid($value)) {
            return false;
        }

        if ($this->allowEmpty && \strlen($value) === 0) {
            return true;
        }

        return !$this->isPwned($value);
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

    /**
     * Whether the password appears in a known data breach.
     *
     * A password is never silently accepted when the lookup did not happen, so
     * an unreachable service raises `GENERAL_PWNED_PASSWORDS_UNAVAILABLE`.
     *
     * @throws Exception when the breach service cannot be reached or answers with nonsense
     */
    abstract protected function isPwned(string $password): bool;
}
