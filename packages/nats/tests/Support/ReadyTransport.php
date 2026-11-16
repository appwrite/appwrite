<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Support;

use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\Transport\Pollable;
use Utopia\NATS\Transport\Transport;

/**
 * Transport decorator for a socket that always looks readable yet has no NATS
 * bytes for the reader, as one does with only a WebSocket ping, a TLS record
 * without application data, or a stale error code behind it. A read with
 * nothing to return waits out its timeout, as a read on a real socket does.
 */
final class ReadyTransport implements Pollable, Transport
{
    public function __construct(private readonly Transport $inner)
    {
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
        try {
            return $this->inner->read($maxBytes, $timeout);
        } catch (TimeoutException $error) {
            usleep((int) (($timeout ?? 0.0) * 1_000_000));
            throw $error;
        }
    }

    public function readLine(?float $timeout = null): string
    {
        return $this->inner->readLine($timeout);
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
        return true;
    }

    public function close(): void
    {
        $this->inner->close();
    }
}
