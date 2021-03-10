<?php

namespace Appwrite\Resque;

use Swoole\Runtime;

use function Swoole\Coroutine\run;

abstract class Worker
{
    public $args = [];

    abstract public function init(): void;
    
    abstract public function execute(): void;
    
    abstract public function shutdown(): void;

    public function setUp(): void
    {
        run(function() {
            Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

            $this->init();
        });
    }

    public function perform()
    {
        run(function() {
            Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

            $this->execute();
        });
    }

    public function tearDown(): void
    {
        run(function() {
            Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

            $this->shutdown();
        });
    }
}
