<?php

namespace Appwrite\Auth\Validator;

/**
 * PasswordStrength.
 *
 * Validates password complexity rules.
 */
class PasswordStrength extends Password
{
    protected int $min;
    protected bool $uppercase;
    protected bool $lowercase;
    protected bool $number;
    protected bool $symbols;

    public function __construct(array $policy = [], bool $allowEmpty = false)
    {
        parent::__construct($allowEmpty);

        $this->min = $policy['min'] ?? 8;
        $this->uppercase = $policy['uppercase'] ?? false;
        $this->lowercase = $policy['lowercase'] ?? false;
        $this->number = $policy['number'] ?? false;
        $this->symbols = $policy['symbols'] ?? false;
    }

    public function getDescription(): string
    {
        $requirements = [
            'between ' . $this->min . ' and 256 characters long',
        ];

        if ($this->uppercase) {
            $requirements[] = 'include an uppercase letter';
        }

        if ($this->lowercase) {
            $requirements[] = 'include a lowercase letter';
        }

        if ($this->number) {
            $requirements[] = 'include a number';
        }

        if ($this->symbols) {
            $requirements[] = 'include a symbol';
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

        if (\strlen($value) < $this->min) {
            return false;
        }

        if ($this->uppercase && !\preg_match('/[A-Z]/', $value)) {
            return false;
        }

        if ($this->lowercase && !\preg_match('/[a-z]/', $value)) {
            return false;
        }

        if ($this->number && !\preg_match('/\d/', $value)) {
            return false;
        }

        if ($this->symbols && !\preg_match('/[^\p{L}\p{N}\s]/u', $value)) {
            return false;
        }

        return true;
    }
}
