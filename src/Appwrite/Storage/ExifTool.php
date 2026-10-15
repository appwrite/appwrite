<?php

namespace Appwrite\Storage;

use Exception;
use InvalidArgumentException;
use Swoole\Runtime;

/**
 * Keeps one exiftool process open (-stay_open) so each strip skips the Perl startup.
 */
class ExifTool
{
    private const READY = '{ready}';

    // Stripped on start so exiftool loads its writer modules before the first real upload.
    private const WARM_UP_JPEG = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAFA3PEY8MlBGQUZaVVBfeMiCeG5uePWvuZHI////////////////////////////////////////////////////wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAABP/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8Aa//Z';

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    /**
     * @param float $startupTimeout Covers loading Perl, which is slow when every worker starts at once.
     */
    public function __construct(
        private readonly string $binary = 'exiftool',
        private readonly float $timeout = 10.0,
        private readonly float $startupTimeout = 60.0,
    ) {
        $this->start();
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Returns false when the file can't be rewritten; throws only when the process itself fails.
     */
    public function strip(string $path): bool
    {
        return $this->stripWithin($path, $this->timeout);
    }

    private function stripWithin(string $path, float $timeout): bool
    {
        // Each argument is one stdin line, so a line break or leading dash could inject options.
        if (!\str_starts_with($path, '/') || \strpbrk($path, "\r\n") !== false) {
            throw new InvalidArgumentException('Path must be absolute and must not contain line breaks.');
        }

        $output = $this->execute([
            '-all=',
            '-tagsfromfile',
            '@',
            '-icc_profile',
            '-orientation',
            '-overwrite_original',
            $path,
        ], $timeout);

        return \str_contains($output, '1 image files updated')
            || \str_contains($output, '1 image files unchanged');
    }

    // Utopia Pool calls reset() to recover a resource after a failure.
    public function reset(): bool
    {
        $this->close();
        $this->start();

        return true;
    }

    public function close(): void
    {
        if (!\is_resource($this->process)) {
            return;
        }

        if (isset($this->pipes[0]) && \is_resource($this->pipes[0])) {
            @\fwrite($this->pipes[0], "-stay_open\nFalse\n");
        }

        foreach ($this->pipes as $pipe) {
            if (\is_resource($pipe)) {
                \fclose($pipe);
            }
        }

        $this->pipes = [];
        $process = $this->process;
        self::native(function () use ($process): void {
            \proc_terminate($process);
            \proc_close($process);
        });
        $this->process = null;
    }

    /**
     * Runs process calls without Swoole's proc_open hook, which stops delivering data when many
     * workers start processes at once. These calls don't yield, so no other coroutine sees the change.
     */
    private static function native(callable $callback): mixed
    {
        $hooks = \class_exists(Runtime::class) ? Runtime::getHookFlags() : 0;
        if (($hooks & SWOOLE_HOOK_PROC) === 0) {
            return $callback();
        }

        Runtime::setHookFlags($hooks & ~SWOOLE_HOOK_PROC);
        try {
            return $callback();
        } finally {
            Runtime::setHookFlags($hooks);
        }
    }

    private function start(): void
    {
        $pipes = [];
        $process = self::native(function () use (&$pipes) {
            return \proc_open(
                [$this->binary, '-stay_open', 'True', '-@', '-'],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    // An unread stderr pipe would fill up and block the process.
                    2 => ['file', '/dev/null', 'w'],
                ],
                $pipes
            );
        });

        if (!\is_resource($process)) {
            throw new Exception('Failed to start exiftool.');
        }

        $this->process = $process;
        $this->pipes = $pipes;
        \stream_set_blocking($this->pipes[1], false);

        try {
            $this->warmUp();
        } catch (\Throwable $th) {
            // PHP skips __destruct when a constructor throws, so stop the process here.
            $this->close();
            throw $th;
        }
    }

    private function warmUp(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'exiftool');
        if ($path === false) {
            return;
        }

        try {
            \file_put_contents($path, \base64_decode(self::WARM_UP_JPEG));
            $this->stripWithin($path, $this->startupTimeout);
        } finally {
            @\unlink($path);
        }
    }

    /**
     * @param array<string> $args
     */
    private function execute(array $args, float $timeout): string
    {
        $process = $this->process;
        if (!\is_resource($process) || !self::native(fn () => \proc_get_status($process))['running']) {
            throw new Exception('exiftool is not running.');
        }

        if (\fwrite($this->pipes[0], \implode("\n", $args) . "\n-execute\n") === false) {
            throw new Exception('Failed to send a command to exiftool.');
        }
        \fflush($this->pipes[0]);

        $output = '';
        $deadline = \microtime(true) + $timeout;

        while (!\str_contains($output, self::READY)) {
            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0) {
                throw new Exception('exiftool did not respond within ' . $timeout . ' seconds.');
            }

            $read = [$this->pipes[1]];
            $write = null;
            $except = null;
            $seconds = (int) $remaining;
            if (@\stream_select($read, $write, $except, $seconds, (int) (($remaining - $seconds) * 1_000_000)) === false) {
                throw new Exception('Failed to read the exiftool output.');
            }

            $chunk = \fread($this->pipes[1], 8192);
            if ($chunk === false || ($chunk === '' && \feof($this->pipes[1]))) {
                throw new Exception('exiftool exited unexpectedly.');
            }

            $output .= $chunk;
        }

        return \substr($output, 0, (int) \strpos($output, self::READY));
    }
}
