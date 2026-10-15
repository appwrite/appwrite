<?php

declare(strict_types=1);

namespace Utopia\NATS\Transport;

use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\TimeoutException;

final class TcpTransport implements Transport
{
    /** @var resource|null */
    private $stream;

    public function connect(string $host, int $port, float $timeout): void
    {
        $address = "tcp://{$host}:{$port}";
        $errno = 0;
        $errstr = '';

        $stream = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            // Nagle off. Every request-reply here writes the inbox SUB and then
            // the request PUB before reading, and Nagle holds that second write
            // until the peer's delayed-ACK timer fires -- around 40ms per
            // exchange, on loopback as readily as over a network.
            stream_context_create(['socket' => ['tcp_nodelay' => true]]),
        );

        if ($stream === false) {
            throw new ConnectionException("Failed to connect to {$address}: [{$errno}] {$errstr}");
        }

        stream_set_blocking($stream, true);
        $this->setTimeout($stream, $timeout);
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
                throw new ConnectionException('Failed to write to socket');
            }

            $written += $chunk;
        }

        return $written;
    }

    public function read(int $maxBytes, ?float $timeout = null): string
    {
        $stream = $this->ensureConnected();

        if ($timeout !== null) {
            $this->setTimeout($stream, $timeout);
        }

        $data = @fread($stream, $maxBytes);

        if ($data === false) {
            if ($this->isTimedOut()) {
                throw new TimeoutException('Read timed out');
            }
            throw new ConnectionException('Failed to read from socket');
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
            $this->setTimeout($stream, $timeout);
        }

        $line = @fgets($stream);

        if ($line === false) {
            if ($this->isTimedOut()) {
                throw new TimeoutException('Read timed out');
            }
            if ($this->isAtEof()) {
                throw new ConnectionException('Connection closed by server');
            }
            throw new ConnectionException('Failed to read line from socket');
        }

        return $line;
    }

    public function upgradeTls(array $options): void
    {
        $stream = $this->ensureConnected();

        $contextOptions = ['ssl' => []];

        if (isset($options['cafile'])) {
            $contextOptions['ssl']['cafile'] = $options['cafile'];
        }
        if (isset($options['local_cert'])) {
            $contextOptions['ssl']['local_cert'] = $options['local_cert'];
        }
        if (isset($options['local_pk'])) {
            $contextOptions['ssl']['local_pk'] = $options['local_pk'];
        }
        if (isset($options['peer_name'])) {
            $contextOptions['ssl']['peer_name'] = $options['peer_name'];
        }
        $contextOptions['ssl']['verify_peer'] = $options['verify_peer'] ?? true;
        $contextOptions['ssl']['verify_peer_name'] = $options['verify_peer_name'] ?? true;

        $context = stream_context_get_options($stream);
        $merged = array_merge_recursive($context, $contextOptions);
        foreach ($merged as $wrapper => $opts) {
            foreach ($opts as $key => $value) {
                stream_context_set_option($stream, $wrapper, $key, \is_array($value) ? end($value) : $value);
            }
        }

        $result = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);

        if ($result !== true) {
            throw new ConnectionException('Failed to upgrade connection to TLS');
        }
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

    /** @return resource */
    private function ensureConnected()
    {
        // A null stream means close() ran; a non-resource stream means it ran
        // concurrently and left a closed handle behind. Both are "not
        // connected", and the second must be caught here because every stream
        // function raises TypeError on a closed resource -- an \Error, which
        // the reconnect paths in Connection do not catch.
        if (!\is_resource($this->stream)) {
            throw new ConnectionException('Not connected');
        }

        return $this->stream;
    }

    /** @param resource $stream */
    private function setTimeout($stream, float $timeout): void
    {
        $seconds = (int) $timeout;
        $microseconds = (int) (($timeout - $seconds) * 1_000_000);
        stream_set_timeout($stream, $seconds, $microseconds);
    }

    /**
     * Whether the stream hit its timeout. Re-reads the property instead of
     * trusting the caller's copy: a concurrent close() invalidates the resource
     * the caller captured, and stream_get_meta_data() would raise TypeError on
     * it. A stream that is gone did not time out -- it is EOF.
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
