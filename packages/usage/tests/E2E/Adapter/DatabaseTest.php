<?php

namespace Utopia\Usage\Tests\E2E\Adapter;

use PDO;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Attribute;
use Utopia\Database\Database;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Index;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Query\Query;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\IndexType;
use Utopia\Usage\Adapter\Database as AdapterDatabase;
use Utopia\Usage\Adapter\SQL;
use Utopia\Usage\Metric;
use Utopia\Usage\Tests\E2E\UsageBase;
use Utopia\Usage\Usage;

class DatabaseTest extends TestCase
{
    use UsageBase;

    private const string SCHEMA_NAMESPACE = 'utopia_usage_schema';

    protected Database $database;

    protected function initializeUsage(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql extension is not installed in this environment');
        }

        $this->database = $this->connect('utopiaTests', 'utopia_usage');

        $this->usage = new Usage(new AdapterDatabase($this->database));

        // Create database if missing
        try {
            $this->database->create();
        } catch (Duplicate) {
            // ignore duplicate exception
        }

        // Always run setup to ensure collection exists
        try {

            $this->usage->setup();
        } catch (Duplicate) {
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

        $adapter = new AdapterDatabase($this->connect('nonexistent_database_xyz', 'test'));

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

    public function testSetupCreatesTheCollection0165Created(): void
    {
        $database = $this->connect('utopiaTests', self::SCHEMA_NAMESPACE);
        $database->create();
        self::dropCollection($database);

        try {
            new AdapterDatabase($database)->setup();

            $collection = $database->getCollection(SQL::COLLECTION);

            $this->assertSame(
                \array_map(self::describeDeclaredAttribute(...), self::declaredAttributes()),
                \array_map(self::describeAttribute(...), $collection->attributes()),
                'every column 0.16.5 declared must come back with the same type, size and flags, in the same order',
            );
            $this->assertSame(
                \array_map(self::describeDeclaredIndex(...), self::declaredIndexes()),
                \array_map(self::describeIndex(...), $collection->indexes()),
                'every index 0.16.5 declared must come back with the same type, columns and prefix lengths, in the same order',
            );
            $this->assertTrue($collection->documentSecurity());
            $this->assertSame([Permission::create(Role::any())], $collection->getPermissions());
        } finally {
            self::dropCollection($database);
        }
    }

    private static function dropCollection(Database $database): void
    {
        if ($database->findCollection(SQL::COLLECTION) !== null) {
            $database->deleteCollection(SQL::COLLECTION);
        }
    }

    private function connect(string $database, string $namespace): Database
    {
        $host = getenv('MARIADB_HOST') ?: 'mariadb';
        $port = getenv('MARIADB_PORT') ?: '3306';

        $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", 'root', 'password', MariaDB::getPDOAttributes());

        $connection = new Database(new MariaDB($pdo), new Cache(new NoCache()));
        $connection->setDatabase($database);
        $connection->setNamespace($namespace);

        return $connection;
    }

    /**
     * The declarations in the array form 0.16.5 passed to utopia-php/database 7's createCollection(), which stored them as given.
     *
     * @return array<array<string, mixed>>
     */
    private static function declaredAttributes(): array
    {
        return [
            ...Metric::getEventSchema(),
            ['$id' => 'type', 'type' => ColumnType::String->value, 'size' => 16, 'required' => false, 'signed' => true, 'array' => false, 'filters' => []],
        ];
    }

    /**
     * @return array<array<string, mixed>>
     */
    private static function declaredIndexes(): array
    {
        return [
            ...Metric::getEventIndexes(),
            ['$id' => 'index-type', 'type' => IndexType::Key->value, 'attributes' => ['type']],
        ];
    }

    /**
     * @param array<string, mixed> $declared
     * @return array<string, mixed>
     */
    private static function describeDeclaredAttribute(array $declared): array
    {
        /** @var array{'$id': string, type: string, size: int, required: bool, signed: bool, array: bool, filters: array<string>, format?: string} $declared */
        return [
            'key' => $declared['$id'],
            'type' => ColumnType::from($declared['type']),
            'size' => $declared['size'],
            'required' => $declared['required'],
            'default' => null,
            'signed' => $declared['signed'],
            'array' => $declared['array'],
            'format' => ($declared['format'] ?? '') ?: null,
            'filters' => $declared['filters'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function describeAttribute(Attribute $attribute): array
    {
        return [
            'key' => $attribute->key,
            'type' => $attribute->type,
            'size' => $attribute->size,
            'required' => $attribute->required,
            'default' => $attribute->default,
            'signed' => $attribute->signed,
            'array' => $attribute->array,
            'format' => $attribute->format ?: null,
            'filters' => $attribute->filters,
        ];
    }

    /**
     * @param array<string, mixed> $declared
     * @return array<string, mixed>
     */
    private static function describeDeclaredIndex(array $declared): array
    {
        /** @var array{'$id': string, type: string, attributes: array<string>, lengths?: array<int>} $declared */
        return [
            'key' => $declared['$id'],
            'type' => IndexType::from($declared['type']),
            'attributes' => $declared['attributes'],
            'lengths' => $declared['lengths'] ?? [],
            'orders' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function describeIndex(Index $index): array
    {
        return [
            'key' => $index->key,
            'type' => $index->type,
            'attributes' => $index->attributes,
            'lengths' => $index->lengths,
            'orders' => $index->orders,
        ];
    }
}
