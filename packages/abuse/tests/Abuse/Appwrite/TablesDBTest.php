<?php

namespace Utopia\Tests;

use Appwrite\Client;
use Appwrite\Models\ColumnIndex;
use Appwrite\Services\TablesDB as TablesDBService;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Abuse\Adapters\TimeLimit\Appwrite\TablesDB;

class AppwriteTablesDBTest extends Base
{
    protected static Client $client;
    protected static string $databaseId;

    public static function setUpBeforeClass(): void
    {
        if (isset(self::$client)) {
            return;
        }

        self::initialiseDatabase();
    }

    private static function initialiseDatabase(): void
    {
        self::$databaseId = 'abuse-cicd-' . \uniqid();
        self::$client = (new Client())
            ->setEndpoint(\getenv('APPWRITE_ENDPOINT') ?: '')
            ->setProject(\getenv('APPWRITE_PROJECT_ID') ?: '')
            ->setKey(\getenv('APPWRITE_API_KEY') ?: '');

        $adapter = new TablesDB('', 1, 1, self::$client, self::$databaseId);
        $adapter->setup();
    }

    public function getAdapter(string $key, int $limit, int $seconds): TimeLimit
    {
        return new TablesDB($key, $limit, $seconds, self::$client, self::$databaseId);
    }

    /**
     * The schema is sent inline with the table, so assert it lands exactly as
     * the dedicated per-column endpoints would have created it.
     */
    public function testSetupCreatesSchema(): void
    {
        $tablesDB = new TablesDBService(self::$client);

        $columns = $this->columnsByKey($tablesDB->listColumns(self::$databaseId, TablesDB::TABLE_ID)->columns);

        $this->assertCount(3, $columns);

        $this->assertSame('string', $columns['key']['type']);
        $this->assertSame(255, $columns['key']['size']);
        $this->assertTrue($columns['key']['required']);

        $this->assertSame('datetime', $columns['time']['type']);
        $this->assertTrue($columns['time']['required']);

        $this->assertSame('integer', $columns['count']['type']);
        $this->assertTrue($columns['count']['required']);
        $this->assertEquals(0, $columns['count']['min']);
        $this->assertEquals(PHP_INT_MAX, $columns['count']['max']);

        $indexes = $this->indexesByKey($tablesDB->listIndexes(self::$databaseId, TablesDB::TABLE_ID)->indexes);

        $this->assertCount(2, $indexes);

        $this->assertSame('unique', $indexes['unique1']->type);
        $this->assertSame(['key', 'time'], $indexes['unique1']->columns);

        $this->assertSame('key', $indexes['index2']->type);
        $this->assertSame(['time'], $indexes['index2']->columns);
    }

    /**
     * A table left behind by a setup that did not run to completion is missing
     * its columns and indexes, and they can no longer be sent inline. Setup has
     * to fill them in one by one instead.
     */
    public function testSetupRepairsPartiallyCreatedTable(): void
    {
        $databaseId = 'abuse-cicd-repair-' . \uniqid();
        $tablesDB = new TablesDBService(self::$client);

        $tablesDB->create($databaseId, TablesDB::DATABASE_NAME);

        try {
            $tablesDB->createTable($databaseId, TablesDB::TABLE_ID, TablesDB::TABLE_NAME);

            $adapter = new TablesDB('repair-{{ip}}', 2, 60, self::$client, $databaseId);
            $adapter->setup();

            $columns = $this->columnsByKey($tablesDB->listColumns($databaseId, TablesDB::TABLE_ID)->columns);
            $indexes = $this->indexesByKey($tablesDB->listIndexes($databaseId, TablesDB::TABLE_ID)->indexes);

            $this->assertCount(3, $columns);
            $this->assertArrayHasKey('key', $columns);
            $this->assertArrayHasKey('time', $columns);
            $this->assertArrayHasKey('count', $columns);

            $this->assertCount(2, $indexes);
            $this->assertArrayHasKey('unique1', $indexes);
            $this->assertArrayHasKey('index2', $indexes);

            $adapter->setParam('{{ip}}', '0.0.0.20');
            $abuse = new Abuse($adapter);
            $this->assertSame($abuse->check(), false);
            $this->assertSame($abuse->check(), false);
            $this->assertSame($abuse->check(), true);
        } finally {
            $tablesDB->delete($databaseId);
        }
    }

    /**
     * Setup runs on every boot, so it has to be a no-op once the table is there.
     */
    public function testSetupIsIdempotent(): void
    {
        $adapter = new TablesDB('', 1, 1, self::$client, self::$databaseId);
        $adapter->setup();

        $tablesDB = new TablesDBService(self::$client);

        $this->assertCount(3, $tablesDB->listColumns(self::$databaseId, TablesDB::TABLE_ID)->columns);
        $this->assertCount(2, $tablesDB->listIndexes(self::$databaseId, TablesDB::TABLE_ID)->indexes);
    }

    /**
     * A listed column arrives as the raw payload: the SDK has no single model
     * to hydrate the union of column types into.
     *
     * @param  array<mixed>  $columns
     * @return array<string, array<string, mixed>>
     */
    private function columnsByKey(array $columns): array
    {
        $byKey = [];

        foreach ($columns as $column) {
            $this->assertIsArray($column);
            $this->assertSame('available', $column['status']);

            $key = $column['key'];
            $this->assertIsString($key);

            $byKey[$key] = $column;
        }

        return $byKey;
    }

    /**
     * A listed index, unlike a column, arrives hydrated.
     *
     * @param  array<mixed>  $indexes
     * @return array<string, ColumnIndex>
     */
    private function indexesByKey(array $indexes): array
    {
        $byKey = [];

        foreach ($indexes as $index) {
            $this->assertInstanceOf(ColumnIndex::class, $index);
            $this->assertSame('available', $index->status);

            $byKey[$index->key] = $index;
        }

        return $byKey;
    }

    public static function tearDownAfterClass(): void
    {
    }
}
