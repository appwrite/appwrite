<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Appwrite\Database\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Utopia\Database\Adapter\ConnectedMemory;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter;
use Utopia\Database\Adapter\Feature\Schemaless;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;

require_once __DIR__ . '/../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

final class FactoryTenantTest extends TestCase
{
    public static function databaseTypes(): \Iterator
    {
        yield 'legacy' => [LEGACY];
        yield 'tablesdb' => [TABLESDB];
        yield 'documentsdb' => [DOCUMENTSDB];
        yield 'vectorsdb' => [VECTORSDB];
    }

    #[DataProvider('databaseTypes')]
    public function testATenantDatabaseOnAnAdapterWithAFixedSchemaIsBuilt(string $type): void
    {
        $database = $this->factory(new ConnectedMemory())->tenant($this->databaseDocument($type), $this->project());

        $this->assertFalse($database->getAdapter()->hasFeature(Schemaless::class));
    }

    public static function schemalessTypes(): \Iterator
    {
        yield 'legacy' => [LEGACY, false];
        yield 'tablesdb' => [TABLESDB, false];
        yield 'documentsdb' => [DOCUMENTSDB, true];
        yield 'vectorsdb' => [VECTORSDB, false];
    }

    #[DataProvider('schemalessTypes')]
    public function testOnlyADocumentsDatabaseIsSchemaless(string $type, bool $schemaless): void
    {
        $connection = new class () extends ConnectedMemory implements Schemaless {
            private bool $schemaless = false;

            #[\Override]
            public function setSchemaless(bool $schemaless): static
            {
                $this->schemaless = $schemaless;

                return $this;
            }

            #[\Override]
            public function isSchemaless(): bool
            {
                return $this->schemaless;
            }
        };

        $database = $this->factory($connection)->tenant($this->databaseDocument($type), $this->project());
        $database->exists();

        $this->assertSame($schemaless, $connection->isSchemaless());
    }

    private function factory(Adapter $connection): Factory
    {
        $pools = new Group();
        $pools->add(new Pool(new Stack(), 'database_db_main', 1, static fn (): Adapter => $connection, 1.0));

        return new Factory($pools, new Cache(new MemoryCache()), new Authorization());
    }

    private function databaseDocument(string $type): Document
    {
        return new Document(['$id' => 'database1', '$sequence' => '1', 'type' => $type]);
    }

    private function project(): Document
    {
        return new Document([
            '$id' => 'project-1',
            '$sequence' => '7',
            'database' => 'mysql://database_db_main',
        ]);
    }
}
