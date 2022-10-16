<?php

namespace FunctionsProxy\Adapter;

use FunctionsProxy\Adapter;

class RoundRobin extends Adapter
{
    private $currentIndex = 0; // TODO: @Meldiron Put into utopia app resource or/and utopia registry

    public function getNextExecutor(?string $contaierId): array
    {
        $executors = $this->getExecutors();
        $executor = $executors[$this->currentIndex] ?? null;
        $this->currentIndex++;

        if (!$executor) {
            $this->currentIndex = 0;
            $executor = $executors[$this->currentIndex] ?? null;
            $this->currentIndex++;
        }

        return $executor ?? null;
    }
}
