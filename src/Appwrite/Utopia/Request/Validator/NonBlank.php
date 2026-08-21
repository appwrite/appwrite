<?php

namespace Appwrite\Utopia\Request\Validator;

use Utopia\Validator;

class NonBlank extends Validator
{
    public function __construct(private Validator $validator)
    {
    }

    public function isValid(mixed $value): bool
    {
        if (\is_string($value) && $value !== '' && \trim($value) === '') {
            return false;
        }

        return $this->validator->isValid($value);
    }

    public function getDescription(): string
    {
        return $this->validator->getDescription() . ' and must not be whitespace-only';
    }

    public function getType(): string
    {
        return $this->validator->getType();
    }

    public function isArray(): bool
    {
        return $this->validator->isArray();
    }
}
