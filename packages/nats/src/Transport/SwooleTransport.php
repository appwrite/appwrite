<?php

declare(strict_types=1);

namespace Utopia\NATS\Transport;

use Swoole\Coroutine\Client;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\TimeoutException;

/**
 * Coroutine-native transport built on Swoole\Coroutine\Client.
 *
 * Unlike the stream-based transports, recv()/send() here yield the coroutine
 * scheduler on their own — independent of Swoole's runtime hook flags — so this
 * works even in contexts that disable SWOOLE_HOOK_TCP (e.g. Appwrite's CLI).
 *
 * Requires ext-swoole; the base package does not depend on it. Wire it in via
 * ConnectionOptions::$transportFactory.
 */
final class SwooleTransport implements Transport
{
    private ?Client $client = null;

    // Bytes read from the socket but not yet consumed by read()/readLine().
    private string $buffer = '';

    /** @param array<string,mixed> $tlsOptions */
    public function __construct(
        private readonly bool $secure = false,
        private readonly array $tlsOptions = [],
    ) {}

    public function connect(string $host, int $port, float $timeout): void
    {
        $flags = SWOOLE_SOCK_TCP | ($this->secure ? SWOOLE_SSL : 0);
        $client = new Client($flags);
        $client->set($this->buildSettings($timeout));

        if (!$client->connect($host, $port, $timeout)) {
            throw new ConnectionException("Failed to connect to {$host}:{$port}: [{$client->errCode}] {$client->errMsg}");
        }

        $this->client = $client;
    }

    public function write(string $data): int
    {
        $client = $this->ensureConnected();
        $total = \strlen($data);
        $written = 0;

        // Loop until every byte is on the wire: send() may accept a short write.
        while ($written < $total) {
            $chunk = $client->send($written === 0 ? $data : substr($data, $written));

            if ($chunk === false || $chunk === 0) {
                throw new ConnectionException("Failed to write to socket: [{$client->errCode}] {$client->errMsg}");
            }

            $written += $chunk;
        }

        return $written;
    }

    public function read(int $maxBytes, ?float $timeout = null): string
    {
        if ($this->buffer === '') {
            $this->fill($timeout);
        }

        $chunk = substr($this->buffer, 0, $maxBytes);
        $this->buffer = substr($this->buffer, \strlen($chunk));

        return $chunk;
    }

    public function readLine(?float $timeout = null): string
    {
        $deadline = $timeout !== null ? microtime(true) + $timeout : null;

        while (($pos = strpos($this->buffer, "\n")) === false) {
            $remaining = $deadline !== null ? max(0.0, $deadline - microtime(true)) : null;
            $this->fill($remaining);
        }

        $line = substr($this->buffer, 0, $pos + 1);
        $this->buffer = substr($this->buffer, $pos + 1);

        return $line;
    }

    public function upgradeTls(array $options): void
    {
        // Coroutine-native STARTTLS: handshake on the live connection, avoiding
        // stream_socket_enable_crypto() (which misbehaves under Swoole hooks).
        $client = $this->ensureConnected();
        $client->set($this->buildTlsSettings($options));

        if (!$client->enableSSL()) {
            throw new ConnectionException('Failed to upgrade connection to TLS');
        }
    }

    public function isConnected(): bool
    {
        return $this->client instanceof \Swoole\Coroutine\Client && $this->client->isConnected();
    }

    public function close(): void
    {
        if ($this->client instanceof \Swoole\Coroutine\Client) {
            $this->client->close();
            $this->client = null;
        }
    }

    private function fill(?float $timeout): void
    {
        $client = $this->ensureConnected();
        $data = $client->recv($timeout ?? -1);

        if ($data === '') {
            throw new ConnectionException('Connection closed by server');
        }

        if ($data === false) {
            // ponytail: Swoole sets errCode 110 (ETIMEDOUT) on a recv timeout; anything else is a hard error.
            if ($client->errCode === 110) {
                throw new TimeoutException('Read timed out');
            }
            throw new ConnectionException("Failed to read from socket: [{$client->errCode}] {$client->errMsg}");
        }

        $this->buffer .= $data;
    }

    private function ensureConnected(): Client
    {
        if (!$this->client instanceof \Swoole\Coroutine\Client) {
            throw new ConnectionException('Not connected');
        }

        return $this->client;
    }

    /** @return array<string,mixed> */
    private function buildSettings(float $timeout): array
    {
        $settings = ['timeout' => $timeout];

        if ($this->secure) {
            $settings += $this->buildTlsSettings($this->tlsOptions);
        }

        return $settings;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function buildTlsSettings(array $options): array
    {
        $settings = [];

        if (!empty($options['cafile'])) {
            $settings['ssl_cafile'] = $options['cafile'];
        }
        if (!empty($options['local_cert'])) {
            $settings['ssl_cert_file'] = $options['local_cert'];
        }
        if (!empty($options['local_pk'])) {
            $settings['ssl_key_file'] = $options['local_pk'];
        }
        if (!empty($options['peer_name'])) {
            $settings['ssl_host_name'] = $options['peer_name'];
        }
        $settings['ssl_verify_peer'] = $options['verify_peer'] ?? true;

        return $settings;
    }
}
