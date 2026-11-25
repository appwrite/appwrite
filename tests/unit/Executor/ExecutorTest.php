<?php

declare(strict_types=1);

namespace Tests\Unit\Executor;

use Executor\Exception as ExecutorException;
use Executor\Executor;
use PHPUnit\Framework\TestCase;

final class ExecutorTest extends TestCase
{
    public function testRuntimeIdKeepsHyphenFreePairs(): void
    {
        $this->assertSame('6a1b2c3d-6e4f5a6b', Executor::runtimeId('6a1b2c3d', '6e4f5a6b'));
    }

    public function testRuntimeIdSeparatesHyphenatedPairs(): void
    {
        $this->assertNotSame(
            Executor::runtimeId('my-app', 'deployment'),
            Executor::runtimeId('my', 'app-deployment')
        );
    }

    public function testRuntimeIdOfHyphenatedPairDiffersFromHyphenFreePair(): void
    {
        $this->assertNotSame(Executor::runtimeId('my-app', '6'), Executor::runtimeId('my', 'app-6'));
        $this->assertNotSame(Executor::runtimeId('a-b', 'c'), Executor::runtimeId('a', 'b-c-1'));
    }

    public function testRuntimeIdKeepsProjectPrefix(): void
    {
        $this->assertStringStartsWith('my-app-', Executor::runtimeId('my-app', 'deployment'));
    }

    public function testBufferedResponseWithinTheLimitIsDecoded(): void
    {
        $response = $this->execute(size: 1024, maxBufferedSize: 4096);

        $this->assertSame(200, $response['statusCode']);
        $this->assertSame(\str_repeat('a', 1024), $response['body']);
        $this->assertSame(['content-type' => 'text/plain'], $response['headers']);
    }

    public function testBufferedResponsePastTheLimitIsRefused(): void
    {
        $this->expectException(ExecutorException::class);
        $this->expectExceptionMessage('Execution response is too large to buffer.');

        $this->execute(size: 64 * 1024, maxBufferedSize: 4096);
    }

    /**
     * Runs an execution against a stand-in executor that answers without streaming,
     * as it does for previews and when streaming is disabled.
     *
     * @return array<string, mixed>
     */
    private function execute(int $size, int $maxBufferedSize): array
    {
        $port = $this->freePort();
        $server = \proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/router.php'],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes
        );
        $this->assertIsResource($server);

        try {
            $deadline = \microtime(true) + 5;
            while (@\fsockopen('127.0.0.1', $port) === false && \microtime(true) < $deadline) {
                \usleep(20_000);
            }

            \putenv('_APP_EXECUTOR_HOST=http://127.0.0.1:' . $port);
            $executor = new Executor();

            return $executor->createExecution(
                projectId: 'project',
                deploymentId: 'deployment',
                body: null,
                variables: [],
                timeout: 5,
                image: 'image',
                source: 'source',
                entrypoint: '',
                version: 'v5',
                path: '/?size=' . $size,
                method: 'GET',
                headers: [],
                cpus: 1,
                memory: 512,
                logging: false,
                responseFormat: Executor::RESPONSE_FORMAT_ARRAY_HEADERS,
                maxBufferedSize: $maxBufferedSize,
            );
        } finally {
            \putenv('_APP_EXECUTOR_HOST');
            \proc_terminate($server);
            \proc_close($server);
        }
    }

    private function freePort(): int
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $name = (string) \stream_socket_get_name($socket, false);
        \fclose($socket);

        return (int) \substr($name, \strrpos($name, ':') + 1);
    }
}
