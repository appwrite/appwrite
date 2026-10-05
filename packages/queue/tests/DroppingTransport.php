<?php

declare(strict_types=1);

namespace Utopia\Queue\Tests;

use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Transport\Transport;

/**
 * Lets the first queue publish reach the server, then drops the socket before
 * its ack is read, so the broker republishes on a fresh connection.
 */
final class DroppingTransport implements Transport
{
    private bool $armed;

    public function __construct(private readonly Transport $inner, bool &$armed)
    {
        $this->armed = &$armed;
    }

    public function connect(string $host, int $port, float $timeout): void
    {
        $this->inner->connect($host, $port, $timeout);
    }

    public function write(string $data): int
    {
        if (!$this->armed || !str_contains($data, 'PUB q.')) {
            return $this->inner->write($data);
        }

        $this->armed = false;
        $this->inner->write($data);
        $this->inner->close();

        throw new ConnectionException('Connection closed by server');
    }

    public function read(int $maxBytes, ?float $timeout = null): string
    {
        return $this->inner->read($maxBytes, $timeout);
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

    public function close(): void
    {
        $this->inner->close();
    }
}
