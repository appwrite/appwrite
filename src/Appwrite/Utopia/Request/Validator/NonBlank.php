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
        // \s with the u modifier covers Unicode whitespace (NBSP, ideographic space, etc.),
        // which PHP's ASCII-only trim() would leave in place.
        if (!\is_string($value) || \preg_match('/^\s*$/u', $value) === 1) {
            return false;
        }

        return $this->validator->isValid($value);
    }
}
