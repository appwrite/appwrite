<?php

namespace Utopia\Query;

use Utopia\Query\Exception\ValidationException;

enum StringLiteral
{
    case Backslash;
    case Extended;
    case Standard;
    case Unicode;

    public function quote(string $value): string
    {
        if ($this->refusesNul() && \str_contains($value, "\0")) {
            throw new ValidationException('String literal contains a NUL byte');
        }

        $doubled = \str_replace("'", "''", $value);

        if ($this === self::Standard || !\str_contains($value, '\\')) {
            return "'" . $doubled . "'";
        }

        if ($this !== self::Unicode && \preg_match('/[\x80-\xFF]\\\\/', $value) === 1) {
            throw new ValidationException('String literal has a backslash after a byte of 0x80 or above, which a multibyte connection charset can read as part of the preceding character');
        }

        $escaped = \str_replace('\\', '\\\\', $doubled);

        return match ($this) {
            self::Extended => "E'" . $escaped . "'",
            default => "'" . $escaped . "'",
        };
    }

    private function refusesNul(): bool
    {
        return $this === self::Extended || $this === self::Standard;
    }
}
