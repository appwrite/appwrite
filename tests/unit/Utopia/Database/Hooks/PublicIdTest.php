<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Hooks;

use Appwrite\Utopia\Database\Hooks\Metadata;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Hook\Lifecycle;
use Utopia\Database\Validator\Authorization;

final class PublicIdTest extends TestCase
{
    public function testResolvePublicIdFindsTheCollectionOfTheNamedDatabaseAndSequence(): void
    {
        $database = $this->catalog([
            'database_2' => ['17' => 'libraries', '18' => 'shelves'],
            'database_3' => ['17' => 'decoy'],
        ]);

        $this->assertSame('libraries', Metadata::resolvePublicId($database, 'database_2_collection_17'));
        $this->assertSame('shelves', Metadata::resolvePublicId($database, 'database_2_collection_18'));
        $this->assertSame('decoy', Metadata::resolvePublicId($database, 'database_3_collection_17'));
    }

    public function testResolvePublicIdReturnsInternalIdForUnknownShape(): void
    {
        $database = $this->database();

        foreach (['users', 'database_2', 'database__collection_1', ''] as $internalId) {
            $this->assertSame($internalId, Metadata::resolvePublicId($database, $internalId));
        }
    }

    public function testResolvePublicIdReturnsInternalIdWhenCatalogMissing(): void
    {
        $database = $this->catalog(['database_2' => ['18' => 'shelves']]);

        $this->assertSame(
            'database_2_collection_17',
            Metadata::resolvePublicId($database, 'database_2_collection_17'),
        );
    }

    public function testResolvePublicIdReturnsInternalIdWhenCatalogIdEmpty(): void
    {
        $database = $this->database(new Document(['$id' => '']));

        $this->assertSame(
            'database_2_collection_17',
            Metadata::resolvePublicId($database, 'database_2_collection_17'),
        );
    }

    public function testResolvePublicIdReadsACatalogTheCallerCannotReadWithoutFiringHooks(): void
    {
        $database = $this->catalog(['database_2' => ['17' => 'libraries']]);

        $hooks = new class () implements Lifecycle {
            /** @var list<Event> */
            public array $events = [];

            public function handle(Event $event, mixed $data): void
            {
                $this->events[] = $event;
            }
        };
        $database->addHook($hooks);

        $this->assertTrue($database->findOne('database_2')->isEmpty(), 'the caller cannot read the catalog');
        $this->assertNotSame([], $hooks->events, 'a read the caller makes fires the hooks');
        $hooks->events = [];

        $this->assertSame('libraries', Metadata::resolvePublicId($database, 'database_2_collection_17'));
        $this->assertSame([], $hooks->events, 'resolving a public ID is not a read the hooks of the request observe');
    }

    public function testResolverDoesNotQueryCatalogDuringTenantTransaction(): void
    {
        $tenant = $this->database(new Document(['$id' => 'movies']), inTransaction: true, hostname: 'mariadb');
        $catalog = $this->database(hostname: 'mariadb');

        $this->assertSame(
            'movies',
            (Metadata::resolver($tenant, $catalog))('database_2_collection_17'),
        );
    }

    public function testResolverUsesCatalogDuringTenantTransactionOnDifferentHost(): void
    {
        $tenant = $this->database(inTransaction: true, hostname: 'dedicated');
        $catalog = $this->database(new Document(['$id' => 'movies']), hostname: 'mariadb');

        $this->assertSame(
            'movies',
            (Metadata::resolver($tenant, $catalog))('database_2_collection_17'),
        );
    }

    public function testResolverUsesCatalogWhenTenantIsIdle(): void
    {
        $tenant = $this->database();
        $catalog = $this->database(new Document(['$id' => 'movies']));

        $this->assertSame(
            'movies',
            (Metadata::resolver($tenant, $catalog))('database_2_collection_17'),
        );
    }

    public function testResolverUsesSeededCatalogWithoutCheckout(): void
    {
        $tenant = $this->database();
        $catalog = $this->database();

        $this->assertSame(
            'movies',
            (Metadata::resolver($tenant, $catalog, [
                'database_2_collection_17' => 'movies',
            ]))('database_2_collection_17'),
        );
    }

    public function testDecorateDuringTenantTransactionDoesNotQueryCatalog(): void
    {
        $tenant = $this->database(new Document(['$id' => 'movies']), inTransaction: true, hostname: 'mariadb');
        $catalog = $this->database(hostname: 'mariadb');

        $result = (new Metadata(
            database: new Document(['$id' => 'db1']),
            context: 'table',
            resolvePublicId: Metadata::resolver($tenant, $catalog),
        ))->decorate(
            Event::DocumentCreate,
            new Document(['$id' => 'database_2_collection_17']),
            new Document(['$id' => 'row1']),
        );

        $this->assertSame('movies', $result->getAttribute('$tableId'));
        $this->assertSame('db1', $result->getAttribute('$databaseId'));
    }

    public function testDecorateDuringDedicatedTransactionUsesCatalog(): void
    {
        $tenant = $this->database(inTransaction: true, hostname: 'dedicated');
        $catalog = $this->database(new Document(['$id' => 'movies']), hostname: 'mariadb');

        $result = (new Metadata(
            database: new Document(['$id' => 'db1']),
            context: 'table',
            resolvePublicId: Metadata::resolver($tenant, $catalog),
        ))->decorate(
            Event::DocumentCreate,
            new Document(['$id' => 'database_2_collection_17']),
            new Document(['$id' => 'row1']),
        );

        $this->assertSame('movies', $result->getAttribute('$tableId'));
        $this->assertSame('db1', $result->getAttribute('$databaseId'));
    }

    /**
     * @param  array<string, array<string, string>>  $collections
     */
    private function catalog(array $collections): Database
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::any()->toString());
        $database = (new Database(new Memory(), new Cache(new None())))
            ->setDatabase('public_ids')
            ->setNamespace('catalog')
            ->setAuthorization($authorization);
        $authorization->skip(function () use ($database, $collections): void {
            $database->create();
            foreach ($collections as $catalogId => $publicIds) {
                $database->createCollection(new Collection(id: $catalogId));
                foreach ($publicIds as $sequence => $publicId) {
                    $database->createDocument($catalogId, new Document(['$id' => $publicId, '$sequence' => (string) $sequence]));
                }
            }
        });

        return $database;
    }

    private function database(?Document $catalog = null, bool $inTransaction = false, string $hostname = ''): Database
    {
        $adapter = new class ($inTransaction) extends Memory {
            public function __construct(private readonly bool $transacting)
            {
                parent::__construct();
            }

            #[\Override]
            public function inTransaction(): bool
            {
                return $this->transacting;
            }
        };
        $adapter->setHostname($hostname);

        return new class ($adapter, $catalog) extends Database {
            public function __construct(Adapter $adapter, private readonly ?Document $found)
            {
                parent::__construct($adapter, new Cache(new None()));
            }

            #[\Override]
            public function findOne(string $collection, array $queries = []): Document
            {
                return $this->found ?? throw new \LogicException('the catalog must not be queried');
            }
        };
    }
}
