<?php

namespace Tests\E2E\Adapter\SharedTables;

use Redis;
use Tests\E2E\Adapter\Base;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Database;
use Utopia\Database\PDO;

class MySQLTest extends Base
{
    public static ?Database $database = null;
    protected static ?PDO $pdo = null;
    protected static string $namespace;

    // Remove once all methods are implemented
    /**
     * Return name of adapter
     *
     * @return string
     */
    public static function getAdapterName(): string
    {
        return "mysql";
    }

    /**
     * @return Database
     */
    public function getDatabase(): Database
    {
        if (!is_null(self::$database)) {
            return self::$database;
        }

        $dbHost = 'mysql';
        $dbPort = '3307';
        $dbUser = 'root';
        $dbPass = 'password';

        $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, MySQL::getPDOAttributes());

        $redis = new Redis();
        $redis->connect('redis', 6379);
        $redis->flushAll();

        $cache = new Cache(new RedisAdapter($redis));

        $database = new Database(new MySQL($pdo), $cache);
        $database
            ->setAuthorization(self::$authorization)
            ->setDatabase('utopiaTests')
            ->setSharedTables(true)
            ->setTenant(999)
            ->setNamespace(static::$namespace = '')
            ->enableLocks(true)
        ;

        if ($database->exists()) {
            $database->delete();
        }

        $database->create();

        self::$pdo = $pdo;
        return self::$database = $database;
    }

    protected function deleteColumn(string $collection, string $column): bool
    {
        $sqlTable = "`" . $this->getDatabase()->getDatabase() . "`.`" . $this->getDatabase()->getNamespace() . "_" . $collection . "`";
        $sql = "ALTER TABLE {$sqlTable} DROP COLUMN `{$column}`";

        self::$pdo->exec($sql);

        return true;
    }

    protected function deleteIndex(string $collection, string $index): bool
    {
        $sqlTable = "`" . $this->getDatabase()->getDatabase() . "`.`" . $this->getDatabase()->getNamespace() . "_" . $collection . "`";
        $sql = "DROP INDEX `{$index}` ON {$sqlTable}";

        self::$pdo->exec($sql);

        return true;
    }
}
