<?php

declare(strict_types=1);

namespace Tests\Unit\Executor;

use Executor\Executor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Bodies over 1 MiB make libcurl add Expect: 100-continue and wait up to 1s
 * when the peer never answers 100. Executor::call() must disable that wait.
 */
final class ExpectHeaderTest extends TestCase
{
    public function testCreateExecutionSkips100ContinueOnLargeBodies(): void
    {
        $port = $this->availablePort();
        $readyFile = \tempnam(\sys_get_temp_dir(), 'executor-expect-ready-');
        $headerFile = \tempnam(\sys_get_temp_dir(), 'executor-expect-headers-');

        if ($readyFile === false || $headerFile === false) {
            throw new RuntimeException('Unable to create executor expect temp files.');
        }

        \unlink($readyFile);

        $server = \proc_open(
            [\PHP_BINARY, '-r', $this->server(), (string) $port, $readyFile, $headerFile],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!\is_resource($server)) {
            throw new RuntimeException('Unable to start the executor expect server.');
        }

        unset($pipes);
        $this->waitUntilReady($readyFile);

        $previousHost = \getenv('_APP_EXECUTOR_HOST');
        $previousSecret = \getenv('_APP_EXECUTOR_SECRET');
        \putenv('_APP_EXECUTOR_HOST=http://127.0.0.1:' . $port);
        \putenv('_APP_EXECUTOR_SECRET=test-secret');

        try {
            $executor = new Executor();
            $result = $executor->createExecution(
                projectId: 'project',
                deploymentId: 'deployment',
                body: \str_repeat('x', 1_500_000),
                variables: [],
                timeout: 15,
                image: 'openruntimes/test:v1',
                source: '/tmp/code.tar.gz',
                entrypoint: 'index.php',
                version: 'v5',
                path: '/',
                method: 'POST',
                headers: [],
                cpus: 1,
                memory: 512,
                logging: false,
                requestTimeout: 10,
            );

            $this->assertSame(200, $result['statusCode']);
            $this->assertSame('ok', $result['body']);
        } finally {
            $this->restoreEnv('_APP_EXECUTOR_HOST', $previousHost);
            $this->restoreEnv('_APP_EXECUTOR_SECRET', $previousSecret);
            \proc_terminate($server);
            \proc_close($server);
        }

        $headersBlock = (string) \file_get_contents($headerFile);
        @\unlink($headerFile);

        $this->assertNotSame('', $headersBlock);
        $lengths = $this->headerValues($headersBlock, 'content-length');
        $this->assertNotSame([], $lengths);
        $this->assertGreaterThan(1024 * 1024, (int) $lengths[0]);

        // libcurl drops an empty Expect header instead of transmitting it, and
        // does not add Expect: 100-continue.
        $expect = \array_map(\strtolower(...), $this->headerValues($headersBlock, 'expect'));
        $this->assertNotContains('100-continue', $expect);
    }

    private function server(): string
    {
        return <<<'PHP'
            $port = (int) $argv[1];
            $readyFile = $argv[2];
            $headerFile = $argv[3];
            $server = stream_socket_server('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage);
            if (!is_resource($server)) {
                fwrite(STDERR, $errorCode . ' ' . $errorMessage);
                exit(1);
            }
            file_put_contents($readyFile, 'ready');
            $connection = @stream_socket_accept($server, 30);
            if (!is_resource($connection)) {
                exit(1);
            }
            stream_set_timeout($connection, 8);
            $headersBlock = '';
            while (($line = fgets($connection, 8192)) !== false) {
                $headersBlock .= $line;
                if ($line === "\r\n" || $line === "\n") {
                    break;
                }
            }
            file_put_contents($headerFile, $headersBlock);
            $length = 0;
            foreach (explode("\r\n", $headersBlock) as $header) {
                $separator = strpos($header, ':');
                if ($separator === false) {
                    continue;
                }
                if (strtolower(substr($header, 0, $separator)) === 'content-length') {
                    $length = (int) trim(substr($header, $separator + 1));
                }
            }
            $received = 0;
            while ($received < $length) {
                $chunk = fread($connection, $length - $received);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $received += strlen($chunk);
            }
            $payload = '{"statusCode":200,"headers":[],"body":"ok","logs":"","errors":"","duration":0.01,"startTime":1}';
            fwrite(
                $connection,
                "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\nConnection: close\r\n\r\n" . $payload,
            );
            fclose($connection);
            PHP;
    }

    /**
     * @return array<int, string>
     */
    private function headerValues(string $headersBlock, string $name): array
    {
        $values = [];

        foreach (\explode("\r\n", $headersBlock) as $line) {
            $separator = \strpos($line, ':');
            if ($separator === false) {
                continue;
            }

            $header = \substr($line, 0, $separator);
            if (\strtolower($header) !== $name) {
                continue;
            }

            $values[] = \trim(\substr($line, $separator + 1));
        }

        return $values;
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

        throw new RuntimeException('Executor expect server did not start.');
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
