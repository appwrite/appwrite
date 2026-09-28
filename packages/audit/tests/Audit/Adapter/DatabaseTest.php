<?php

declare(strict_types=1);

namespace Utopia\Tests\Audit\Adapter;

use PDO;
use PHPUnit\Framework\TestCase;
use Utopia\Audit\Adapter;
use Utopia\Audit\Audit;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Tests\Audit\AuditBase;

/**
 * Database Adapter Tests
 */
final class DatabaseTest extends TestCase
{
    use AuditBase;

    protected function initializeAudit(): void
    {
        $dbHost = getenv('MARIADB_HOST') ?: '127.0.0.1';
        $dbPort = getenv('MARIADB_PORT') ?: '13307';
        $dbUser = 'root';
        $dbPass = 'password';

        $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, MariaDB::getPdoAttributes());
        $cache = new Cache(new NoCache());
        $database = new Database(new MariaDB($pdo), $cache);
        $database->setDatabase('utopiaTests');
        $database->setNamespace('namespace');

        $adapter = new Adapter\Database($database);
        $this->audit = new Audit($adapter);
        if (! $database->exists('utopiaTests')) {
            $database->create();
            $this->audit->setup();
        }
    }
}
