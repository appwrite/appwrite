<?php

declare(strict_types=1);

namespace Appwrite\Utopia\Request\Validator;

use Utopia\Validator;

/**
 * Rejects whitespace-only strings, then delegates to an inner validator.
 */
class NonBlank extends Validator
{
    public function __construct(private Validator $validator)
    {
    }

    public function getDescription(): string
    {
        return $this->validator->getDescription() . ' and must not be blank';
    }

    public function isArray(): bool
    {
        return $this->validator->isArray();
    }

    public function getType(): string
    {
        return $this->validator->getType();
    }

    public function isValid(mixed $value): bool
    {
        if (!\is_string($value) || \trim($value) === '') {
            return false;
        }

        return $this->validator->isValid($value);
    }
}
