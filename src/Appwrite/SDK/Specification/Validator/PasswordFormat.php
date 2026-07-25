<?php

namespace Appwrite\SDK\Specification\Validator;

use Utopia\Validator;

class PasswordFormat extends Validator
{
    public function __construct(private Validator $validator)
    {
    }

    public function getDescription(): string
    {
        return $this->validator->getDescription();
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
        return $this->validator->isValid($value);
    }
}
