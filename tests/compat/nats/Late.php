<?php

namespace Tests\Compat\Nats;

use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\Transport\Transport;

/**
 * The PHP tests' LateReadTransport (packages/nats/tests/Support/
 * LateReadTransport.php): a transport decorator for a reply the reader gets
 * late. `delay` holds the next read back, the reply waiting on the socket;
 * `arrive_after` makes nothing readable until then, as a reply still in
 * transit. The twin of `Late` in crates/tools/compat/src/libs/nats.rs.
 */
final class Late implements Transport
{
    private float $delay = 0.0;

    private float $arrival = 0.0;

    public function __construct(private readonly Transport $inner)
    {
    }

    public function delayNextRead(float $seconds): void
    {
        $this->delay = $seconds;
    }

    public function arriveAfter(float $seconds): void
    {
        $this->arrival = microtime(true) + $seconds;
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
        $this->hold();

        return $this->inner->read($maxBytes, $timeout);
    }

    public function readLine(?float $timeout = null): string
    {
        $this->hold();

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

    private function hold(): void
    {
        if ($this->delay > 0) {
            usleep((int) ($this->delay * 1_000_000));
            $this->delay = 0.0;
        }
        if (microtime(true) < $this->arrival) {
            usleep(1_000);
            throw new TimeoutException('Nothing has arrived yet');
        }
    }
}
