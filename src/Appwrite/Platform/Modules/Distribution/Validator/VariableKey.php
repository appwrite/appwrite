<?php

namespace Appwrite\Platform\Modules\Distribution\Validator;

use Appwrite\Platform\Modules\Compute\Validator\VariableKey as ComputeVariableKey;

class VariableKey extends ComputeVariableKey
{
    public const array RESERVED_PREFIXES = [
        'DISTRIBUTION_',
        'APPWRITE_',
    ];

    public function getDescription(): string
    {
        return parent::getDescription() . ' Value must not start with ' . \implode(' or ', self::RESERVED_PREFIXES) . ' in any letter case.';
    }

    public function isValid(mixed $value): bool
    {
        if (!parent::isValid($value)) {
            return false;
        }

        $key = \strtoupper($value);

        foreach (self::RESERVED_PREFIXES as $prefix) {
            if (\str_starts_with($key, $prefix)) {
                return false;
            }
        }

        return true;
    }
}
