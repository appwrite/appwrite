<?php

namespace Utopia\CLI\Adapters;

use Utopia\CLI\Adapter;

class Generic extends Adapter
{
    public function start($callback): self
    {
        $callback();

        return $this;
    }

    public function stop(): self
    {
        return $this;
    }

    public function onWorkerStart($callback): self
    {
        return $this;
    }

    public function onWorkerStop(callable $callback): self
    {
        return $this;
    }

    public function onJob(callable $callback): self
    {
        \call_user_func($callback);

        return $this;
    }
}
