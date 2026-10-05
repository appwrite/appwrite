<?php

declare(strict_types=1);

namespace Tests\Unit\Executor;

use Executor\Executor;
use PHPUnit\Framework\TestCase;

/**
 * Large POST bodies make libcurl add Expect: 100-continue and wait up to 1s when
 * the peer never answers 100. Executor::call() must send an empty Expect header
 * so that handshake is skipped.
 */
final class ExpectHeaderTest extends TestCase
{
    public function testCreateExecutionSendsEmptyExpectOnLargeBodies(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork required');
        }

        $listener = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($listener);
        $name = \stream_socket_get_name($listener, false);
        $this->assertNotFalse($name);
        $port = (int) \explode(':', $name)[1];

        $captureFile = \tempnam(\sys_get_temp_dir(), 'executor-expect-');
        $this->assertNotFalse($captureFile);

        $pid = \pcntl_fork();
        $this->assertNotSame(-1, $pid);

        if ($pid === 0) {
            $connection = @\stream_socket_accept($listener, 10);
            if (!\is_resource($connection)) {
                exit(1);
            }

            \stream_set_timeout($connection, 5);
            $request = '';
            while (!\str_contains($request, "\r\n\r\n")) {
                $chunk = \fread($connection, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $request .= $chunk;
            }

            if (\preg_match('/Content-Length:\s*(\d+)/i', $request, $matches) === 1) {
                $length = (int) $matches[1];
                $headerEnd = \strpos($request, "\r\n\r\n");
                $body = $headerEnd === false ? '' : \substr($request, $headerEnd + 4);
                while (\strlen($body) < $length) {
                    $chunk = \fread($connection, 8192);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $body .= $chunk;
                }
                $request = ($headerEnd === false ? $request : \substr($request, 0, $headerEnd + 4)) . $body;
            }

            \file_put_contents($captureFile, $request);

            $payload = \json_encode([
                'statusCode' => 200,
                'headers' => [],
                'body' => 'ok',
                'logs' => '',
                'errors' => '',
                'duration' => 0.01,
                'startTime' => 1.0,
            ], JSON_THROW_ON_ERROR);

            $response = "HTTP/1.1 200 OK\r\n"
                . "Content-Type: application/json\r\n"
                . 'Content-Length: ' . \strlen($payload) . "\r\n"
                . "Connection: close\r\n"
                . "\r\n"
                . $payload;

            \fwrite($connection, $response);
            \fclose($connection);
            exit(0);
        }

        \fclose($listener);

        $previousHost = \getenv('_APP_EXECUTOR_HOST');
        $previousSecret = \getenv('_APP_EXECUTOR_SECRET');
        \putenv('_APP_EXECUTOR_HOST=http://127.0.0.1:' . $port);
        \putenv('_APP_EXECUTOR_SECRET=test-secret');

        try {
            $executor = new Executor();
            $body = \str_repeat('x', 1_500_000);

            $started = \microtime(true);
            $result = $executor->createExecution(
                projectId: 'project',
                deploymentId: 'deployment',
                body: $body,
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
            $elapsed = \microtime(true) - $started;

            $this->assertSame(200, $result['statusCode']);
            // A missing empty Expect header would stall ~1s waiting for 100 Continue.
            $this->assertLessThan(0.75, $elapsed);
        } finally {
            if ($previousHost === false) {
                \putenv('_APP_EXECUTOR_HOST');
            } else {
                \putenv('_APP_EXECUTOR_HOST=' . $previousHost);
            }
            if ($previousSecret === false) {
                \putenv('_APP_EXECUTOR_SECRET');
            } else {
                \putenv('_APP_EXECUTOR_SECRET=' . $previousSecret);
            }

            \pcntl_waitpid($pid, $status);
        }

        $captured = (string) \file_get_contents($captureFile);
        @\unlink($captureFile);

        $this->assertNotSame('', $captured);
        $this->assertMatchesRegularExpression('/(^|\r\n)Expect:\s*(\r\n|$)/i', $captured);
        $this->assertDoesNotMatchRegularExpression('/(^|\r\n)Expect:\s*100-continue(\r\n|$)/i', $captured);
    }
}
