<?php

namespace Tests\E2E\Adapter;

use Redis;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Database;
use Utopia\Database\PDO;

class SQLiteTest extends Base
{
    public static ?Database $database = null;
    protected static ?PDO $pdo = null;
    protected static string $namespace;

    /**
     * @return Database
     */
    public function getDatabase(): Database
    {
        if (!is_null(self::$database)) {
            return self::$database;
        }

        $db = __DIR__."/database.sql";

        if (file_exists($db)) {
            unlink($db);
        }

        $dsn = $db;
        //$dsn = 'memory'; // Overwrite for fast tests
        $pdo = new PDO("sqlite:" . $dsn, null, null, SQLite::getPDOAttributes());

        $redis = new Redis();
        $redis->connect('redis', 6379);
        $redis->flushAll();
        $cache = new Cache(new RedisAdapter($redis));

        $adapter = new SQLite($pdo);
        $adapter->setEmulateMySQL(true);

        $database = new Database($adapter, $cache);
        $database
            ->setAuthorization(self::$authorization)
            ->setDatabase('utopiaTests')
            ->setNamespace(static::$namespace = 'myapp_' . uniqid());

        if ($database->exists()) {
            $database->delete();
        }

        $database->create();

        self::$pdo = $pdo;
        return self::$database = $database;
    }

    protected function deleteColumn(string $collection, string $column): bool
    {
        $sqlTable = "`" . $this->getDatabase()->getNamespace() . "_" . $collection . "`";
        $sql = "ALTER TABLE {$sqlTable} DROP COLUMN `{$column}`";

        self::$pdo->exec($sql);

        return true;
    }

    protected function deleteIndex(string $collection, string $index): bool
    {
        $index = "`".$this->getDatabase()->getNamespace()."_".$this->getDatabase()->getTenant()."_{$collection}_{$index}`";
        $sql = "DROP INDEX {$index}";

        self::$pdo->exec($sql);

        return true;
    }
}
