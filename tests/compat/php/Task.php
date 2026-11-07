<?php

namespace Tests\Compat;

/**
 * Runs a library's PHP task (a script outside src/, see crates/tools/compat/src/runner/tasks.rs)
 * as its own process, the way a developer runs it: `php <script> <args>` from the repository root,
 * with extra environment variables. The Rust side is crates/tools/compat/src/task.rs.
 */
final class Task
{
    /**
     * @param string $script Repository path of the script (`packages/emails/import.php`).
     * @param list<string> $args Its arguments.
     * @param array<string, string> $env Variables added to the driver's environment.
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public static function run(string $script, array $args, array $env): array
    {
        $root = \dirname(__DIR__, 3);
        $command = array_merge([PHP_BINARY, $root . '/' . $script], $args);
        // Files rather than pipes: a process filling one pipe while the other is read would block.
        $out = (string) tempnam(sys_get_temp_dir(), 'compat-task');
        $err = (string) tempnam(sys_get_temp_dir(), 'compat-task');
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, $root, array_merge(getenv(), $env));
        if (!\is_resource($process)) {
            throw new Fault("could not start {$script}");
        }
        $exit = proc_close($process);
        $result = ['exit' => $exit, 'stdout' => (string) file_get_contents($out), 'stderr' => (string) file_get_contents($err)];
        unlink($out);
        unlink($err);

        return $result;
    }

    /**
     * Includes a PHP data file a task wrote (`return [...];`), null when there is none. Past
     * opcache: a file written again within the same second keeps its timestamp.
     */
    public static function data(string $file): mixed
    {
        if (!is_file($file)) {
            return null;
        }
        if (\function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }

        return include $file;
    }
}
