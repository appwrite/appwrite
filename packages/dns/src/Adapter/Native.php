<?php

namespace Utopia\DNS\Adapter;

use Exception;
use Utopia\DNS\Adapter;
use Utopia\DNS\Adapter\Native\Transport;
use Utopia\DNS\Protocol;

/**
 * Composes one or more Native transports into a single blocking select loop.
 * UDP and TCP transports can bind the same port number.
 */
class Native extends Adapter
{
    /** @var callable(string $buffer, string $ip, int $port, Protocol $protocol): string */
    protected mixed $onPacket;

    /** @var list<callable(int $workerId): void> */
    protected array $onWorkerStart = [];

    /**
     * @param list<Transport> $transports
     */
    public function __construct(protected array $transports)
    {
        if ($transports === []) {
            throw new Exception('At least one transport is required.');
        }
    }

    /**
     * Worker start callback
     *
     * @param callable(int $workerId): void $callback
     * @phpstan-param callable(int $workerId): void $callback
     */
    public function onWorkerStart(callable $callback): void
    {
        $this->onWorkerStart[] = $callback;
    }

    /**
     * @phpstan-param callable(string $buffer, string $ip, int $port, Protocol $protocol): string $callback
     */
    public function onPacket(callable $callback): void
    {
        $this->onPacket = $callback;
    }

    /**
     * Start the DNS server
     */
    public function start(): void
    {
        foreach ($this->transports as $transport) {
            $transport->bind();
        }

        foreach ($this->onWorkerStart as $callback) {
            \call_user_func($callback, 0);
        }

        /** @phpstan-ignore-next-line */
        while (1) {
            $read = [];
            $owners = [];

            foreach ($this->transports as $transport) {
                // Idle connection housekeeping (select below wakes at least once a second)
                $transport->tick();

                foreach ($transport->getSockets() as $socket) {
                    $read[] = $socket;
                    $owners[spl_object_id($socket)] = $transport;
                }
            }

            $write = [];
            $except = [];

            $changed = socket_select($read, $write, $except, 1);
            if ($changed === false) {
                continue;
            }
            if ($changed === 0) {
                continue;
            }

            foreach ($read as $socket) {
                $owners[spl_object_id($socket)]->onReadable($socket, $this->onPacket);
            }
        }
    }
}
