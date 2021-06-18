<?php

use Appwrite\Realtime\Server;
use Utopia\App;

require_once __DIR__ . '/init.php';

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$config = [
    'package_max_length' => 64000 // Default maximum Package Size (64kb)
];

$realtimeServer = new Server($register, port: App::getEnv('PORT', 80), config: $config);
