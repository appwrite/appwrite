<?php

namespace Appwrite\Utopia;

final class Bytes
{
    /**
     * Human readable data size format from bytes input.
     *
     * Previously `Utopia\Storage\Storage::human()`, removed in storage 3.0.
     */
    public static function human(int $bytes, int $decimals = 2, string $system = 'metric'): string
    {
        $mod = ($system === 'binary') ? 1024 : 1000;

        $units = [
            'binary' => ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB'],
            'metric' => ['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'],
        ];

        $factor = (int) floor((\strlen((string) $bytes) - 1) / 3);

        return \sprintf("%.{$decimals}f%s", $bytes / $mod ** $factor, $units[$system][$factor]);
    }
}
