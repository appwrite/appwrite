<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\ListCache;
use Appwrite\Usage\Operations;
use Appwrite\Utopia\Database\Hooks\Metadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
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

/**
 * A list served from the `ttl` list cache returns the related documents the list returned when it was read from the
 * database, so it must meter the same `databases.operations.reads`: cloud bills them.
 */
final class ListCacheTest extends TestCase
{
    private const string KEY = 'list-cache';
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

    private ListCacheTestCache $cache;

    protected function setUp(): void
    {
        $this->authorization = new Authorization();
        $this->authorization->addRole(Role::any()->toString());
        $this->adapter = new Memory();
        $this->database = new Document(['$id' => 'library', '$sequence' => '1']);
        $this->cache = new ListCacheTestCache();

        $this->seed();
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function relatedLists(): iterable
    {
        yield 'one-to-many' => [self::ALBUMS, ['*', 'tracks.*']];
        yield 'many-to-one' => [self::TRACKS, ['*', 'album.*', 'artist.*']];
        yield 'many-to-one at depth 2' => [self::ALBUMS, ['*', 'tracks.*', 'tracks.artist.*']];
        yield 'one-to-many at depth 2' => [self::LABELS, ['*', 'albums.*', 'albums.tracks.*']];
    }

    /**
     * @param list<string> $select
     */
    #[DataProvider('relatedLists')]
    public function testAHitMetersTheReadsOfTheMissThatCachedIt(string $collection, array $select): void
    {
        $queries = [Query::select($select)];
        $miss = new Operations();
        $hit = new Operations();

        $before = $this->listCache($queries)->documents(self::TTL, $miss);
        $documents = $this->tenant($miss)->find($collection, $queries);
        $reads = $miss->reads($documents);
        $this->listCache($queries)->saveDocuments($documents, $miss);
        $cached = $this->listCache($queries)->documents(self::TTL, $hit);

        $this->assertNull($before, 'a list is read from the database until a miss caches it');
        $this->assertGreaterThan(\count($documents), $reads, 'the list must return related documents');
        $this->assertSame($reads, $miss->reads($documents), 'caching a list does not change what its miss meters');
        $this->assertNotNull($cached, 'the miss caches the list');
        $this->assertSame(self::copies($documents), self::copies($cached), 'the hit returns the documents the miss cached');
        $this->assertSame($reads, $hit->reads($cached), 'the hit meters every related document it returns, as the miss did');
    }

    public function testAHitOnAnEntryWithoutOperationsMetersOneReadPerDocument(): void
    {
        $queries = [Query::select(['*', 'tracks.*'])];
        $miss = new Operations();
        $documents = $this->tenant($miss)->find(self::ALBUMS, $queries);
        $this->listCache($queries)->saveDocuments($documents, $miss);
        foreach ($this->fields(ListCache::OPERATIONS) as $field) {
            unset($this->cache->entries[self::KEY][$field]);
        }
        $hit = new Operations();

        $cached = $this->listCache($queries)->documents(self::TTL, $hit);

        $this->assertGreaterThan(\count($documents), $miss->reads($documents), 'the list must return related documents');
        $this->assertNotNull($cached);
        $this->assertSame(self::copies($documents), self::copies($cached));
        $this->assertSame(\count($cached), $hit->reads($cached), 'an entry cached before the operations were stored meters one read per document, as before');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function mismatchedOperations(): iterable
    {
        yield 'fewer counts than documents' => [[4]];
        yield 'more counts than documents' => [[3, 2, 2, 1]];
        yield 'counts that are not integers' => [['3', '2', '1']];
        yield 'a count below one' => [[3, 0, 1]];
        yield 'counts keyed by name' => [['album1' => 3, 'album2' => 2, 'album3' => 1]];
        yield 'not a list' => ['3,2,1'];
    }

    #[DataProvider('mismatchedOperations')]
    public function testAHitWithOperationsThatDoNotFitItsDocumentsMetersOneReadPerDocument(mixed $counts): void
    {
        $queries = [Query::select(['*', 'tracks.*'])];
        $miss = new Operations();
        $documents = $this->tenant($miss)->find(self::ALBUMS, $queries);
        $this->listCache($queries)->saveDocuments($documents, $miss);
        $operations = $this->fields(ListCache::OPERATIONS);
        foreach ($operations as $field) {
            $this->cache->entries[self::KEY][$field] = $counts;
        }
        $hit = new Operations();

        $cached = $this->listCache($queries)->documents(self::TTL, $hit);

        $this->assertCount(1, $this->fields(ListCache::DOCUMENTS));
        $this->assertCount(1, $operations, 'the miss caches the operations of its documents next to them');
        $this->assertNotNull($cached);
        $this->assertSame(\count($cached), $hit->reads($cached));
    }

    public function testAnEmptyListIsNotCached(): void
    {
        $queries = [Query::equal('$id', ['missing'])];

        $this->listCache($queries)->saveDocuments([], new Operations());

        $this->assertSame([], $this->cache->entries, 'an empty list writes nothing');
        $this->assertNull($this->listCache($queries)->documents(self::TTL, new Operations()), 'an empty list is read from the database again');
    }

    public function testTheTotalIsCachedForTheVariation(): void
    {
        $queries = [Query::limit(2)];

        $before = $this->listCache($queries)->total(self::TTL);
        $this->listCache($queries)->saveDocuments([new Document(['$id' => 'album1'])], new Operations());
        $this->listCache($queries)->saveTotal(3);

        $this->assertNull($before, 'a total is counted until it is cached');
        $this->assertSame(3, $this->listCache($queries)->total(self::TTL));
        $this->assertCount(1, $this->listCache($queries)->documents(self::TTL, new Operations()) ?? [], 'the total is cached next to the list, not over it');
    }

    /**
     * @return iterable<string, array{Document, list<string>, list<Query>}>
     */
    public static function otherVariations(): iterable
    {
        yield 'other roles' => [self::collection(), [Role::any()->toString(), Role::users()->toString()], [Query::limit(2)]];
        yield 'other queries' => [self::collection(), [Role::any()->toString()], [Query::limit(3)]];
        yield 'other schema' => [self::collection([new Document(['$id' => 'year', 'key' => 'year', 'type' => 'integer'])]), [Role::any()->toString()], [Query::limit(2)]];
    }

    /**
     * @param list<string> $roles
     * @param list<Query> $queries
     */
    #[DataProvider('otherVariations')]
    public function testEachVariationHasItsOwnFields(Document $collection, array $roles, array $queries): void
    {
        $cached = new ListCache($this->cache, self::KEY, self::collection(), [Role::any()->toString()], [Query::limit(2)]);
        $cached->saveDocuments([new Document(['$id' => 'album1'])], new Operations());
        $cached->saveTotal(1);
        $same = new ListCache($this->cache, self::KEY, self::collection(), [Role::any()->toString()], [Query::limit(2)]);
        $other = new ListCache($this->cache, self::KEY, $collection, $roles, $queries);

        $this->assertNotNull($same->documents(self::TTL, new Operations()), 'the same variation reads the cached list');
        $this->assertSame(1, $same->total(self::TTL));
        $this->assertNull($other->documents(self::TTL, new Operations()), 'another variation does not read the list cached for this one');
        $this->assertNull($other->total(self::TTL), 'another variation does not read the total cached for this one');
    }

    public function testTheKeyAndFieldsKeepTheirFormat(): void
    {
        $project = (new Database(new Memory(), new Cache(new None())))
            ->setCacheName('project')
            ->setNamespace('_1')
            ->setTenant(7);
        $project->getAdapter()->setHostname('db1');
        $collection = self::collection([new Document(['$id' => 'name', 'key' => 'name', 'type' => 'string', 'size' => 100])]);
        $key = ListCache::key($project, 'albums');
        $cache = new ListCache($this->cache, $key, $collection, ['any'], [Query::limit(2)]);

        $cache->saveDocuments([new Document(['$id' => 'album1'])], new Operations());
        $cache->saveTotal(1);

        $this->assertSame('project-cache:db1:_1:7:collection:albums', $key, 'the purge deletes the hash that lists were cached in before a deploy');
        $this->assertSame([
            '7f26239cc080b84d8de167ea674bff49:f6b3719697da5b47b8d2c8dfd9a5c6b3:aeb4d9b8240ec54f89388c785c81c04f:documents',
            '7f26239cc080b84d8de167ea674bff49:f6b3719697da5b47b8d2c8dfd9a5c6b3:aeb4d9b8240ec54f89388c785c81c04f:operations',
            '7f26239cc080b84d8de167ea674bff49:f6b3719697da5b47b8d2c8dfd9a5c6b3:aeb4d9b8240ec54f89388c785c81c04f:total',
        ], \array_keys($this->cache->entries[$key] ?? []), 'lists cached before a deploy are read after it');
    }

    /**
     * @param list<Query> $queries
     */
    private function listCache(array $queries): ListCache
    {
        return new ListCache($this->cache, self::KEY, self::collection(), $this->authorization->getRoles(), $queries);
    }

    /**
     * @return list<string>
     */
    private function fields(string $type): array
    {
        return \array_values(\array_filter(
            \array_keys($this->cache->entries[self::KEY] ?? []),
            static fn (string $field): bool => \str_ends_with($field, ':' . $type),
        ));
    }

    /**
     * @param array<Document> $documents
     * @return list<array<string, mixed>>
     */
    private static function copies(array $documents): array
    {
        return \array_values(\array_map(static fn (Document $document): array => $document->getArrayCopy(), $documents));
    }

    /**
     * @param list<Document> $attributes
     */
    private static function collection(array $attributes = []): Document
    {
        return new Document(['$id' => 'albums', 'attributes' => $attributes, 'indexes' => []]);
    }

    private function tenant(?Operations $operations = null): Database
    {
        $tenant = (new Database($this->adapter, new Cache(new None())))
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
