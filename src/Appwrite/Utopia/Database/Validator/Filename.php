<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Validator\Text;

class Filename extends Text
{
    public function getDescription(): string
    {
        return parent::getDescription() . ' and must not contain control characters';
    }

    public function isValid(mixed $value): bool
    {
        if (!parent::isValid($value)) {
            return false;
        }

        $length = \strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $code = \ord($value[$i]);
            if ($code < 32 || $code === 127) {
                return false;
            }
        }

        return true;
    }
}
