<?php

declare(strict_types=1);

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * Phone
 *
 * Validate that a variable is a valid E.164 phone number.
 */
class Phone extends Validator
{
    public function __construct(protected bool $allowEmpty = false, protected bool $normalize = false) {}

    /**
     * Recover E.164 phone numbers from URL/path transport damage.
     *
     * Path routers may leave a leading '+' percent-encoded as `%2B`, and some
     * HTTP stacks decode a bare '+' into a space. Some clients may also omit
     * the leading '+' when placing the number in a URL path.
     */
    public static function normalize(string $value): string
    {
        $value = rawurldecode($value);

        if (preg_match('/^ [1-9]\d{6,14}$/', $value) === 1) {
            return '+' . substr($value, 1);
        }

        if ($value !== '' && !str_starts_with($value, '+') && preg_match('/^[1-9]\d{6,14}$/', $value) === 1) {
            return '+' . $value;
        }

        return $value;
    }

    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        return "Phone number must start with a '+' and contain between 7 and 15 digits.";
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

    /**
     * Is valid
     *
     * Validation will pass when $value is a valid E.164 phone number.
     */
    public function isValid(mixed $value): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        if ($this->allowEmpty && $value === '') {
            return true;
        }

        if ($this->normalize) {
            $value = self::normalize($value);
        }

        return preg_match('/^\+[1-9]\d{6,14}$/', $value) === 1;
    }
}
