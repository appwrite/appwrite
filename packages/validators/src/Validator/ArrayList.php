<?php

declare(strict_types=1);

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * ArrayList
 *
 * Validate that an variable is a valid array value and each element passes given validation
 */
class ArrayList extends Validator
{
    /**
     * Array constructor.
     *
     * Pass a validator that must be applied to each element in this array
     */
    public function __construct(protected Validator $validator, protected int $length = 0) {}

    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        $msg = 'Value must a valid array';

        if ($this->length > 0) {
            $msg .= ' no longer than ' . $this->length . ' items';
        }

        if (!\in_array($this->validator->getDescription(), ['', '0'], true)) {
            $msg .= ' and ' . $this->validator->getDescription();
        }

        return $msg;
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
        return $this->validator->getType();
    }

    /**
     * Get Nested Validator
     */
    public function getValidator(): Validator
    {
        return $this->validator;
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is valid array and validator is valid.
     */
    public function isValid(mixed $value): bool
    {
        if (!\is_array($value)) {
            return false;
        }

        foreach ($value as $element) {
            if (!$this->validator->isValid($element)) {
                return false;
            }
        }
        return !$this->length || \count($value) <= $this->length;
    }
}
