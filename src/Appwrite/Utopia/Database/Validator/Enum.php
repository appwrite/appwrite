<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Validator;

class Enum extends Validator
{
    protected array $allowed;

    public function __construct(array $allowed)
    {
        $this->allowed = $allowed;
        $this->message = 'Value must be one of: ' . implode(', ', $allowed);
    }

    public function isValid(mixed $value): bool
    {
        return in_array($value, $this->allowed, true);
    }
}