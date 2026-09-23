<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http\Documents;

use Appwrite\Databases\TransactionState;
use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Functions\EventProcessor;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Create;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Get;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Queries\Create as QueryDocuments;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Update;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Upsert;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\XList;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Get as GetRow;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Queries\Create as QueryRows;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\XList as ListRows;
use Appwrite\Usage\Context;
use Appwrite\Usage\Operations;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Hooks\Metadata;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionParameter;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Hook\Permissions;
use Utopia\Database\Hook\Relationships;
use Utopia\Database\Query;
use Utopia\Database\Relationship;
use Utopia\Database\RelationType;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Query\Schema\ColumnType;

require_once __DIR__ . '/../../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

/**
 * Main metered a document request as the document itself plus every related document nested in it: a read by the
 * documents it returned, a write by the documents in its payload. Cloud bills `databases.operations.reads` and
 * `databases.operations.writes`, so each endpoint must report exactly main's count.
 */
final class OperationsMeteringTest extends TestCase
{
    private const string DATABASE_ID = 'library';
    private const string ALBUM_ID = 'album1';
    private const string ALBUMS = 'database_1_collection_1';
    private const string TRACKS = 'database_1_collection_2';
    private const string GENRES = 'database_1_collection_3';
    private const array PUBLIC_IDS = [
        self::ALBUMS => 'albums',
        self::TRACKS => 'tracks',
        self::GENRES => 'genres',
    ];

    private Authorization $authorization;

    private Document $database;

    /**
     * @var array<string, array<string, Document>>
     */
    private array $metadata;

    private Context $usage;

    protected function setUp(): void
    {
        $this->authorization = new Authorization();
        $this->authorization->addRole(User::ROLE_KEYS);
        $this->authorization->addRole(Role::any()->toString());

        $this->database = new Document([
            '$id' => self::DATABASE_ID,
            '$sequence' => '1',
            'type' => DATABASE_TYPE_LEGACY,
            'enabled' => true,
        ]);

        $this->metadata = [
            'databases' => [self::DATABASE_ID => $this->database],
            'database_1' => [
                'albums' => self::collection('albums', '1', [
                    self::attribute('name'),
                    self::relationship('tracks', 'tracks', RelationType::OneToMany, 'album', 'parent'),
                ]),
                'tracks' => self::collection('tracks', '2', [
                    self::attribute('name'),
                    self::relationship('album', 'albums', RelationType::OneToMany, 'tracks', 'child'),
                    self::relationship('genres', 'genres', RelationType::ManyToMany, '', 'parent'),
                ]),
                'genres' => self::collection('genres', '3', [self::attribute('name')]),
            ],
        ];

        $this->usage = new Context();
    }

    public function testUpdateMetersEveryNestedDocumentOfThePayload(): void
    {
        $tenant = $this->tenant();
        $this->seedAlbum($tenant, []);

        $this->update($tenant, [
            'tracks' => \array_map(
                static fn (int $index): array => ['$id' => 'track' . $index, 'name' => 'Track ' . $index],
                \range(1, 4),
            ),
        ]);

        $this->assertSame(5, $this->metric(METRIC_DATABASES_OPERATIONS_WRITES), 'the album and the four tracks it writes');
        $this->assertCount(4, $tenant->getDocument(self::ALBUMS, self::ALBUM_ID)->getAttribute('tracks'));
    }

    public function testUpdateMetersNestedDocumentsAtEveryDepth(): void
    {
        $tenant = $this->tenant();
        $this->seedAlbum($tenant, []);

        $this->update($tenant, [
            'tracks' => [
                ['$id' => 'track1', 'genres' => [['$id' => 'rock', 'name' => 'Rock'], 'jazz']],
                'track2',
            ],
        ]);

        $this->assertSame(3, $this->metric(METRIC_DATABASES_OPERATIONS_WRITES), 'the album, track1 and rock; linked IDs are not writes');
    }

    public function testUpdateDoesNotMeterRelatedDocumentsItLeavesAlone(): void
    {
        $tenant = $this->tenant();
        $this->seedAlbum($tenant, ['track1', 'track2', 'track3']);

        $this->update($tenant, ['name' => 'Renamed']);

        $this->assertSame(1, $this->metric(METRIC_DATABASES_OPERATIONS_WRITES), 'the related tracks the response loads were not written');
    }

    public function testCreateMetersEveryNestedDocumentOfThePayload(): void
    {
        $tenant = $this->tenant();
        $this->seedGenre($tenant, 'jazz');

        $this->create($tenant, 'albums', [
            'name' => 'Album',
            'tracks' => [
                ['$id' => 'track1', 'genres' => [['$id' => 'rock', 'name' => 'Rock'], 'jazz']],
                ['$id' => 'track2', 'genres' => []],
            ],
        ]);

        $this->assertSame(4, $this->metric(METRIC_DATABASES_OPERATIONS_WRITES), 'the album, two tracks and rock');
        $this->assertCount(2, $tenant->getDocument(self::ALBUMS, self::ALBUM_ID)->getAttribute('tracks'));
    }

    public function testCreateMetersEveryDocumentOfABatch(): void
    {
        $tenant = $this->tenant();

        $this->create($tenant, 'genres', documents: [
            ['$id' => 'rock', 'name' => 'Rock'],
            ['$id' => 'jazz', 'name' => 'Jazz'],
            ['$id' => 'soul', 'name' => 'Soul'],
        ]);

        $this->assertSame(3, $this->metric(METRIC_DATABASES_OPERATIONS_WRITES));
    }

    public function testUpsertMetersEveryNestedDocumentOfThePayload(): void
    {
        $written = [];
        $tenant = $this->createStub(Database::class);
        $tenant->method('getDocument')->willReturn(new Document());
        $tenant->method('withPreserveDates')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $tenant->method('upsertDocuments')->willReturnCallback(
            static function (string $collection, array $documents, int $batchSize = 0, ?callable $onNext = null) use (&$written): int {
                foreach ($documents as $document) {
                    $written[] = $document;
                    $onNext && $onNext($document);
                }

                return \count($documents);
            }
        );

        $transactionState = $this->createStub(TransactionState::class);
        (new Upsert())->action(
            databaseId: self::DATABASE_ID,
            collectionId: 'albums',
            documentId: self::ALBUM_ID,
            data: [
                'name' => 'Album',
                'tracks' => [
                    ['$id' => 'track1', 'genres' => [['$id' => 'rock']]],
                    ['$id' => 'track2'],
                    'track3',
                ],
            ],
            permissions: null,
            transactionId: null,
            requestTimestamp: null,
            response: $this->createStub(Response::class),
            user: new User(),
            dbForProject: $this->projectDatabase(),
            getDatabasesDB: static fn (): Database => $tenant,
            queueForEvents: $this->createStub(Event::class),
            usage: $this->usage,
            transactionState: $transactionState,
            plan: [],
            authorization: $this->authorization,
        );

        $this->assertCount(1, $written);
        $this->assertSame(4, $this->metric(METRIC_DATABASES_OPERATIONS_WRITES), 'the album, two tracks and rock');
    }

    public function testGetMetersEveryRelatedDocumentItReturns(): void
    {
        $operations = new Operations();
        $tenant = $this->tenant($operations);
        $this->seedGenre($tenant, 'rock');
        $this->seedAlbum($tenant, [['$id' => 'track1', 'genres' => ['rock']], ['$id' => 'track2']]);

        $response = $this->createMock(Response::class);
        $response->expects($this->once())->method('addHeader')->with('X-Debug-Operations', '5');

        $this->get($tenant, [Query::select(['*', 'tracks.*', 'tracks.genres.*'])->toString()], $response, $operations);

        $this->assertSame(5, $this->metric(METRIC_DATABASES_OPERATIONS_READS), 'the album, two tracks, rock and the empty genre list of track2');
    }

    public function testGetWithoutRelationshipsMetersOneRead(): void
    {
        $operations = new Operations();
        $tenant = $this->tenant($operations);
        $this->seedAlbum($tenant, ['track1', 'track2']);

        $this->get($tenant, [], $this->createStub(Response::class), $operations);

        $this->assertSame(1, $this->metric(METRIC_DATABASES_OPERATIONS_READS));
    }

    public function testListMetersEveryRelatedDocumentItReturns(): void
    {
        $operations = new Operations();
        $tenant = $this->tenant($operations);
        $this->seedAlbum($tenant, ['track1', 'track2']);
        $this->seedAlbum($tenant, ['track3'], 'album2');
        $this->seedAlbum($tenant, [], 'album3');

        $this->list($tenant, [Query::select(['*', 'tracks.*'])->toString()], $operations);

        $this->assertSame(7, $this->metric(METRIC_DATABASES_OPERATIONS_READS), 'three albums, three tracks and the empty track list of album3');
    }

    public function testListDoesNotMeterTheCursorDocument(): void
    {
        $operations = new Operations();
        $tenant = $this->tenant($operations);
        $this->seedAlbum($tenant, ['track1', 'track2']);
        $this->seedAlbum($tenant, ['track3'], 'album2');

        $this->list($tenant, [
            Query::select(['*', 'tracks.*'])->toString(),
            Query::cursorAfter(self::ALBUM_ID)->toString(),
        ], $operations);

        $this->assertSame(2, $this->metric(METRIC_DATABASES_OPERATIONS_READS), 'album2 and track3, not the cursor album');
    }

    public function testEmptyListMetersOneRead(): void
    {
        $operations = new Operations();
        $this->list($this->tenant($operations), [], $operations);

        $this->assertSame(1, $this->metric(METRIC_DATABASES_OPERATIONS_READS));
    }

    public function testReadsFallBackToOnePerDocumentWhenTheHookHasNoCounter(): void
    {
        $tenant = $this->tenant();
        $this->seedAlbum($tenant, ['track1', 'track2']);
        $this->seedAlbum($tenant, ['track3'], 'album2');
        $query = [Query::select(['*', 'tracks.*'])->toString()];
        $operations = new Operations();

        $this->get($tenant, $query, $this->createStub(Response::class), $operations);
        $this->list($tenant, $query, $operations);

        $this->assertSame([1, 2], $this->metrics(METRIC_DATABASES_OPERATIONS_READS), 'a getDatabasesDB that never records meters one read per document, as before');
    }

    /**
     * @return iterable<string, array{Action}>
     */
    public static function relationshipReads(): iterable
    {
        yield 'get document' => [new Get()];
        yield 'list documents' => [new XList()];
        yield 'query documents' => [new QueryDocuments()];
        yield 'get row' => [new GetRow()];
        yield 'list rows' => [new ListRows()];
        yield 'query rows' => [new QueryRows()];
    }

    #[DataProvider('relationshipReads')]
    public function testRelationshipReadsReceiveTheRequestCounter(Action $action): void
    {
        $arguments = \array_keys($action->getOptions());
        $parameters = \array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionMethod($action, 'action'))->getParameters(),
        );

        $this->assertContains('injection:operations', $arguments, 'the route must inject the request counter');
        $this->assertSame(
            \array_search('operations', $parameters, true),
            \array_search('injection:operations', $arguments, true),
            'the counter must arrive in the action\'s operations parameter',
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function update(Database $tenant, array $data): void
    {
        (new Update())->action(
            databaseId: self::DATABASE_ID,
            collectionId: 'albums',
            documentId: self::ALBUM_ID,
            data: $data,
            permissions: null,
            transactionId: null,
            requestTimestamp: null,
            response: $this->createStub(Response::class),
            dbForProject: $this->projectDatabase(),
            getDatabasesDB: static fn (): Database => $tenant,
            queueForEvents: $this->createStub(Event::class),
            usage: $this->usage,
            transactionState: $this->createStub(TransactionState::class),
            plan: [],
            authorization: $this->authorization,
            user: new User(),
        );
    }

    /**
     * @param array<string, mixed>|null $data
     * @param array<array<string, mixed>>|null $documents
     */
    private function create(Database $tenant, string $collectionId, ?array $data = null, ?array $documents = null): void
    {
        (new Create())->action(
            databaseId: self::DATABASE_ID,
            documentId: $data === null ? null : self::ALBUM_ID,
            collectionId: $collectionId,
            data: $data,
            permissions: null,
            documents: $documents,
            transactionId: null,
            response: $this->createStub(Response::class),
            dbForProject: $this->projectDatabase(),
            getDatabasesDB: static fn (): Database => $tenant,
            user: new User(),
            queueForEvents: $this->createStub(Event::class),
            usage: $this->usage,
            queueForRealtime: $this->createStub(Event::class),
            publisherForFunctions: $this->createStub(FunctionPublisher::class),
            queueForWebhooks: $this->createStub(Event::class),
            plan: [],
            authorization: $this->authorization,
            eventProcessor: $this->createStub(EventProcessor::class),
        );
    }

    /**
     * @param array<string> $queries
     */
    private function get(Database $tenant, array $queries, Response $response, Operations $operations): void
    {
        (new Get())->action(
            databaseId: self::DATABASE_ID,
            collectionId: 'albums',
            documentId: self::ALBUM_ID,
            queries: $queries,
            transactionId: null,
            response: $response,
            dbForProject: $this->projectDatabase(),
            getDatabasesDB: static fn (): Database => $tenant,
            usage: $this->usage,
            transactionState: $this->createStub(TransactionState::class),
            authorization: $this->authorization,
            user: new User(),
            operations: $operations,
        );
    }

    /**
     * @param array<string> $queries
     */
    private function list(Database $tenant, array $queries, Operations $operations): void
    {
        (new XList())->action(
            databaseId: self::DATABASE_ID,
            collectionId: 'albums',
            queries: $queries,
            transactionId: null,
            includeTotal: true,
            ttl: 0,
            response: $this->createStub(Response::class),
            dbForProject: $this->projectDatabase(),
            user: new User(),
            getDatabasesDB: static fn (): Database => $tenant,
            usage: $this->usage,
            transactionState: $this->createStub(TransactionState::class),
            authorization: $this->authorization,
            operations: $operations,
        );
    }

    private function metric(string $key): int
    {
        $values = $this->metrics($key);
        $this->assertCount(1, $values, 'the request must meter ' . $key . ' once');

        return $values[0];
    }

    /**
     * @return list<int>
     */
    private function metrics(string $key): array
    {
        return \array_values(\array_map(
            static fn (array $metric): int => $metric['value'],
            \array_filter($this->usage->getMetrics(), static fn (array $metric): bool => $metric['key'] === $key),
        ));
    }

    private function projectDatabase(): Database
    {
        $database = $this->createStub(Database::class);
        $database->method('getDocument')->willReturnCallback(
            fn (string $collection, string $id): Document => $this->metadata[$collection][$id] ?? new Document()
        );

        return $database;
    }

    private function tenant(?Operations $operations = null): Database
    {
        $tenant = (new Database(new Memory(), new Cache(new NoCache())))
            ->setDatabase('metering')
            ->setNamespace('operations')
            ->setAuthorization($this->authorization);

        $this->authorization->skip(function () use ($tenant): void {
            $tenant->create();
            $permissions = [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ];
            foreach (\array_keys(self::PUBLIC_IDS) as $collection) {
                $tenant->createCollection(new Collection(
                    id: $collection,
                    attributes: [Attribute::string(key: 'name', size: 100, required: false)],
                    permissions: $permissions,
                ));
            }
            $tenant->addHook(new Permissions());
            $tenant->addHook(new Relationships($tenant));
            $tenant->createRelationship(new Relationship(
                collection: self::ALBUMS,
                relatedCollection: self::TRACKS,
                type: RelationType::OneToMany,
                twoWay: true,
                key: 'tracks',
                twoWayKey: 'album',
            ));
            $tenant->createRelationship(new Relationship(
                collection: self::TRACKS,
                relatedCollection: self::GENRES,
                type: RelationType::ManyToMany,
                key: 'genres',
            ));
        });

        $resolvePublicId = static fn (string $internalId): string => self::PUBLIC_IDS[$internalId] ?? $internalId;
        $tenant->addHook($operations === null
            ? new Metadata(database: $this->database, resolvePublicId: $resolvePublicId, tenant: $tenant)
            : new Metadata(database: $this->database, resolvePublicId: $resolvePublicId, tenant: $tenant, operations: $operations));

        return $tenant;
    }

    /**
     * @param array<string|array<string, mixed>> $tracks new tracks, by ID or as documents
     */
    private function seedAlbum(Database $tenant, array $tracks, string $id = self::ALBUM_ID): void
    {
        $this->authorization->skip(fn () => $tenant->createDocument(self::ALBUMS, new Document([
            '$id' => $id,
            'name' => $id,
            'tracks' => \array_map(static fn (string|array $track): array => \is_string($track) ? ['$id' => $track] : $track, $tracks),
        ])));
    }

    private function seedGenre(Database $tenant, string $id): void
    {
        $this->authorization->skip(fn () => $tenant->createDocument(self::GENRES, new Document([
            '$id' => $id,
            'name' => $id,
        ])));
    }

    /**
     * @param list<Document> $attributes
     */
    private static function collection(string $id, string $sequence, array $attributes): Document
    {
        return new Document([
            '$id' => $id,
            '$sequence' => $sequence,
            'enabled' => true,
            'documentSecurity' => false,
            'attributes' => $attributes,
        ]);
    }

    private static function attribute(string $key): Document
    {
        return new Document([
            '$id' => $key,
            'key' => $key,
            'type' => ColumnType::String->value,
            'size' => 100,
            'required' => false,
            'array' => false,
        ]);
    }

    private static function relationship(string $key, string $relatedCollection, RelationType $type, string $twoWayKey, string $side): Document
    {
        return new Document([
            '$id' => $key,
            'key' => $key,
            'type' => ColumnType::Relationship->value,
            'relatedCollection' => $relatedCollection,
            'relationType' => $type->value,
            'twoWay' => $twoWayKey !== '',
            'twoWayKey' => $twoWayKey,
            'onDelete' => 'setNull',
            'side' => $side,
        ]);
    }
}
