<?php

namespace Appwrite\Storage;

class Bytes
{
    private const UNITS = ['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];

    public static function human(int|float $bytes, int $decimals = 2): string
    {
        $factor = (int) \floor((\strlen((string) (int) $bytes) - 1) / 3);

        return \sprintf("%.{$decimals}f%s", $bytes / 1000 ** $factor, self::UNITS[$factor]);
    }
}
