<?php

declare(strict_types=1);

namespace Utopia\Validator;

/**
 * Identifier
 *
 * Validates that a value is a C-style identifier: letters, digits and
 * underscores only, not starting with a digit. This is the shape required of
 * environment variable names and shell identifiers, so a value that passes a
 * plain length check but contains a tab, a space or an accented letter is
 * rejected here.
 */
class Identifier extends Text
{
    public function __construct(int $length = 0)
    {
        parent::__construct($length, 1);
    }

    #[\Override]
    public function getDescription(): string
    {
        $description = 'Value must contain only letters, digits and underscores and must not start with a digit';

        if ($this->length !== 0) {
            $description .= ', and be at most ' . $this->length . ' chars';
        }

        return $description . '.';
    }

    #[\Override]
    public function isValid(mixed $value): bool
    {
        return parent::isValid($value) && preg_match('/^[A-Za-z_]\w*$/D', (string) $value) === 1;
    }
}
