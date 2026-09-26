<?php

namespace Utopia\DNS\Adapter;

use Exception;
use Swoole\Http\Server as HttpServer;
use Swoole\Runtime;
use Swoole\Server;
use Swoole\Server\Port;
use Utopia\DNS\Adapter;
use Utopia\DNS\Adapter\Swoole\Http;
use Utopia\DNS\Adapter\Swoole\Transport;
use Utopia\DNS\Protocol;

/**
 * Composes one or more Swoole transports onto a single Swoole server,
 * sharing one process and worker pool. UDP and TCP transports can bind
 * the same port number; each HTTP transport needs its own TCP port.
 */
class Swoole extends Adapter
{
    protected Server $server;

    /** @var list<array{Transport, Server|Port}> */
    protected array $listeners = [];

    /**
     * @param list<Transport> $transports
     * @param int $idleTimeout Seconds before idle TCP connections are closed (RFC 7766)
     */
    public function __construct(
        array $transports,
        protected int $workers = 1,
        protected int $maxCoroutines = 3000,
        protected int $idleTimeout = 30,
    ) {
        if ($transports === []) {
            throw new Exception('At least one transport is required.');
        }

        // HTTP request events are only dispatched by Swoole\Http\Server,
        // so an HTTP transport must provide the master listener.
        usort($transports, fn(Transport $a, Transport $b): int => ($b instanceof Http) <=> ($a instanceof Http));

        $master = $transports[0];
        $this->server = $master instanceof Http
            ? new HttpServer($master->host, $master->port, SWOOLE_PROCESS, $master->getSockType())
            : new Server($master->host, $master->port, SWOOLE_PROCESS, $master->getSockType());

        $this->server->set($master->getSettings() + [
            'worker_num' => $this->workers,
            'max_coroutine' => $this->maxCoroutines,
            // RFC 7766 Section 6.2.3: close idle TCP connections so slow
            // clients cannot hold per-connection buffers open indefinitely
            'heartbeat_idle_time' => $this->idleTimeout,
            'heartbeat_check_interval' => max(1, intdiv($this->idleTimeout, 3)),
        ]);

        $this->listeners[] = [$master, $this->server];

        foreach (\array_slice($transports, 1) as $transport) {
            $port = $this->server->addListener($transport->host, $transport->port, $transport->getSockType());

            if (!$port instanceof Port) {
                throw new Exception(\sprintf('Could not listen on %s:%d.', $transport->host, $transport->port));
            }

            $settings = $transport->getSettings();
            if ($settings !== []) {
                $port->set($settings);
            }

            $this->listeners[] = [$transport, $port];
        }
    }

    /**
     * Worker start callback
     *
     * @param callable(int $workerId): void $callback
     */
    public function onWorkerStart(callable $callback): void
    {
        $this->server->on('WorkerStart', function ($server, $workerId) use ($callback): void {
            if (\is_int($workerId)) {
                \call_user_func($callback, $workerId);
            }
        });
    }

    /**
     * @phpstan-param callable(string $buffer, string $ip, int $port, Protocol $protocol): string $callback
     */
    public function onPacket(callable $callback): void
    {
        foreach ($this->listeners as [$transport, $target]) {
            $transport->attach($target, $callback);
        }
    }

    /**
     * Start the DNS server
     */
    public function start(): void
    {
        Runtime::enableCoroutine();
        $this->server->start();
    }
}
