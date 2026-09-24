<?php

declare(strict_types=1);

namespace Utopia\Validator;

use Utopia\Validator;

class HexColor extends Validator
{
    public function getDescription(): string
    {
        return 'Value must be a valid Hex color code';
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

    public function isValid(mixed $value): bool
    {
        return \is_string($value) && preg_match('/^([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $value);
    }
}
