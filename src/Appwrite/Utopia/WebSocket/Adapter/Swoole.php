<?php

namespace Appwrite\Utopia\WebSocket\Adapter;

use Swoole\WebSocket\Server;
use Utopia\WebSocket\Adapter\Swoole as SwooleAdapter;

/**
 * Adds the worker-exit hook the base adapter leaves out. Unlike onWorkerStop,
 * it runs while the exiting worker's event loop is still alive, so connections
 * the worker holds can still be closed through the normal onClose path.
 */
class Swoole extends SwooleAdapter
{
    public function __construct(string $host = '0.0.0.0', int $port = 80)
    {
        parent::__construct($host, $port);

        // The exit hook only fires on an asynchronous exit; the wait is how long
        // Swoole lets the loop drain before it kills the worker anyway. Whoever
        // stops the process caps it well before this -- a Cloud pod leaves ~10s
        // after its preStop -- so keep it high enough not to be the tighter limit,
        // since a worker holding thousands of connections needs every second.
        $this->config['reload_async'] = true;
        $this->config['max_wait_time'] = 15;
    }

    public function onWorkerExit(callable $callback): self
    {
        $this->server->on('workerExit', function (Server $server, int $workerId) use ($callback) {
            call_user_func($callback, $workerId);
        });

        return $this;
    }
}
