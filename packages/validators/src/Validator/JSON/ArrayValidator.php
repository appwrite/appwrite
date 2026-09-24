<?php

declare(strict_types=1);

namespace Utopia\Validator\JSON;

use Utopia\Validator;

/**
 * ArrayValidator
 *
 * Validate that a variable is a JSON array, either already decoded or still encoded as a string.
 *
 * Named with a suffix because `Array` is a reserved class name in PHP.
 */
class ArrayValidator extends Validator
{
    /**
     * Pass an encoded length to cap the size of accepted arrays, 0 to allow any size
     */
    public function __construct(protected int $length = 0) {}

    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        $message = 'Value must be a valid JSON array';

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
        return true;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     */
    public function getType(): string
    {
        return self::TYPE_ARRAY;
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is a JSON array, or a string encoding one.
     */
    public function isValid(mixed $value): bool
    {
        if (\is_string($value)) {
            if (!$this->hasValidLength($value)) {
                return false;
            }

            // Decoding to objects rather than associative arrays keeps `[]` and `{}`
            // distinguishable, which is impossible once both collapse to an empty array.
            $decoded = json_decode($value);

            return json_last_error() === JSON_ERROR_NONE && \is_array($decoded);
        }

        if (!\is_array($value)) {
            return false;
        }

        // An already decoded empty array is an ambiguous `[]` or `{}`, so it stays valid.
        // Encoded objects are rejected above, while their shape is still observable.
        if ($value !== [] && !array_is_list($value)) {
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
