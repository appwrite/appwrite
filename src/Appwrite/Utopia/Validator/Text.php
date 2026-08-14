<?php

namespace Appwrite\Utopia\Validator;

use Utopia\Validator\Text as UtopiaText;

/**
 * Text
 *
 * Same length rules as Utopia text, but whitespace-only values are invalid.
 */
class Text extends UtopiaText
{
    public function getDescription(): string
    {
        return parent::getDescription().' and must not be whitespace only';
    }

    public function isValid(mixed $value): bool
    {
        if (! parent::isValid($value)) {
            return false;
        }

        return \trim($value) !== '' || $value === '';
    }
}
