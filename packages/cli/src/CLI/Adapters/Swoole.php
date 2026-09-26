<?php

namespace Utopia\CLI\Adapters;

use Swoole\Process\Pool;
use Swoole\Runtime;
use Utopia\CLI\Adapter;

class Swoole extends Adapter
{
    protected Pool $pool;

    public function __construct(int $workerNum = 0)
    {
        parent::__construct($workerNum);

        $this->pool = new Pool($workerNum);
    }

    public function start($callback): self
    {
        Runtime::enableCoroutine();
        $this->pool->set(['enable_coroutine' => true]);

        $this->onWorkerStart($callback);
        $this->onWorkerStop(fn() => $this->pool->shutdown());
        $this->pool->start();

        return $this;
    }

    public function stop(): self
    {
        $this->pool->shutdown();

        return $this;
    }

    public function onWorkerStart($callback): self
    {
        $this->pool->on('WorkerStart', function (Pool $pool, string $workerId) use ($callback): void {
            \call_user_func($callback, $workerId);
        });

        return $this;
    }

    public function onWorkerStop(callable $callback): self
    {
        $this->pool->on('WorkerStop', function (Pool $pool, string $workerId) use ($callback): void {
            \call_user_func($callback, $workerId);
        });

        return $this;
    }

    public function onJob(callable $callback): self
    {
        \call_user_func($callback);

        return $this;
    }

    public function getNative(): Pool
    {
        return $this->pool;
    }
}
