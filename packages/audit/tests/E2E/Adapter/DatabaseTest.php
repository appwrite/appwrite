<?php

declare(strict_types=1);

namespace Utopia\Audit\Tests\E2E\Adapter;

use PDO;
use PHPUnit\Framework\TestCase;
use Utopia\Audit\Adapter;
use Utopia\Audit\Audit;
use Utopia\Audit\Log;
use Utopia\Audit\Query;
use Utopia\Audit\Tests\E2E\AuditBase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;

/**
 * Database Adapter Tests
 */
final class DatabaseTest extends TestCase
{
    use AuditBase;

    protected function initializeAudit(): void
    {
        $host = getenv('MARIADB_HOST') ?: '127.0.0.1';
        $port = getenv('MARIADB_PORT') ?: '13307';
        $username = 'root';
        $password = 'password';

        $connection = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [
            PDO::ATTR_TIMEOUT => 3,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_STRINGIFY_FETCHES => true,
        ]);
        $cache = new Cache(new NoCache());
        $database = new Database(new MariaDB($connection), $cache);
        $database->setDatabase('utopiaTests');
        $database->setNamespace('namespace');

        $adapter = new Adapter\Database($database);
        $this->audit = new Audit($adapter);
        if (! $database->exists('utopiaTests')) {
            $database->create();
            $this->audit->setup();
        }
    }

    public function testLogWithoutUserIdOrData(): void
    {
        $log = $this->audit->log(null, 'schema.created', 'schema/defaults', 'test', '127.0.0.1');

        $stored = $this->audit->getLogById($log->getId());
        $this->assertInstanceOf(Log::class, $stored);
        $this->assertNull($stored->getUserId());
        $this->assertSame([], $stored->getAttribute('data'));
        $this->assertNotEmpty($stored->getAttribute('time'));

        $logs = $this->audit->find([
            Query::equal('resource', ['schema/defaults']),
            Query::containsString('event', ['created']),
        ]);
        $this->assertCount(1, $logs);
        $this->assertSame($log->getId(), $logs[0]->getId());
    }

    public function testUserIdAcceptsFullKeyLength(): void
    {
        $userId = str_repeat('u', Database::LENGTH_KEY);

        $log = $this->audit->log($userId, 'schema.length', 'schema/length', 'test', '127.0.0.1');

        $stored = $this->audit->getLogById($log->getId());
        $this->assertInstanceOf(Log::class, $stored);
        $this->assertSame($userId, $stored->getUserId());
        $this->assertCount(1, $this->audit->getLogsByUser($userId));
    }
}
