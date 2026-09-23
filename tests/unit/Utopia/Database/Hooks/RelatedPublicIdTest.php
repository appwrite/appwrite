<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Hooks;

use Appwrite\Database\Factory as DatabaseFactory;
use Appwrite\Databases\TransactionState;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\XList;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Hooks\Metadata;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Hook\Relationships;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Database\Relationship;
use Utopia\Database\RelationType;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\Query\CursorDirection;
use Utopia\Query\Schema\ColumnType;

require_once __DIR__ . '/../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

/**
 * Documents nested through relationships carry the public ID of their collection. Resolving it must not cost every
 * request an uncached query on the project catalog per related collection: the IDs never change, and the catalog's
 * collection documents are already cached by the database library.
 */
final class RelatedPublicIdTest extends TestCase
{
    private const string DATABASE_ID = 'library';

    private Authorization $authorization;

    private Memory $catalogAdapter;

    /**
     * @var list<string> every read that reached the catalog adapter, as `method:collection`
     */
    private array $catalogReads = [];

    private Cache $catalogCache;

    private Memory $tenantAdapter;

    /**
     * @var array<string, string> public collection ID => internal collection ID
     */
    private array $internalIds = [];

    /**
     * @var array<string, array{encode: callable, decode: callable, signature: string}>
     */
    private array $filters;

    protected function setUp(): void
    {
        $this->filters = (new ReflectionProperty(Database::class, 'filters'))->getValue();
        require __DIR__ . '/../../../../../app/init/database/filters.php';

        $this->authorization = new Authorization();
        $this->authorization->addRole(User::ROLE_KEYS);
        $this->authorization->addRole(Role::any()->toString());
        $this->catalogCache = new Cache(new MemoryCache());
        $this->tenantAdapter = new Memory();

        $this->catalogAdapter = new class (function (string $read): void {
            $this->catalogReads[] = $read;
        }) extends Memory {
            /**
             * @param Closure(string): void $read
             */
            public function __construct(private readonly Closure $read)
            {
                parent::__construct();
            }

            public function getDocument(Document $collection, string $id, array $queries = [], bool $forUpdate = false): Document
            {
                ($this->read)('getDocument:' . $collection->getId());

                return parent::getDocument($collection, $id, $queries, $forUpdate);
            }

            public function find(Document $collection, array $queries = [], ?int $limit = 25, ?int $offset = null, array $orderAttributes = [], array $orderTypes = [], array $cursor = [], CursorDirection $cursorDirection = CursorDirection::After, PermissionType $forPermission = PermissionType::Read): array
            {
                ($this->read)('find:' . $collection->getId());

                return parent::find($collection, $queries, $limit, $offset, $orderAttributes, $orderTypes, $cursor, $cursorDirection, $forPermission);
            }
        };

        $this->seedCatalog();
        $this->seedTenant();
        $this->catalogReads = [];
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Database::class, 'filters'))->setValue(null, $this->filters);
    }

    /**
     * @return iterable<string, array{list<string>, array<string, string>}>
     */
    public static function relatedLists(): iterable
    {
        yield 'two related collections' => [['*', 'tracks.*', 'label.*'], ['tracks' => 'tracks', 'label' => 'labels']];
        yield 'a related collection of a related collection' => [['*', 'tracks.*', 'tracks.artist.*'], ['tracks' => 'tracks', 'tracks.artist' => 'artists']];
    }

    /**
     * @param list<string> $select
     * @param array<string, string> $related relationship path => public ID of the collection it leads to
     */
    #[DataProvider('relatedLists')]
    public function testRepeatListResolvesRelatedCollectionsWithoutQueryingTheCatalog(array $select, array $related): void
    {
        $first = $this->list($select);
        $firstReads = $this->catalogReads;
        $this->catalogReads = [];
        $second = $this->list($select);

        foreach ([$first, $second] as $documents) {
            $this->assertCount(2, $documents);
            foreach ($documents as $document) {
                $this->assertSame('albums', $document->getAttribute('$collectionId'));
                foreach ($related as $path => $publicId) {
                    foreach ($this->related($document, $path) as $relation) {
                        $this->assertSame(self::DATABASE_ID, $relation->getAttribute('$databaseId'));
                        $this->assertSame($publicId, $relation->getAttribute('$collectionId'), $path);
                    }
                }
            }
        }
        foreach (\array_keys($related) as $path) {
            $this->assertNotEmpty($this->related($first[0], $path), 'album1 must return documents through ' . $path);
        }
        $this->assertNotEmpty($firstReads, 'the first request reads the catalog cold');
        $this->assertSame([], $this->catalogReads, 'a repeat request resolves every related collection from the cached catalog');
    }

    public function testRelatedCollectionOutsideTheRelationshipsIsResolvedFromTheCatalog(): void
    {
        $resolve = Metadata::resolver(
            $this->tenant(),
            $this->catalog(),
            [$this->internalIds['albums'] => 'albums'],
        );

        $this->assertSame('artists', $resolve($this->internalIds['artists']));
        $this->assertNotContains('find:database_1', $this->catalogReads, 'a related collection is read by its public ID, through the document cache');
        $this->assertSame('playlists', $resolve($this->internalIds['playlists']));
        $this->assertContains('find:database_1', $this->catalogReads, 'a collection no relationship leads to is looked up by its sequence');
    }

    /**
     * @param list<string> $select
     * @return list<Document>
     */
    private function list(array $select): array
    {
        $catalog = $this->catalog();
        $factory = $this->createStub(DatabaseFactory::class);
        $factory->method('tenant')->willReturnCallback(fn (): Database => $this->tenant());
        $request = $this->createStub(Request::class);
        $request->method('getURI')->willReturn('/v1/databases/' . self::DATABASE_ID . '/collections/albums/documents');
        $request->method('getHeaderLine')->willReturn('');

        $register = require __DIR__ . '/../../../../../app/init/resources/request.php';
        $container = new Container();
        $register($container);
        $container->set('databaseFactory', static fn (): DatabaseFactory => $factory);
        $container->set('project', static fn (): Document => new Document(['$id' => 'project']));
        $container->set('request', static fn (): Request => $request);
        $container->set('dbForProject', static fn (): Database => $catalog);
        $container->set('usage', static fn (): Context => new Context());

        $documents = [];
        $response = $this->createStub(Response::class);
        $response->method('dynamic')->willReturnCallback(
            static function (Document $list) use (&$documents): void {
                $documents = \array_values($list->getAttribute('documents'));
            }
        );

        (new XList())->action(
            databaseId: self::DATABASE_ID,
            collectionId: 'albums',
            queries: [Query::select($select)->toString(), Query::orderAsc('$id')->toString()],
            transactionId: null,
            includeTotal: false,
            ttl: 0,
            response: $response,
            dbForProject: $catalog,
            user: new User(),
            getDatabasesDB: $container->get('getDatabasesDB'),
            usage: $container->get('usage'),
            transactionState: $this->createStub(TransactionState::class),
            authorization: $this->authorization,
            operations: $container->get('operations'),
        );

        return $documents;
    }

    /**
     * @return list<Document>
     */
    private function related(Document $document, string $path): array
    {
        $documents = [$document];
        foreach (\explode('.', $path) as $key) {
            $next = [];
            foreach ($documents as $parent) {
                $value = $parent->getAttribute($key);
                foreach (\is_array($value) ? $value : [$value] as $relation) {
                    if ($relation instanceof Document) {
                        $next[] = $relation;
                    }
                }
            }
            $documents = $next;
        }

        return $documents;
    }

    private function catalog(): Database
    {
        return (new Database($this->catalogAdapter, $this->catalogCache))
            ->setDatabase('appwrite')
            ->setNamespace('catalog')
            ->setAuthorization($this->authorization);
    }

    private function tenant(): Database
    {
        $tenant = (new Database($this->tenantAdapter, new Cache(new NoCache())))
            ->setDatabase('appwrite')
            ->setNamespace('tenant')
            ->setAuthorization($this->authorization);

        return $tenant->addHook(new Relationships($tenant));
    }

    private function seedCatalog(): void
    {
        $catalog = $this->catalog();
        $collections = Config::getParam('collections', []);

        $this->authorization->skip(function () use ($catalog, $collections): void {
            $catalog->create();
            foreach (['databases', 'attributes', 'indexes'] as $id) {
                $catalog->createCollection(new Collection(
                    id: $id,
                    attributes: $collections['projects'][$id]['attributes'],
                    indexes: $collections['projects'][$id]['indexes'],
                ));
            }

            $database = $catalog->createDocument('databases', new Document([
                '$id' => self::DATABASE_ID,
                'name' => 'Library',
                'enabled' => true,
                'type' => DATABASE_TYPE_LEGACY,
            ]));
            $this->assertSame('1', $database->getSequence());
            $catalog->createCollection(new Collection(
                id: 'database_1',
                attributes: $collections['databases']['collections']['attributes'],
                indexes: $collections['databases']['collections']['indexes'],
            ));

            foreach (['albums', 'tracks', 'artists', 'labels', 'playlists'] as $id) {
                $collection = $catalog->createDocument('database_1', new Document([
                    '$id' => $id,
                    'databaseInternalId' => '1',
                    'databaseId' => self::DATABASE_ID,
                    'name' => $id,
                    'enabled' => true,
                    'documentSecurity' => false,
                ]));
                $this->internalIds[$id] = 'database_1_collection_' . $collection->getSequence();
            }

            foreach ([
                ['albums', 'tracks', 'tracks', RelationType::OneToMany, 'album', 'parent'],
                ['tracks', 'album', 'albums', RelationType::OneToMany, 'tracks', 'child'],
                ['labels', 'albums', 'albums', RelationType::OneToMany, 'label', 'parent'],
                ['albums', 'label', 'labels', RelationType::OneToMany, 'albums', 'child'],
                ['tracks', 'artist', 'artists', RelationType::ManyToOne, '', 'parent'],
            ] as [$collectionId, $key, $relatedCollection, $type, $twoWayKey, $side]) {
                $catalog->createDocument('attributes', new Document([
                    '$id' => '1_' . \substr($this->internalIds[$collectionId], \strlen('database_1_collection_')) . '_' . $key,
                    'databaseInternalId' => '1',
                    'databaseId' => self::DATABASE_ID,
                    'collectionInternalId' => \substr($this->internalIds[$collectionId], \strlen('database_1_collection_')),
                    'collectionId' => $collectionId,
                    'key' => $key,
                    'type' => ColumnType::Relationship->value,
                    'status' => 'available',
                    'size' => 0,
                    'required' => false,
                    'array' => false,
                    'filters' => [],
                    'options' => [
                        'relatedCollection' => $relatedCollection,
                        'relationType' => $type->value,
                        'twoWay' => $twoWayKey !== '',
                        'twoWayKey' => $twoWayKey,
                        'onDelete' => 'setNull',
                        'side' => $side,
                    ],
                ]));
            }
        });
    }

    private function seedTenant(): void
    {
        $tenant = $this->tenant();
        $this->authorization->skip(function () use ($tenant): void {
            $tenant->create();
            foreach ($this->internalIds as $internalId) {
                $tenant->createCollection(new Collection(
                    id: $internalId,
                    attributes: [Attribute::string(key: 'name', size: 100, required: false)],
                    permissions: [Permission::read(Role::any()), Permission::create(Role::any())],
                ));
            }
            $tenant->createRelationship(new Relationship(
                collection: $this->internalIds['albums'],
                relatedCollection: $this->internalIds['tracks'],
                type: RelationType::OneToMany,
                twoWay: true,
                key: 'tracks',
                twoWayKey: 'album',
            ));
            $tenant->createRelationship(new Relationship(
                collection: $this->internalIds['labels'],
                relatedCollection: $this->internalIds['albums'],
                type: RelationType::OneToMany,
                twoWay: true,
                key: 'albums',
                twoWayKey: 'label',
            ));
            $tenant->createRelationship(new Relationship(
                collection: $this->internalIds['tracks'],
                relatedCollection: $this->internalIds['artists'],
                type: RelationType::ManyToOne,
                key: 'artist',
            ));

            $tenant->createDocument($this->internalIds['artists'], new Document(['$id' => 'artist1', 'name' => 'artist1']));
            $tenant->createDocument($this->internalIds['labels'], new Document(['$id' => 'label1', 'name' => 'label1']));
            foreach (['album1' => ['track1', 'track2'], 'album2' => ['track3']] as $album => $tracks) {
                $tenant->createDocument($this->internalIds['albums'], new Document([
                    '$id' => $album,
                    'name' => $album,
                    'label' => 'label1',
                    'tracks' => \array_map(
                        static fn (string $track): array => ['$id' => $track, 'name' => $track, 'artist' => 'artist1'],
                        $tracks,
                    ),
                ]));
            }
        });
    }
}
