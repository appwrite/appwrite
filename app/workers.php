<?php

use Appwrite\Extend\PDO;
use Utopia\App;
use Appwrite\Resque\Worker;

/** @var Utopia\Registry\Registry $register */

require_once __DIR__.'/init.php';

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

Worker::error(function ($error, $action) use ($register) {
    /** @var Throwable|Exception $error */
    /** @var string $action */

    $logger = $register->get('logger');

    if($logger) {
        $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

        $className = \get_called_class();
        $workerType = $className;

        $log = new Log();

        $log->setNamespace("worker-" . $workerType);
        $log->setServer(\gethostname());
        $log->setVersion($version);
        $log->setType(Log::TYPE_ERROR);
        $log->setMessage($error->getMessage());

        $log->addTag('workerType', $workerType);
        $log->addTag('code', $error->getCode());
        $log->addTag('verboseType', \get_class($error));

        $log->addExtra('file', $error->getFile());
        $log->addExtra('line', $error->getLine());
        $log->addExtra('trace', $error->getTraceAsString());
        // $log->addExtra('args', $this->args);
        // TODO: Test worker error for get_called_class and if args are in trace. What about JWT in args? Other secrets? What about realtime/API?

        $action = 'worker.' . $workerType . '.' . $action;
        $log->setAction($action);

        $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
        $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

        $responseCode = $logger->addLog($log);
        Console::info('Setup log pushed with status code: ' . $responseCode);
    }
});