<?php

declare(strict_types=1);

/**
 * Symbol stubs for optional extensions, so phpstan can analyse the adapters
 * on hosts where the extension is not installed. Never autoloaded.
 */
if (!function_exists('xzencode')) {
    function xzencode(string $payload, int $level = 6): string|false
    {
        return false;
    }
}

if (!function_exists('xzdecode')) {
    function xzdecode(string $payload): string|false
    {
        return false;
    }
}
