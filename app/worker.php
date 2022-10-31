<?php

require_once __DIR__ . '/init.php';

use Appwrite\DSN\DSN;
use Appwrite\URL\URL as AppwriteURL;
use Swoole\Runtime;
use Utopia\App;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Queue\Server;
use Utopia\Registry\Registry;

global $register;

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

Server::setResource('register', fn() => $register);

Server::setResource('dbForConsole', function (Cache $cache, Registry $register) {
    $pools = $register->get('pools');
    $dbAdapter = $pools
        ->get('console')
        ->pop()
        ->getResource()
    ;

    $database = new Database($dbAdapter, $cache);
    $database->setNamespace('console');

    return $database;
}, ['cache', 'register']);

Server::setResource('cache', function (Registry $register) {
    $pools = $register->get('pools');
    $list = Config::getParam('pools-cache', []);
    $adapters = [];

    foreach ($list as $value) {
        $adapters[] = $pools
            ->get($value)
            ->pop()
            ->getResource()
        ;
    }

    return new Cache(new Sharding($adapters));
}, ['register']);

Server::setResource('ErrorLog', function (Logger $logger) {
    return function (Throwable $error, $action) use ($logger) {

        if ($logger) {
            $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

            if ($error->getCode() >= 500 || $error->getCode() === 0) {
                $log = new Log();

                $log->setNamespace("http");
                $log->setServer(\gethostname());
                $log->setVersion($version);
                $log->setType(Log::TYPE_ERROR);
                $log->setMessage($error->getMessage());

                $log->setAction($action);

                $log->addTag('verboseType', get_class($error));
                $log->addTag('code', $error->getCode());

                $log->addExtra('file', $error->getFile());
                $log->addExtra('line', $error->getLine());
                $log->addExtra('trace', $error->getTraceAsString());
                $log->addExtra('detailedTrace', $error->getTrace());
                $log->addExtra('roles', Authorization::$roles);

                $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
                $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

                $responseCode = $logger->addLog($log);
                Console::info('Log pushed with status code: ' . $responseCode);
            }
        }

        $code    = $error->getCode();
        $message = $error->getMessage();
        $file    = $error->getFile();
        $line    = $error->getLine();
        $trace    = $error->getTrace();

        Console::error('[Error] Timestamp: ' . date('c', time()));
        Console::error('[Error] Type: ' . get_class($error));
        Console::error('[Error] Message: ' . $message);
        Console::error('[Error] File: ' . $file);
        Console::error('[Error] Line: ' . $line);
        Console::error('[Error] Code: ' . $code);
        Console::error('[Error] Trace: ' . $trace);
    };
});

$fallbackForRedis = AppwriteURL::unparse([
    'scheme' => 'redis',
    'host' => App::getEnv('_APP_REDIS_HOST', 'redis'),
    'port' => App::getEnv('_APP_REDIS_PORT', '6379'),
    'user' => App::getEnv('_APP_REDIS_USER', ''),
    'pass' => App::getEnv('_APP_REDIS_PASS', ''),
]);

$connection = App::getEnv('_APP_CONNECTIONS_QUEUE', $fallbackForRedis);
$dsns = explode(',', $connection ?? '');

if (empty($dsns)) {
    Console::error("Dsn not found");
}

$dsn = explode('=', $dsns[0]);
$dsn = $dsn[1] ?? '';
$dsn = new DSN($dsn);
