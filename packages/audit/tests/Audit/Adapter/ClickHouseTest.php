<?php

declare(strict_types=1);

namespace Utopia\Tests\Audit\Adapter;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Audit\Adapter\ClickHouse;
use Utopia\Audit\Audit;
use Utopia\Audit\Query;
use Utopia\Tests\Audit\AuditBase;

/**
 * ClickHouse Adapter Tests
 *
 * Tests ClickHouse-specific features and configurations.
 * Generic audit functionality tests are in AuditBase trait.
 */
final class ClickHouseTest extends TestCase
{
    use AuditBase;

    protected function initializeAudit(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'localhost';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 18123);
        $secure = filter_var(getenv('CLICKHOUSE_SECURE') ?: false, FILTER_VALIDATE_BOOLEAN);

        $clickHouse = new ClickHouse(
            host: $host,
            username: $username,
            password: $password,
            port: $port,
            secure: $secure,
        );

        if ($database = getenv('CLICKHOUSE_DATABASE')) {
            $clickHouse->setDatabase($database);
        } else {
            $clickHouse->setDatabase('default');
        }

        $this->audit = new Audit($clickHouse);
        $this->audit->setup();
    }

    /**
     * Provide required attributes for ClickHouse adapter tests.
     *
     * @return array<string, mixed>
     */
    protected function getRequiredAttributes(): array
    {
        return [
            'actorType' => 'member',
            'resourceType' => 'document',
            'resourceId' => 'res-1',
            'projectId' => 'proj-1',
            'projectInternalId' => 'proj-int-1',
            'teamId' => 'team-1',
            'teamInternalId' => 'team-int-1',
            'hostname' => 'example.org',
            'country' => 'us',
        ];
    }

    /**
     * Test constructor validates host
     */
    public function testConstructorValidatesHost(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('ClickHouse host is not a valid hostname or IP address');

        new ClickHouse(
            host: '',
            username: 'default',
            password: '',
        );
    }

    /**
     * Test constructor validates port range
     */
    public function testConstructorValidatesPortTooLow(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('ClickHouse port must be between 1 and 65535');

        new ClickHouse(
            host: 'localhost',
            username: 'default',
            password: '',
            port: 0,
        );
    }

    /**
     * Test constructor validates port range upper bound
     */
    public function testConstructorValidatesPortTooHigh(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('ClickHouse port must be between 1 and 65535');

        new ClickHouse(
            host: 'localhost',
            username: 'default',
            password: '',
            port: 65536,
        );
    }

    /**
     * Test constructor with valid parameters
     */
    public function testConstructorWithValidParameters(): void
    {
        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'testuser',
            password: 'testpass',
            port: 8443,
            secure: true,
        );

        $this->assertInstanceOf(ClickHouse::class, $adapter);
        $this->assertSame('ClickHouse', $adapter->getName());
    }

    /**
     * Test getName returns correct adapter name
     */
    public function testGetName(): void
    {
        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $this->assertSame('ClickHouse', $adapter->getName());
    }

    /**
     * Test setDatabase validates empty identifier
     */
    public function testSetDatabaseValidatesEmpty(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Database cannot be empty');

        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $adapter->setDatabase('');
    }

    /**
     * Test setDatabase validates identifier length
     */
    public function testSetDatabaseValidatesLength(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Database cannot exceed 255 characters');

        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $adapter->setDatabase(str_repeat('a', 256));
    }

    /**
     * Test setDatabase validates identifier format
     */
    public function testSetDatabaseValidatesFormat(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Database must start with a letter or underscore');

        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $adapter->setDatabase('123invalid');
    }

    /**
     * Test setDatabase rejects SQL keywords
     */
    public function testSetDatabaseRejectsKeywords(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Database cannot be a reserved SQL keyword');

        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $adapter->setDatabase('SELECT');
    }

    /**
     * Test setDatabase with valid identifier
     */
    public function testSetDatabaseWithValidIdentifier(): void
    {
        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $result = $adapter->setDatabase('my_database_123');
        $this->assertInstanceOf(ClickHouse::class, $result);
    }

    /**
     * Test setTable validates empty identifier
     */
    public function testSetTableValidatesEmpty(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Table cannot be empty');

        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $adapter->setTable('');
    }

    /**
     * Test setTable validates identifier length
     */
    public function testSetTableValidatesLength(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Table cannot exceed 255 characters');

        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $adapter->setTable(str_repeat('a', 256));
    }

    /**
     * Test setTable validates identifier format
     */
    public function testSetTableValidatesFormat(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Table must start with a letter or underscore');

        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $adapter->setTable('123invalid');
    }

    /**
     * Test setTable rejects SQL keywords
     */
    public function testSetTableRejectsKeywords(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Table cannot be a reserved SQL keyword');

        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $adapter->setTable('SELECT');
    }

    /**
     * Test setTable with valid identifier
     */
    public function testSetTableWithValidIdentifier(): void
    {
        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $result = $adapter->setTable('my_audit_logs');
        $this->assertInstanceOf(ClickHouse::class, $result);
        $this->assertSame('my_audit_logs', $adapter->getTable());
    }

    /**
     * Test setNamespace allows empty string
     */
    public function testSetNamespaceAllowsEmpty(): void
    {
        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $result = $adapter->setNamespace('');
        $this->assertInstanceOf(ClickHouse::class, $result);
        $this->assertSame('', $adapter->getNamespace());
    }

    /**
     * Test setNamespace validates identifier format
     */
    public function testSetNamespaceValidatesFormat(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Namespace must start with a letter or underscore');

        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $adapter->setNamespace('9invalid');
    }

    /**
     * Test setNamespace with valid identifier
     */
    public function testSetNamespaceWithValidIdentifier(): void
    {
        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $result = $adapter->setNamespace('project_123');
        $this->assertInstanceOf(ClickHouse::class, $result);
        $this->assertSame('project_123', $adapter->getNamespace());
    }

    /**
     * Test setSecure method
     */
    public function testSetSecure(): void
    {
        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
            port: 8123,
            secure: false,
        );

        $result = $adapter->setSecure(true);
        $this->assertInstanceOf(ClickHouse::class, $result);
    }

    /**
     * Test setRetention stores the value and getRetention returns it
     */
    public function testSetRetention(): void
    {
        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $this->assertNull($adapter->getRetention());

        $result = $adapter->setRetention(30);
        $this->assertInstanceOf(ClickHouse::class, $result);
        $this->assertEquals(30, $adapter->getRetention());
    }

    /**
     * Test setRetention accepts null to disable retention
     */
    public function testSetRetentionAcceptsNull(): void
    {
        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $adapter->setRetention(30);
        $adapter->setRetention(null);
        $this->assertNull($adapter->getRetention());
    }

    /**
     * Test setRetention rejects zero days
     */
    public function testSetRetentionRejectsZero(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Retention must be a positive number of days');

        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $adapter->setRetention(0);
    }

    /**
     * Test setRetention rejects negative days
     */
    public function testSetRetentionRejectsNegative(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Retention must be a positive number of days');

        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $adapter->setRetention(-1);
    }

    /**
     * Test shared tables configuration
     */
    public function testSharedTablesConfiguration(): void
    {
        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        // Test initial state
        $this->assertFalse($adapter->isSharedTables());
        $this->assertNull($adapter->getTenant());

        // Test setting shared tables
        $result = $adapter->setSharedTables(true);
        $this->assertInstanceOf(ClickHouse::class, $result);
        $this->assertTrue($adapter->isSharedTables());

        // Test setting tenant
        $result2 = $adapter->setTenant(12345);
        $this->assertInstanceOf(ClickHouse::class, $result2);
        $this->assertEquals(12345, $adapter->getTenant());

        // Test setting tenant to null
        $adapter->setTenant(null);
        $this->assertNull($adapter->getTenant());
    }

    /**
     * Test batch operations with special characters
     */
    public function testBatchOperationsWithSpecialCharacters(): void
    {
        // Test batch with special characters in data
        $batchEvents = [
            [
                'actorId' => 'actor`with`backticks',
                'event' => 'create',
                'resource' => 'doc/"quotes"',
                'userAgent' => "User'Agent\"With'Quotes",
                'ip' => '192.168.1.1',
                'data' => ['special' => "data with 'quotes'"],
                'time' => \Utopia\Database\DateTime::formatTz(\Utopia\Database\DateTime::now()) ?? '',
            ],
        ];

        $batchEvents = $this->applyRequiredAttributesToBatch($batchEvents);
        $result = $this->audit->logBatch($batchEvents);
        $this->assertTrue($result);

        // Verify retrieval
        $logs = $this->audit->getLogsByUser('actor`with`backticks');
        $this->assertGreaterThan(0, \count($logs));
    }

    /**
     * Test that ClickHouse adapter has all required attributes
     */
    public function testClickHouseAdapterAttributes(): void
    {
        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $attributes = $adapter->getAttributes();
        $attributeIds = array_map(fn(array $attr): mixed => $attr['$id'], $attributes);

        // Verify all expected attributes exist
        $expectedAttributes = [
            'actorType',
            'actorId',
            'actorInternalId',
            'resourceParent',
            'resourceType',
            'resourceId',
            'resourceInternalId',
            'event',
            'resource',
            'userAgent',
            'ip',
            'country',
            'time',
            'data',
            'projectId',
            'projectInternalId',
            'teamId',
            'teamInternalId',
            'hostname',
            // premium geo
            'city',
            'continentCode',
            'subdivisions',
            'isp',
            'autonomousSystemNumber',
            'autonomousSystemOrganization',
            'connectionType',
            'connectionUsageType',
            'connectionOrganization',
            // sdk
            'sdk',
            'sdkVersion',
            // user-agent — parsed OS / client / device
            'osCode',
            'osName',
            'osVersion',
            'clientType',
            'clientCode',
            'clientName',
            'clientVersion',
            'clientEngine',
            'clientEngineVersion',
            'deviceName',
            'deviceBrand',
            'deviceModel',
        ];

        foreach ($expectedAttributes as $expected) {
            $this->assertContains($expected, $attributeIds, "Attribute '{$expected}' not found in ClickHouse adapter");
        }
    }

    /**
     * Test that user-agent columns get the correct ClickHouse type: bounded
     * name/code/type dimensions use LowCardinality(Nullable(String)), while
     * high-cardinality version/model strings stay plain Nullable(String).
     */
    public function testUserAgentColumnTypes(): void
    {
        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $method = new \ReflectionMethod($adapter, 'getColumnDefinition');

        $lowCardinality = [
            'osCode',
            'osName',
            'clientType',
            'clientCode',
            'clientName',
            'clientEngine',
            'deviceName',
            'deviceBrand',
        ];
        foreach ($lowCardinality as $column) {
            $definition = $method->invoke($adapter, $column);
            $this->assertEquals("{$column} LowCardinality(Nullable(String))", $definition);
        }

        $highCardinality = [
            'osVersion',
            'clientVersion',
            'clientEngineVersion',
            'deviceModel',
        ];
        foreach ($highCardinality as $column) {
            $definition = $method->invoke($adapter, $column);
            $this->assertEquals("{$column} Nullable(String)", $definition);
        }
    }

    /**
     * Test that premium geo attributes are all optional String columns.
     */
    public function testPremiumGeoAttributesAreOptionalStrings(): void
    {
        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $attributes = $adapter->getAttributes();
        $byId = [];
        foreach ($attributes as $attribute) {
            $byId[$attribute['$id']] = $attribute;
        }

        $geoColumns = [
            'city',
            'continentCode',
            'subdivisions',
            'isp',
            'autonomousSystemNumber',
            'autonomousSystemOrganization',
            'connectionType',
            'connectionUsageType',
            'connectionOrganization',
        ];

        foreach ($geoColumns as $column) {
            $this->assertArrayHasKey($column, $byId, "Premium geo attribute '{$column}' not found");
            $this->assertEquals(\Utopia\Database\Database::VAR_STRING, $byId[$column]['type'], "'{$column}' should be a string");
            $this->assertFalse($byId[$column]['required'], "'{$column}' should be optional");
            $this->assertFalse($byId[$column]['array'], "'{$column}' should not be an array");
        }
    }

    /**
     * Test that premium geo columns get the correct ClickHouse type:
     * low-cardinality dimensions use LowCardinality(Nullable(String)), while
     * high-cardinality ones stay plain Nullable(String).
     */
    public function testPremiumGeoColumnTypes(): void
    {
        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $method = new \ReflectionMethod($adapter, 'getColumnDefinition');

        $lowCardinality = [
            'continentCode',
            'subdivisions',
            'connectionType',
            'connectionUsageType',
        ];
        foreach ($lowCardinality as $column) {
            $definition = $method->invoke($adapter, $column);
            $this->assertEquals("{$column} LowCardinality(Nullable(String))", $definition);
        }

        // autonomousSystemNumber is high-cardinality (~100k ASNs) so it is a
        // plain Nullable(String), not LowCardinality.
        $highCardinality = [
            'city',
            'isp',
            'autonomousSystemNumber',
            'autonomousSystemOrganization',
            'connectionOrganization',
        ];
        foreach ($highCardinality as $column) {
            $definition = $method->invoke($adapter, $column);
            $this->assertEquals("{$column} Nullable(String)", $definition);
        }
    }

    /**
     * Premium geo values must round-trip through a real write/read cycle:
     * write a log carrying all 9 geo fields and read it back unchanged. This
     * proves the columns are actually created, written and selected (not just
     * present in the schema definition).
     */
    public function testPremiumGeoRoundTrip(): void
    {
        $actorId = 'geo-actor-' . uniqid('', true);
        $geo = [
            'city' => 'Mountain View',
            'continentCode' => 'NA',
            'subdivisions' => 'California',
            'isp' => 'Google',
            'autonomousSystemNumber' => '15169',
            'autonomousSystemOrganization' => 'GOOGLE',
            'connectionType' => 'cable',
            'connectionUsageType' => 'residential',
            'connectionOrganization' => 'Google LLC',
        ];

        $batchEvents = [array_merge([
            'actorId' => $actorId,
            'event' => 'geo.roundtrip',
            'resource' => 'document/geo-1',
            'userAgent' => 'RoundTrip/1.0',
            'ip' => '8.8.8.8',
            'data' => [],
            'time' => \Utopia\Database\DateTime::formatTz(\Utopia\Database\DateTime::now()) ?? '',
        ], $geo)];

        $batchEvents = $this->applyRequiredAttributesToBatch($batchEvents);
        $this->assertTrue($this->audit->logBatch($batchEvents));

        $logs = $this->audit->getLogsByUser($actorId);
        $this->assertGreaterThan(0, \count($logs), 'geo round-trip log was not persisted');

        $log = $logs[0];
        foreach ($geo as $key => $expected) {
            $this->assertSame($expected, $log->getAttribute($key), "premium geo '{$key}' did not round-trip");
        }
    }

    /**
     * User-agent OS / client / device values must round-trip through a real
     * write/read cycle, proving the columns are created, written and selected.
     */
    public function testUserAgentRoundTrip(): void
    {
        $actorId = 'ua-actor-' . uniqid('', true);
        $ua = [
            'osCode' => 'IOS',
            'osName' => 'iOS',
            'osVersion' => '17.4',
            'clientType' => 'browser',
            'clientCode' => 'MF',
            'clientName' => 'Mobile Safari',
            'clientVersion' => '17.4',
            'clientEngine' => 'WebKit',
            'clientEngineVersion' => '605.1.15',
            'deviceName' => 'smartphone',
            'deviceBrand' => 'Apple',
            'deviceModel' => 'iPhone',
        ];

        $batchEvents = [array_merge([
            'actorId' => $actorId,
            'event' => 'ua.roundtrip',
            'resource' => 'document/ua-1',
            'userAgent' => 'RoundTrip/1.0',
            'ip' => '8.8.8.8',
            'data' => [],
            'time' => \Utopia\Database\DateTime::formatTz(\Utopia\Database\DateTime::now()) ?? '',
        ], $ua)];

        $batchEvents = $this->applyRequiredAttributesToBatch($batchEvents);
        $this->assertTrue($this->audit->logBatch($batchEvents));

        $logs = $this->audit->getLogsByUser($actorId);
        $this->assertGreaterThan(0, \count($logs), 'user-agent round-trip log was not persisted');

        $log = $logs[0];
        foreach ($ua as $key => $expected) {
            $this->assertSame($expected, $log->getAttribute($key), "user-agent '{$key}' did not round-trip");
        }

        // Exercise the typed getters so a wrong attribute key in any of them is
        // caught (getAttribute() above can't detect that).
        $this->assertSame($ua['osCode'], $log->getOsCode());
        $this->assertSame($ua['osName'], $log->getOsName());
        $this->assertSame($ua['osVersion'], $log->getOsVersion());
        $this->assertSame($ua['clientType'], $log->getClientType());
        $this->assertSame($ua['clientCode'], $log->getClientCode());
        $this->assertSame($ua['clientName'], $log->getClientName());
        $this->assertSame($ua['clientVersion'], $log->getClientVersion());
        $this->assertSame($ua['clientEngine'], $log->getClientEngine());
        $this->assertSame($ua['clientEngineVersion'], $log->getClientEngineVersion());
        $this->assertSame($ua['deviceName'], $log->getDeviceName());
        $this->assertSame($ua['deviceBrand'], $log->getDeviceBrand());
        $this->assertSame($ua['deviceModel'], $log->getDeviceModel());
    }

    /**
     * Test that ClickHouse adapter has all required indexes
     */
    public function testClickHouseAdapterIndexes(): void
    {
        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $indexes = $adapter->getIndexes();
        $indexIds = array_map(fn(array $idx): mixed => $idx['$id'], $indexes);

        // Verify all ClickHouse-specific indexes exist
        $expectedClickHouseIndexes = [
            '_key_actor_internal_and_event',
            '_key_project_internal_id',
            '_key_team_internal_id',
            '_key_actor_internal_id',
            '_key_actor_type',
            '_key_country',
            '_key_hostname',
            '_key_sdk',
        ];

        foreach ($expectedClickHouseIndexes as $expected) {
            $this->assertContains($expected, $indexIds, "ClickHouse index '{$expected}' not found in ClickHouse adapter");
        }

        // Verify parent indexes are also included (with parent naming convention)
        $parentExpectedIndexes = ['idx_event', 'idx_actorId_event', 'idx_resource_event', 'idx_time_desc'];
        foreach ($parentExpectedIndexes as $expected) {
            $this->assertContains($expected, $indexIds, "Parent index '{$expected}' not found in ClickHouse adapter");
        }
    }

    /**
     * Test parsing of complex resource paths into resourceType/resourceId/resourceParent
     */
    public function testParseResourceComplexPath(): void
    {
        $actorId = 'parseActor';
        $userAgent = 'UnitTestAgent/1.0';
        $ip = '127.0.0.1';

        $resource = 'database/6978484940ff05762e1a/table/697848498066e3d2ef64';

        // Ensure we don't provide resourceType/resourceId in data so adapter must parse it
        $data = ['example' => 'value'];

        // Merge required adapter attributes so ClickHouse won't reject the log,
        // but ensure we do NOT supply resourceType/resourceId/resourceParent so adapter parses them
        $required = $this->getRequiredAttributes();
        unset($required['resourceType'], $required['resourceId'], $required['resourceParent']);
        $dataWithAttributes = array_merge($data, $required);

        $log = $this->audit->log($actorId, 'create', $resource, $userAgent, $ip, $dataWithAttributes);

        $this->assertInstanceOf(\Utopia\Audit\Log::class, $log);

        $this->assertEquals('table', $log->getAttribute('resourceType'));
        $this->assertEquals('697848498066e3d2ef64', $log->getAttribute('resourceId'));
        $this->assertEquals('database/6978484940ff05762e1a', $log->getAttribute('resourceParent'));
    }

    /**
     * Directly test the protected parseResource method via reflection.
     */
    public function testParseResourceMethod(): void
    {
        $adapter = new ClickHouse(
            host: 'clickhouse',
            username: 'default',
            password: 'clickhouse',
        );

        $method = new \ReflectionMethod($adapter, 'parseResource');

        $resource = 'database/6978484940ff05762e1a/table/697848498066e3d2ef64';
        $parsed = $method->invoke($adapter, $resource);

        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('resourceId', $parsed);
        $this->assertArrayHasKey('resourceType', $parsed);
        $this->assertArrayHasKey('resourceParent', $parsed);

        $this->assertEquals('697848498066e3d2ef64', $parsed['resourceId']);
        $this->assertEquals('table', $parsed['resourceType']);
        $this->assertEquals('database/6978484940ff05762e1a', $parsed['resourceParent']);

        $sixPart = 'database/693586330029ae2f0d3f/collection/watch_history/document/6a06d9a7001c3cd05d20';
        /** @var array{resourceId: string, resourceType: string, resourceParent: string} $parsedSix */
        $parsedSix = $method->invoke($adapter, $sixPart);

        $this->assertEquals('6a06d9a7001c3cd05d20', $parsedSix['resourceId']);
        $this->assertEquals('document', $parsedSix['resourceType']);
        $this->assertEquals('database/693586330029ae2f0d3f/collection/watch_history', $parsedSix['resourceParent']);

        $twoPart = 'user/abc123';
        /** @var array{resourceId: string, resourceType: string, resourceParent: string} $parsedTwo */
        $parsedTwo = $method->invoke($adapter, $twoPart);

        $this->assertEquals('abc123', $parsedTwo['resourceId']);
        $this->assertEquals('user', $parsedTwo['resourceType']);
        $this->assertEquals('', $parsedTwo['resourceParent']);

        $oddPart = 'foo/bar/baz';
        /** @var array{resourceId: string, resourceType: string, resourceParent: string} $parsedOdd */
        $parsedOdd = $method->invoke($adapter, $oddPart);

        $this->assertEquals('foo/bar/baz', $parsedOdd['resourceId']);
        $this->assertEquals('', $parsedOdd['resourceType']);
        $this->assertEquals('', $parsedOdd['resourceParent']);
    }

    public function testCursorAfterPaginatesLogs(): void
    {
        $page1 = $this->audit->find([
            Query::orderAsc('id'),
            Query::limit(2),
        ]);

        $this->assertCount(2, $page1);

        $page2 = $this->audit->find([
            Query::orderAsc('id'),
            Query::limit(2),
            Query::cursorAfter($page1[\count($page1) - 1]),
        ]);

        $this->assertGreaterThanOrEqual(1, \count($page2));
        foreach ($page2 as $log) {
            $this->assertNotEquals($page1[0]->getId(), $log->getId());
            $this->assertNotEquals($page1[1]->getId(), $log->getId());
        }
    }

    public function testCursorBeforeReversesPagination(): void
    {
        $all = $this->audit->find([
            Query::orderAsc('id'),
            Query::limit(50),
        ]);

        $this->assertGreaterThanOrEqual(3, \count($all));

        $before = $this->audit->find([
            Query::orderAsc('id'),
            Query::limit(2),
            Query::cursorBefore($all[\count($all) - 1]),
        ]);

        $this->assertCount(2, $before);
        $this->assertEquals($all[\count($all) - 3]->getId(), $before[0]->getId());
        $this->assertEquals($all[\count($all) - 2]->getId(), $before[1]->getId());
    }

    public function testCursorAcceptsAssociativeArray(): void
    {
        $all = $this->audit->find([
            Query::orderAsc('id'),
            Query::limit(50),
        ]);

        $this->assertGreaterThanOrEqual(2, \count($all));

        $page = $this->audit->find([
            Query::orderAsc('id'),
            Query::limit(50),
            Query::cursorAfter(['id' => $all[0]->getId()]),
        ]);

        $this->assertCount(\count($all) - 1, $page);
        $this->assertEquals($all[1]->getId(), $page[0]->getId());
    }

    public function testCountWithMaxBound(): void
    {
        $unbounded = $this->audit->count();
        $this->assertGreaterThanOrEqual(4, $unbounded);

        $bounded = $this->audit->count([], max: 2);
        $this->assertSame(2, $bounded);

        $boundedAboveTotal = $this->audit->count([], max: 10_000);
        $this->assertSame($unbounded, $boundedAboveTotal);
    }

    public function testCountByUserWithMaxBound(): void
    {
        $unbounded = $this->audit->countLogsByUser('userId');
        $this->assertSame(3, $unbounded);

        $bounded = $this->audit->countLogsByUser('userId', max: 1);
        $this->assertSame(1, $bounded);
    }

    public function testNotEqualQuery(): void
    {
        // Fixture: 3x event=update/delete for actor, plus 1x event=insert for null actor
        $logs = $this->audit->find([
            Query::notEqual('event', 'update'),
        ]);
        // 1 delete + 1 insert = 2
        $this->assertCount(2, $logs);
        foreach ($logs as $log) {
            $this->assertNotEquals('update', $log->getEvent());
        }
    }

    public function testContainsSubstringQuery(): void
    {
        // Contains is a substring match (like utopia-php/database), not an
        // exact IN match — 'dat' matches only the 'update' logs
        $logs = $this->audit->find([
            Query::containsString('event', ['dat']),
        ]);
        $this->assertCount(2, $logs);
        foreach ($logs as $log) {
            $this->assertEquals('update', $log->getEvent());
        }

        // Multiple needles OR together: 'dat' (update) + 'ins' (insert)
        $logs = $this->audit->find([
            Query::containsString('event', ['dat', 'ins']),
        ]);
        $this->assertCount(3, $logs);
    }

    public function testContainsEscapesLikeWildcards(): void
    {
        // '%' and '_' in needles are literals, not LIKE wildcards —
        // no fixture event contains a literal '%'
        $logs = $this->audit->find([
            Query::containsString('event', ['%']),
        ]);
        $this->assertCount(0, $logs);

        $logs = $this->audit->find([
            Query::containsString('event', ['u_date']),
        ]);
        $this->assertCount(0, $logs);
    }

    public function testNotContainsQuery(): void
    {
        $logs = $this->audit->find([
            Query::notContains('event', ['update', 'delete']),
        ]);
        // Only the insert log
        $this->assertCount(1, $logs);
        $this->assertEquals('insert', $logs[0]->getEvent());

        // Negated substring: excludes anything containing 'dat' (update),
        // keeps delete + insert
        $logs = $this->audit->find([
            Query::notContains('event', ['dat']),
        ]);
        $this->assertCount(2, $logs);
        foreach ($logs as $log) {
            $this->assertStringNotContainsString('dat', $log->getEvent());
        }
    }

    public function testLesserEqualAndGreaterEqualQueries(): void
    {
        $now = new \DateTime()->modify('+1 minute');
        $past = new \DateTime()->modify('-1 hour');

        $allLe = $this->audit->find([
            Query::lessThanEqual('time', \Utopia\Database\DateTime::format($now)),
        ]);
        $this->assertGreaterThanOrEqual(4, \count($allLe));

        $noneLe = $this->audit->find([
            Query::lessThanEqual('time', \Utopia\Database\DateTime::format($past)),
        ]);
        $this->assertCount(0, $noneLe);

        $allGe = $this->audit->find([
            Query::greaterThanEqual('time', \Utopia\Database\DateTime::format($past)),
        ]);
        $this->assertGreaterThanOrEqual(4, \count($allGe));
    }

    public function testNotBetweenQuery(): void
    {
        $past = new \DateTime()->modify('-2 hour');
        $oldPast = new \DateTime()->modify('-3 hour');

        $logs = $this->audit->find([
            Query::notBetween(
                'time',
                \Utopia\Database\DateTime::format($oldPast),
                \Utopia\Database\DateTime::format($past),
            ),
        ]);
        // All 4 fixture logs are outside the past window
        $this->assertGreaterThanOrEqual(4, \count($logs));
    }

    public function testIsNullAndIsNotNullQueries(): void
    {
        $nullActor = $this->audit->find([
            Query::isNull('actorId'),
        ]);
        // Only the insert log has null actorId
        $this->assertCount(1, $nullActor);
        $this->assertEquals('insert', $nullActor[0]->getEvent());

        $notNullActor = $this->audit->find([
            Query::isNotNull('actorId'),
        ]);
        $this->assertCount(3, $notNullActor);
    }

    public function testStartsWithAndEndsWithQueries(): void
    {
        $resourcePrefix = $this->audit->find([
            Query::startsWith('resource', 'database/'),
        ]);
        // 3 logs are on database/document/*
        $this->assertCount(3, $resourcePrefix);
        foreach ($resourcePrefix as $log) {
            $this->assertStringStartsWith('database/', $log->getResource());
        }

        $endsWithNull = $this->audit->find([
            Query::endsWith('resource', '/null'),
        ]);
        // 'user/null' is the only match
        $this->assertCount(1, $endsWithNull);
        $this->assertEquals('user/null', $endsWithNull[0]->getResource());
    }

    public function testContainsRejectsEmptyValues(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Contains queries require at least one value.');

        $this->audit->find([
            Query::containsString('event', []),
        ]);
    }

    public function testNotContainsRejectsEmptyValues(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('NotContains queries require at least one value.');

        $this->audit->find([
            Query::notContains('event', []),
        ]);
    }

    public function testEqualRejectsEmptyValues(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Equal queries require at least one value.');

        $this->audit->find([
            new Query(Query::TYPE_EQUAL, 'event', []),
        ]);
    }

    public function testSelectProjectsRequestedColumns(): void
    {
        $logs = $this->audit->find([
            Query::select(['event', 'resource']),
            Query::equal('actorId', 'userId'),
            Query::limit(1),
        ]);

        $this->assertGreaterThanOrEqual(1, \count($logs));

        $row = $logs[0]->getArrayCopy();
        // `id` is always projected so the Log model still has its identifier
        $this->assertArrayHasKey('$id', $row);
        // Requested columns present
        $this->assertArrayHasKey('event', $row);
        $this->assertArrayHasKey('resource', $row);
        // Unrequested columns are absent
        $this->assertArrayNotHasKey('userAgent', $row);
        $this->assertArrayNotHasKey('ip', $row);
        $this->assertArrayNotHasKey('data', $row);
    }

    public function testSelectAutoIncludesTenantWhenShared(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'localhost';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 18123);

        $adapter = new ClickHouse(
            host: $host,
            username: 'default',
            password: 'clickhouse',
            port: $port,
        );
        $adapter->setNamespace('select_tenant_test');
        $adapter->setSharedTables(true);
        $adapter->setTenant(7);
        $adapter->setup();

        $audit = new Audit($adapter);
        $audit->log('u1', 'create', 'doc/1', 'agent', '127.0.0.1', $this->getRequiredAttributes());

        $logs = $audit->find([
            Query::select(['event']),
            Query::limit(1),
        ]);

        $this->assertCount(1, $logs);
        $row = $logs[0]->getArrayCopy();
        $this->assertArrayHasKey('$id', $row);
        $this->assertArrayHasKey('event', $row);
        // tenant is always projected when sharedTables is on, even if the
        // caller didn't list it
        $this->assertArrayHasKey('tenant', $row);
    }

    public function testSelectRejectsUnknownColumn(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid attribute name: bogus_column');

        $this->audit->find([
            Query::select(['bogus_column']),
        ]);
    }

    public function testSelectRejectsEmptyValues(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Select queries require at least one value.');

        $this->audit->find([
            Query::select([]),
        ]);
    }

    public function testNotStartsWithFilter(): void
    {
        $logs = $this->audit->find([
            Query::notStartsWith('resource', 'database/'),
        ]);
        // From fixture: only 'user/null' doesn't start with 'database/'
        $this->assertCount(1, $logs);
        $this->assertEquals('user/null', $logs[0]->getResource());
    }

    public function testNotEndsWithFilter(): void
    {
        $logs = $this->audit->find([
            Query::notEndsWith('resource', '/null'),
        ]);
        // From fixture: 3 logs are on database/document/{1,2,2}
        $this->assertCount(3, $logs);
        foreach ($logs as $log) {
            $this->assertStringStartsNotWith('user/', $log->getResource());
        }
    }

    public function testRegexFilter(): void
    {
        $logs = $this->audit->find([
            Query::regex('resource', '^database/document/\\d+$'),
        ]);
        // From fixture: 3 database/document/{1,2,2} rows match
        $this->assertCount(3, $logs);
    }

    public function testOrderRandomReturnsRows(): void
    {
        $logs = $this->audit->find([
            Query::orderRandom(),
            Query::limit(2),
        ]);
        // Hard to assert randomness; just confirm the query executes and limits.
        $this->assertCount(2, $logs);
    }

    public function testOrderRandomRejectedWithCursor(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cursor pagination cannot be combined with orderRandom');

        $this->audit->find([
            Query::orderRandom(),
            Query::cursorAfter(['id' => 'whatever']),
        ]);
    }

    public function testOrderRandomRejectedWithColumnOrder(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('orderRandom cannot be combined with orderAsc/orderDesc');

        $this->audit->find([
            Query::orderRandom(),
            Query::orderDesc('time'),
        ]);
    }

    public function testSharedTableSortKeyLeadsWithTenant(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'localhost';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 18123);
        $secure = filter_var(getenv('CLICKHOUSE_SECURE') ?: false, FILTER_VALIDATE_BOOLEAN);
        $database = getenv('CLICKHOUSE_DATABASE') ?: 'default';

        $namespace = 'projtest_' . uniqid();

        $adapter = new ClickHouse(
            host: $host,
            username: $username,
            password: $password,
            port: $port,
            secure: $secure,
        );
        $adapter->setDatabase($database);
        $adapter->setNamespace($namespace);
        $adapter->setSharedTables(true);
        $adapter->setTenant(1);

        $table = $namespace . '_audits';

        $http = function (string $sql, array $params = []) use ($host, $port, $username, $password, $secure, $database): string {
            $scheme = $secure ? 'https' : 'http';
            $url = "{$scheme}://{$host}:{$port}/?database=" . rawurlencode($database)
                . '&user=' . rawurlencode($username)
                . '&password=' . rawurlencode($password);
            /** @var array<string, string> $params */
            foreach ($params as $key => $value) {
                $url .= '&param_' . rawurlencode($key) . '=' . rawurlencode($value);
            }
            $ctx = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => "Content-Type: text/plain\r\n",
                'content' => $sql,
                'timeout' => 15,
                'ignore_errors' => true,
            ]]);
            $out = @file_get_contents($url, false, $ctx);

            return $out === false ? '' : trim($out);
        };

        try {
            new Audit($adapter)->setup();

            $sortingKey = $http(
                'SELECT sorting_key FROM system.tables WHERE database = {db:String} AND name = {tbl:String}',
                ['db' => $database, 'tbl' => $table],
            );

            $this->assertTrue(
                str_starts_with(trim($sortingKey), 'tenant'),
                "Expected sorting key to lead with 'tenant', got: {$sortingKey}",
            );
        } finally {
            $escDb = '`' . str_replace('`', '``', $database) . '`';
            $escTbl = '`' . str_replace('`', '``', $table) . '`';
            $http("DROP TABLE IF EXISTS {$escDb}.{$escTbl}");
        }
    }
}
