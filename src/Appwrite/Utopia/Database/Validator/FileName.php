<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Validator\Text;

/**
 * A stored file name. It is sent back in Content-Disposition headers, where
 * a line break or another control byte would end the header, or make the
 * server drop it, so none are accepted.
 */
class FileName extends Text
{
    public function __construct(int $length = 2048)
    {
        parent::__construct($length, 0);
    }

    public function getDescription(): string
    {
        return 'Value must be a valid string without control characters and at most ' . $this->length . ' chars.';
    }

    public function isValid(mixed $value): bool
    {
        if (!parent::isValid($value)) {
            return false;
        }

        $length = \strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $byte = \ord($value[$i]);
            if ($byte < 0x20 || $byte === 0x7F) {
                return false;
            }
        }

        return true;
    }
}
