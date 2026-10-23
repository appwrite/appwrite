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
    private const string ADDRESS_NAME = 'IP Address:';

    private readonly string $host;
    private readonly int $port;
    private string $path;
    private readonly bool $ssl;

    /** IP literal an https endpoint names, which the collector certificate must name too */
    private readonly ?string $address;

    /** @var array<string, string> Pre-computed base headers */
    private readonly array $baseHeaders;

    /** @var array<string, mixed> Pre-computed client settings */
    private readonly array $settings;

    private readonly Channel $pool;

    private readonly Atomic $shutdown;

    /**
     * @param 'application/json'|'application/x-ndjson'|'application/x-protobuf' $contentType
     * @param array<string, string> $headers
     * @param string|null $certificateAuthority PEM bundle trusted for HTTPS endpoints; the system store when null
     */
    public function __construct(
        string $endpoint,
        private readonly string $contentType = ContentTypes::PROTOBUF,
        private readonly array  $headers = [],
        private readonly float  $timeout = 10.0,
        private readonly int $poolSize = 8,
        int $socketBufferSize = 64 * 1024, // 64 KB
        ?string $certificateAuthority = null,
    ) {
        $parsed = parse_url($endpoint);
        if ($parsed === false) {
            throw new \InvalidArgumentException("Invalid endpoint URL: {$endpoint}");
        }

        $this->ssl = ($parsed['scheme'] ?? 'http') === 'https';
        $this->host = $parsed['host'] ?? 'localhost';
        $this->port = $parsed['port'] ?? ($this->ssl ? 443 : 80);
        $address = trim($this->host, '[]');
        $this->address = $this->ssl && filter_var($address, FILTER_VALIDATE_IP) !== false ? $address : null;
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
            ...($this->ssl ? $this->tlsSettings($certificateAuthority) : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tlsSettings(?string $certificateAuthority): array
    {
        $settings = [
            'ssl_verify_peer' => true,
            'ssl_allow_self_signed' => false,
            'ssl_host_name' => $this->address === null ? $this->host : '',
        ];

        if ($certificateAuthority !== null) {
            $settings['ssl_cafile'] = $certificateAuthority;
        }

        return $settings;
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

        $headers = $this->baseHeaders;
        $headers['Content-Length'] = \strlen($payload);

        $client = null;
        $forceClose = false;

        try {
            $client = $this->pooledClient();
            $reused = $client instanceof Client;
            $client ??= $this->connect();
            $statusCode = $this->post($client, $headers, $payload);

            if ($reused && $this->isRetryable($statusCode)) {
                $client->close();
                $client = $this->connect();
                $statusCode = $this->post($client, $headers, $payload);
            }

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

            return new ErrorFuture(new Exception("OTLP export failed with status {$statusCode}: {$bodyStr}"));
        } catch (\Throwable $e) {
            $forceClose = true;

            return new ErrorFuture($e);
        } finally {
            if ($client instanceof Client) {
                $this->putClient($client, $forceClose);
            }
        }
    }

    /**
     * Whether a request that failed on a pooled connection can be sent again.
     *
     * Swoole reconnects a pooled socket the collector closed while idle, but not
     * one it dropped with the request in flight. Both statuses below mean the
     * socket died before any answer, so the export never landed and a fresh
     * connection can carry it. A timeout is deliberately not retryable: the
     * collector may have accepted the export and still be working on it, so
     * sending it again would duplicate it and wait out a second timeout.
     *
     * A pooled connection to a collector addressed by IP refuses that silent
     * reconnect (see verifyAddress()), which surfaces as a failed connect before
     * anything was sent, so a fresh, verified connection can carry the export.
     */
    private function isRetryable(int $statusCode): bool
    {
        return match ($statusCode) {
            SWOOLE_HTTP_CLIENT_ESTATUS_SERVER_RESET,
            SWOOLE_HTTP_CLIENT_ESTATUS_SEND_FAILED => true,
            SWOOLE_HTTP_CLIENT_ESTATUS_CONNECT_FAILED => $this->address !== null,
            default => false,
        };
    }

    /**
     * @param array<string, string|int> $headers
     */
    private function post(Client $client, array $headers, string $payload): int
    {
        $client->setHeaders($headers);
        $client->post($this->path, $payload);
        $statusCode = $client->getStatusCode();

        return \is_int($statusCode) ? $statusCode : -1;
    }

    private function pooledClient(): ?Client
    {
        $client = $this->pool->pop(0.001);
        if ($client === false) {
            $client = $this->pool->pop(0.05);
        }
        if (!$client instanceof Client) {
            return null;
        }
        if ($client->connected) {
            return $client;
        }
        $client->close();

        return null;
    }

    private function connect(): Client
    {
        $client = new Client($this->host, $this->port, $this->ssl);
        $client->set($this->settings);

        if ($this->address !== null) {
            $this->verifyAddress($client, $this->address);
        }

        return $client;
    }

    /**
     * Swoole checks a certificate's DNS names but never its IP addresses, so a
     * collector addressed by IP is checked here instead: on a HEAD request for the
     * root that carries none of the endpoint's path, query, headers or payload,
     * before the connection carries an export.
     *
     * @throws Exception When the collector cannot be reached or its certificate does not name the address
     */
    private function verifyAddress(Client $client, string $address): void
    {
        $client->setMethod('HEAD');
        $client->execute('/');

        if ($client->getStatusCode() < 0) {
            $errCode = \is_int($client->errCode) ? $client->errCode : 0;
            $errMsg = \is_string($client->errMsg) ? $client->errMsg : 'Unknown error';
            $client->close();

            throw new Exception("OTLP connection failed: {$errMsg} (code: {$errCode})");
        }

        $certificate = $client->getPeerCert();
        if (!\is_string($certificate) || !\in_array(inet_pton($address), $this->addresses($certificate), true)) {
            $client->close();

            throw new Exception("OTLP connection failed: the collector certificate does not name {$address}");
        }

        // Swoole silently reconnects a socket the collector dropped, which would skip the check above.
        // Naming the address makes that handshake fail instead.
        $client->set(['ssl_host_name' => $address]);
    }

    /**
     * IP addresses a PEM certificate names in its subject alternative names, in packed form.
     *
     * @return array<string>
     */
    private function addresses(string $certificate): array
    {
        $parsed = openssl_x509_parse($certificate);
        $extensions = \is_array($parsed) && \is_array($parsed['extensions'] ?? null) ? $parsed['extensions'] : [];
        $names = \is_string($extensions['subjectAltName'] ?? null) ? $extensions['subjectAltName'] : '';

        $addresses = [];
        foreach (explode(', ', $names) as $name) {
            $packed = str_starts_with($name, self::ADDRESS_NAME) ? inet_pton(substr($name, \strlen(self::ADDRESS_NAME))) : false;
            if ($packed !== false) {
                $addresses[] = $packed;
            }
        }

        return $addresses;
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
