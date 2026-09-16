<?php

namespace Tests\Telemetry\Adapter\OpenTelemetry\Swoole;

use Swoole\Coroutine;

use function Swoole\Coroutine\go;

use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\Http\Server;

use function Swoole\Coroutine\run;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Utopia\Telemetry\Exception;

/**
 * Mock OTLP server for integration testing.
 *
 * Provides a simple way to spin up a mock metrics collector and capture requests.
 */
class MockOtlpServer
{
    private const HOST = '127.0.0.1';

    private static int $portOffset = 0;

    private Server $server;

    private int $port;

    /** @var array<int, array{payload: string, headers: array<string, string>, timestamp: float}> */
    private array $requests = [];

    private int $statusCode = 200;

    private string $responseBody = '';

    private float $responseDelay = 0;

    public function __construct()
    {
        $this->port = self::HOST === '127.0.0.1' ? 19318 + (self::$portOffset++) : 19318;
    }

    /**
     * Get the endpoint URL for this server.
     */
    public function getEndpoint(): string
    {
        return 'http://' . self::HOST . ':' . $this->port . '/v1/metrics';
    }

    /**
     * Get just the port number.
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Configure the response status code.
     */
    public function respondWith(int $statusCode, string $body = ''): self
    {
        $this->statusCode = $statusCode;
        $this->responseBody = $body;

        return $this;
    }

    /**
     * Add artificial delay to responses (for testing timeouts/concurrency).
     */
    public function withDelay(float $seconds): self
    {
        $this->responseDelay = $seconds;

        return $this;
    }

    /**
     * Start the server (non-blocking).
     */
    public function start(): void
    {
        $this->server = new Server(self::HOST, $this->port);
        $this->server->set(['open_http2_protocol' => false]);

        $this->server->handle('/v1/metrics', function (Request $request, Response $response): void {
            $this->requests[] = [
                'payload' => $request->getContent(),
                'headers' => $request->header,
                'timestamp' => microtime(true),
            ];

            if ($this->responseDelay > 0) {
                Coroutine::sleep($this->responseDelay);
            }

            $response->status($this->statusCode);
            $response->end($this->responseBody);
        });

        go(fn(): mixed => $this->server->start());

        $this->waitUntilReady();
    }

    /**
     * Stop the server.
     */
    public function stop(): void
    {
        $this->server->shutdown();
    }

    /**
     * Wait for the server to be ready to accept connections.
     *
     * @throws Exception If the server fails to start within the timeout period
     */
    private function waitUntilReady(float $timeout = 2.0): void
    {
        $start = microtime(true);
        while (microtime(true) - $start < $timeout) {
            $client = new Client(self::HOST, $this->port);
            $client->set(['timeout' => 0.1]);
            $client->get('/');
            if ($client->statusCode !== -1 && $client->statusCode !== -2) {
                $client->close();

                return;
            }
            $client->close();
            Coroutine::sleep(0.01);
        }

        throw new Exception(\sprintf(
            'MockOtlpServer failed to start: could not connect to %s:%d within %.1f seconds',
            self::HOST,
            $this->port,
            $timeout,
        ));
    }

    /**
     * Get all captured requests.
     *
     * @return array<int, array{payload: string, headers: array<string, string>, timestamp: float}>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    /**
     * Get the number of requests received.
     */
    public function getRequestCount(): int
    {
        return \count($this->requests);
    }

    /**
     * Get the last request received.
     *
     * @return array{payload: string, headers: array<string, string>, timestamp: float}|null
     */
    public function getLastRequest(): ?array
    {
        return $this->requests[\count($this->requests) - 1] ?? null;
    }

    /**
     * Clear captured requests.
     */
    public function reset(): void
    {
        $this->requests = [];
    }

    /**
     * Run a test with a mock server, handling lifecycle automatically.
     *
     * If already inside a coroutine, runs directly. Otherwise wraps in run().
     *
     * @param callable(MockOtlpServer): void $test
     */
    public static function run(callable $test): void
    {
        $exception = null;

        $executor = function () use ($test, &$exception): void {
            $server = new self();
            $server->start();

            try {
                $test($server);
            } catch (\Throwable $e) {
                $exception = $e;
            } finally {
                $server->stop();
            }
        };

        if (Coroutine::getCid() > 0) {
            $executor();
        } else {
            run($executor);
        }

        if ($exception instanceof \Throwable) {
            throw $exception;
        }
    }
}
