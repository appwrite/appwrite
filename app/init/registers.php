<?php

use Appwrite\Extend\Exception;
use Appwrite\GraphQL\Promises\Adapter\Swoole;
use Appwrite\Hooks\Hooks;
use Appwrite\PubSub\Adapter\Redis as PubSub;
use Appwrite\URL\URL as AppwriteURL;
use MaxMind\Db\Reader;
use PHPMailer\PHPMailer\PHPMailer;
use Swoole\Database\PDOProxy;
use Utopia\App;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\SQL;
use Utopia\Database\PDO;
use Utopia\Domains\Validator\PublicDomain;
use Utopia\DSN\DSN;
use Utopia\Logger\Adapter\AppSignal;
use Utopia\Logger\Adapter\LogOwl;
use Utopia\Logger\Adapter\Raygun;
use Utopia\Logger\Adapter\Sentry;
use Utopia\Logger\Logger;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;
use Utopia\Queue;
use Utopia\Registry\Registry;
use Utopia\System\System;

$register = new Registry();

App::setMode(System::getEnv('_APP_ENV', App::MODE_TYPE_PRODUCTION));

if (!App::isProduction()) {
    // Allow specific domains to skip public domain validation in dev environment
    // Useful for existing tests involving webhooks
    PublicDomain::allow(['request-catcher-sms']);
    PublicDomain::allow(['request-catcher-webhook']);
}

$register->set('logger', function () {
    // Register error logger
    $providerName = System::getEnv('_APP_LOGGING_PROVIDER', '');
    $providerConfig = System::getEnv('_APP_LOGGING_CONFIG', '');

    if (empty($providerConfig)) {
        return;
    }

    try {
        $loggingProvider = new DSN($providerConfig ?? '');

        $providerName = $loggingProvider->getScheme();
        $providerConfig = match ($providerName) {
            'sentry' => ['key' => $loggingProvider->getPassword(), 'projectId' => $loggingProvider->getUser() ?? '', 'host' => 'https://' . $loggingProvider->getHost()],
            'logowl' => ['ticket' => $loggingProvider->getUser() ?? '', 'host' => $loggingProvider->getHost()],
            default => ['key' => $loggingProvider->getHost()],
        };
    } catch (Throwable $th) {
        // Fallback for older Appwrite versions up to 1.5.x that use _APP_LOGGING_PROVIDER and _APP_LOGGING_CONFIG environment variables
        Console::warning('Using deprecated logging configuration. Please update your configuration to use DSN format.' . $th->getMessage());
        $configChunks = \explode(";", $providerConfig);

        $providerConfig = match ($providerName) {
            'sentry' => [ 'key' => $configChunks[0], 'projectId' => $configChunks[1] ?? '', 'host' => '',],
            'logowl' => ['ticket' => $configChunks[0] ?? '', 'host' => ''],
            default => ['key' => $providerConfig],
        };
    }

    if (empty($providerName) || empty($providerConfig)) {
        return;
    }

    if (!Logger::hasProvider($providerName)) {
        throw new Exception(Exception::GENERAL_SERVER_ERROR, "Logging provider not supported. Logging is disabled");
    }

    try {
        $adapter = match ($providerName) {
            'sentry' => new Sentry($providerConfig['projectId'], $providerConfig['key'], $providerConfig['host']),
            'logowl' => new LogOwl($providerConfig['ticket'], $providerConfig['host']),
            'raygun' => new Raygun($providerConfig['key']),
            'appsignal' => new AppSignal($providerConfig['key']),
            default => null
        };
    } catch (Throwable $th) {
        $adapter = null;
    }

    if ($adapter === null) {
        Console::error("Logging provider not supported. Logging is disabled");
        return;
    }

    return new Logger($adapter);
});

$register->set('realtimeLogger', function () {
    // Register error logger for realtime, falls back to default logging config
    $providerConfig = System::getEnv('_APP_LOGGING_CONFIG_REALTIME', '')
        ?: System::getEnv('_APP_LOGGING_CONFIG', '');

    if (empty($providerConfig)) {
        return;
    }

    $loggingProvider = new DSN($providerConfig);
    $providerName = $loggingProvider->getScheme();
    $providerConfig = match ($providerName) {
        'sentry' => ['key' => $loggingProvider->getPassword(), 'projectId' => $loggingProvider->getUser() ?? '', 'host' => 'https://' . $loggingProvider->getHost()],
        'logowl' => ['ticket' => $loggingProvider->getUser() ?? '', 'host' => $loggingProvider->getHost()],
        default => ['key' => $loggingProvider->getHost()],
    };

    if (empty($providerName) || empty($providerConfig)) {
        return;
    }

    if (!Logger::hasProvider($providerName)) {
        throw new Exception(Exception::GENERAL_SERVER_ERROR, "Logging provider not supported. Logging is disabled");
    }

    try {
        $adapter = match ($providerName) {
            'sentry' => new Sentry($providerConfig['projectId'], $providerConfig['key'], $providerConfig['host']),
            'logowl' => new LogOwl($providerConfig['ticket'], $providerConfig['host']),
            'raygun' => new Raygun($providerConfig['key']),
            'appsignal' => new AppSignal($providerConfig['key']),
            default => null
        };
    } catch (Throwable $th) {
        $adapter = null;
    }

    if ($adapter === null) {
        Console::error("Logging provider not supported. Logging is disabled");
        return;
    }

    return new Logger($adapter);
});

$register->set('pools', function () {
    $group = new Group();

    $fallbackForDB = 'db_main=' . AppwriteURL::unparse([
        'scheme' => 'mariadb',
        'host' => System::getEnv('_APP_DB_HOST', 'mariadb'),
        'port' => System::getEnv('_APP_DB_PORT', '3306'),
        'user' => System::getEnv('_APP_DB_USER', ''),
        'pass' => System::getEnv('_APP_DB_PASS', ''),
        'path' => System::getEnv('_APP_DB_SCHEMA', ''),
    ]);
    $fallbackForRedis = 'redis_main=' . AppwriteURL::unparse([
        'scheme' => 'redis',
        'host' => System::getEnv('_APP_REDIS_HOST', 'redis'),
        'port' => System::getEnv('_APP_REDIS_PORT', '6379'),
        'user' => System::getEnv('_APP_REDIS_USER', ''),
        'pass' => System::getEnv('_APP_REDIS_PASS', ''),
    ]);

    $connections = [
        'console' => [
            'type' => 'database',
            'dsns' => $fallbackForDB,
            'multiple' => false,
            'schemes' => ['mariadb', 'mysql'],
        ],
        'database' => [
            'type' => 'database',
            'dsns' => $fallbackForDB,
            'multiple' => true,
            'schemes' => ['mariadb', 'mysql'],
        ],
        'logs' => [
            'type' => 'database',
            'dsns' => System::getEnv('_APP_CONNECTIONS_DB_LOGS', $fallbackForDB),
            'multiple' => false,
            'schemes' => ['mariadb', 'mysql'],
        ],
        'publisher' => [
            'type' => 'publisher',
            'dsns' => $fallbackForRedis,
            'multiple' => false,
            'schemes' => ['redis'],
        ],
        'consumer' => [
            'type' => 'consumer',
            'dsns' => $fallbackForRedis,
            'multiple' => false,
            'schemes' => ['redis'],
        ],
        'pubsub' => [
            'type' => 'pubsub',
            'dsns' => $fallbackForRedis,
            'multiple' => false,
            'schemes' => ['redis'],
        ],
        'cache' => [
            'type' => 'cache',
            'dsns' => $fallbackForRedis,
            'multiple' => true,
            'schemes' => ['redis'],
        ],
    ];

    $maxConnections = System::getEnv('_APP_CONNECTIONS_MAX', 151);
    $instanceConnections = $maxConnections / System::getEnv('_APP_POOL_CLIENTS', 14);

    $multiprocessing = System::getEnv('_APP_SERVER_MULTIPROCESS', 'disabled') === 'enabled';

    if ($multiprocessing) {
        $workerCount = intval(System::getEnv('_APP_CPU_NUM', swoole_cpu_num())) * intval(System::getEnv('_APP_WORKER_PER_CORE', 6));
    } else {
        $workerCount = 1;
    }

    if ($workerCount > $instanceConnections) {
        throw new \Exception('Pool size is too small. Increase the number of allowed database connections or decrease the number of workers.', 500);
    }

    $poolSize = (int)($instanceConnections / $workerCount);

    foreach ($connections as $key => $connection) {
        $type = $connection['type'] ?? '';
        $multiple = $connection['multiple'] ?? false;
        $schemes = $connection['schemes'] ?? [];
        $config = [];
        $dsns = explode(',', $connection['dsns'] ?? '');
        foreach ($dsns as &$dsn) {
            $dsn = explode('=', $dsn);
            $name = ($multiple) ? $key . '_' . $dsn[0] : $key;
            $dsn = $dsn[1] ?? '';
            $config[] = $name;
            if (empty($dsn)) {
                //throw new Exception(Exception::GENERAL_SERVER_ERROR, "Missing value for DSN connection in {$key}");
                continue;
            }

            $dsn = new DSN($dsn);
            $dsnHost = $dsn->getHost();
            $dsnPort = $dsn->getPort();
            $dsnUser = $dsn->getUser();
            $dsnPass = $dsn->getPassword();
            $dsnScheme = $dsn->getScheme();
            $dsnDatabase = $dsn->getPath();

            if (!in_array($dsnScheme, $schemes)) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, "Invalid console database scheme");
            }

            /**
             * Get Resource
             *
             * Creation could be reused across connection types like database, cache, queue, etc.
             *
             * Resource assignment to an adapter will happen below.
             */
            $resource = match ($dsnScheme) {
                'mysql',
                'mariadb' => function () use ($dsnHost, $dsnPort, $dsnUser, $dsnPass, $dsnDatabase) {
                    return new PDOProxy(function () use ($dsnHost, $dsnPort, $dsnUser, $dsnPass, $dsnDatabase) {
                        return new PDO("mysql:host={$dsnHost};port={$dsnPort};dbname={$dsnDatabase};charset=utf8mb4", $dsnUser, $dsnPass, [
                            \PDO::ATTR_TIMEOUT => 3, // Seconds
                            \PDO::ATTR_PERSISTENT => false,
                            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                            \PDO::ATTR_EMULATE_PREPARES => true,
                            \PDO::ATTR_STRINGIFY_FETCHES => true
                        ]);
                    });
                },
                'redis' => function () use ($dsnHost, $dsnPort, $dsnPass) {
                    $redis = new \Redis();
                    @$redis->pconnect($dsnHost, (int)$dsnPort);
                    if ($dsnPass) {
                        $redis->auth($dsnPass);
                    }
                    $redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);

                    return $redis;
                },
                default => throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Invalid scheme'),
            };

            $pool = new Pool($name, $poolSize, function () use ($type, $resource, $dsn) {
                // Get Adapter
                switch ($type) {
                    case 'database':
                        $adapter = match ($dsn->getScheme()) {
                            'mariadb' => new MariaDB($resource()),
                            'mysql' => new MySQL($resource()),
                            default => null
                        };

                        $adapter->setDatabase($dsn->getPath());
                        return $adapter;
                    case 'pubsub':
                        return match ($dsn->getScheme()) {
                            'redis' => new PubSub($resource()),
                            default => null
                        };
                    case 'publisher':
                    case 'consumer':
                        return match ($dsn->getScheme()) {
                            'redis' => new Queue\Broker\Redis(new Queue\Connection\Redis($dsn->getHost(), $dsn->getPort())),
                            default => null
                        };
                    case 'cache':
                        return match ($dsn->getScheme()) {
                            'redis' => new RedisCache($resource()),
                            default => null
                        };
                    default:
                        throw new Exception(Exception::GENERAL_SERVER_ERROR, "Server error: Missing adapter implementation.");
                }
            });

            $group->add($pool);
        }

        Config::setParam('pools-' . $key, $config);
    }

    return $group;
});

$register->set('db', function () {
    // This is usually for our workers or CLI commands scope
    $dbHost = System::getEnv('_APP_DB_HOST', '');
    $dbPort = System::getEnv('_APP_DB_PORT', '');
    $dbUser = System::getEnv('_APP_DB_USER', '');
    $dbPass = System::getEnv('_APP_DB_PASS', '');
    $dbScheme = System::getEnv('_APP_DB_SCHEMA', '');

    return new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbScheme};charset=utf8mb4",
        $dbUser,
        $dbPass,
        SQL::getPDOAttributes()
    );
});

$register->set('smtp', function () {
    $mail = new PHPMailer(true);

    $mail->isSMTP();

    $username = System::getEnv('_APP_SMTP_USERNAME');
    $password = System::getEnv('_APP_SMTP_PASSWORD');

    $mail->XMailer = 'Appwrite Mailer';
    $mail->Host = System::getEnv('_APP_SMTP_HOST', 'smtp');
    $mail->Port = System::getEnv('_APP_SMTP_PORT', 25);
    $mail->SMTPAuth = !empty($username) && !empty($password);
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = System::getEnv('_APP_SMTP_SECURE', '');
    $mail->SMTPAutoTLS = false;
    $mail->CharSet = 'UTF-8';

    $from = \urldecode(System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server'));
    $email = System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);

    $mail->setFrom($email, $from);
    $mail->addReplyTo($email, $from);

    $mail->isHTML(true);

    return $mail;
});
$register->set('geodb', function () {
    return new Reader(__DIR__ . '/../assets/dbip/dbip-country-lite-2025-12.mmdb');
});
$register->set('passwordsDictionary', function () {
    $content = \file_get_contents(__DIR__ . '/../assets/security/10k-common-passwords');
    $content = explode("\n", $content);
    $content = array_flip($content);
    return $content;
});
$register->set('promiseAdapter', function () {
    return new Swoole();
});
$register->set('hooks', function () {
    return new Hooks();
});
