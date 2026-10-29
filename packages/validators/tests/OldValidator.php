<?php

declare(strict_types=1);

namespace Utopia\Validator\Tests;

/**
 * A validator written before the rename, extending the old base class name.
 */
final class OldValidator extends \Utopia\Validator
{
    public function getDescription(): string
    {
        return 'Value must be "old".';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }

    public function isValid(mixed $value): bool
    {
        return $value === 'old';
    }
}
