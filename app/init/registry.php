<?php

use Utopia\App;
use Utopia\Config\Config;
use Utopia\Logger\Logger;
use Utopia\Registry\Registry;
use Appwrite\Extend\Exception;
use Utopia\Pools\Group;
use Appwrite\URL\URL as AppwriteURL;
use Appwrite\GraphQL\Promises\Adapter\Swoole;
use Utopia\DSN\DSN;
use Utopia\Pools\Pool;
use MaxMind\Db\Reader;
use PHPMailer\PHPMailer\PHPMailer;
use Swoole\Database\PDOProxy;
use Utopia\Queue;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\MySQL;

$register = new Registry();

/*
 * Registry
 */
$register->set('logger', function () {
    // Register error logger
    $providerName = App::getEnv('_APP_LOGGING_PROVIDER', '');
    $providerConfig = App::getEnv('_APP_LOGGING_CONFIG', '');

    if (empty($providerName) || empty($providerConfig)) {
        return null;
    }

    if (!Logger::hasProvider($providerName)) {
        throw new Exception(Exception::GENERAL_SERVER_ERROR, "Logging provider not supported. Logging is disabled");
    }

    $classname = '\\Utopia\\Logger\\Adapter\\' . \ucfirst($providerName);
    $adapter = new $classname($providerConfig);
    return new Logger($adapter);
});
$register->set('pools', function () {
    $group = new Group();

    $fallbackForDB = AppwriteURL::unparse([
        'scheme' => 'mariadb',
        'host' => App::getEnv('_APP_DB_HOST', 'mariadb'),
        'port' => App::getEnv('_APP_DB_PORT', '3306'),
        'user' => App::getEnv('_APP_DB_USER', ''),
        'pass' => App::getEnv('_APP_DB_PASS', ''),
    ]);
    $fallbackForRedis = AppwriteURL::unparse([
        'scheme' => 'redis',
        'host' => App::getEnv('_APP_REDIS_HOST', 'redis'),
        'port' => App::getEnv('_APP_REDIS_PORT', '6379'),
        'user' => App::getEnv('_APP_REDIS_USER', ''),
        'pass' => App::getEnv('_APP_REDIS_PASS', ''),
    ]);

    $connections = [
        'console' => [
            'type' => 'database',
            'dsns' => App::getEnv('_APP_CONNECTIONS_DB_CONSOLE', $fallbackForDB),
            'multiple' => false,
            'schemes' => ['mariadb', 'mysql'],
        ],
        'database' => [
            'type' => 'database',
            'dsns' => App::getEnv('_APP_CONNECTIONS_DB_PROJECT', $fallbackForDB),
            'multiple' => true,
            'schemes' => ['mariadb', 'mysql'],
        ],
        'queue' => [
            'type' => 'queue',
            'dsns' => App::getEnv('_APP_CONNECTIONS_QUEUE', $fallbackForRedis),
            'multiple' => false,
            'schemes' => ['redis'],
        ],
        'pubsub' => [
            'type' => 'pubsub',
            'dsns' => App::getEnv('_APP_CONNECTIONS_PUBSUB', $fallbackForRedis),
            'multiple' => false,
            'schemes' => ['redis'],
        ],
        'cache' => [
            'type' => 'cache',
            'dsns' => App::getEnv('_APP_CONNECTIONS_CACHE', $fallbackForRedis),
            'multiple' => true,
            'schemes' => ['redis'],
        ],
    ];

    $maxConnections = App::getEnv('_APP_CONNECTIONS_MAX', 151);
    $instanceConnections = $maxConnections / App::getEnv('_APP_POOL_CLIENTS', 14);

    $multiprocessing = App::getEnv('_APP_SERVER_MULTIPROCESS', 'disabled') === 'enabled';

    if ($multiprocessing) {
        $workerCount = swoole_cpu_num() * intval(App::getEnv('_APP_WORKER_PER_CORE', 6));
    } else {
        $workerCount = 1;
    }

    if ($workerCount > $instanceConnections) {
        throw new \Exception('Pool size is too small. Increase the number of allowed database connections or decrease the number of workers.', 500);
    }

    $poolSize = (int)($instanceConnections / $workerCount);

    foreach ($connections as $key => $connection) {
        $type = $connection['type'] ?? '';
        $dsns = $connection['dsns'] ?? '';
        $multipe = $connection['multiple'] ?? false;
        $schemes = $connection['schemes'] ?? [];
        $config = [];
        $dsns = explode(',', $connection['dsns'] ?? '');
        foreach ($dsns as &$dsn) {
            $dsn = explode('=', $dsn);
            $name = ($multipe) ? $key . '_' . $dsn[0] : $key;
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
             * Creation could be reused accross connection types like database, cache, queue, etc.
             *
             * Resource assignment to an adapter will happen below.
             */
            switch ($dsnScheme) {
                case 'mysql':
                case 'mariadb':
                    $resource = function () use ($dsnHost, $dsnPort, $dsnUser, $dsnPass, $dsnDatabase) {
                        return new PDOProxy(function () use ($dsnHost, $dsnPort, $dsnUser, $dsnPass, $dsnDatabase) {
                            return new PDO("mysql:host={$dsnHost};port={$dsnPort};dbname={$dsnDatabase};charset=utf8mb4", $dsnUser, $dsnPass, array(
                                PDO::ATTR_TIMEOUT => 3, // Seconds
                                PDO::ATTR_PERSISTENT => true,
                                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                                PDO::ATTR_ERRMODE => App::isDevelopment() ? PDO::ERRMODE_WARNING : PDO::ERRMODE_SILENT, // If in production mode, warnings are not displayed
                                PDO::ATTR_EMULATE_PREPARES => true,
                                PDO::ATTR_STRINGIFY_FETCHES => true
                            ));
                        });
                    };
                    break;
                case 'redis':
                    $resource = function () use ($dsnHost, $dsnPort, $dsnPass) {
                        $redis = new Redis();
                        @$redis->pconnect($dsnHost, (int)$dsnPort);
                        if ($dsnPass) {
                            $redis->auth($dsnPass);
                        }
                        $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);

                        return $redis;
                    };
                    break;

                default:
                    throw new Exception(Exception::GENERAL_SERVER_ERROR, "Invalid scheme");
                    break;
            }

            $pool = new Pool($name, $poolSize, function () use ($type, $resource, $dsn) {
                // Get Adapter
                $adapter = null;
                switch ($type) {
                    case 'database':
                        $adapter = match ($dsn->getScheme()) {
                            'mariadb' => new MariaDB($resource()),
                            'mysql' => new MySQL($resource()),
                            default => null
                        };

                        $adapter->setDefaultDatabase($dsn->getPath());
                        break;
                    case 'pubsub':
                        $adapter = $resource();
                        break;
                    case 'queue':
                        $adapter = match ($dsn->getScheme()) {
                            'redis' => new Queue\Connection\Redis($dsn->getHost(), $dsn->getPort()),
                            default => null
                        };
                        break;
                    case 'cache':
                        $adapter = match ($dsn->getScheme()) {
                            'redis' => new RedisCache($resource()),
                            default => null
                        };
                        break;

                    default:
                        throw new Exception(Exception::GENERAL_SERVER_ERROR, "Server error: Missing adapter implementation.");
                        break;
                }

                return $adapter;
            });

            $group->add($pool);
        }

        Config::setParam('pools-' . $key, $config);
    }

    return $group;
});

$register->set('smtp', function () {
    $mail = new PHPMailer(true);

    $mail->isSMTP();

    $username = App::getEnv('_APP_SMTP_USERNAME', null);
    $password = App::getEnv('_APP_SMTP_PASSWORD', null);

    $mail->XMailer = 'Appwrite Mailer';
    $mail->Host = App::getEnv('_APP_SMTP_HOST', 'smtp');
    $mail->Port = App::getEnv('_APP_SMTP_PORT', 25);
    $mail->SMTPAuth = (!empty($username) && !empty($password));
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = App::getEnv('_APP_SMTP_SECURE', false);
    $mail->SMTPAutoTLS = false;
    $mail->CharSet = 'UTF-8';

    $from = \urldecode(App::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server'));
    $email = App::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);

    $mail->setFrom($email, $from);
    $mail->addReplyTo($email, $from);

    $mail->isHTML(true);

    return $mail;
});
$register->set('geodb', function () {
    return new Reader(__DIR__ . '/../assets/dbip/dbip-country-lite-2023-01.mmdb');
});
$register->set('promiseAdapter', function () {
    return new Swoole();
});
