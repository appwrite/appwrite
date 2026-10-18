<?php

namespace Utopia\Abuse\Tests\E2E\Appwrite;

use Appwrite\AppwriteException;
use Appwrite\Client;
use Appwrite\Models\ColumnIndex;
use Appwrite\Services\TablesDB as TablesDBService;
use LogicException;
use Override;
use Throwable;
use Utopia\Abuse\Adapter\TimeLimit;
use Utopia\Abuse\Adapter\TimeLimit\Appwrite\TablesDB;
use Utopia\Abuse\Tests\E2E\Base;

class TablesDBTest extends Base
{
    protected static ?Client $client = null;
    protected static ?string $databaseId = null;

    /** @var array<string, TablesDBService> */
    private static array $owned = [];

    #[Override]
    public static function setUpBeforeClass(): void
    {
        if (isset(self::$client)) {
            return;
        }

        if ((\getenv('APPWRITE_ENDPOINT') ?: '') === '') {
            self::markTestSkipped('Set APPWRITE_ENDPOINT, APPWRITE_PROJECT_ID and APPWRITE_API_KEY to a disposable Appwrite project.');
        }

        try {
            self::initialiseDatabase();
        } catch (Throwable $failure) {
            try {
                self::clean(setup: $failure);
            } finally {
                self::$client = null;
                self::$databaseId = null;
            }
            throw $failure;
        }
    }

    private static function initialiseDatabase(): void
    {
        self::$client = new Client()
            ->setEndpoint(\getenv('APPWRITE_ENDPOINT') ?: '')
            ->setProject(\getenv('APPWRITE_PROJECT_ID') ?: '')
            ->setKey(\getenv('APPWRITE_API_KEY') ?: '');

        $databaseId = 'abuse-' . \bin2hex(\random_bytes(12));
        $service = new TablesDBService(self::client());
        $service->create($databaseId, TablesDB::DATABASE_NAME);
        self::$owned[$databaseId] = $service;
        self::$databaseId = $databaseId;

        $adapter = new TablesDB('', 1, 1, self::client(), self::database());
        $adapter->setup();
    }

    #[Override]
    public function getAdapter(string $key, int $limit, int $seconds): TimeLimit
    {
        return new TablesDB($key, $limit, $seconds, self::client(), self::database());
    }

    private static function client(): Client
    {
        return self::$client ?? throw new LogicException('Fixture client is not initialized');
    }

    private static function database(): string
    {
        return self::$databaseId ?? throw new LogicException('Fixture database is not initialized');
    }

    /**
     * The schema is sent inline with the table, so assert it lands exactly as
     * the dedicated per-column endpoints would have created it.
     */
    public function testSetupCreatesSchema(): void
    {
        $tablesDB = new TablesDBService(self::client());

        $columns = $this->columnsByKey($tablesDB->listColumns(self::database(), TablesDB::TABLE_ID)->columns);

        $this->assertCount(3, $columns);

        $this->assertSame('string', $columns['key']['type']);
        $this->assertSame(255, $columns['key']['size']);
        $this->assertTrue($columns['key']['required']);

        $this->assertSame('datetime', $columns['time']['type']);
        $this->assertTrue($columns['time']['required']);

        $this->assertSame('integer', $columns['count']['type']);
        $this->assertTrue($columns['count']['required']);
        $this->assertSame(0, $columns['count']['min']);
        $this->assertSame(PHP_INT_MAX, $columns['count']['max']);

        $indexes = $this->indexesByKey($tablesDB->listIndexes(self::database(), TablesDB::TABLE_ID)->indexes);

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
        $databaseId = 'abuse-' . \bin2hex(\random_bytes(12));
        $tablesDB = new TablesDBService(self::client());

        $tablesDB->create($databaseId, TablesDB::DATABASE_NAME);
        self::$owned[$databaseId] = $tablesDB;

        $failure = null;
        try {
            $tablesDB->createTable($databaseId, TablesDB::TABLE_ID, TablesDB::TABLE_NAME);

            $adapter = new TablesDB('repair-{{ip}}', 2, 60, self::client(), $databaseId);
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

            $adapter = $adapter->withParams(['{{ip}}' => '0.0.0.20']);
            $this->assertFalse($adapter->check()->limited);
            $this->assertFalse($adapter->check()->limited);
            $this->assertTrue($adapter->check()->limited);
        } catch (Throwable $error) {
            $failure = $error;
            throw $error;
        } finally {
            self::clean($databaseId, $failure);
        }
    }

    /**
     * Setup runs on every boot, so it has to be a no-op once the table is there.
     */
    public function testSetupIsIdempotent(): void
    {
        $adapter = new TablesDB('', 1, 1, self::client(), self::database());
        $adapter->setup();

        $tablesDB = new TablesDBService(self::client());

        $this->assertCount(3, $tablesDB->listColumns(self::database(), TablesDB::TABLE_ID)->columns);
        $this->assertCount(2, $tablesDB->listIndexes(self::database(), TablesDB::TABLE_ID)->indexes);
    }

    /**
     * A listed column arrives as the raw payload: the SDK has no single model
     * to hydrate the union of column types into.
     *
     * @param  array<mixed>  $columns
     * @return array<string, array<mixed>>
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

    #[Override]
    public static function tearDownAfterClass(): void
    {
        try {
            self::clean();
        } finally {
            self::$client = null;
            self::$databaseId = null;
        }
    }

    private static function clean(?string $databaseId = null, ?Throwable $setup = null): void
    {
        $failure = null;
        foreach (self::$owned as $id => $service) {
            if ($databaseId !== null && $id !== $databaseId) {
                continue;
            }
            try {
                $service->delete($id);
            } catch (AppwriteException $error) {
                if ($error->getCode() !== 404) {
                    $failure ??= $error;
                    continue;
                }
            } catch (Throwable $error) {
                $failure ??= $error;
                continue;
            }
            unset(self::$owned[$id]);
        }
        if ($failure !== null) {
            throw $setup === null ? $failure : new Cleanup($setup, $failure);
        }
    }
}
