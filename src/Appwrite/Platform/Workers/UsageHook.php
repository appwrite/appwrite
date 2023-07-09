<?php

namespace Appwrite\Platform\Workers;

use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Swoole\Timer;

class UsageHook extends Usage {

    public function __construct()
    {
        $this
            ->setType(Action::TYPE_WORKER_START)
            ->inject('register')
            ->inject('cache')
            ->inject('pools')
            ->callback(function($register, $cache, $pools){
                $this->action($register, $cache, $pools);
            })
        ;
    }

    public static function getName(): string
    {
        return 'usageHook';
    }

    public function action($register, $cache, $pools): void
    {
        Console::info('Usage worker, worker start loop');
        Timer::tick(30000, function () use ($register, $cache, $pools) {
            Console::info('Inside usage worker start loop');
        });
    }
}