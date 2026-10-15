<?php

namespace Utopia\Tests\Unit\Network;

final class Server
{
    private const STARTUP_TIMEOUT = 10;

    private const ROUTER = __DIR__.'/../../resources/http/router.php';

    /**
     * @var resource|null
     */
    private $process = null;

    private int $port;

    private string $log;

    /**
     * @param array<string, string> $environment
     */
    public function __construct(array $environment = [])
    {
        $this->port = self::findPort();

        $log = \tempnam(\sys_get_temp_dir(), 'fixture');
        if ($log === false) {
            throw new \RuntimeException('Unable to create the fixture log');
        }
        $this->log = $log;

        $process = \proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$this->port, self::ROUTER],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes,
            null,
            \array_merge(\getenv(), $environment, ['FIXTURE_LOG' => $this->log]),
        );
        if ($process === false) {
            throw new \RuntimeException('Unable to start the fixture server');
        }
        $this->process = $process;
        \register_shutdown_function($this->stop(...));

        $this->waitForPort();
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getURL(string $host = '127.0.0.1'): string
    {
        return 'http://'.$host.':'.$this->port;
    }

    /**
     * @return array<string>
     */
    public function getRequests(): array
    {
        \clearstatcache(true, $this->log);

        return \file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    }

    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }

        \proc_terminate($this->process);
        \proc_close($this->process);
        $this->process = null;

        if (\is_file($this->log)) {
            \unlink($this->log);
        }
    }

    private static function findPort(): int
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        if ($socket === false) {
            throw new \RuntimeException('Unable to reserve a port: '.$message);
        }

        $name = \stream_socket_get_name($socket, false);
        \fclose($socket);

        if ($name === false) {
            throw new \RuntimeException('Unable to read the reserved port');
        }

        return (int) \substr($name, \strrpos($name, ':') + 1);
    }

    private function waitForPort(): void
    {
        $deadline = \microtime(true) + self::STARTUP_TIMEOUT;

        while (\microtime(true) < $deadline) {
            $connection = @\stream_socket_client('tcp://127.0.0.1:'.$this->port, $code, $message, 0.1);
            if ($connection !== false) {
                \fclose($connection);

                return;
            }
            \usleep(50_000);
        }

        $this->stop();

        throw new \RuntimeException('Fixture server did not start on port '.$this->port);
    }
}
