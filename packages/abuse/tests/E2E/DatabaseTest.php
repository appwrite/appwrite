<?php

namespace Utopia\Abuse\Tests\E2E;

use PDO;
use Utopia\Abuse\Adapter\TimeLimit;
use Utopia\Abuse\Adapter\TimeLimit\Database as AdapterDatabase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Database;

class DatabaseTest extends Base
{
    protected static Database $database;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        if (isset(self::$database)) {
            return;
        }

        self::$database = self::initialiseDatabase();
    }

    private static function initialiseDatabase(): Database
    {
        $pdo = new PDO('mysql:host=' . Services::HOST . ';port=' . Services::MYSQL_PORT . ';charset=utf8mb4', 'root', 'password', [
            PDO::ATTR_TIMEOUT => 3,
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_STRINGIFY_FETCHES => true,
        ]);
        $database = new Database(new MySQL($pdo), new Cache(new NoCache()));
        $database->setDatabase('utopiaTests');
        $database->setNamespace('namespace');

        $adapter = new AdapterDatabase('', 1, 1, $database);
        if (!$database->exists('utopiaTests')) {
            $database->create();
            $adapter->setup();
        }

        return $database;
    }

    #[\Override]
    public function getAdapter(string $key, int $limit, int $seconds): TimeLimit
    {
        return new AdapterDatabase($key, $limit, $seconds, self::$database);
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        if (isset(self::$database)) {
            self::$database->delete();
        }
    }
}
