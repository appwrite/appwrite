<?php

declare(strict_types=1);

namespace Utopia\DNS\Adapter\Native;

use Socket;
use Utopia\DNS\Protocol;

/**
 * A single protocol endpoint multiplexed into the Native adapter's
 * shared select loop. UDP and TCP transports can bind the same port number.
 */
abstract class Transport
{
    public function __construct(
        public readonly string $host = '0.0.0.0',
        public readonly int $port = 53,
    ) {}

    /**
     * Create and bind the transport's sockets. Called once before the loop starts.
     */
    abstract public function bind(): void;

    /**
     * Sockets to watch for readability.
     *
     * @return list<Socket>
     */
    abstract public function getSockets(): array;

    /**
     * Handle one of this transport's sockets becoming readable.
     *
     * @param callable(string $buffer, string $ip, int $port, Protocol $protocol): string $onPacket
     */
    abstract public function onReadable(Socket $socket, callable $onPacket): void;

    /**
     * Periodic housekeeping between select ticks (idle connection cleanup).
     */
    public function tick(): void {}
}
