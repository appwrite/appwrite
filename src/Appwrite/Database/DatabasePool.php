<?php

namespace Appwrite\Database;

use Appwrite\Extend\Exception;
use Swoole\Database\PDOPool;
use Swoole\Database\PDOProxy;

class DatabasePool {

    /**
     * @var array
     */
    protected array $pools = [];

    /**
     * @var string
     */
    protected string $consoleDB = '';

    /**
     * Function to get the name of the console database.
     * 
     * @return ?PDOProxy
     */
    public function getConsoleDB(): ?PDOProxy
    {
        if (empty($this->consoleDB)) {
            throw new Exception("Console DB not set", 500);
        }

        return $this->get($this->consoleDB);
    }

    /**
     * Return a PDO instance back to the console database pool
     *
     * @param PDOProxy $db
     * 
     * @return void
     */
    public function putConsoleDb(PDOProxy $db): void
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

    /**
     * Add a new PDOPool into the list of available pools
     * 
     * @param string $name
     * @param PDOPool $dbPool
     *
     * @return void
     */
    public function add(string $name, PDOPool $dbPool): void
    {
        $this->pools[$name] = $dbPool;
    }

    /**
     * Get a PDO instance from the list of available database pools
     * 
     * @param string $name
     * 
     * @return ?PDOProxy
     */
    public function get(string $name): ?PDOProxy
    {
        $pool = $this->pools[$name] ?? null;
        if ($pool === null) {
            throw new Exception("Database pool with name : $name not found. Check the value of _APP_PROJECT_DB in .env", 500);
        }
        return $pool->get();
    }

    /**
     * Function to get a random PDO instance from the available database pools database
     * 
     * @return array [PDO, string]
     */
    public function getAny(): ?array
    {
        if (count($this->pools) === 0) {
            throw new Exception("No database pools found. Add pools using DatabasePool::add() method", 500);
        }
        
        $key = array_rand($this->pools);
        $pool = $this->pools[$key] ?? null;
        
        return [
            'name' => $key, 
            'db' => $pool ? $pool->get() : null
        ];
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
}