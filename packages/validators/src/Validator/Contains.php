<?php

declare(strict_types=1);

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * Contains
 *
 * Validate that a string contains at least one of the predefined substrings.
 */
class Contains extends Validator
{
    protected array $patterns;

    /**
     * Constructor
     *
     * Sets a list of substrings to search for and strict mode.
     *
     * @param  bool  $strict enable case-sensitive matching
     */
    public function __construct(array $patterns, protected bool $strict = false)
    {
        if ($patterns === []) {
            throw new \InvalidArgumentException('Patterns array cannot be empty');
        }

        $this->patterns = $patterns;
    }

    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        $message = 'Value must contain one of (' . implode(', ', $this->patterns) . ')';

        if ($this->strict) {
            $message .= ' (case-sensitive)';
        } else {
            $message .= ' (case-insensitive)';
        }

        return $message;
    }

    /**
     * Is valid
     *
     * Validation will pass when $value contains at least one of the patterns.
     *
     * @param  mixed  $value
     */
    public function isValid($value): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        if (!$this->strict) {
            $value = mb_strtolower($value, 'UTF-8');
        }

        foreach ($this->patterns as $pattern) {
            $pattern = $this->strict ? $pattern : mb_strtolower((string) $pattern, 'UTF-8');

            if (str_contains($value, (string) $pattern)) {
                return true;
            }
        }

        return false;
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
}
