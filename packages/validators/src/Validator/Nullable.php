<?php

declare(strict_types=1);

namespace Utopia\Validator;

use Utopia\Validator;

class Nullable extends Validator
{
    public function __construct(protected Validator $validator)
    {
    }

    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        return $this->validator->getDescription() . ' or null';
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
        return $this->validator->getType();
    }

    public function getValidator(): Validator
    {
        return $this->validator;
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is text with valid length.
     */
    public function isValid(mixed $value): bool
    {
        if (\is_null($value)) {
            return true;
        }

        return $this->validator->isValid($value);
    }

    /**
     * Delegate casting to the wrapped validator so loose forms (for example
     * boolean `"false"`) still become their canonical type when present.
     */
    public function cast(mixed $value): mixed
    {
        if (\is_null($value)) {
            return null;
        }

        return $this->validator->cast($value);
    }
}
