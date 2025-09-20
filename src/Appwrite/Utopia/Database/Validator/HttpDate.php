<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Validator;

class HttpDate extends Validator
{
    public function __construct()
    {
        $this->message = 'Value must be a valid HTTP-date (RFC 1123).';
    }

    public function isValid(mixed $value): bool
    {
        if (!is_string($value) || strlen($value) > 64) {
            return false;
        }
        // Try to parse as RFC 1123 (e.g., Sun, 06 Nov 1994 08:49:37 GMT)
        $dt = \DateTime::createFromFormat('D, d M Y H:i:s \G\M\T', $value);
        return $dt !== false;
    }
}