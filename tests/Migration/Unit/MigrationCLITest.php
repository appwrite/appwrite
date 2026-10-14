<?php

namespace Utopia\Tests\Unit;

require_once \dirname(__DIR__, 3).'/bin/MigrationCLI.php';

use Override;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory as MemoryAdapter;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Database as DatabaseResource;
use Utopia\Migration\Source;
use Utopia\Migration\Transfer;
use Utopia\Query\Schema\ColumnType;
use Utopia\Tests\Unit\Adapters\MockSource;

final class TestMigrationCLI extends \MigrationCLI
{
    public function __construct(
        private readonly Database $database,
        private readonly ?Source $injectedSource = null,
    ) {
    }

    #[Override]
    public function getDatabase(string $type): Database
    {
        $this->database->getAuthorization()->disable();

        return $this->database;
    }

    #[Override]
    public function getSource(): Source
    {
        return $this->injectedSource ?? parent::getSource();
    }

    #[Override]
    public function drawFrame(): void
    {
    }

    #[Override]
    protected function loadEnvironment(): void
    {
    }

    /** @return list<\Utopia\Migration\Exception> */
    public function getErrors(): array
    {
        return $this->destination->getErrors();
    }
}

#[BackupGlobals(true)]
final class MigrationCLITest extends TestCase
{
    private const array FILTER_STATE = ['filters', 'defaultFiltersRegistered'];

    /** @var array<string, mixed> */
    private array $filterState = [];

    public function testStartFinalizesSuccessfulAppwriteDatabase(): void
    {
        $database = $this->createProjectDatabase();
        $source = $this->createSource(new DatabaseResource(
            id: 'database',
            name: 'Database',
            type: 'tablesdb',
            database: 'source-dsn',
            databaseStatus: 'ready',
        ));
        $cli = new TestMigrationCLI($database, $source);

        $cli->start();

        $created = $database->getDocument('databases', 'database');
        $this->assertSame([], $cli->getErrors());
        $this->assertSame('ready', $created->getAttribute('status'));
        $this->assertNotNull($database->findCollection('database_'.$created->getSequence()));
    }

    public function testStartFinalizesSuccessfulResourcesWhenDestinationHasErrors(): void
    {
        $database = $this->createProjectDatabase();
        $valid = new DatabaseResource(
            id: 'database',
            name: 'Database',
            type: 'tablesdb',
            database: 'source-dsn',
            databaseStatus: 'ready',
        );
        $invalid = new DatabaseResource(
            id: 'invalid id',
            name: 'Invalid database',
            type: 'tablesdb',
            database: 'source-dsn',
            databaseStatus: 'ready',
        );
        $cli = new TestMigrationCLI($database, $this->createSource($valid, $invalid));

        $cli->start();

        $created = $database->getDocument('databases', 'database');
        $errors = $cli->getErrors();
        $this->assertCount(1, $errors);
        $this->assertSame('invalid id', $errors[0]->getResourceId());
        $this->assertSame(Transfer::GROUP_DATABASES, $errors[0]->getResourceGroup());
        $this->assertSame(Resource::STATUS_SUCCESS, $valid->getStatus());
        $this->assertSame(Resource::STATUS_ERROR, $invalid->getStatus());
        $this->assertSame('ready', $created->getAttribute('status'));
        $this->assertNotNull($database->findCollection('database_'.$created->getSequence()));
    }

    public function testTheMetadataSubqueryFiltersLoadTheTableColumnsAndIndexes(): void
    {
        \MigrationCLI::registerFilters();
        $database = $this->createMetadataDatabase();

        $table = $database->getDocument('tables', 'products');

        $columns = $table->getAttribute('attributes');
        $this->assertSame(
            ['title', 'orders'],
            \array_map(static fn (Document $column): string => $column->getAttribute('key'), $columns),
            'Only the columns of this table and database must be loaded.',
        );
        $this->assertTrue($columns[0]->getAttribute('encrypt'), 'A string column must expose whether it is encrypted.');
        $this->assertSame('orders', $columns[1]->getAttribute('relatedCollection'), 'A relationship column must expose its options.');
        $this->assertFalse($columns[1]->isSet('options'));

        $this->assertSame(
            ['idx_title'],
            \array_map(static fn (Document $index): string => $index->getAttribute('key'), $table->getAttribute('indexes')),
            'Only the indexes of this table and database must be loaded.',
        );
    }

    private function createMetadataDatabase(): Database
    {
        $database = new Database(new MemoryAdapter(), new Cache(new MemoryCache()));
        $database
            ->setDatabase('appwrite')
            ->setNamespace('_metadata');
        $database->create();
        $database->getAuthorization()->disable();

        $database->createCollection(Collection::create(
            id: 'attributes',
            attributes: [
                Attribute::string(key: 'key', size: 256),
                Attribute::string(key: 'type', size: 256),
                Attribute::string(key: 'collectionInternalId', size: Database::LENGTH_KEY),
                Attribute::string(key: 'databaseInternalId', size: Database::LENGTH_KEY),
                Attribute::string(key: 'filters', size: 64, array: true),
                Attribute::string(key: 'options', size: 16384, filters: ['json']),
            ],
        ));
        $database->createCollection(Collection::create(
            id: 'indexes',
            attributes: [
                Attribute::string(key: 'key', size: 256),
                Attribute::string(key: 'collectionInternalId', size: Database::LENGTH_KEY),
                Attribute::string(key: 'databaseInternalId', size: Database::LENGTH_KEY),
            ],
        ));
        $database->createCollection(Collection::create(
            id: 'tables',
            attributes: [
                Attribute::string(key: 'databaseInternalId', size: Database::LENGTH_KEY),
                Attribute::string(key: 'attributes', size: 16384, filters: ['subQueryAttributes']),
                Attribute::string(key: 'indexes', size: 16384, filters: ['subQueryIndexes']),
            ],
        ));

        $table = $database->createDocument('tables', new Document(['$id' => 'products', 'databaseInternalId' => '1']));
        $other = $database->createDocument('tables', new Document(['$id' => 'customers', 'databaseInternalId' => '1']));

        $rows = [
            ['key' => 'title', 'type' => ColumnType::String->value, 'collectionInternalId' => $table->getSequence(), 'databaseInternalId' => '1', 'filters' => ['encrypt']],
            ['key' => 'orders', 'type' => ColumnType::Relationship->value, 'collectionInternalId' => $table->getSequence(), 'databaseInternalId' => '1', 'options' => ['relatedCollection' => 'orders']],
            ['key' => 'name', 'type' => ColumnType::String->value, 'collectionInternalId' => $other->getSequence(), 'databaseInternalId' => '1'],
            ['key' => 'title', 'type' => ColumnType::String->value, 'collectionInternalId' => $table->getSequence(), 'databaseInternalId' => '2'],
        ];
        foreach ($rows as $row) {
            $database->createDocument('attributes', new Document($row));
        }

        $indexes = [
            ['key' => 'idx_title', 'collectionInternalId' => $table->getSequence(), 'databaseInternalId' => '1'],
            ['key' => 'idx_name', 'collectionInternalId' => $other->getSequence(), 'databaseInternalId' => '1'],
        ];
        foreach ($indexes as $index) {
            $database->createDocument('indexes', new Document($index));
        }

        return $database;
    }

    private function createProjectDatabase(): Database
    {
        $database = new Database(new MemoryAdapter(), new Cache(new MemoryCache()));
        $database
            ->setDatabase('appwrite')
            ->setNamespace('_project');
        $database->create();
        $database->createCollection(Collection::create(
            id: 'databases',
            attributes: [
                Attribute::string(key: 'name', size: 256, required: true),
                Attribute::boolean(key: 'enabled', default: true),
                Attribute::string(key: 'search', size: 16384),
                Attribute::string(key: 'originalId', size: Database::LENGTH_KEY),
                Attribute::string(key: 'type', size: 128, default: 'tablesdb'),
                Attribute::string(key: 'database', size: 2000),
                Attribute::string(key: 'status', size: 16),
            ],
        ));

        return $database;
    }

    private function createSource(DatabaseResource ...$resources): MockSource
    {
        $source = new class () extends MockSource {
            /** @return list<string> */
            #[Override]
            public static function getSupportedResources(): array
            {
                return [Resource::TYPE_DATABASE];
            }

            #[Override]
            public function supportsDatabaseStatus(): bool
            {
                return true;
            }
        };
        foreach ($resources as $resource) {
            $source->pushMockResource($resource);
        }

        return $source;
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::FILTER_STATE as $property) {
            $this->filterState[$property] = (new \ReflectionProperty(Database::class, $property))->getValue();
        }
        $_ENV['DESTINATION_PROVIDER'] = 'appwrite';
        $_ENV['DESTINATION_APPWRITE_TEST_PROJECT'] = 'destination-project';
        $_ENV['DESTINATION_APPWRITE_TEST_ENDPOINT'] = 'http://example.test/v1';
        $_ENV['DESTINATION_APPWRITE_TEST_KEY'] = 'test-key';
        $_ENV['DESTINATION_APPWRITE_TEST_PROJECT_INTERNAL_ID'] = '1';
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->filterState as $property => $value) {
            (new \ReflectionProperty(Database::class, $property))->setValue(null, $value);
        }
        parent::tearDown();
    }
}
