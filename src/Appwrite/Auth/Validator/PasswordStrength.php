<?php

namespace Appwrite\Auth\Validator;

/**
 * PasswordStrength.
 *
 * Validates password complexity rules.
 */
class PasswordStrength extends Password
{
    protected int $minLength;
    protected bool $requireUppercase;
    protected bool $requireLowercase;
    protected bool $requireNumber;
    protected bool $requireSpecialChar;

    public function __construct(array $policy = [], bool $allowEmpty = false)
    {
        parent::__construct($allowEmpty, $policy['minLength'] ?? 8);

        $this->requireUppercase = $policy['requireUppercase'] ?? false;
        $this->requireLowercase = $policy['requireLowercase'] ?? false;
        $this->requireNumber = $policy['requireNumber'] ?? false;
        $this->requireSpecialChar = $policy['requireSpecialChar'] ?? false;
    }

    public function getDescription(): string
    {
        $requirements = [
            'between ' . $this->minLength . ' and 256 characters long',
        ];

        if ($this->requireUppercase) {
            $requirements[] = 'include an uppercase letter';
        }

        if ($this->requireLowercase) {
            $requirements[] = 'include a lowercase letter';
        }

        if ($this->requireNumber) {
            $requirements[] = 'include a number';
        }

        if ($this->requireSpecialChar) {
            $requirements[] = 'include a special character';
        }

        return 'Password must be ' . \implode(', ', $requirements) . '.';
    }

    /**
     * @param mixed $value
     */
    public function isValid($value): bool
    {
        if (!parent::isValid($value)) {
            return false;
        }

        if ($this->allowEmpty && \strlen($value) === 0) {
            return true;
        }

        if (\strlen($value) < $this->minLength) {
            return false;
        }

        if ($this->requireUppercase && !\preg_match('/[A-Z]/', $value)) {
            return false;
        }

        if ($this->requireLowercase && !\preg_match('/[a-z]/', $value)) {
            return false;
        }

        if ($this->requireNumber && !\preg_match('/\d/', $value)) {
            return false;
        }

        if ($this->requireSpecialChar && !\preg_match('/[^\p{L}\p{N}\s]/u', $value)) {
            return false;
        }

        return true;
    }
}
