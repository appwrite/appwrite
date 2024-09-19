<?php

if (\file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

use Ahc\Jwt\JWT;
use Appwrite\Auth\Authentication;
use Appwrite\Auth\MFA\Type\TOTP;
use Appwrite\Event\Audit;
use Appwrite\Event\Build;
use Appwrite\Event\Certificate;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Mail;
use Appwrite\Event\Messaging;
use Appwrite\Event\Migration;
use Appwrite\Event\Usage;
use Appwrite\Extend\Exception;
use Appwrite\GraphQL\Promises\Adapter\Swoole;
use Appwrite\GraphQL\Schema;
use Appwrite\Hooks\Hooks;
use Appwrite\URL\URL;
use Appwrite\Utopia\Queue\Connections;
use Appwrite\Utopia\Response\Models;
use MaxMind\Db\Reader;
use PHPMailer\PHPMailer\PHPMailer;
use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool;
use Swoole\Database\RedisConfig;
use Swoole\Database\RedisPool;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\Database\TimeLimit;
use Utopia\Cache\Adapter\Redis as CacheRedis;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\SQL;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\Domains\Domain;
use Utopia\Domains\Validator\PublicDomain;
use Utopia\DSN\DSN;
use Utopia\Http\Http;
use Utopia\Http\Request;
use Utopia\Http\Validator\Hostname;
use Utopia\Locale\Locale;
use Utopia\Logger\Adapter\AppSignal;
use Utopia\Logger\Adapter\LogOwl;
use Utopia\Logger\Adapter\Raygun;
use Utopia\Logger\Adapter\Sentry;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Queue;
use Utopia\Registry\Registry;
use Utopia\Storage\Device\Local;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\GitHub;

require_once __DIR__ . '/init/constants.php';
require_once __DIR__ . '/init/config.php';
require_once __DIR__ . '/init/locale.php';
require_once __DIR__ . '/init/database/filters.php';
require_once __DIR__ . '/init/database/formats.php';

ini_set('memory_limit', '-1');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('default_socket_timeout', -1);
error_reporting(E_ALL);

global $http, $container, $registry;

Http::setMode(System::getEnv('_APP_ENV', Http::MODE_TYPE_PRODUCTION));

if (!Http::isProduction()) {
    // Allow specific domains to skip public domain validation in dev environment
    // Useful for existing tests involving webhooks
    PublicDomain::allow(['request-catcher']);
}


$container = new Container();
$registry = new Registry();

$registry->set('logger', function () {
    // Register error logger
    $providerName = System::getEnv('_APP_LOGGING_PROVIDER', '');
    $providerConfig = System::getEnv('_APP_LOGGING_CONFIG', '');

    try {
        $loggingProvider = new DSN($providerConfig ?? '');

        $providerName = $loggingProvider->getScheme();
        $providerConfig = match ($providerName) {
            'sentry' => ['key' => $loggingProvider->getPassword(), 'projectId' => $loggingProvider->getUser() ?? '', 'host' => 'https://' . $loggingProvider->getHost()],
            'logowl' => ['ticket' => $loggingProvider->getUser() ?? '', 'host' => $loggingProvider->getHost()],
            default => ['key' => $loggingProvider->getHost()],
        };
    } catch (Throwable $th) {
        Console::warning('Using deprecated logging configuration. Please update your configuration to use DSN format.' . $th->getMessage());
        // Fallback for older Appwrite versions up to 1.5.x that use _APP_LOGGING_PROVIDER and _APP_LOGGING_CONFIG environment variables
        $configChunks = \explode(";", $providerConfig);

        $providerConfig = match ($providerName) {
            'sentry' => ['key' => $configChunks[0], 'projectId' => $configChunks[1] ?? '', 'host' => '',],
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

    $logger = new Logger($adapter);
    $logger->setSample(0.4);
    return $logger;
});

$registry->set('geodb', function () {
    /**
     * @disregard P1009 Undefined type
     */
    return new Reader(__DIR__ . '/assets/dbip/dbip-country-lite-2024-09.mmdb');
});

$registry->set('hooks', function () {
    return new Hooks();
});

$registry->set(
    'pools',
    (function () {
        $fallbackForDB = 'db_main=' . URL::unparse([
            'scheme' => 'mariadb',
            'host' => System::getEnv('_APP_DB_HOST', 'mariadb'),
            'port' => System::getEnv('_APP_DB_PORT', '3306'),
            'user' => System::getEnv('_APP_DB_USER', ''),
            'pass' => System::getEnv('_APP_DB_PASS', ''),
            'path' => System::getEnv('_APP_DB_SCHEMA', ''),
        ]);
        $fallbackForRedis = 'redis_main=' . URL::unparse([
            'scheme' => 'redis',
            'host' => System::getEnv('_APP_REDIS_HOST', 'redis'),
            'port' => System::getEnv('_APP_REDIS_PORT', '6379'),
            'user' => System::getEnv('_APP_REDIS_USER', ''),
            'pass' => System::getEnv('_APP_REDIS_PASS', ''),
        ]);

        $connections = [
            'console' => [
                'type' => 'database',
                'dsns' => System::getEnv('_APP_CONNECTIONS_DB_CONSOLE', $fallbackForDB),
                'multiple' => false,
                'schemes' => ['mariadb', 'mysql'],
            ],
            'database' => [
                'type' => 'database',
                'dsns' => System::getEnv('_APP_CONNECTIONS_DB_PROJECT', $fallbackForDB),
                'multiple' => true,
                'schemes' => ['mariadb', 'mysql'],
            ],
            'queue' => [
                'type' => 'queue',
                'dsns' => System::getEnv('_APP_CONNECTIONS_QUEUE', $fallbackForRedis),
                'multiple' => false,
                'schemes' => ['redis'],
            ],
            'pubsub' => [
                'type' => 'pubsub',
                'dsns' => System::getEnv('_APP_CONNECTIONS_PUBSUB', $fallbackForRedis),
                'multiple' => false,
                'schemes' => ['redis'],
            ],
            'cache' => [
                'type' => 'cache',
                'dsns' => System::getEnv('_APP_CONNECTIONS_CACHE', $fallbackForRedis),
                'multiple' => true,
                'schemes' => ['redis'],
            ],
        ];

        $pools = [];
        $poolSize = (int)System::getEnv('_APP_POOL_SIZE', 64);

        foreach ($connections as $key => $connection) {
            $dsns = $connection['dsns'] ?? '';
            $multiple = $connection['multiple'] ?? false;
            $schemes = $connection['schemes'] ?? [];
            $dsns = explode(',', $connection['dsns'] ?? '');
            $config = [];

            foreach ($dsns as &$dsn) {
                $dsn = explode('=', $dsn);
                $name = ($multiple) ? $key . '_' . $dsn[0] : $key;
                $config[] = $name;
                $dsn = $dsn[1] ?? '';

                if (empty($dsn)) {
                    throw new Exception(Exception::GENERAL_SERVER_ERROR, "Missing value for DSN connection in {$key}");
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
                        $pool = new PDOPool(
                            (new PDOConfig())
                                ->withHost($dsnHost)
                                ->withPort($dsnPort)
                                ->withDbName($dsnDatabase)
                                ->withCharset('utf8mb4')
                                ->withUsername($dsnUser)
                                ->withPassword($dsnPass)
                                ->withOptions([
                                    // No need to set PDO::ATTR_ERRMODE it is overwitten in PDOProxy
                                    // PDO::ATTR_TIMEOUT => 3, // Seconds
                                    // PDO::ATTR_PERSISTENT => true,
                                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                                    PDO::ATTR_EMULATE_PREPARES => true,
                                    PDO::ATTR_STRINGIFY_FETCHES => true,
                                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,

                                ]),
                            $poolSize
                        );
                        break;
                    case 'redis':
                        $pool = new RedisPool(
                            (new RedisConfig())
                                ->withHost($dsnHost)
                                ->withPort((int)$dsnPort)
                                ->withAuth($dsnPass ?? ''),
                            $poolSize
                        );
                        break;

                    default:
                        throw new Exception(Exception::GENERAL_SERVER_ERROR, "Invalid scheme");
                }

                $pools['pools-' . $key . '-' . $name] = [
                    'pool' => $pool,
                    'dsn' => $dsn,
                ];
            }

            Config::setParam('pools-' . $key, $config);
        }

        return function () use ($pools): array {
            return $pools;
        };
    })()
);

$registry->set('smtp', function () {
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

$registry->set('promiseAdapter', function () {
    return new Swoole();
});

$registry->set('db', function () {
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

// Autoload
class_exists(JWT::class, true);
class_exists(DSN::class, true);
class_exists(Log::class, true);
class_exists(TOTP::class, true);
class_exists(Mail::class, true);
class_exists(Func::class, true);
class_exists(Cache::class, true);
class_exists(Abuse::class, true);
class_exists(MySQL::class, true);
class_exists(Event::class, true);
class_exists(Audit::class, true);
class_exists(Usage::class, true);
class_exists(Local::class, true);
class_exists(Build::class, true);
class_exists(Locale::class, true);
class_exists(Delete::class, true);
class_exists(GitHub::class, true);
class_exists(Schema::class, true);
class_exists(Domain::class, true);
class_exists(Console::class, true);
class_exists(Request::class, true);
class_exists(MariaDB::class, true);
class_exists(Document::class, true);
class_exists(Sharding::class, true);
class_exists(Database::class, true);
class_exists(Hostname::class, true);
class_exists(TimeLimit::class, true);
class_exists(Migration::class, true);
class_exists(Messaging::class, true);
class_exists(CacheRedis::class, true);
class_exists(Connections::class, true);
class_exists(Certificate::class, true);
class_exists(EventDatabase::class, true);
class_exists(Authorization::class, true);
class_exists(Authentication::class, true);
class_exists(Queue\Connection\Redis::class, true);

require_once __DIR__ . '/init/resources.php';

Models::init();
