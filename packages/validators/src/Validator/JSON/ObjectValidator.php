<?php

declare(strict_types=1);

namespace Utopia\Validator\JSON;

use Utopia\Validator;

/**
 * ObjectValidator
 *
 * Validate that a variable is a JSON object, either already decoded or still encoded as a string.
 *
 * Named with a suffix because `Object` is a reserved class name in PHP.
 */
class ObjectValidator extends Validator
{
    /**
     * Pass an encoded length to cap the size of accepted objects, 0 to allow any size
     */
    public function __construct(protected int $length = 0) {}

    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        $message = 'Value must be a valid JSON object';

        if ($this->length > 0) {
            $message .= ' no longer than ' . $this->length . ' characters when encoded';
        }

        return $message;
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
        return self::TYPE_OBJECT;
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is a JSON object, or a string encoding one.
     */
    public function isValid(mixed $value): bool
    {
        if (\is_string($value)) {
            if (!$this->hasValidLength($value)) {
                return false;
            }

            // Decoding to objects rather than associative arrays keeps `{}` and `[]`
            // distinguishable, which is impossible once both collapse to an empty array.
            $decoded = json_decode($value);

            return json_last_error() === JSON_ERROR_NONE && $decoded instanceof \stdClass;
        }

        if ($value instanceof \stdClass) {
            return $this->hasValidLength(json_encode($value));
        }

        if (!\is_array($value)) {
            return false;
        }

        // An already decoded empty array is an ambiguous `{}` or `[]`, so it stays valid.
        // Encoded lists are rejected above, while their shape is still observable.
        if ($value !== [] && array_is_list($value)) {
            return false;
        }

        return $this->hasValidLength(json_encode($value));
    }

    private function hasValidLength(string|false $encoded): bool
    {
        if ($this->length === 0) {
            return true;
        }

        return $encoded !== false && \strlen($encoded) <= $this->length;
    }
}
