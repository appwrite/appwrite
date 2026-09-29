<?php

declare(strict_types=1);

namespace Utopia\NATS\Transport;

use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\TimeoutException;

final class TlsTransport implements Transport
{
    /** @var resource|null */
    private $stream;

    public function __construct(
        private readonly array $tlsOptions = [],
    ) {}

    public function connect(string $host, int $port, float $timeout): void
    {
        $address = "tls://{$host}:{$port}";
        $errno = 0;
        $errstr = '';

        $context = stream_context_create(['ssl' => $this->buildSslOptions()]);

        $stream = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            throw new ConnectionException("Failed to connect to {$address}: [{$errno}] {$errstr}");
        }

        stream_set_blocking($stream, true);
        $seconds = (int) $timeout;
        $microseconds = (int) (($timeout - $seconds) * 1_000_000);
        stream_set_timeout($stream, $seconds, $microseconds);

        $this->stream = $stream;
    }

    public function write(string $data): int
    {
        $this->ensureConnected();
        $total = \strlen($data);
        $written = 0;

        // Loop until every byte is on the wire: fwrite may perform a short write.
        // Re-resolved on every pass rather than captured once. fwrite() is a
        // yield point under Swoole's stream hooks, so a close() can land between
        // two iterations of a short write -- and the next fwrite() on the
        // handle the first pass captured raises TypeError, an \Error, which is
        // precisely what the reconnect paths in Connection do not catch. The
        // classification helpers below were hardened for this; the write itself
        // was still holding the stale handle.
        while ($written < $total) {
            $chunk = @fwrite($this->ensureConnected(), substr($data, $written));

            if ($chunk === false || $chunk === 0) {
                if ($this->isTimedOut()) {
                    throw new TimeoutException('Write timed out');
                }
                if ($this->isAtEof()) {
                    throw new ConnectionException('Connection closed by server');
                }
                throw new ConnectionException('Failed to write to TLS socket');
            }

            $written += $chunk;
        }

        return $written;
    }

    public function read(int $maxBytes, ?float $timeout = null): string
    {
        $stream = $this->ensureConnected();

        if ($timeout !== null) {
            $seconds = (int) $timeout;
            $microseconds = (int) (($timeout - $seconds) * 1_000_000);
            stream_set_timeout($stream, $seconds, $microseconds);
        }

        $data = @fread($stream, $maxBytes);

        if ($data === false) {
            if ($this->isTimedOut()) {
                throw new TimeoutException('Read timed out');
            }
            throw new ConnectionException('Failed to read from TLS socket');
        }

        if ($data === '' && $this->isAtEof()) {
            throw new ConnectionException('Connection closed by server');
        }

        return $data;
    }

    public function readLine(?float $timeout = null): string
    {
        $stream = $this->ensureConnected();

        if ($timeout !== null) {
            $seconds = (int) $timeout;
            $microseconds = (int) (($timeout - $seconds) * 1_000_000);
            stream_set_timeout($stream, $seconds, $microseconds);
        }

        $line = @fgets($stream);

        if ($line === false) {
            if ($this->isTimedOut()) {
                throw new TimeoutException('Read timed out');
            }
            if ($this->isAtEof()) {
                throw new ConnectionException('Connection closed by server');
            }
            throw new ConnectionException('Failed to read line from TLS socket');
        }

        return $line;
    }

    public function upgradeTls(array $options): void
    {
        // Already TLS, nothing to do
    }

    public function isConnected(): bool
    {
        return \is_resource($this->stream) && !feof($this->stream);
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    private function buildSslOptions(): array
    {
        $opts = [
            'verify_peer' => $this->tlsOptions['verify_peer'] ?? true,
            'verify_peer_name' => $this->tlsOptions['verify_peer_name'] ?? true,
        ];

        if (isset($this->tlsOptions['cafile'])) {
            $opts['cafile'] = $this->tlsOptions['cafile'];
        }
        if (isset($this->tlsOptions['local_cert'])) {
            $opts['local_cert'] = $this->tlsOptions['local_cert'];
        }
        if (isset($this->tlsOptions['local_pk'])) {
            $opts['local_pk'] = $this->tlsOptions['local_pk'];
        }
        if (isset($this->tlsOptions['peer_name'])) {
            $opts['peer_name'] = $this->tlsOptions['peer_name'];
        }

        return $opts;
    }

    /** @return resource */
    private function ensureConnected()
    {
        // See TcpTransport::ensureConnected(): a concurrently closed stream is
        // still a resource-typed property but raises TypeError on use, and that
        // \Error bypasses the reconnect paths in Connection.
        if (!\is_resource($this->stream)) {
            throw new ConnectionException('Not connected');
        }

        return $this->stream;
    }

    /**
     * Whether the stream hit its timeout, re-reading the property rather than
     * trusting a caller's copy that a concurrent close() may have invalidated.
     */
    private function isTimedOut(): bool
    {
        if (!\is_resource($this->stream)) {
            return false;
        }

        return stream_get_meta_data($this->stream)['timed_out'];
    }

    /**
     * Whether the stream is at end of file. A closed or vanished stream counts
     * as EOF so callers report "connection closed" rather than raising.
     */
    private function isAtEof(): bool
    {
        if (!\is_resource($this->stream)) {
            return true;
        }

        return feof($this->stream);
    }
}
