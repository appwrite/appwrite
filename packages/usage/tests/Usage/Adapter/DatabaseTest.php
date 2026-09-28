<?php

namespace Utopia\Tests\Adapter;

use PDO;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\Exception\Duplicate;
use Utopia\Query\Query;
use Utopia\Tests\Usage\UsageBase;
use Utopia\Usage\Adapter\Database as AdapterDatabase;
use Utopia\Usage\Usage;

class DatabaseTest extends TestCase
{
    use UsageBase;

    protected Database $database;

    protected function initializeUsage(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql extension is not installed in this environment');
        }

        $dbHost = getenv('MARIADB_HOST') ?: 'mariadb';
        $dbPort = getenv('MARIADB_PORT') ?: '3306';
        $dbUser = 'root';
        $dbPass = 'password';

        $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, MariaDB::getPdoAttributes());
        $cache = new Cache(new NoCache());
        $this->database = new Database(new MariaDB($pdo), $cache);
        $this->database->setDatabase('utopiaTests');
        $this->database->setNamespace('utopia_usage');

        $this->usage = new Usage(new AdapterDatabase($this->database));

        // Create database if missing
        try {
            $this->database->create();
        } catch (Duplicate $ex) {
            // ignore duplicate exception
        }

        // Always run setup to ensure collection exists
        try {

            $this->usage->setup();
        } catch (Duplicate $ex) {
            // ignore duplicate exception
        }
    }

    /**
     * Round-trip a row with the full event dimension set through the
     * Database adapter.
     */
    public function testEventColumnsExtractedFromTags(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql extension is not installed');
        }

        $this->usage->purge('1', [], Usage::TYPE_EVENT);

        $this->assertTrue($this->usage->addBatch([
            [
                'tenant' => '1',
                'metric' => 'event-cols-db',
                'value' => 42,
                'tags' => [
                    'path' => '/v1/storage/files',
                    'method' => 'POST',
                    'status' => '201',
                    'service' => 'storage',
                    'resourceType' => 'bucket',
                    'resourceId' => 'bucket123',
                    'resourceInternalId' => '42',
                    'teamId' => 'team_x',
                    'teamInternalId' => '7',
                    'country' => 'US',
                    'region' => 'us-east',
                    'hostname' => 'app.example.com',
                    'osName' => 'iOS',
                    'clientName' => 'Appwrite SDK',
                    'deviceName' => 'smartphone',
                ],
            ],
        ], Usage::TYPE_EVENT));

        $results = $this->usage->find('1', [
            \Utopia\Query\Query::equal('metric', ['event-cols-db']),
        ], Usage::TYPE_EVENT);

        $this->assertCount(1, $results);
        $metric = $results[0];
        $this->assertEquals('/v1/storage/files', $metric->getPath());
        $this->assertEquals('storage', $metric->getService());
        $this->assertEquals('42', $metric->getResourceInternalId());
        $this->assertEquals('team_x', $metric->getTeamId());
        $this->assertEquals('7', $metric->getTeamInternalId());
        $this->assertEquals('us', $metric->getCountry());
        $this->assertEquals('us-east', $metric->getRegion());
        $this->assertEquals('app.example.com', $metric->getHostname());
        $this->assertEquals('iOS', $metric->getOsName());
        $this->assertEquals('Appwrite SDK', $metric->getClientName());
        $this->assertEquals('smartphone', $metric->getDeviceName());
    }

    /**
     * Gauge rows round-trip the four gauge dimension columns.
     */
    public function testGaugeColumnsRoundTrip(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql extension is not installed');
        }

        $this->usage->purge('1', [], Usage::TYPE_GAUGE);

        $this->assertTrue($this->usage->addBatch([
            [
                'tenant' => '1',
                'metric' => 'gauge-cols-db',
                'value' => 500,
                'tags' => [
                    'teamId' => 'team_x',
                    'teamInternalId' => '7',
                    'resourceId' => 'r1',
                    'resourceInternalId' => '42',
                ],
            ],
        ], Usage::TYPE_GAUGE));

        $results = $this->usage->find('1', [
            \Utopia\Query\Query::equal('metric', ['gauge-cols-db']),
        ], Usage::TYPE_GAUGE);

        $this->assertCount(1, $results);
        $metric = $results[0];
        $this->assertEquals('team_x', $metric->getTeamId());
        $this->assertEquals('7', $metric->getTeamInternalId());
        $this->assertEquals('r1', $metric->getResourceId());
        $this->assertEquals('42', $metric->getResourceInternalId());
    }

    public function testUnknownTagKeyThrows(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql extension is not installed');
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches("/Unknown column 'bogus'/");
        $this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'x', 'value' => 1, 'tags' => ['bogus' => 'v']],
        ], Usage::TYPE_EVENT);
    }

    public function testCountryAndRegionLowercased(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql extension is not installed');
        }

        $this->usage->purge('1', [], Usage::TYPE_EVENT);
        $this->assertTrue($this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'lc-db', 'value' => 1, 'tags' => ['country' => 'US', 'region' => 'FR']],
        ], Usage::TYPE_EVENT));

        $results = $this->usage->find('1', [
            \Utopia\Query\Query::equal('metric', ['lc-db']),
        ], Usage::TYPE_EVENT);

        $this->assertCount(1, $results);
        $this->assertSame('us', $results[0]->getCountry());
        $this->assertSame('fr', $results[0]->getRegion());
    }

    public function testEmptyStringCoercedToNull(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql extension is not installed');
        }

        $this->usage->purge('1', [], Usage::TYPE_EVENT);
        $this->assertTrue($this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'empty-db', 'value' => 1, 'tags' => ['osName' => '']],
        ], Usage::TYPE_EVENT));

        $results = $this->usage->find('1', [
            \Utopia\Query\Query::equal('metric', ['empty-db']),
        ], Usage::TYPE_EVENT);

        $this->assertCount(1, $results);
        $this->assertNull($results[0]->getOsName());
    }

    /**
     * Test healthCheck() method
     */
    public function testHealthCheck(): void
    {
        $adapter = $this->usage->getAdapter();

        $health = $adapter->healthCheck();

        // Assert basic structure
        $this->assertArrayHasKey('healthy', $health);

        // Assert connection is healthy
        $this->assertTrue($health['healthy'], 'Database should be healthy');

        // Assert additional fields are present when healthy
        $this->assertArrayHasKey('database', $health);
        $this->assertArrayHasKey('collection', $health);
        $this->assertIsString($health['database']);
        $this->assertIsString($health['collection']);
    }

    /**
     * Test healthCheck() with database that doesn't exist
     */
    public function testHealthCheckWithNonExistentDatabase(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql extension is not installed');
        }

        // Create a new database instance pointing to a non-existent database
        $dbHost = getenv('MARIADB_HOST') ?: 'mariadb';
        $dbPort = getenv('MARIADB_PORT') ?: '3306';
        $dbUser = 'root';
        $dbPass = 'password';

        $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, MariaDB::getPdoAttributes());
        $cache = new Cache(new NoCache());
        $database = new Database(new MariaDB($pdo), $cache);
        $database->setDatabase('nonexistent_database_xyz');
        $database->setNamespace('test');

        $adapter = new AdapterDatabase($database);

        $health = $adapter->healthCheck();

        // Assert basic structure
        $this->assertArrayHasKey('healthy', $health);

        // Assert connection failed
        $this->assertFalse($health['healthy'], 'Database should be unhealthy with non-existent database');

        // Assert error message is present
        $this->assertArrayHasKey('error', $health);
        if (isset($health['error'])) {
            $this->assertIsString($health['error']);
            $this->assertNotEmpty($health['error']);
        }
    }

    public function testNegativeValueRejectedByDefault(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql extension is not installed');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Value cannot be negative');

        $this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'db-negative-default', 'value' => -1],
        ], Usage::TYPE_EVENT);
    }

    public function testNegativeValuePersistsWhenOptedIn(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql extension is not installed');
        }

        $this->usage->purge('1', [], Usage::TYPE_EVENT);

        // A signed delta opts in per row. The accumulator sets this flag and
        // the ClickHouse adapter honours it; an adapter that ignored it would
        // reject the row on every flush, since a failed batch stays buffered.
        $this->assertTrue($this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'db-negative-optin', 'value' => -3, 'allowNegative' => true],
        ], Usage::TYPE_EVENT));

        $this->assertEquals(
            -3,
            $this->usage->sum('1', [Query::equal('metric', ['db-negative-optin'])], 'value', Usage::TYPE_EVENT),
            'the opted-in negative must be stored, not silently dropped',
        );
    }
}
