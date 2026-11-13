<?php

declare(strict_types=1);

namespace Utopia\Migration\Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory as MemoryAdapter;
use Utopia\Database\Attribute as UtopiaAttribute;
use Utopia\Database\Collection;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document as UtopiaDocument;
use Utopia\Migration\Destinations\Appwrite as AppwriteDestination;
use Utopia\Migration\Destinations\OnDuplicate;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Database as DatabaseResource;
use Utopia\Migration\Tests\Adapters\MockSource;
use Utopia\Migration\Transfer;
use Utopia\Query\Schema\ColumnType;

/**
 * Regression for PR #151: the destination must never write the source's DSN
 * into `_databases.database`. With no resolver the migrated row must be blank,
 * so the runtime falls back to the destination project's own DSN. With a
 * resolver the row must carry the resolver's value and nothing of the source's.
 *
 * Reproduces the comuneo-pre-production incident where post-migration
 * `_databases.database` rows pointed at the source's host (db11) and
 * destination reads hit `Table 'appwrite._<tenant>__metadata' doesn't exist`.
 */
final class AppwriteDestinationDsnTest extends TestCase
{
    private const SOURCE_DSN = 'database_db_fra1_self_hosted_11_0';

    private const DATABASE_ID = 'src-database';

    public function testWithoutAResolverTheMigratedRowCarriesNoDsn(): void
    {
        $database = $this->transferDatabase(getDatabaseDSN: null);

        $this->assertSame(
            '',
            $this->migratedDsn($database),
            'Without a resolver the destination must not propagate the source DSN.',
        );
    }

    public function testWithAResolverTheMigratedRowCarriesItsValue(): void
    {
        $expected = 'appwrite://database_db_fra1_self_hosted_17_0?database=appwrite&namespace=_1';

        $database = $this->transferDatabase(
            getDatabaseDSN: static fn (DatabaseResource $resource): string => $expected,
        );

        $resolved = $this->migratedDsn($database);
        $this->assertSame($expected, $resolved);
        $this->assertNotSame(self::SOURCE_DSN, $resolved, 'Source DSN must not leak through the resolver path.');
    }

    public function testTheResolverIsHandedTheDatabaseBeingMigrated(): void
    {
        $database = $this->transferDatabase(
            getDatabaseDSN: static fn (DatabaseResource $resource): string => 'resolved://'
                . $resource->getId()
                . '/'
                . $resource->getDatabase(),
        );

        $this->assertSame(
            'resolved://' . self::DATABASE_ID . '/' . self::SOURCE_DSN,
            $this->migratedDsn($database),
        );
    }

    private function transferDatabase(?callable $getDatabaseDSN): UtopiaDatabase
    {
        $database = $this->projectDatabase();

        $source = new MockSource();
        $source->pushMockResource(new DatabaseResource(
            id: self::DATABASE_ID,
            name: 'src',
            type: 'legacy',
            database: self::SOURCE_DSN,
        ));

        $destination = new AppwriteDestination(
            project: 'destination-project',
            endpoint: 'http://example.test/v1',
            key: 'test-key',
            dbForProject: $database,
            getDatabasesDB: static fn (UtopiaDocument $document): UtopiaDatabase => $database,
            collectionStructure: ['attributes' => [], 'indexes' => []],
            dbForPlatform: $database,
            projectInternalId: '1',
            onDuplicate: OnDuplicate::Fail,
            getDatabaseDSN: $getDatabaseDSN,
        );

        $transfer = new Transfer($source, $destination);
        $database->getAuthorization()->skip(
            static function () use ($transfer): void {
                $transfer->run([Resource::TYPE_DATABASE], static function (): void {
                });
            },
        );

        $this->assertSame(
            [],
            \array_map(static fn ($error): string => $error->getMessage(), $destination->getErrors()),
        );

        return $database;
    }

    private function migratedDsn(UtopiaDatabase $database): mixed
    {
        $row = $database->getAuthorization()->skip(
            static fn (): UtopiaDocument => $database->getDocument('databases', self::DATABASE_ID),
        );

        $this->assertFalse($row->isEmpty(), 'The migrated database must be written to the destination metadata');

        return $row->getAttribute('database');
    }

    private function projectDatabase(): UtopiaDatabase
    {
        $database = new UtopiaDatabase(
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
                $this->attribute('description', ColumnType::String, size: 1024),
                $this->attribute('enabled', ColumnType::Boolean, default: true),
                $this->attribute('search', ColumnType::String, size: 16384),
                $this->attribute('originalId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                $this->attribute('type', ColumnType::String, default: 'tablesdb', size: 128),
                $this->attribute('database', ColumnType::String, size: 2000),
            ],
        ));

        return $database;
    }

    private function attribute(
        string $id,
        ColumnType $type,
        bool $required = false,
        mixed $default = null,
        int $size = 0,
    ): UtopiaAttribute {
        return UtopiaAttribute::fromArray([
            'key' => $id,
            'type' => $type,
            'size' => $size,
            'required' => $required,
            'default' => $default,
        ]);
    }
}
