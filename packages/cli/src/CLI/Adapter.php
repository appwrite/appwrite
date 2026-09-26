<?php

declare(strict_types=1);

namespace Utopia\CLI;

abstract class Adapter
{
    public int $workerNum;

    public function __construct(int $workerNum = 0)
    {
        $this->workerNum = $workerNum;
    }

    /**
     * Starts the Server.
     *
     * @param $callback
     */
    abstract public function start($callback): self;

    /**
     * Stops the Server.
     */
    abstract public function stop(): self;

    /**
     * Is called when a Worker starts.
     */
    abstract public function onWorkerStart(callable $callback): self;

    /**
     * Is called when a Worker stops.
     */
    abstract public function onWorkerStop(callable $callback): self;

    /**
     * Is called when a job is processed.
     */
    abstract public function onJob(callable $callback): self;
}
