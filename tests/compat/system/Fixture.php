<?php

namespace Tests\Compat\System;

use Tests\Compat\Fault;

/**
 * A machine described by a case: what php_uname(), file_get_contents(),
 * is_readable(), shell_exec(), scandir(), disk_*_space() and sleep() return
 * to Utopia\System\System while it is active (see overrides.php, the same
 * namespace-fallback technique as packages/system/tests/fixtures).
 *
 * `files` maps a path to its content, `false` (readable but failing), or a
 * list of contents returned by successive reads; a path that is not listed
 * is not readable. `exec` maps a command to its output, `dirs` a directory
 * to its entries (or `false`), `disk` holds `total` and `free` bytes.
 */
final class Fixture
{
    public static ?Fixture $active = null;

    /** @var list<int> */
    public array $sleeps = [];

    /** @var array<string, int> */
    private array $reads = [];

    /**
     * @param array<string, mixed> $machine
     */
    public function __construct(private readonly array $machine)
    {
    }

    public function uname(string $mode): string
    {
        return match ($mode) {
            's' => $this->machine['os'] ?? 'Linux',
            'm' => $this->machine['arch'] ?? 'x86_64',
            'n' => $this->machine['hostname'] ?? 'localhost',
            default => throw new Fault("php_uname mode {$mode} is not in fixtures"),
        };
    }

    public function read(string $path): string|false
    {
        $content = $this->machine['files'][$path] ?? false;
        if (\is_array($content)) {
            $read = $this->reads[$path] ?? 0;
            $this->reads[$path] = $read + 1;

            return $content[min($read, \count($content) - 1)];
        }

        return $content;
    }

    public function readable(string $path): bool
    {
        return \array_key_exists($path, $this->machine['files'] ?? []);
    }

    public function exec(string $command): ?string
    {
        return $this->machine['exec'][$command] ?? null;
    }

    /**
     * @return list<string>|false
     */
    public function scandir(string $directory): array|false
    {
        return $this->machine['dirs'][$directory] ?? false;
    }

    public function disk(string $which): float|false
    {
        $bytes = $this->machine['disk'][$which] ?? false;

        return $bytes === false ? false : (float) $bytes;
    }

    public function sleep(int $seconds): int
    {
        $this->sleeps[] = $seconds;

        return 0;
    }
}
