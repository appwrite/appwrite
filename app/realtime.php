<?php

use Appwrite\Extend\PDO;
use Appwrite\Realtime\Server;
use Utopia\App;

require_once __DIR__ . '/init.php';

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$config = [
    'package_max_length' => 64000 // Default maximum Package Size (64kb)
];

$register->set('db', function () {
    $dbHost = App::getEnv('_APP_DB_HOST', '');
    $dbUser = App::getEnv('_APP_DB_USER', '');
    $dbPass = App::getEnv('_APP_DB_PASS', '');
    $dbScheme = App::getEnv('_APP_DB_SCHEMA', '');

    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbScheme};charset=utf8mb4", $dbUser, $dbPass, array(
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
        PDO::ATTR_TIMEOUT => 3, // Seconds
        PDO::ATTR_PERSISTENT => true,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ));

    return $pdo;
});
$register->set('cache', function () { // Register cache connection
    $redis = new Redis();
    $redis->pconnect(App::getEnv('_APP_REDIS_HOST', ''), App::getEnv('_APP_REDIS_PORT', ''));
    $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);

    return $redis;
});

$realtimeServer = new Server($register, config: $config);
