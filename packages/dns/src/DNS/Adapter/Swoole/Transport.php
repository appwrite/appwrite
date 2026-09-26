<?php

declare(strict_types=1);

namespace Utopia\DNS\Adapter\Swoole;

use Swoole\Server;
use Swoole\Server\Port;
use Utopia\DNS\Protocol;

/**
 * A single protocol endpoint composed onto a shared Swoole server.
 *
 * Transports of one Swoole adapter run in one process and worker pool,
 * so UDP and TCP can listen on the same port number.
 */
abstract class Transport
{
    public function __construct(
        public readonly string $host = '0.0.0.0',
        public readonly int $port = 53,
    ) {}

    /**
     * Socket type used to create the listener (SWOOLE_SOCK_UDP, SWOOLE_SOCK_TCP, ...).
     */
    abstract public function getSockType(): int;

    /**
     * Listener settings applied via Server::set() (master) or Port::set() (secondary).
     *
     * @return array<string, mixed>
     */
    abstract public function getSettings(): array;

    /**
     * Register event handlers. $target is the Server itself when this transport
     * is the master listener, or the Port returned by addListener() otherwise.
     *
     * @param callable(string $buffer, string $ip, int $port, Protocol $protocol): string $onPacket
     */
    abstract public function attach(Server|Port $target, callable $onPacket): void;
}
