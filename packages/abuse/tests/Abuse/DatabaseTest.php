<?php

namespace Utopia\Tests;

use PDO;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Abuse\Adapters\TimeLimit\Database as AdapterDatabase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Database;

class DatabaseTest extends Base
{
    protected static Database $db;

    public static function setUpBeforeClass(): void
    {
        if (isset(self::$db)) {
            return;
        }

        self::$db = self::initialiseDatabase();
    }

    private static function initialiseDatabase(): Database
    {
        $dbHost = 'mysql';
        $dbUser = 'root';
        $dbPort = '3306';
        $dbPass = 'password';

        $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, MariaDB::getPdoAttributes());
        $db = new Database(new MySQL($pdo), new Cache(new NoCache()));
        $db->setDatabase('utopiaTests');
        $db->setNamespace('namespace');

        $adapter = new AdapterDatabase('', 1, 1, $db);
        if (!$db->exists('utopiaTests')) {
            $db->create();
            $adapter->setup();
        }

        return $db;
    }

    public function getAdapter(string $key, int $limit, int $seconds): TimeLimit
    {
        return new AdapterDatabase($key, $limit, $seconds, self::$db);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$db)) {
            self::$db->delete();
        }
    }
}
