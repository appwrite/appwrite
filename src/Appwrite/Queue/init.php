<?php

namespace Appwrite\Queue;

use PDOException;
use Throwable;
use Utopia\Queue\Message;
use Utopia\Queue\Server;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;

Server::setResource('register', fn() => $register);

Server::setResource('logger', function ($register) {
    return $register->get('logger');
}, ['register']);

function getDB(string $type, string $projectId = '', string $projectInternalId = ''): Database
{
    global $register;

    $namespace = '';
    $sleep = DATABASE_RECONNECT_SLEEP; // overwritten when necessary

    switch ($type) {
        case 'project':
            if (!$projectId) {
                throw new \Exception('ProjectID not provided - cannot get database');
            }
            $namespace = "_{$projectInternalId}";
            break;
        case 'console':
            $namespace = "_console";
            $sleep = 5; // ConsoleDB needs extra sleep time to ensure tables are created
            break;
        default:
            throw new \Exception('Unknown database type: ' . $type);
            break;
    }

    $attempts = 0;

    do {
        try {
            $attempts++;
            $cache = new Cache(new RedisCache($register->get('cache')));
            $database = new Database(new MariaDB($register->get('db')), $cache);
            $database->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
            $database->setNamespace($namespace); // Main DB

            if (!empty($projectId) && !$database->getDocument('projects', $projectId)->isEmpty()) {
                throw new \Exception("Project does not exist: {$projectId}");
            }

            if ($type === 'console' && !$database->exists($database->getDefaultDatabase(), Database::METADATA)) {
                throw new \Exception('Console project not ready');
            }

            break; // leave loop if successful
        } catch (\Exception $e) {
            Console::warning("Database not ready. Retrying connection ({$attempts})...");
            if ($attempts >= DATABASE_RECONNECT_MAX_ATTEMPTS) {
                throw new \Exception('Failed to connect to database: ' . $e->getMessage());
            }
            sleep($sleep);
        }
    } while ($attempts < DATABASE_RECONNECT_MAX_ATTEMPTS);

    return $database;
}

Server::setResource('dbForProject', function(Message $message) {
    $project = new Document($message->getPayload()['project'] ?? []);
    $projectDb = getDB('project', $project->getId(), $project->getInternalId());

    return $projectDb;
}, ['message']);

Server::setResource('logError', function(Logger $logger) {
    $logError = function(Throwable $error) use ($logger) {
        /** Delegate PDO exceptions to the global handler so the database connection can be returned to the pool */
        if ($error instanceof PDOException) {
            throw $error;
        }

        if ($logger) {
            $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

            if ($error->getCode() >= 500 || $error->getCode() === 0) {
                $log = new Log();

                $log->setNamespace("http");
                $log->setServer(\gethostname());
                $log->setVersion($version);
                $log->setType(Log::TYPE_ERROR);
                $log->setMessage($error->getMessage());

                $log->addTag('verboseType', get_class($error));
                $log->addTag('code', $error->getCode());

                $log->addExtra('file', $error->getFile());
                $log->addExtra('line', $error->getLine());
                $log->addExtra('trace', $error->getTraceAsString());
                $log->addExtra('detailedTrace', $error->getTrace());
                $log->addExtra('roles', Authorization::$roles);
                // TODO: @Meldiron Add project ID

                $log->setAction("appwrite-worker"); // TODO: @Meldiron get action

                $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
                $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

                $responseCode = $logger->addLog($log);
                Console::info('Log pushed with status code: ' . $responseCode);
            }
        }

        $code = $error->getCode();
        $message = $error->getMessage();
        $file = $error->getFile();
        $line = $error->getLine();
        $trace = $error->getTrace();

        Console::error('[Error] Timestamp: ' . date('c', time()));
        Console::error('[Error] Type: ' . get_class($error));
        Console::error('[Error] Message: ' . $message);
        Console::error('[Error] File: ' . $file);
        Console::error('[Error] Line: ' . $line);
        Console::error('[Error] Code: ' . $code);
        Console::error('[Error] Trace: ' . $trace);
    };
    
    return $logError;
});