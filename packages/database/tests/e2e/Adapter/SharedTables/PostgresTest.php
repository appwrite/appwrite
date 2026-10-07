<?php

namespace Tests\E2E\Adapter\SharedTables;

use Redis;
use Tests\E2E\Adapter\Base;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Database;
use Utopia\Database\PDO;

class PostgresTest extends Base
{
    public static ?Database $database = null;
    public static ?PDO $pdo = null;
    protected static string $namespace;

    /**
     * Return name of adapter
     *
     * @return string
     */
    public static function getAdapterName(): string
    {
        return "postgres";
    }

    /**
     * @reture Adapter
     */
    public function getDatabase(): Database
    {
        if (!is_null(self::$database)) {
            return self::$database;
        }

        $dbHost = 'postgres';
        $dbPort = '5432';
        $dbUser = 'root';
        $dbPass = 'password';

        $pdo = new PDO("pgsql:host={$dbHost};port={$dbPort};", $dbUser, $dbPass, Postgres::getPDOAttributes());
        $redis = new Redis();
        $redis->connect('redis', 6379);
        $redis->flushAll();
        $cache = new Cache(new RedisAdapter($redis));

        $database = new Database(new Postgres($pdo), $cache);
        $database
            ->setAuthorization(self::$authorization)
            ->setDatabase('utopiaTests')
            ->setSharedTables(true)
            ->setTenant(999)
            ->setNamespace(static::$namespace = '');

        if ($database->exists()) {
            $database->delete();
        }

        $database->create();

        self::$pdo = $pdo;
        return self::$database = $database;
    }

    protected function deleteColumn(string $collection, string $column): bool
    {
        $sqlTable = '"' . $this->getDatabase()->getDatabase() . '"."' . $this->getDatabase()->getNamespace() . '_' . $collection . '"';
        $sql = "ALTER TABLE {$sqlTable} DROP COLUMN \"{$column}\"";

        self::$pdo->exec($sql);

        return true;
    }

    protected function deleteIndex(string $collection, string $index): bool
    {
        $key = "\"".$this->getDatabase()->getNamespace()."_".$this->getDatabase()->getTenant()."_{$collection}_{$index}\"";

        $sql = "DROP INDEX \"".$this->getDatabase()->getDatabase()."\".{$key}";

        self::$pdo->exec($sql);

        return true;
    }
}
