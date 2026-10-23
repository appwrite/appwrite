<?php

declare(strict_types=1);

namespace Tests\Unit\Executor;

use Executor\Executor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Executor::call() builds a new HTTP client per request. Idle connections to the
 * executor must still be reused, or each execution leaves a TIME_WAIT socket.
 */
final class ConnectionReuseTest extends TestCase
{
    public function testCallsReuseOneConnection(): void
    {
        $observed = $this->callExecutor(5, closeFirst: false);

        $this->assertSame(5, $observed['responses']);
        $this->assertSame(1, $observed['connections']);
    }

    public function testCallReconnectsWhenTheConnectionIsClosed(): void
    {
        $observed = $this->callExecutor(4, closeFirst: true);

        $this->assertSame(4, $observed['responses']);
        $this->assertSame(2, $observed['connections']);
    }

    /**
     * @return array{responses: int, connections: int}
     */
    private function callExecutor(int $calls, bool $closeFirst): array
    {
        $port = $this->availablePort();
        $readyFile = \tempnam(\sys_get_temp_dir(), 'executor-reuse-ready-');
        $countFile = \tempnam(\sys_get_temp_dir(), 'executor-reuse-count-');

        if ($readyFile === false || $countFile === false) {
            throw new RuntimeException('Unable to create executor reuse temp files.');
        }

        \unlink($readyFile);
        \file_put_contents($countFile, '0');

        $server = \proc_open(
            [\PHP_BINARY, '-r', $this->server(), (string) $port, $readyFile, $countFile, $closeFirst ? '1' : '0'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!\is_resource($server)) {
            throw new RuntimeException('Unable to start the executor reuse server.');
        }

        unset($pipes);
        $this->waitUntilReady($readyFile);

        $previousHost = \getenv('_APP_EXECUTOR_HOST');
        $previousSecret = \getenv('_APP_EXECUTOR_SECRET');
        \putenv('_APP_EXECUTOR_HOST=http://127.0.0.1:' . $port);
        \putenv('_APP_EXECUTOR_SECRET=test-secret');

        $responses = 0;

        try {
            $executor = new Executor();

            for ($i = 0; $i < $calls; $i++) {
                $body = $executor->deleteRuntime('project', 'deployment-' . $i);
                $this->assertSame(['ok' => true], $body);
                $responses++;
            }
        } finally {
            $this->restoreEnv('_APP_EXECUTOR_HOST', $previousHost);
            $this->restoreEnv('_APP_EXECUTOR_SECRET', $previousSecret);
            \proc_terminate($server);
            \proc_close($server);
            @\unlink($readyFile);
        }

        $connections = (int) \file_get_contents($countFile);
        @\unlink($countFile);

        return [
            'responses' => $responses,
            'connections' => $connections,
        ];
    }

    private function server(): string
    {
        return <<<'PHP'
            $port = (int) $argv[1];
            $readyFile = $argv[2];
            $countFile = $argv[3];
            $closeFirst = $argv[4] === '1';
            $server = stream_socket_server('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage);
            if (!is_resource($server)) {
                fwrite(STDERR, $errorCode . ' ' . $errorMessage);
                exit(1);
            }
            file_put_contents($readyFile, 'ready');
            $connections = 0;
            while (true) {
                $connection = @stream_socket_accept($server, 30);
                if (!is_resource($connection)) {
                    continue;
                }
                $connections++;
                file_put_contents($countFile, (string) $connections);
                $closeAfterResponse = $closeFirst && $connections === 1;
                while (true) {
                    $request = '';
                    while (($line = fgets($connection, 8192)) !== false) {
                        $request .= $line;
                        if ($line === "\r\n" || $line === "\n") {
                            break;
                        }
                    }
                    if ($request === '') {
                        break;
                    }
                    $length = 0;
                    foreach (explode("\r\n", $request) as $header) {
                        $separator = strpos($header, ':');
                        if ($separator === false) {
                            continue;
                        }
                        if (strtolower(substr($header, 0, $separator)) === 'content-length') {
                            $length = (int) trim(substr($header, $separator + 1));
                        }
                    }
                    $body = '';
                    while (strlen($body) < $length) {
                        $chunk = fread($connection, $length - strlen($body));
                        if ($chunk === false || $chunk === '') {
                            break;
                        }
                        $body .= $chunk;
                    }
                    $payload = '{"ok":true}';
                    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n\r\n" . $payload);
                    if ($closeAfterResponse) {
                        break;
                    }
                }
                fclose($connection);
            }
            PHP;
    }

    private function availablePort(): int
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if (!\is_resource($server)) {
            throw new RuntimeException('Unable to find an available TCP port: ' . $errorCode . ' ' . $errorMessage);
        }

        $name = \stream_socket_get_name($server, false);
        \fclose($server);

        if ($name === false) {
            throw new RuntimeException('Unable to read TCP port.');
        }

        $port = \explode(':', $name)[1] ?? '';

        if ($port === '' || !\ctype_digit($port)) {
            throw new RuntimeException('Unable to parse TCP port.');
        }

        return (int) $port;
    }

    private function waitUntilReady(string $readyFile): void
    {
        $deadline = \microtime(true) + 5;

        do {
            if (\is_file($readyFile)) {
                \unlink($readyFile);

                return;
            }

            \usleep(50_000);
        } while (\microtime(true) < $deadline);

        throw new RuntimeException('Executor reuse server did not start.');
    }

    private function restoreEnv(string $name, string|false $previous): void
    {
        if ($previous === false) {
            \putenv($name);

            return;
        }

        \putenv($name . '=' . $previous);
    }
}
