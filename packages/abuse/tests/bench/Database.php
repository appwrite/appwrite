<?php

namespace Utopia\Abuse\Tests\Bench;

use PDO;
use Utopia\Abuse\Adapter\TimeLimit\Database as TimeLimit;
use Utopia\Abuse\Tests\E2E\Services;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Database as UtopiaDatabase;

final class Database extends Base
{
    protected UtopiaDatabase $database;

    /**
     * @throws \Exception
     */
    #[\Override]
    public function setUp(): void
    {
        // Limit login attempts to 3 per 5 minutes
        $host = Services::HOST;
        $user = 'root';
        $port = Services::MYSQL_PORT;
        $password = 'password';

        $pdo = new PDO(
            dsn: "mysql:host={$host};port={$port};charset=utf8mb4",
            username: $user,
            password: $password,
            options: [
                PDO::ATTR_TIMEOUT => 3,
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => true,
                PDO::ATTR_STRINGIFY_FETCHES => true,
            ],
        );

        $database = new UtopiaDatabase(new MySQL($pdo), new Cache(new NoCache()));
        $database->setDatabase('utopiaTests');
        $database->setNamespace('namespace');
        $this->database = $database;

        $adapter = new TimeLimit('login-attempt-from-{{ip}}', 3, 60 * 5, $database);
        if (!$database->exists('utopiaTests')) {
            $database->create();
            $adapter->setup();
        }
        $this->adapter = $adapter;
    }
}
