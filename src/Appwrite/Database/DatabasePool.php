<?php

namespace Appwrite\Database;

use Appwrite\DSN\DSN;
use Appwrite\Extend\Exception;
use PDO;
use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool;
use Swoole\Database\PDOProxy;
use Utopia\App;

class DatabasePool {

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
    protected array $databases = [];

    /**
     * @var string
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
        if(count($consoleDB) != 1) {
            throw new Exception('Console DB should contain only one entry', 500);
        }

        if(empty($projectDB)) {
            throw new Exception('Project DB is not defined', 500);
        }

        $this->consoleDB = array_key_first($consoleDB);
        $this->databases = array_merge($consoleDB, $projectDB);

        /** Create PDO pool instances for all the databases */
        foreach ($this->databases as $name => $dsn) {
            $dsn = new DSN($dsn);
            $pool = new PDOPool(
                (new PDOConfig())
                ->withHost($dsn->getHost())
                ->withPort($dsn->getPort())
                ->withDbName($dsn->getDatabase())
                ->withCharset('utf8mb4')
                ->withUsername($dsn->getUser())
                ->withPassword($dsn->getPassword())
                ->withOptions([
                    PDO::ATTR_ERRMODE => App::isDevelopment() ? PDO::ERRMODE_WARNING : PDO::ERRMODE_SILENT, // If in production mode, warnings are not displayed
                ]),
                64
            );
    
            $this->pools[$name] = $pool;
        }
    }

    /**
     * Get a single PDO instance
     * 
     * @param string $name
     * 
     * @return ?PDO
     */
    public function getDB(string $name): ?PDO
    {
        $dsn = $this->dsn[$name] ?? false;

        if ($dsn === false) {
            throw new Exception("Database with name : $name not found.", 500);
        }

        $dsn =  new DSN($dsn);
        $dbHost = $dsn->getHost();
        $dbPort = $dsn->getPort();
        $dbUser = $dsn->getUser();
        $dbPass = $dsn->getPassword();
        $dbScheme = $dsn->getDatabase();

        $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbScheme};charset=utf8mb4", $dbUser, $dbPass, array(
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
            PDO::ATTR_TIMEOUT => 3, // Seconds
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ));

        return $pdo;
    }

    /**
     * Get a PDO instance from the list of available database pools . To be used in co-routines
     * 
     * @param string $name
     * 
     * @return ?PDOProxy
     */
    public function getDBFromPool(string $name): ?PDOProxy
    {
        $pool = $this->pools[$name] ?? throw new Exception("Database pool with name : $name not found. Check the value of _APP_PROJECT_DB in .env", 500);
        return $pool->get();
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
     * Function to get a random PDO instance from the available database pools
     * 
     * @return array [PDO, string]
     */
    public function getAnyFromPool(): array
    {
        $key = array_rand($this->pools);
        $pool = $this->getDBFromPool($key);

        return [
            'name' => $key, 
            'db' => $pool
        ];
    }

    /**
     * Convenience methods for console DB
     */

    /**
     * Function to get a single instace of the console DB
     * 
     * @return ?PDO
     */
    public function getConsoleDB(): ?PDO
    {
        if (empty($this->consoleDB)) {
            throw new Exception('Console DB is not defined', 500);
        };

        return $this->getDB($this->consoleDB);
    }

    /**
     * Function to get an instance of the console DB from the database pool
     * 
     * @return ?PDOProxy
     */
    public function getConsoleDBFromPool(): ?PDOProxy
    {
        if (empty($this->consoleDB)) {
            throw new Exception("Console DB not set", 500);
        }

        return $this->getDBFromPool($this->consoleDB);
    }

    /**
     * Return the console DB back to the console database pool
     *
     * @param PDOProxy $db
     * 
     * @return void
     */
    public function putConsoleDB(PDOProxy $db): void
    {
       $this->put($db, $this->consoleDB);
    }

    /**
     * Function to set the name of the console database
     * 
     * @param string $consoleDB
     * 
     * @return void
     */
    public function setConsoleDB(string $consoleDB): void
    {
        if(!isset($this->pools[$consoleDB])) {
            throw new Exception("Console DB with name : $consoleDB not found. Add it using ", 500);
        }
        
        $this->consoleDB = $consoleDB;
    }
}