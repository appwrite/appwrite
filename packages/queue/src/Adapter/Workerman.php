<?php

namespace Utopia\Queue\Adapter;

use Utopia\DI\Container;
use Utopia\Queue\Adapter;
use Utopia\Queue\Consumer;
use Workerman\Worker;

class Workerman extends Adapter
{
    protected Worker $worker;

    public function __construct(
        Consumer|callable $consumer,
        int $workerNum,
        string $namespace = 'utopia-queue',
        Container $resources = new Container(),
    ) {
        parent::__construct($consumer, $workerNum, $namespace, $resources);

        $this->worker = new Worker();
        $this->worker->count = $workerNum;
    }

    public function start(): self
    {
        Worker::runAll();
        return $this;
    }

    public function stop(): self
    {
        Worker::stopAll();
        return $this;
    }

    public function workerStart(callable $callback): self
    {
        $this->worker->onWorkerStart = function ($worker) use ($callback): void {
            \call_user_func($callback, $worker->workerId);
        };

        return $this;
    }

    public function workerStop(callable $callback): self
    {
        $this->worker->onWorkerStop = function ($worker) use ($callback): void {
            \call_user_func($callback, $worker->workerId);
        };

        return $this;
    }
}
