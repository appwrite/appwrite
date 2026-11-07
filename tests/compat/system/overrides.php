<?php

/*
 * The functions Utopia\System\System calls, resolved in its namespace first:
 * while a Tests\Compat\System\Fixture is active they read the fixture,
 * otherwise they are the global functions. Loaded with the adapter, before
 * System runs, as packages/system/tests/fixtures/cgroup-memory.php is.
 */

namespace Utopia\System;

use Tests\Compat\System\Fixture;

function php_uname(string $mode = 'a'): string
{
    return Fixture::$active?->uname($mode) ?? \php_uname($mode);
}

function file_get_contents(string $filename): string|false
{
    return Fixture::$active !== null ? Fixture::$active->read($filename) : \file_get_contents($filename);
}

function is_readable(string $filename): bool
{
    return Fixture::$active !== null ? Fixture::$active->readable($filename) : \is_readable($filename);
}

function shell_exec(string $command): string|false|null
{
    return Fixture::$active !== null ? Fixture::$active->exec($command) : \shell_exec($command);
}

/**
 * @return list<string>|false
 */
function scandir(string $directory, int $sorting_order = SCANDIR_SORT_ASCENDING): array|false
{
    return Fixture::$active !== null ? Fixture::$active->scandir($directory) : \scandir($directory, $sorting_order);
}

function disk_total_space(string $directory): float|false
{
    return Fixture::$active !== null ? Fixture::$active->disk('total') : \disk_total_space($directory);
}

function disk_free_space(string $directory): float|false
{
    return Fixture::$active !== null ? Fixture::$active->disk('free') : \disk_free_space($directory);
}

function sleep(int $seconds): int
{
    return Fixture::$active !== null ? Fixture::$active->sleep($seconds) : \sleep($seconds);
}
