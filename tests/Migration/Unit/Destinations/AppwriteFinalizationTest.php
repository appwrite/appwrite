<?php

namespace Utopia\Tests\Unit\Destinations;

use Override;
use PHPUnit\Framework\TestCase;
use Throwable;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory as MemoryAdapter;
use Utopia\Database\Attribute as UtopiaAttribute;
use Utopia\Database\Collection;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document as UtopiaDocument;
use Utopia\Database\Query;
use Utopia\Migration\Destinations\Appwrite as AppwriteDestination;
use Utopia\Migration\Destinations\OnDuplicate;
use Utopia\Migration\Exception\Aborted;
use Utopia\Migration\Exception as MigrationException;
use Utopia\Migration\Exception\Finalization;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Columns\Text;
use Utopia\Migration\Resources\Database\Database as DatabaseResource;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Migration\Transfer;
use Utopia\Query\Schema\ColumnType;
use Utopia\Tests\Unit\Adapters\MockSource;

final class AppwriteFinalizationTest extends TestCase
{
    private const SOURCE_OLDER = '2000-01-01 00:00:00';

    private const SOURCE_NEWER = '2999-01-01 00:00:00';

    public function testSkippedFinalizationIsReportedWhenTheLifecycleEnds(): void
    {
        $database = $this->projectDatabase();
        $destination = $this->destination($database);

        $this->import($database, $destination, [
            $this->databaseResource('first'),
            $this->databaseResource('second'),
        ]);

        $this->assertSame([], $destination->getErrors(), 'A run awaiting success() must not fail the caller\'s error gate');

        $destination->cleanUp();

        $this->assertSame(
            [
                [Resource::TYPE_DATABASE, Transfer::GROUP_DATABASES, 'first'],
                [Resource::TYPE_DATABASE, Transfer::GROUP_DATABASES, 'second'],
            ],
            $this->subjects($destination->getErrors()),
            'Ending the lifecycle without success() must report every database it left unfinalized',
        );
        $this->assertStringContainsString('success()', $destination->getErrors()[0]->getMessage());
        $this->assertSame('provisioning', $this->databaseStatus($database, 'first'));
        $this->assertSame('provisioning', $this->databaseStatus($database, 'second'));
    }

    public function testFinalizedRunReportsNothingWhenTheLifecycleEnds(): void
    {
        $database = $this->projectDatabase();
        $destination = $this->destination($database);
        $this->import($database, $destination, [$this->databaseResource('first')]);

        $this->assertNull($this->finalize($database, $destination));
        $destination->cleanUp();

        $this->assertSame([], $destination->getErrors());
        $this->assertSame('ready', $this->databaseStatus($database, 'first'));
    }

    public function testAbandonedRunReportsNothingWhenTheLifecycleEnds(): void
    {
        $database = $this->projectDatabase();
        $destination = $this->destination($database);
        $this->import($database, $destination, [$this->databaseResource('first')]);

        $destination->error();
        $destination->cleanUp();

        $this->assertSame([], $destination->getErrors());
        $this->assertSame('provisioning', $this->databaseStatus($database, 'first'));
    }

    public function testRerunWithoutFinalizationReportsTheDiscardedRun(): void
    {
        $database = $this->projectDatabase();
        $destination = $this->destination($database);

        $this->import($database, $destination, [$this->databaseResource('first')]);
        $this->import($database, $destination, [$this->databaseResource('second')]);

        $this->assertSame(
            [[Resource::TYPE_DATABASE, Transfer::GROUP_DATABASES, 'first']],
            $this->subjects($destination->getErrors()),
            'A second run must not silently discard the first run\'s unfinalized databases',
        );

        $this->assertNull($this->finalize($database, $destination));

        $this->assertSame('provisioning', $this->databaseStatus($database, 'first'));
        $this->assertSame('ready', $this->databaseStatus($database, 'second'));
    }

    public function testSuccessDoesNotFinalizeAnAbortedRun(): void
    {
        $database = $this->projectDatabase();
        $destination = $this->destination($database);

        try {
            $this->import(
                $database,
                $destination,
                [$this->databaseResource('first')],
                static function (): void {
                    throw new \RuntimeException('transfer aborted');
                },
            );
            $this->fail('The aborted transfer must throw');
        } catch (\RuntimeException $error) {
            $this->assertSame('transfer aborted', $error->getMessage());
        }

        $this->assertNull($this->finalize($database, $destination));
        $destination->cleanUp();

        $this->assertSame('provisioning', $this->databaseStatus($database, 'first'), 'An interrupted run must never be marked ready');
        $this->assertSame([], $destination->getErrors());
    }

    public function testSuccessFinalizesNothingWhenTheSourceSwallowedTheAbort(): void
    {
        $database = $this->projectDatabase();
        $this->seedTablesWithLegacyColumns($database);

        $abort = new Aborted('Migration attempt was superseded');
        $destination = $this->destination($database, OnDuplicate::Overwrite);

        try {
            $this->importSwallowingAbort($database, $destination, $this->newerSchemaWithoutLegacyColumns(), $abort);
            $this->fail('The swallowed abort must still end the transfer');
        } catch (Aborted $caught) {
            $this->assertSame($abort, $caught);
        }

        $this->assertNull($this->finalize($database, $destination));

        $this->assertSame('provisioning', $this->databaseStatus($database, 'first'), 'An aborted run must never be marked ready');
        $this->assertSame('provisioning', $this->databaseStatus($database, 'second'), 'An aborted run must never be marked ready');
        $this->assertSame(['legacy', 'name'], $this->columnKeys($database, 'first', 'first-items'), 'An aborted run must not sweep its overwrite orphans');
        $this->assertSame(['legacy', 'name'], $this->columnKeys($database, 'second', 'second-items'), 'An aborted run must not sweep its overwrite orphans');

        $errors = $destination->getErrors();
        $destination->cleanUp();

        $this->assertSame($errors, $destination->getErrors(), 'An aborted run is not reported as a skipped finalization');
    }

    public function testFailedFinalizationThrowsAfterAttemptingEveryDatabase(): void
    {
        $database = $this->projectDatabase();
        $database->failReadyWrites = ['first', 'third'];
        $destination = $this->destination($database);
        $this->import($database, $destination, [
            $this->databaseResource('first'),
            $this->databaseResource('second'),
            $this->databaseResource('third'),
        ]);

        $failure = $this->finalize($database, $destination);

        $this->assertSame('provisioning', $this->databaseStatus($database, 'first'));
        $this->assertSame('ready', $this->databaseStatus($database, 'second'));
        $this->assertSame('provisioning', $this->databaseStatus($database, 'third'));
        $this->assertNotNull($failure, 'A failed finalization must throw, not only append errors');
        $this->assertInstanceOf(Finalization::class, $failure);
        $this->assertSame(
            [
                [Resource::TYPE_DATABASE, Transfer::GROUP_DATABASES, 'first'],
                [Resource::TYPE_DATABASE, Transfer::GROUP_DATABASES, 'third'],
            ],
            $this->subjects($failure->failures),
        );
        $this->assertSame('ready status unavailable', $failure->failures[0]->getMessage());
        $this->assertSame($failure->failures, $destination->getErrors(), 'Each failure is also recorded for the report');
        $this->assertSame($failure->failures[0], $failure->getPrevious());
        $this->assertSame(MigrationException::CODE_INTERNAL, $failure->getCode());
        $this->assertStringContainsString('first', $failure->getMessage());
        $this->assertStringContainsString('third', $failure->getMessage());

        $destination->cleanUp();

        $this->assertCount(2, $destination->getErrors(), 'An attempted finalization is not reported again as skipped');
    }

    public function testOneFailedReadyFlipStillSweepsEveryOverwrittenTable(): void
    {
        $database = $this->projectDatabase();
        $this->seedTablesWithLegacyColumns($database);
        $database->failReadyWrites = ['first'];

        $destination = $this->destination($database, OnDuplicate::Overwrite);
        $this->import($database, $destination, $this->newerSchemaWithoutLegacyColumns());

        $failure = $this->finalize($database, $destination);

        $this->assertSame(['name'], $this->columnKeys($database, 'first', 'first-items'), 'The sweep must run for the database whose flip failed');
        $this->assertSame(['name'], $this->columnKeys($database, 'second', 'second-items'), 'The sweep must run for every other database');
        $this->assertSame('provisioning', $this->databaseStatus($database, 'first'));
        $this->assertSame('ready', $this->databaseStatus($database, 'second'));
        $this->assertInstanceOf(Finalization::class, $failure);
        $this->assertSame(
            [[Resource::TYPE_DATABASE, Transfer::GROUP_DATABASES, 'first']],
            $this->subjects($failure->failures),
        );
    }

    public function testOrphanSweepFailureStillSweepsTheOtherTablesAndThrows(): void
    {
        $database = $this->projectDatabase();
        $this->seedTablesWithLegacyColumns($database);

        $destination = $this->destination($database, OnDuplicate::Overwrite);
        $this->import($database, $destination, $this->newerSchemaWithoutLegacyColumns());
        $database->failAttributeScanOf = $this->table($database, 'first', 'first-items');

        $failure = $this->finalize($database, $destination);

        $database->failAttributeScanOf = null;
        $this->assertSame(['legacy', 'name'], $this->columnKeys($database, 'first', 'first-items'));
        $this->assertSame(['name'], $this->columnKeys($database, 'second', 'second-items'), 'One table\'s failed sweep must not skip the others');
        $this->assertSame('ready', $this->databaseStatus($database, 'first'), 'Databases are flipped before any sweep can fail');
        $this->assertSame('ready', $this->databaseStatus($database, 'second'));
        $this->assertInstanceOf(Finalization::class, $failure, 'A failed sweep must surface as the finalization exception');
        $this->assertSame(
            [[Resource::TYPE_TABLE, Transfer::GROUP_DATABASES, 'first-items']],
            $this->subjects($failure->failures),
        );
        $this->assertSame('attribute scan unavailable', $failure->failures[0]->getMessage());
        $this->assertSame($failure->failures, $destination->getErrors());
    }

    /**
     * A source that records the export failure and carries on, the way the library sources did
     * before they rethrew aborts.
     *
     * @param list<Resource> $resources
     */
    private function importSwallowingAbort(
        FailingFinalizationDatabase $database,
        AppwriteDestination $destination,
        array $resources,
        Aborted $abort,
    ): void {
        $source = new class () extends MockSource {
            #[Override]
            public function supportsDatabaseStatus(): bool
            {
                return true;
            }

            #[Override]
            protected function exportGroupDatabases(int $batchSize, array $resources): void
            {
                foreach ($resources as $resource) {
                    try {
                        parent::exportGroupDatabases($batchSize, [$resource]);
                    } catch (Throwable $error) {
                        $this->addError(new MigrationException($resource, Transfer::GROUP_DATABASES, message: $error->getMessage(), previous: $error));
                    }
                }
            }
        };

        $types = [];
        foreach ($resources as $resource) {
            $source->pushMockResource($resource);
            $types[$resource->getName()] = true;
        }

        $transfer = new Transfer($source, $destination);
        $database->getAuthorization()->skip(
            static fn () => $transfer->run(\array_keys($types), static fn () => throw $abort),
        );
    }

    private function seedTablesWithLegacyColumns(FailingFinalizationDatabase $database): void
    {
        $resources = [];
        foreach (['first', 'second'] as $databaseId) {
            $resource = $this->databaseResource($databaseId);
            $table = new Table($resource, 'Items', $databaseId.'-items', updatedAt: self::SOURCE_OLDER);
            \array_push(
                $resources,
                $resource,
                $table,
                (new Text('name', $table, size: 64, updatedAt: self::SOURCE_OLDER))->setId($databaseId.'-name'),
                (new Text('legacy', $table, size: 64, updatedAt: self::SOURCE_OLDER))->setId($databaseId.'-legacy'),
            );
        }

        $destination = $this->destination($database);
        $this->import($database, $destination, $resources);

        $this->assertNull($this->finalize($database, $destination));
        $this->assertSame([], $destination->getErrors());
        $this->assertSame(['legacy', 'name'], $this->columnKeys($database, 'first', 'first-items'));
        $this->assertSame(['legacy', 'name'], $this->columnKeys($database, 'second', 'second-items'));
    }

    /**
     * Newer and renamed, because an overwrite only reclaims a database whose source is newer and differs.
     *
     * @return list<Resource>
     */
    private function newerSchemaWithoutLegacyColumns(): array
    {
        $resources = [];
        foreach (['first', 'second'] as $databaseId) {
            $resource = $this->databaseResource($databaseId, 'Renamed', self::SOURCE_NEWER);
            $table = new Table($resource, 'Items', $databaseId.'-items', updatedAt: self::SOURCE_OLDER);
            \array_push(
                $resources,
                $resource,
                $table,
                (new Text('name', $table, size: 64, updatedAt: self::SOURCE_OLDER))->setId($databaseId.'-name'),
            );
        }

        return $resources;
    }

    private function databaseResource(string $id, string $name = 'Database', string $updatedAt = ''): DatabaseResource
    {
        return new DatabaseResource(
            id: $id,
            name: $name,
            updatedAt: $updatedAt,
            type: 'tablesdb',
            database: 'source-dsn',
            databaseStatus: 'ready',
        );
    }

    private function destination(
        FailingFinalizationDatabase $database,
        OnDuplicate $onDuplicate = OnDuplicate::Fail,
    ): AppwriteDestination {
        return new AppwriteDestination(
            project: 'destination-project',
            endpoint: 'http://example.test/v1',
            key: 'test-key',
            dbForProject: $database,
            getDatabasesDB: static fn (UtopiaDocument $document): UtopiaDatabase => $database,
            collectionStructure: [
                'attributes' => [
                    $this->attributeArray('databaseInternalId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                    $this->attributeArray('databaseId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                    $this->attributeArray('name', ColumnType::String, size: 256),
                    $this->attributeArray('enabled', ColumnType::Boolean, default: true),
                    $this->attributeArray('documentSecurity', ColumnType::Boolean, default: false),
                    $this->attributeArray('search', ColumnType::String, size: 16384),
                ],
                'indexes' => [],
            ],
            dbForPlatform: $database,
            projectInternalId: '1',
            onDuplicate: $onDuplicate,
        );
    }

    /**
     * @param list<Resource> $resources
     */
    private function import(
        FailingFinalizationDatabase $database,
        AppwriteDestination $destination,
        array $resources,
        ?callable $callback = null,
    ): void {
        $source = new class () extends MockSource {
            #[Override]
            public function supportsDatabaseStatus(): bool
            {
                return true;
            }
        };
        $types = [];
        foreach ($resources as $resource) {
            $source->pushMockResource($resource);
            $types[$resource->getName()] = true;
        }

        $transfer = new Transfer($source, $destination);
        $database->getAuthorization()->skip(
            static fn () => $transfer->run(\array_keys($types), $callback ?? static function (): void {
            }),
        );
    }

    private function finalize(FailingFinalizationDatabase $database, AppwriteDestination $destination): ?\Throwable
    {
        try {
            $database->getAuthorization()->skip($destination->success(...));
        } catch (\Throwable $failure) {
            return $failure;
        }

        return null;
    }

    private function databaseStatus(FailingFinalizationDatabase $database, string $databaseId): mixed
    {
        return $database->getAuthorization()->skip(
            static fn (): UtopiaDocument => $database->getDocument('databases', $databaseId),
        )->getAttribute('status');
    }

    private function table(FailingFinalizationDatabase $database, string $databaseId, string $tableId): UtopiaDocument
    {
        return $database->getAuthorization()->skip(static function () use ($database, $databaseId, $tableId): UtopiaDocument {
            $metadata = $database->getDocument('databases', $databaseId);

            return $database->getDocument('database_'.$metadata->getSequence(), $tableId);
        });
    }

    /**
     * @return list<string>
     */
    private function columnKeys(FailingFinalizationDatabase $database, string $databaseId, string $tableId): array
    {
        $table = $this->table($database, $databaseId, $tableId);
        $columns = $database->getAuthorization()->skip(static fn (): array => $database->find('attributes', [
            Query::equal('databaseInternalId', [$table->getAttribute('databaseInternalId')]),
            Query::equal('collectionInternalId', [$table->getSequence()]),
        ]));
        $keys = \array_map(static fn (UtopiaDocument $column): string => $column->getAttribute('key'), $columns);
        \sort($keys);

        return $keys;
    }

    /**
     * @param array<MigrationException> $errors
     * @return list<array{string, string, string}>
     */
    private function subjects(array $errors): array
    {
        return \array_values(\array_map(
            static fn (MigrationException $error): array => [
                $error->getResourceName(),
                $error->getResourceGroup(),
                $error->getResourceId(),
            ],
            $errors,
        ));
    }

    private function projectDatabase(): FailingFinalizationDatabase
    {
        $database = new FailingFinalizationDatabase(
            new MemoryAdapter(),
            new Cache(new MemoryCache()),
        );
        $database
            ->setDatabase('appwrite')
            ->setNamespace('_project');
        $database->create();

        $database->createCollection(Collection::create(
            id: 'databases',
            attributes: [
                $this->attribute('name', ColumnType::String, required: true, size: 256),
                $this->attribute('enabled', ColumnType::Boolean, default: true),
                $this->attribute('search', ColumnType::String, size: 16384),
                $this->attribute('originalId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                $this->attribute('type', ColumnType::String, default: 'tablesdb', size: 128),
                $this->attribute('database', ColumnType::String, size: 2000),
                $this->attribute('status', ColumnType::String, size: 16),
            ],
        ));

        $database->createCollection(Collection::create(
            id: 'attributes',
            attributes: [
                $this->attribute('key', ColumnType::String, size: 256),
                $this->attribute('databaseInternalId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                $this->attribute('databaseId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                $this->attribute('collectionInternalId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                $this->attribute('collectionId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                $this->attribute('type', ColumnType::String, size: 256),
                $this->attribute('status', ColumnType::String, size: 64),
                $this->attribute('size', ColumnType::Integer),
                $this->attribute('required', ColumnType::Boolean, default: false),
                $this->attribute('signed', ColumnType::Boolean, default: true),
                $this->attribute('default', ColumnType::String, size: 16384),
                $this->attribute('array', ColumnType::Boolean, default: false),
                $this->attribute('format', ColumnType::String, size: 64),
                $this->attribute('formatOptions', ColumnType::String, size: 16384, filters: ['json']),
                $this->attribute('filters', ColumnType::String, size: 64, array: true),
                $this->attribute('options', ColumnType::String, size: 16384, filters: ['json']),
            ],
        ));

        $database->createCollection(Collection::create(
            id: 'indexes',
            attributes: [
                $this->attribute('key', ColumnType::String, size: 256),
                $this->attribute('databaseInternalId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                $this->attribute('collectionInternalId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
            ],
        ));

        return $database;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributeArray(
        string $id,
        ColumnType $type,
        mixed $default = null,
        int $size = 0,
    ): array {
        return [
            '$id' => $id,
            'type' => $type->value,
            'size' => $size,
            'required' => false,
            'default' => $default,
            'array' => false,
            'signed' => true,
            'filters' => [],
        ];
    }

    /**
     * @param array<string> $filters
     */
    private function attribute(
        string $id,
        ColumnType $type,
        bool $required = false,
        mixed $default = null,
        int $size = 0,
        bool $array = false,
        array $filters = [],
    ): UtopiaAttribute {
        return UtopiaAttribute::fromArray([
            'key' => $id,
            'type' => $type,
            'size' => $size,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'filters' => $filters,
        ]);
    }
}
