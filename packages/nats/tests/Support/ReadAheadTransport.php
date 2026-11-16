<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Support;

use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\Transport\Pollable;
use Utopia\NATS\Transport\Transport;

/**
 * Transport decorator that lets a test wait for the server to close the socket
 * without a sleep. awaitClose() reads ahead until the real transport reports the
 * close and keeps what it read, so the client is still served every byte the
 * server sent, and then the close itself from the real transport.
 */
final class ReadAheadTransport implements Pollable, Transport
{
    private const float READ_TIMEOUT = 0.1;

    private string $buffer = '';

    public function __construct(private readonly Transport $inner)
    {
    }

    public function awaitClose(float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;
        while (($remaining = $deadline - microtime(true)) > 0) {
            try {
                $this->buffer .= $this->inner->read(65536, min($remaining, self::READ_TIMEOUT));
            } catch (TimeoutException) {
            } catch (ConnectionException) {
                return true;
            }
        }

        return false;
    }

    public function connect(string $host, int $port, float $timeout): void
    {
        $this->inner->connect($host, $port, $timeout);
    }

    public function write(string $data): int
    {
        return $this->inner->write($data);
    }

    public function read(int $maxBytes, ?float $timeout = null): string
    {
        if ($this->buffer === '') {
            return $this->inner->read($maxBytes, $timeout);
        }

        $chunk = substr($this->buffer, 0, $maxBytes);
        $this->buffer = substr($this->buffer, \strlen($chunk));

        return $chunk;
    }

    public function readLine(?float $timeout = null): string
    {
        $end = strpos($this->buffer, "\n");
        if ($end === false) {
            $partial = $this->buffer;
            $this->buffer = '';

            return $partial . $this->inner->readLine($timeout);
        }

        $line = substr($this->buffer, 0, $end + 1);
        $this->buffer = substr($this->buffer, $end + 1);

        return $line;
    }

    /** @param array<string, mixed> $options */
    public function upgradeTls(array $options): void
    {
        $this->inner->upgradeTls($options);
    }

    public function isConnected(): bool
    {
        return $this->inner->isConnected();
    }

    public function poll(): bool
    {
        return $this->buffer !== '' || ($this->inner instanceof Pollable && $this->inner->poll());
    }

    public function close(): void
    {
        $this->inner->close();
    }
}
