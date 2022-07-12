<?php

namespace FunctionsProxy\Adapter;

use FunctionsProxy\Adapter;


class RoundRobin extends Adapter
{
    private $currentIndex = 0; // TODO: Put into redis to share across proxies

    public function getNextExecutor(): string
    {
        $executors = $this->getExecutors();
        $executor = $executors[$this->currentIndex] ?? null;
        $this->currentIndex++;

        if (!$executor) {
            $this->currentIndex = 0;
            $executor = $executors[$this->currentIndex] ?? null;
            $this->currentIndex++;
        }

        return $executor['hostname'] ?? null;
    }
}
