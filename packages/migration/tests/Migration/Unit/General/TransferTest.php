<?php

namespace Utopia\Tests\Unit\General;

use Override;
use PHPUnit\Framework\TestCase;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Database;
use Utopia\Migration\Resources\Database\Document;
use Utopia\Migration\Resources\Database\Row;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Migration\Transfer;
use Utopia\Tests\Unit\Adapters\MockDestination;
use Utopia\Tests\Unit\Adapters\MockSource;

class TransferTest extends TestCase
{
    protected Transfer $transfer;
    protected MockSource $source;
    protected MockDestination $destination;

    public function setup(): void
    {
        $this->source = new MockSource();
        $this->destination = new MockDestination();

        $this->transfer = new Transfer(
            $this->source,
            $this->destination
        );
    }

    /**
     * @throws \Exception
     */
    public function testRootResourceId(): void
    {
        /**
         * TEST FOR FAILURE
         * Make sure we can't create a transfer with multiple root resources when supplying a rootResourceId
         */
        try {
            $this->transfer->run([Resource::TYPE_USER, Resource::TYPE_DATABASE], function () {
            }, 'rootResourceId');
            $this->fail('Multiple root resources should not be allowed');
        } catch (\Exception $e) {
            $this->assertSame('Resource type must be set when resource ID is set.', $e->getMessage());
        }

        $this->source->pushMockResource(new Database('test', 'test'));
        $this->source->pushMockResource(new Database('test2', 'test'));

        /**
         * TEST FOR SUCCESS
         */
        $this->transfer->run(
            [Resource::TYPE_DATABASE],
            function () {
            },
            'test',
            Resource::TYPE_DATABASE
        );
        $this->assertCount(1, $this->destination->getResourceTypeData(Transfer::GROUP_DATABASES, Resource::TYPE_DATABASE));

        $database = $this->destination->getResourceById(Transfer::GROUP_DATABASES, Resource::TYPE_DATABASE, 'test');
        /** @var Database $database */
        $this->assertNotNull($database);
        $this->assertSame('test', $database->getDatabaseName());
        $this->assertSame('test', $database->getId());
    }

    public function testLegacyCompoundRootResourceIdScopesDatabaseEntity(): void
    {
        $database = new Database('database', 'Database');
        $first = new Table($database, 'First table', 'first');
        $second = new Table($database, 'Second table', 'second');

        $this->source->pushMockResource($database);
        $this->source->pushMockResource($first);
        $this->source->pushMockResource($second);

        $this->transfer->run(
            [Resource::TYPE_DATABASE, Resource::TYPE_TABLE],
            static function (): void {
            },
            $database->getId() . ':' . $second->getId(),
            Resource::TYPE_DATABASE,
        );

        $tables = $this->destination->getResourceTypeData(Transfer::GROUP_DATABASES, Resource::TYPE_TABLE);

        $this->assertSame(['second'], $tables);
        $this->assertNull($this->destination->getResourceById(Transfer::GROUP_DATABASES, Resource::TYPE_TABLE, 'first'));
        $this->assertSame($second, $this->destination->getResourceById(Transfer::GROUP_DATABASES, Resource::TYPE_TABLE, 'second'));
    }

    public function testLegacySourceSubclassChildSelectorPropertyRemainsCompatible(): void
    {
        $source = new class () extends MockSource {
            protected $rootResourceChildId = ['external'];
        };
        $destination = new MockDestination();
        $transfer = new Transfer($source, $destination);
        $database = new Database('database', 'Database');
        $first = new Table($database, 'First table', 'first');
        $second = new Table($database, 'Second table', 'second');

        $source->pushMockResource($database);
        $source->pushMockResource($first);
        $source->pushMockResource($second);

        $transfer->run(
            [Resource::TYPE_DATABASE, Resource::TYPE_TABLE],
            static function (): void {
            },
            'database:second',
            Resource::TYPE_DATABASE,
        );

        $this->assertSame(
            ['second'],
            $destination->getResourceTypeData(Transfer::GROUP_DATABASES, Resource::TYPE_TABLE),
        );
    }

    public function testExplicitSelectorKeepsColonContainingIdsOpaque(): void
    {
        $database = new Database('database:with:colon', 'Database');
        $first = new Table($database, 'First table', 'first');
        $second = new Table($database, 'Second table', 'table:with:colon');

        $this->source->pushMockResource($database);
        $this->source->pushMockResource($first);
        $this->source->pushMockResource($second);

        $this->transfer->runWithResourceSelector(
            [Resource::TYPE_DATABASE, Resource::TYPE_TABLE],
            static function (): void {
            },
            resourceId: $second->getId(),
            resourceInternalId: '2',
            resourceType: Resource::TYPE_TABLE,
            parentResourceId: $database->getId(),
            parentResourceInternalId: '1',
            parentResourceType: Resource::TYPE_DATABASE,
        );

        $this->assertSame(
            ['table:with:colon'],
            $this->destination->getResourceTypeData(Transfer::GROUP_DATABASES, Resource::TYPE_TABLE),
        );
        $this->assertSame(
            $database,
            $this->destination->getResourceById(Transfer::GROUP_DATABASES, Resource::TYPE_DATABASE, 'database:with:colon'),
        );
        $this->assertSame(
            $second,
            $this->destination->getResourceById(Transfer::GROUP_DATABASES, Resource::TYPE_TABLE, 'table:with:colon'),
        );
    }

    public function testExplicitSelectorDispatchesLegacyOverridesExactlyOnce(): void
    {
        $source = new class () extends MockSource {
            public int $runCount = 0;

            /** @var array{resourceId: string, resourceInternalId: string, resourceType: string, parentResourceId: string, parentResourceInternalId: string, parentResourceType: string}|null */
            public ?array $selector = null;

            #[Override]
            public function run(array $resources, callable $callback, string $rootResourceId = '', string $rootResourceType = ''): void
            {
                $this->runCount++;
                parent::run($resources, $callback, $rootResourceId, $rootResourceType);
            }

            #[Override]
            public function runWithResourceSelector(
                array $resources,
                callable $callback,
                string $resourceId,
                string $resourceInternalId,
                string $resourceType,
                string $parentResourceId,
                string $parentResourceInternalId,
                string $parentResourceType,
            ): void {
                $this->selector = [
                    'resourceId' => $resourceId,
                    'resourceInternalId' => $resourceInternalId,
                    'resourceType' => $resourceType,
                    'parentResourceId' => $parentResourceId,
                    'parentResourceInternalId' => $parentResourceInternalId,
                    'parentResourceType' => $parentResourceType,
                ];
                parent::runWithResourceSelector(
                    $resources,
                    $callback,
                    $resourceId,
                    $resourceInternalId,
                    $resourceType,
                    $parentResourceId,
                    $parentResourceInternalId,
                    $parentResourceType,
                );
            }
        };
        $destination = new class () extends MockDestination {
            public int $runCount = 0;

            #[Override]
            public function run(array $resources, callable $callback, string $rootResourceId = '', string $rootResourceType = ''): void
            {
                $this->runCount++;
                parent::run($resources, $callback, $rootResourceId, $rootResourceType);
            }
        };
        $transfer = new class ($source, $destination) extends Transfer {
            public int $runCount = 0;

            #[Override]
            public function run(
                array $resources,
                callable $callback,
                ?string $rootResourceId = null,
                ?string $rootResourceType = null,
            ): void {
                $this->runCount++;
                parent::run($resources, $callback, $rootResourceId, $rootResourceType);
            }
        };

        $database = new Database('database', 'Database');
        $table = new Table($database, 'Table', 'table');
        $source->pushMockResource($database);
        $source->pushMockResource($table);

        $transfer->runWithResourceSelector(
            [Resource::TYPE_DATABASE, Resource::TYPE_TABLE],
            static function (): void {
            },
            resourceId: $table->getId(),
            resourceInternalId: '2',
            resourceType: Resource::TYPE_TABLE,
            parentResourceId: $database->getId(),
            parentResourceInternalId: '1',
            parentResourceType: Resource::TYPE_DATABASE,
        );

        $this->assertSame(1, $transfer->runCount);
        $this->assertSame(1, $destination->runCount);
        $this->assertSame(1, $source->runCount);
        $this->assertSame([
            'resourceId' => 'table',
            'resourceInternalId' => '2',
            'resourceType' => Resource::TYPE_TABLE,
            'parentResourceId' => 'database',
            'parentResourceInternalId' => '1',
            'parentResourceType' => Resource::TYPE_DATABASE,
        ], $source->selector);
    }

    public function testExplicitSelectorStateIsRestoredAfterSuccess(): void
    {
        $selectedDatabase = new Database('selected', 'Selected');
        $selectedTable = new Table($selectedDatabase, 'Selected table', 'selected-table');
        $legacyDatabase = new Database('legacy', 'Legacy');
        $legacyTable = new Table($legacyDatabase, 'Legacy table', 'legacy-table');

        foreach ([$selectedDatabase, $selectedTable, $legacyDatabase, $legacyTable] as $resource) {
            $this->source->pushMockResource($resource);
        }

        $this->transfer->runWithResourceSelector(
            [Resource::TYPE_DATABASE, Resource::TYPE_TABLE],
            static function (): void {
            },
            resourceId: $selectedTable->getId(),
            resourceInternalId: '2',
            resourceType: Resource::TYPE_TABLE,
            parentResourceId: $selectedDatabase->getId(),
            parentResourceInternalId: '1',
            parentResourceType: Resource::TYPE_DATABASE,
        );
        $this->transfer->run(
            [Resource::TYPE_DATABASE, Resource::TYPE_TABLE],
            static function (): void {
            },
            'legacy:legacy-table',
            Resource::TYPE_DATABASE,
        );

        $this->assertSame(
            $legacyTable,
            $this->destination->getResourceById(Transfer::GROUP_DATABASES, Resource::TYPE_TABLE, 'legacy-table'),
        );
    }

    public function testExplicitSelectorStateIsRestoredAfterFailure(): void
    {
        $selectedDatabase = new Database('selected', 'Selected');
        $selectedTable = new Table($selectedDatabase, 'Selected table', 'selected-table');
        $legacyDatabase = new Database('legacy', 'Legacy');
        $legacyTable = new Table($legacyDatabase, 'Legacy table', 'legacy-table');

        foreach ([$selectedDatabase, $selectedTable, $legacyDatabase, $legacyTable] as $resource) {
            $this->source->pushMockResource($resource);
        }

        try {
            $this->transfer->runWithResourceSelector(
                [Resource::TYPE_DATABASE, Resource::TYPE_TABLE],
                static function (): void {
                    throw new \RuntimeException('stop');
                },
                resourceId: $selectedTable->getId(),
                resourceInternalId: '2',
                resourceType: Resource::TYPE_TABLE,
                parentResourceId: $selectedDatabase->getId(),
                parentResourceInternalId: '1',
                parentResourceType: Resource::TYPE_DATABASE,
            );
            $this->fail('The callback exception should escape the transfer.');
        } catch (\RuntimeException $error) {
            $this->assertSame('stop', $error->getMessage());
        }

        $this->transfer->run(
            [Resource::TYPE_DATABASE, Resource::TYPE_TABLE],
            static function (): void {
            },
            'legacy:legacy-table',
            Resource::TYPE_DATABASE,
        );

        $this->assertSame(
            $legacyTable,
            $this->destination->getResourceById(Transfer::GROUP_DATABASES, Resource::TYPE_TABLE, 'legacy-table'),
        );
    }

    /**
     * Row and document counts are aggregated into the cache by status. When such
     * a count exists for a resource type that was not part of the migration
     * request, getStatusCounters() must ignore it, exactly as it already does for
     * non-row resources via the isset() guard. Otherwise it reads an unseeded
     * 'pending' key (triggering an "Undefined array key" warning) and reports a
     * phantom, non-empty counter for a type the caller never asked to migrate.
     */
    public function testStatusCountersIgnoreUnrequestedRowCounts(): void
    {
        // No resource types were requested, so 'row'/'document' are unrequested.
        // A row count still leaks into the cache: the destination tallies row and
        // document counts by status as it imports them.
        $table = new Table(new Database('db', 'db'), 'table', 'table');
        $row = new Row('row-1', $table);
        $row->setStatus(Resource::STATUS_SUCCESS);

        $this->transfer->getCache()->add($row);

        $counters = $this->transfer->getStatusCounters();

        $this->assertArrayNotHasKey(Resource::TYPE_ROW, $counters);
        $this->assertSame([], $counters);
    }

    public function testStatusCountersMergeResourcesAndRowCountsForRequestedTypesOnly(): void
    {
        $database = new Database('database', 'Database');
        $table = new Table($database, 'Table', 'table');
        $this->source->pushMockResource($database);
        $this->source->pushMockResource($table);
        $this->source->pushMockResource(new Row('first', $table));
        $this->source->pushMockResource(new Row('second', $table));

        $this->transfer->run(
            [Resource::TYPE_DATABASE, Resource::TYPE_ROW],
            static function (): void {
            },
        );

        $failedRow = new Row('third', $table);
        $failedRow->setStatus(Resource::STATUS_ERROR);
        $unrequestedTable = new Table($database, 'Unrequested', 'unrequested');
        $unrequestedTable->setStatus(Resource::STATUS_SUCCESS);
        $unrequestedDocument = new Document('document', $table);
        $unrequestedDocument->setStatus(Resource::STATUS_SUCCESS);

        $cache = $this->transfer->getCache();
        $cache->add($failedRow);
        $cache->add($unrequestedTable);
        $cache->add($unrequestedDocument);

        $this->source->previousReport = [
            Resource::TYPE_DATABASE => 3,
            Resource::TYPE_ROW => 2,
            Resource::TYPE_USER => 4,
            'size' => 9,
            'version' => '1.0.0',
        ];

        $this->assertSame([
            Resource::TYPE_DATABASE => [
                Resource::STATUS_PENDING => 2,
                Resource::STATUS_SUCCESS => 1,
                Resource::STATUS_ERROR => 0,
                Resource::STATUS_SKIPPED => 0,
                Resource::STATUS_PROCESSING => 0,
                Resource::STATUS_WARNING => 0,
            ],
            Resource::TYPE_ROW => [
                Resource::STATUS_PENDING => 0,
                Resource::STATUS_SUCCESS => 2,
                Resource::STATUS_ERROR => 1,
                Resource::STATUS_SKIPPED => 0,
                Resource::STATUS_PROCESSING => 0,
                Resource::STATUS_WARNING => 0,
            ],
        ], $this->transfer->getStatusCounters());
    }

    public function testStatusCountersAreEmptyWhenRequestedTypesHaveNoActivity(): void
    {
        $this->transfer->run(
            [Resource::TYPE_DATABASE, Resource::TYPE_ROW],
            static function (): void {
            },
        );

        $this->assertSame([], $this->transfer->getStatusCounters());
    }

    public function testStatusCountersReportRowCountsUnderUnseededStatuses(): void
    {
        $database = new Database('database', 'Database');
        $table = new Table($database, 'Table', 'table');
        $this->source->pushMockResource($database);
        $this->source->pushMockResource($table);

        $this->transfer->run(
            [Resource::TYPE_DATABASE, Resource::TYPE_ROW],
            static function (): void {
            },
        );

        $cache = $this->transfer->getCache();

        foreach (['first', 'second'] as $rowId) {
            $row = new Row($rowId, $table);
            $row->setStatus(Resource::STATUS_DISREGARDED);
            $cache->add($row);
        }

        $counters = $this->transfer->getStatusCounters();

        $this->assertSame([
            Resource::STATUS_PENDING => 0,
            Resource::STATUS_SUCCESS => 0,
            Resource::STATUS_ERROR => 0,
            Resource::STATUS_SKIPPED => 0,
            Resource::STATUS_PROCESSING => 0,
            Resource::STATUS_WARNING => 0,
            Resource::STATUS_DISREGARDED => 2,
        ], $counters[Resource::TYPE_ROW]);
        $this->assertSame(1, $counters[Resource::TYPE_DATABASE][Resource::STATUS_SUCCESS]);
    }
}
