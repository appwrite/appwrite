<?php

require dirname(__DIR__) . '/vendor/autoload.php';

if (!\extension_loaded('swoole') && !\class_exists(\Swoole\Coroutine::class, false)) {
    require __DIR__ . '/stubs/SwooleCoroutine.php';
}
