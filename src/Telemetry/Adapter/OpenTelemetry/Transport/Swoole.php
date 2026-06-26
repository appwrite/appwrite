<?php

namespace Utopia\Telemetry\Adapter\OpenTelemetry\Transport;

use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use Swoole\Atomic;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;
use Utopia\Telemetry\Exception;

/**
 * Swoole coroutine-native HTTP transport for OpenTelemetry.
 * Uses connection pooling with keep-alive for maximum throughput.
 *
 * @implements TransportInterface<string>
 */
class Swoole implements TransportInterface
{
    private string $host;
    private int $port;
    private string $path;
    private bool $ssl;

    /** @var array<string, string> Pre-computed base headers */
    private array $baseHeaders;

    /** @var array<string, mixed> Pre-computed client settings */
    private array $settings;

    private Channel $pool;

    private Atomic $shutdown;

    /**
     * @param 'application/json'|'application/x-ndjson'|'application/x-protobuf' $contentType
     * @param array<string, string> $headers
     */
    public function __construct(
        string $endpoint,
        private string $contentType = ContentTypes::PROTOBUF,
        private array  $headers = [],
        private float  $timeout = 10.0,
        private int $poolSize = 8,
        int $socketBufferSize = 64 * 1024, // 64 KB
    ) {
        $parsed = parse_url($endpoint);
        if ($parsed === false) {
            throw new \InvalidArgumentException("Invalid endpoint URL: {$endpoint}");
        }

        $this->ssl = ($parsed['scheme'] ?? 'http') === 'https';
        $this->host = $parsed['host'] ?? 'localhost';
        $this->port = $parsed['port'] ?? ($this->ssl ? 443 : 80);
        $this->path = $parsed['path'] ?? '/';
        if (isset($parsed['query'])) {
            $this->path .= '?' . $parsed['query'];
        }
        $this->shutdown = new Atomic(0);
        $this->pool = new Channel($this->poolSize);

        $this->baseHeaders = array_merge(
            [
                'Content-Type' => $this->contentType,
                'Connection' => 'keep-alive',
            ],
            $this->headers,
        );
        $this->settings = [
            'timeout' => $this->timeout,
            'connect_timeout' => max(0.5, $this->timeout),
            'read_timeout' => max(1.0, $this->timeout),
            'write_timeout' => max(1.0, $this->timeout),
            'keep_alive' => true,
            'open_tcp_nodelay' => true,
            'open_tcp_keepalive' => true,
            'tcp_keepidle' => 60,
            'tcp_keepinterval' => 5,
            'tcp_keepcount' => 3,
            'socket_buffer_size' => $socketBufferSize,
            'http_compression' => false,
        ];
    }

    /**
     * Get the content type used for requests.
     */
    public function contentType(): string
    {
        return $this->contentType;
    }

    /**
     * @return FutureInterface<string>
     */
    public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if ($this->shutdown->get() === 1) {
            return new ErrorFuture(new Exception('Transport has been shut down'));
        }

        $client = null;
        $forceClose = false;

        try {
            $client = $this->popClient();
            $headers = $this->baseHeaders;
            $headers['Content-Length'] = \strlen($payload);
            $client->setHeaders($headers);
            $client->post($this->path, $payload);
            $statusCode = $client->getStatusCode();

            // Connection error (timeout, reset, etc.)
            if ($statusCode < 0) {
                $forceClose = true;
                $errCode = \is_int($client->errCode) ? $client->errCode : 0;
                $errMsg = \is_string($client->errMsg) ? $client->errMsg : 'Unknown error';

                return new ErrorFuture(new Exception("OTLP connection failed: {$errMsg} (code: {$errCode})"));
            }

            $body = $client->getBody();
            $bodyStr = \is_string($body) ? $body : '';

            if ($statusCode >= 200 && $statusCode < 300) {
                return new CompletedFuture($bodyStr);
            }

            // Server error may need fresh connection
            if ($statusCode >= 500) {
                $forceClose = true;
            }

            $statusCodeStr = \is_int($statusCode) ? (string) $statusCode : 'unknown';
            return new ErrorFuture(new Exception("OTLP export failed with status {$statusCodeStr}: {$bodyStr}"));
        } catch (\Throwable $e) {
            $forceClose = true;

            return new ErrorFuture($e);
        } finally {
            if ($client instanceof \Swoole\Coroutine\Http\Client) {
                $this->putClient($client, $forceClose);
            }
        }
    }

    /**
     * Acquire a client from the pool or create a new one.
     */
    private function popClient(): Client
    {
        $client = $this->pool->pop(0.001);
        if ($client === false) {
            $client = $this->pool->pop(0.05);
        }
        if ($client instanceof Client) {
            if ($client->connected) {
                return $client;
            }
            $client->close();
        }

        $client = new Client($this->host, $this->port, $this->ssl);
        $client->set($this->settings);

        return $client;
    }

    /**
     * Return a client to the pool or close it.
     */
    private function putClient(Client $client, bool $forceClose = false): void
    {
        if ($this->shutdown->get() === 1 || $forceClose || !$client->connected) {
            $client->close();
            return;
        }
        if (!$this->pool->push($client, 1.0)) {
            $client->close();
        }
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        $this->shutdown->set(1);

        // Drain and close all pooled connections
        while (!$this->pool->isEmpty()) {
            $client = $this->pool->pop(0.001);
            if ($client instanceof Client) {
                $client->close();
            }
        }
        $this->pool->close();

        return true;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }
}
