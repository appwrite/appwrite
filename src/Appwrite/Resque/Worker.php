<?php

namespace Appwrite\Resque;

use Swoole\Runtime;

use function Swoole\Coroutine\run;

abstract class Worker
{
    public $args = [];

    abstract public function init(): void;

    abstract public function run(): void;

    abstract public function shutdown(): void;

    public function setUp(): void
    {
        $this->init();
    }

    public function perform()
    {
        $this->run();
    }

    public function tearDown(): void
    {
        $this->shutdown();
    }
}