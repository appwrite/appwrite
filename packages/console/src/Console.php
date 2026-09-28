<?php

namespace Utopia;

class Console
{
    /**
     * Title
     *
     * Sets the process title visible in tools such as top and ps.
     */
    public static function title(string $title): bool
    {
        return @cli_set_process_title($title);
    }

    /**
     * Log
     *
     * Log messages to console
     */
    public static function log(string $message): int|false
    {
        return fwrite(STDOUT, $message . "\n");
    }

    /**
     * Success
     *
     * Log success messages to console
     */
    public static function success(string $message): int|false
    {
        return fwrite(STDOUT, "\033[32m" . $message . "\033[0m\n");
    }

    /**
     * Error
     *
     * Log error messages to console
     */
    public static function error(string $message): int|false
    {
        return fwrite(STDERR, "\033[31m" . $message . "\033[0m\n");
    }

    /**
     * Info
     *
     * Log informative messages to console
     */
    public static function info(string $message): int|false
    {
        return fwrite(STDOUT, "\033[34m" . $message . "\033[0m\n");
    }

    /**
     * Warning
     *
     * Log warning messages to console
     */
    public static function warning(string $message): int|false
    {
        return fwrite(STDERR, "\033[1;33m" . $message . "\033[0m\n");
    }

    /**
     * Confirm
     *
     * Prompt for user input in interactive mode
     */
    public static function confirm(string $question): string
    {
        if (! self::isInteractive()) {
            return '';
        }

        self::log($question);

        $handle = fopen('php://stdin', 'r');
        $line = trim(fgets($handle));

        fclose($handle);

        return $line;
    }

    /**
     * Exit
     *
     * Terminate the current process with the provided exit status.
     */
    public static function exit(int $status = 0): void
    {
        exit($status);
    }

    /**
     * Execute a Command
     *
     * This function was inspired by: https://stackoverflow.com/a/13287902/2299554
     *
     * @param  string  $stdout  Stdout contents (by reference).
     * @param  string  $stderr  Stderr contents (by reference).
     */
    public static function execute(Command|array|string $cmd, string $stdin, string &$stdout, string &$stderr, int $timeout = -1, ?callable $onProgress = null): int
    {
        if ($cmd instanceof Command) {
            $cmd = $cmd->isPlain()
                ? $cmd->toArray()
                : $cmd->toString();
        }

        // If the $cmd is passed as string, it will be wrapped into a subshell by \proc_open
        // Forward stdout and exit codes from the subshell.
        if (\is_string($cmd)) {
            $cmd = '( ' . $cmd . ' ) 3>/dev/null ; echo $? >&3';
        }

        $pipes = [];
        $process = proc_open(
            $cmd,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        $start = time();
        $stdout = '';
        $stderr = '';
        $status = '';

        if (\is_resource($process)) {
            stream_set_blocking($pipes[0], false);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            stream_set_blocking($pipes[3], false);

            fwrite($pipes[0], $stdin);
            fclose($pipes[0]);
        }

        while (\is_resource($process)) {
            $stdoutContents = stream_get_contents($pipes[1]) ?: '';
            $stderrContents = stream_get_contents($pipes[2]) ?: '';

            $stderr .= $stderrContents;

            $outputContents = $stdoutContents;

            if (isset($onProgress) && ($outputContents !== '' && $outputContents !== '0')) {
                $onProgress($outputContents, $process);
            }

            $stdout .= $outputContents;
            $status .= stream_get_contents($pipes[3]);

            if ($timeout > 0 && time() - $start > $timeout) {
                proc_terminate($process, 9);

                return 1;
            }

            $procStatus = proc_get_status($process);
            if (! $procStatus['running']) {
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return ($status !== '')
                    ? (int) str_replace("\n", '', $status)
                    : $procStatus['exitcode'];
            }

            usleep(10000);
        }

        return 1;
    }

    /**
     * Is Interactive Mode?
     */
    public static function isInteractive(): bool
    {
        return 'cli' === PHP_SAPI && \defined('STDOUT');
    }

    /**
     * Loop
     *
     * Repeatedly execute a callback while limiting CPU consumption.
     *
     * @param  int  $sleep Sleep duration in seconds.
     * @param  int  $delay Initial delay in seconds.
     *
     * @throws \Exception
     */
    public static function loop(callable $callback, int $sleep = 1 /* seconds */, int $delay = 0 /* seconds */, ?callable $onError = null): void
    {
        gc_enable();

        $time = 0;

        if ($delay > 0) {
            sleep($delay);
        }

        while (! connection_aborted() || PHP_SAPI === 'cli') {
            $suspend = $sleep;

            try {
                $execStart = time();
                $callback();
            } catch (\Exception $e) {
                if ($onError != null) {
                    $onError($e);
                } else {
                    throw $e;
                }
            }

            $execTotal = time() - $execStart;
            $suspend -= $execTotal;

            $intSeconds = \intval($suspend);
            $microSeconds = ($suspend - $intSeconds) * 1000000;

            if ($intSeconds > 0) {
                sleep($intSeconds);
            }

            if ($microSeconds > 0) {
                usleep($microSeconds);
            }

            $time += $suspend;

            if (PHP_SAPI === 'cli' && $time >= 60 * 5) {
                // Every 5 minutes
                $time = 0;
                gc_collect_cycles();
                //Forces collection of any existing garbage cycles
            }
        }
    }
}
