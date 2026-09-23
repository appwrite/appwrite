<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http\Documents;

use Appwrite\Databases\TransactionState;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\XList;
use Appwrite\Usage\Context;
use Appwrite\Usage\Operations;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Hooks\Metadata;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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

require_once __DIR__ . '/../../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

/**
 * A list served from the `ttl` list cache returns the related documents the list returned when it was read from the
 * database, so it must meter the same `databases.operations.reads`: cloud bills them.
 */
final class CachedListMeteringTest extends TestCase
{
    private const string DATABASE_ID = 'library';
    private const string ALBUMS = 'database_1_collection_1';
    private const string TRACKS = 'database_1_collection_2';
    private const string ARTISTS = 'database_1_collection_3';
    private const string LABELS = 'database_1_collection_4';
    private const array PUBLIC_IDS = [
        self::ALBUMS => 'albums',
        self::TRACKS => 'tracks',
        self::ARTISTS => 'artists',
        self::LABELS => 'labels',
    ];
    private const int TTL = 60;

    private Authorization $authorization;

    private Memory $adapter;

    private Document $database;

    /**
     * @var array<string, array<string, Document>>
     */
    private array $metadata;

    /**
     * @var array<string, array<string, mixed>> cache key => hash field => value
     */
    private array $cache = [];

    protected function setUp(): void
    {
        $this->authorization = new Authorization();
        $this->authorization->addRole(User::ROLE_KEYS);
        $this->authorization->addRole(Role::any()->toString());
        $this->adapter = new Memory();

        $this->database = new Document([
            '$id' => self::DATABASE_ID,
            '$sequence' => '1',
            'type' => DATABASE_TYPE_LEGACY,
            'enabled' => true,
        ]);

        $this->metadata = ['databases' => [self::DATABASE_ID => $this->database]];
        foreach (self::PUBLIC_IDS as $internalId => $publicId) {
            $this->metadata['database_1'][$publicId] = new Document([
                '$id' => $publicId,
                '$sequence' => \substr($internalId, \strlen('database_1_collection_')),
                'enabled' => true,
                'documentSecurity' => false,
                'attributes' => [],
            ]);
        }

        $this->seed();
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function relatedLists(): iterable
    {
        yield 'one-to-many' => ['albums', ['*', 'tracks.*']];
        yield 'many-to-one' => ['tracks', ['*', 'album.*', 'artist.*']];
        yield 'many-to-one at depth 2' => ['albums', ['*', 'tracks.*', 'tracks.artist.*']];
        yield 'one-to-many at depth 2' => ['labels', ['*', 'albums.*', 'albums.tracks.*']];
    }

    /**
     * @param list<string> $select
     */
    #[DataProvider('relatedLists')]
    public function testCacheHitMetersTheReadsOfTheMiss(string $collectionId, array $select): void
    {
        $queries = [Query::select($select)->toString()];

        $uncached = $this->list($collectionId, $queries, 0);
        $miss = $this->list($collectionId, $queries, self::TTL);
        $hit = $this->list($collectionId, $queries, self::TTL);

        $this->assertSame('miss', $miss['cache']);
        $this->assertSame('hit', $hit['cache']);
        $this->assertSame($miss['documents'], $hit['documents'], 'the hit returns the documents the miss cached');
        $this->assertGreaterThan(\count($miss['documents']), $miss['reads'], 'the list must return related documents');
        $this->assertSame($uncached['reads'], $miss['reads'], 'caching a list does not change what the miss meters');
        $this->assertSame($miss['reads'], $hit['reads'], 'the hit meters every related document it returns, as the miss did');
    }

    public function testCacheHitOnAnEntryWithoutOperationsMetersOneReadPerDocument(): void
    {
        $queries = [Query::select(['*', 'tracks.*'])->toString()];
        $miss = $this->list('albums', $queries, self::TTL);
        $this->cache = \array_map(
            static fn (array $fields): array => \array_filter(
                $fields,
                static fn (string $field): bool => \str_ends_with($field, ':' . XList::LIST_CACHE_FIELD_DOCUMENTS)
                    || \str_ends_with($field, ':' . XList::LIST_CACHE_FIELD_TOTAL),
                ARRAY_FILTER_USE_KEY,
            ),
            $this->cache,
        );

        $hit = $this->list('albums', $queries, self::TTL);

        $this->assertSame('hit', $hit['cache']);
        $this->assertSame($miss['documents'], $hit['documents']);
        $this->assertSame(\count($hit['documents']), $hit['reads'], 'an entry cached before the operations were stored meters one read per document, as before');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function mismatchedOperations(): iterable
    {
        yield 'fewer counts than documents' => [[4]];
        yield 'more counts than documents' => [[1, 1, 1, 1]];
        yield 'counts that are not integers' => [['3', '2', '1']];
        yield 'a count below one' => [[3, 0, 1]];
        yield 'counts keyed by name' => [['album1' => 3, 'album2' => 2, 'album3' => 1]];
        yield 'not a list' => ['3,2,1'];
    }

    #[DataProvider('mismatchedOperations')]
    public function testCacheHitWithOperationsThatDoNotFitItsDocumentsMetersOneReadPerDocument(mixed $operations): void
    {
        $queries = [Query::select(['*', 'tracks.*'])->toString()];
        $this->list('albums', $queries, self::TTL);
        $replaced = 0;
        foreach ($this->cache as $key => $fields) {
            foreach (\array_keys($fields) as $field) {
                if (!\str_ends_with($field, ':' . XList::LIST_CACHE_FIELD_OPERATIONS)) {
                    continue;
                }
                $this->cache[$key][$field] = $operations;
                $replaced++;
            }
        }
        $this->assertSame(1, $replaced, 'the miss caches the operations of its documents next to them');

        $hit = $this->list('albums', $queries, self::TTL);

        $this->assertSame('hit', $hit['cache']);
        $this->assertSame(\count($hit['documents']), $hit['reads']);
    }

    public function testEmptyListIsNotCached(): void
    {
        $queries = [Query::equal('$id', ['missing'])->toString(), Query::select(['*', 'tracks.*'])->toString()];

        $first = $this->list('albums', $queries, self::TTL);
        $second = $this->list('albums', $queries, self::TTL);

        $this->assertSame(['miss', 'miss'], [$first['cache'], $second['cache']]);
        $this->assertSame([1, 1], [$first['reads'], $second['reads']]);
    }

    /**
     * @param list<string> $queries
     * @return array{reads: int, cache: ?string, documents: list<array<string, mixed>>}
     */
    private function list(string $collectionId, array $queries, int $ttl): array
    {
        $operations = new Operations();
        $usage = new Context();
        $headers = [];
        $documents = [];
        $response = $this->createStub(Response::class);
        $response->method('addHeader')->willReturnCallback(
            static function (string $key, string $value) use (&$headers, $response): Response {
                $headers[$key] = $value;

                return $response;
            }
        );
        $response->method('dynamic')->willReturnCallback(
            static function (Document $list) use (&$documents): void {
                $documents = \array_map(
                    static fn (Document $document): array => $document->getArrayCopy(),
                    $list->getAttribute('documents'),
                );
            }
        );

        (new XList())->action(
            databaseId: self::DATABASE_ID,
            collectionId: $collectionId,
            queries: $queries,
            transactionId: null,
            includeTotal: true,
            ttl: $ttl,
            response: $response,
            dbForProject: $this->projectDatabase(),
            user: new User(),
            getDatabasesDB: fn (): Database => $this->tenant($operations),
            usage: $usage,
            transactionState: $this->createStub(TransactionState::class),
            authorization: $this->authorization,
            operations: $operations,
        );

        $reads = \array_values(\array_filter(
            $usage->getMetrics(),
            static fn (array $metric): bool => $metric['key'] === METRIC_DATABASES_OPERATIONS_READS,
        ));
        $this->assertCount(1, $reads, 'a list meters its reads once');

        return [
            'reads' => $reads[0]['value'],
            'cache' => $headers['X-Appwrite-Cache'] ?? null,
            'documents' => $documents,
        ];
    }

    private function projectDatabase(): Database
    {
        $cache = $this->createStub(Cache::class);
        $cache->method('load')->willReturnCallback(
            fn (string $key, int $ttl, string $hash = ''): mixed => $this->cache[$key][$hash] ?? false
        );
        $cache->method('save')->willReturnCallback(
            function (string $key, mixed $data, string $hash = ''): bool {
                if (empty($data)) {
                    return false;
                }
                $this->cache[$key][$hash] = $data;

                return true;
            }
        );
        $cache->method('saveMany')->willReturnCallback(
            function (string $key, array $data): array {
                foreach ($data as $field => $value) {
                    $this->cache[$key][$field] = $value;
                }

                return $data;
            }
        );

        $database = $this->createStub(Database::class);
        $database->method('getCache')->willReturn($cache);
        $database->method('getDocument')->willReturnCallback(
            fn (string $collection, string $id): Document => $this->metadata[$collection][$id] ?? new Document()
        );

        return $database;
    }

    private function tenant(?Operations $operations = null): Database
    {
        $tenant = (new Database($this->adapter, new Cache(new NoCache())))
            ->setDatabase('metering')
            ->setNamespace('cached')
            ->setAuthorization($this->authorization);
        $tenant->addHook(new Permissions());
        $tenant->addHook(new Relationships($tenant));

        if ($operations !== null) {
            $tenant->addHook(new Metadata(
                database: $this->database,
                resolvePublicId: static fn (string $internalId): string => self::PUBLIC_IDS[$internalId] ?? $internalId,
                tenant: $tenant,
                operations: $operations,
            ));
        }

        return $tenant;
    }

    private function seed(): void
    {
        $tenant = $this->tenant();
        $this->authorization->skip(function () use ($tenant): void {
            $tenant->create();
            foreach (\array_keys(self::PUBLIC_IDS) as $collection) {
                $tenant->createCollection(new Collection(
                    id: $collection,
                    attributes: [Attribute::string(key: 'name', size: 100, required: false)],
                    permissions: [Permission::read(Role::any()), Permission::create(Role::any())],
                ));
            }
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
                relatedCollection: self::ARTISTS,
                type: RelationType::ManyToOne,
                key: 'artist',
            ));
            $tenant->createRelationship(new Relationship(
                collection: self::LABELS,
                relatedCollection: self::ALBUMS,
                type: RelationType::OneToMany,
                twoWay: true,
                key: 'albums',
                twoWayKey: 'label',
            ));

            foreach (['artist1', 'artist2'] as $artist) {
                $tenant->createDocument(self::ARTISTS, new Document(['$id' => $artist, 'name' => $artist]));
            }
            foreach ([
                'album1' => ['track1' => 'artist1', 'track2' => 'artist2'],
                'album2' => ['track3' => 'artist1'],
                'album3' => [],
            ] as $album => $tracks) {
                $tenant->createDocument(self::ALBUMS, new Document([
                    '$id' => $album,
                    'name' => $album,
                    'tracks' => \array_map(
                        static fn (string $track, string $artist): array => ['$id' => $track, 'name' => $track, 'artist' => $artist],
                        \array_keys($tracks),
                        $tracks,
                    ),
                ]));
            }
            $tenant->createDocument(self::LABELS, new Document(['$id' => 'label1', 'name' => 'label1', 'albums' => ['album1', 'album2']]));
            $tenant->createDocument(self::LABELS, new Document(['$id' => 'label2', 'name' => 'label2', 'albums' => []]));
        });
    }
}
