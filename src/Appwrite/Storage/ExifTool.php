<?php

namespace Appwrite\Storage;

use Exception;
use InvalidArgumentException;

/**
 * A long-running exiftool process (`-stay_open`) that removes metadata from images.
 *
 * Starting exiftool loads Perl and its modules, which takes hundreds of milliseconds.
 * Keeping one process open and sending it commands brings each strip down to milliseconds.
 */
class ExifTool
{
    private const READY = '{ready}';

    /**
     * A 1x1 JPEG stripped once on start. exiftool loads its writer modules on the
     * first write, which otherwise adds about 100ms to the first real strip.
     */
    private const WARM_UP_JPEG = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAFA3PEY8MlBGQUZaVVBfeMiCeG5uePWvuZHI////////////////////////////////////////////////////wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAABP/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8Aa//Z';

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    public function __construct(
        private readonly string $binary = 'exiftool',
        private readonly float $timeout = 10.0,
    ) {
        $this->start();
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Remove all metadata from the image at $path, in place.
     *
     * The colour profile and orientation are written back so the image still renders
     * the same way. Pixel data is not re-encoded.
     *
     * Returns false when exiftool could not write the file, for example a corrupt or
     * unsupported image. Throws only when the process itself failed, so callers that
     * pool this resource restart it only when it is actually broken.
     *
     * @throws InvalidArgumentException when $path is not a safe argument
     * @throws Exception when the exiftool process fails or times out
     */
    public function strip(string $path): bool
    {
        // Every argument is one line on exiftool's stdin: a newline would start a new
        // argument and a leading dash would be read as an option.
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
        ]);

        return \str_contains($output, '1 image files updated')
            || \str_contains($output, '1 image files unchanged');
    }

    /**
     * Restart the process. Utopia Pool calls this to recover a resource after a failure.
     */
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
        \proc_terminate($this->process);
        \proc_close($this->process);
        $this->process = null;
    }

    private function start(): void
    {
        $process = \proc_open(
            [$this->binary, '-stay_open', 'True', '-@', '-'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                // Results are read from the summary on stdout. A stderr pipe nobody
                // reads would eventually fill up and block the process.
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes
        );

        if (!\is_resource($process)) {
            throw new Exception('Failed to start exiftool.');
        }

        $this->process = $process;
        $this->pipes = $pipes;
        \stream_set_blocking($this->pipes[1], false);

        $this->warmUp();
    }

    private function warmUp(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'exiftool');
        if ($path === false) {
            return;
        }

        try {
            \file_put_contents($path, \base64_decode(self::WARM_UP_JPEG));
            $this->strip($path);
        } finally {
            @\unlink($path);
        }
    }

    /**
     * @param array<string> $args
     * @throws Exception
     */
    private function execute(array $args): string
    {
        if (!\is_resource($this->process) || !\proc_get_status($this->process)['running']) {
            throw new Exception('exiftool is not running.');
        }

        if (\fwrite($this->pipes[0], \implode("\n", $args) . "\n-execute\n") === false) {
            throw new Exception('Failed to send a command to exiftool.');
        }
        \fflush($this->pipes[0]);

        $output = '';
        $deadline = \microtime(true) + $this->timeout;

        while (!\str_contains($output, self::READY)) {
            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0) {
                throw new Exception('exiftool did not respond within ' . $this->timeout . ' seconds.');
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
