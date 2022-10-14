<?php

namespace FunctionsProxy\Adapter;

use FunctionsProxy\Adapter;

class Random extends Adapter
{
    public function getNextExecutor(): array
    {
        $executors = $this->getExecutors();
        $executor = $executors[\array_rand($executors)] ?? null;

        \var_dump($executor);
        
        return $executor ?? null;
    }
}
