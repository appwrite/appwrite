<?php

declare(strict_types=1);

namespace Utopia\DNS;

abstract class Adapter
{
    /**
     * Worker start
     *
     * @param callable(int $workerId): void $callback
     * @phpstan-param callable(int $workerId): void $callback
     */
    abstract public function onWorkerStart(callable $callback): void;

    /**
     * Packet handler
     *
     * @param callable(string $buffer, string $ip, int $port, Protocol $protocol): string $callback
     * @phpstan-param callable(string $buffer, string $ip, int $port, Protocol $protocol):string $callback
     */
    abstract public function onPacket(callable $callback): void;

    /**
     * Start the DNS server
     */
    abstract public function start(): void;
}
