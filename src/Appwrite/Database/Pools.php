<?php

namespace Appwrite\Database;

use PDO;
use Utopia\App;
use Appwrite\DSN\DSN;
use Utopia\Cache\Cache;
use Swoole\Database\PDOProxy;
use Utopia\Database\Database;
use Appwrite\Extend\Exception;
use Appwrite\Database\PDOPool;
use Swoole\Database\PDOConfig;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\CLI\Console;

class Pools
{
    /**
     * @var array
     *
     * Array to store mappings from database names to PDOPool instances.
     */
    protected array $pools = [];

    /**
     * @var array
     *
     * Array to store mappings from database names to DSNs
     */
    protected array $dsns = [];

    /**
     * @var string
     *
     * The name of the console Database
     */
    protected string $consoleDB = '';

    /**
     * Constructor for Database pools
     *
     * @param array $consoleDB
     * @param array $projectDB
     *
     */
    public function __construct(array $consoleDB, array $projectDB)
    {
        if (count($consoleDB) != 1) {
            throw new Exception('Console DB should contain only one entry', 500);
        }

        if (empty($projectDB)) {
            throw new Exception('Project DB is not defined', 500);
        }

        $this->consoleDB = array_key_first($consoleDB);
        $this->dsns = array_merge($consoleDB, $projectDB);

        /** Create PDO pool instances for all the dsns */
        foreach ($this->dsns as $name => $dsn) {
            $dsn = new DSN($dsn);
            $pdoConfig = (new PDOConfig())
                ->withHost($dsn->getHost())
                ->withPort($dsn->getPort())
                ->withDbName($dsn->getDatabase())
                ->withCharset('utf8mb4')
                ->withUsername($dsn->getUser())
                ->withPassword($dsn->getPassword())
                ->withOptions([
                    PDO::ATTR_ERRMODE => App::isDevelopment() ? PDO::ERRMODE_WARNING : PDO::ERRMODE_SILENT, // If in production mode, warnings are not displayed
                    PDO::ATTR_TIMEOUT => 3, // Seconds
                    PDO::ATTR_PERSISTENT => true,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => true,
                    PDO::ATTR_STRINGIFY_FETCHES => true
                ]);

            $pool = new PDOPool($pdoConfig, $name, 64);

            $this->pools[$name] = $pool;
        }
    }

    /**
     * Get a single PDO instance by database name
     *
     * @param string $name
     *
     * @return ?PDO
     */
    public function getPDO(string $name): ?PDO
    {
        $dsn = $this->dsns[$name] ?? throw new Exception("Database with name : $name not found.", 500);

        $dsn =  new DSN($dsn);
        $dbHost = $dsn->getHost();
        $dbPort = $dsn->getPort();
        $dbUser = $dsn->getUser();
        $dbPass = $dsn->getPassword();
        $dbScheme = $dsn->getDatabase();

        $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbScheme};charset=utf8mb4", $dbUser, $dbPass, array(
            PDO::ATTR_TIMEOUT => 3, // Seconds
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_ERRMODE => App::isDevelopment() ? PDO::ERRMODE_WARNING : PDO::ERRMODE_SILENT, // If in production mode, warnings are not displayed
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_STRINGIFY_FETCHES => true
        ));

        return $pdo;
    }

    /**
     * Get a PDO instance from the list of available database pools. Meant to be used in co-routines
     *
     * @param string $projectId
     *
     * @return array
     */
    public function getPDOFromPool(string $name): PDOWrapper
    {
        $pool = $this->pools[$name] ?? throw new Exception("Database pool with name : $name not found. Check the value of _APP_CONNECTIONS_DB_PROJECT in .env", 500);
        $pdo = $pool->get();
        return $pdo;
    }

     /**
     * Get a random PDO instance from the available database pools
     *
     * @return PDOWrapper
     */
    public function getAnyFromPool(): PDOWrapper
    {
        $name = array_rand($this->pools);
        $pool = $this->pools[$name] ?? throw new Exception("Database pool with name : $name not found. Check the value of _APP_CONNECTIONS_DB_PROJECT in .env", 500);
        $pdo = $pool->get();
        return $pdo;
    }

    public function reset(): void
    {
        foreach ($this->pools as $pool) {
            $pool->reset();
        }
    }

    /**
     * Return a PDO instance back to its database pool
     *
     * @param PDOProxy $db
     * @param string $name
     *
     * @return void
     */
    public function put(PDOProxy $db, string $name): void
    {
        $pool = $this->pools[$name] ?? null;
        if ($pool === null) {
            throw new Exception("Failed to put PDO into database pool. Database pool with name : $name not found", 500);
        }
        $pool->put($db);
    }

    /**
     * Get the name of the console DB
     *
     * @return ?string
     */
    public function getConsoleDB(): ?string
    {
        if (empty($this->consoleDB)) {
            throw new Exception('Console DB is not defined', 500);
        };

        return $this->consoleDB;
    }

    public static function wait(Database $database, string $collection)
    {
        $attempts = 0;
        do {
            try {
                $attempts++;
                if (!$database->exists($database->getDefaultDatabase(), $collection)) {
                    throw new Exception('Collection not ready');
                }
                break; // leave loop if successful
            } catch (\Exception $e) {
                Console::warning("Database not ready. Retrying connection ({$attempts})...");
                if ($attempts >= DATABASE_RECONNECT_MAX_ATTEMPTS) {
                    throw new \Exception('Failed to connect to database: ' . $e->getMessage());
                }
                sleep(DATABASE_RECONNECT_SLEEP);
            }
        } while ($attempts < DATABASE_RECONNECT_MAX_ATTEMPTS);

        return $database;
    }

    /**
     * Get a database instance from a PDO and cache
     *
     * @param PDO|PDOProxy $pdo
     * @param \Redis $redis
     * @param string $namespace
     *
     * @return Database
     */
    public static function getDatabase(PDO|PDOProxy $pdo, \Redis $redis, string $namespace = ''): Database
    {
        $cache = new Cache(new RedisCache($redis));
        $database = new Database(new MariaDB($pdo), $cache);
        $database->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
        $database->setNamespace($namespace);
        return $database;
    }
}
