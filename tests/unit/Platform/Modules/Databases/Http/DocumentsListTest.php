<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http;

use Appwrite\Databases\TransactionState;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\XList;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Databases\ListCacheTestCache;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;

require_once __DIR__ . '/../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

/**
 * The database library reads aggregate and groupBy queries through aggregate(), and find() refuses them, so a list
 * request that aggregates has to be routed there on every read path: uncached, and on a cache miss.
 */
final class DocumentsListTest extends TestCase
{
    private const string DATABASE_ID = 'library';

    private const string COLLECTION_ID = 'movies';

    private const string TABLE = 'database_1_collection_1';

    private Authorization $authorization;

    private Database $store;

    private Database $dbForProject;

    protected function setUp(): void
    {
        $this->authorization = new Authorization();
        $this->authorization->addRole(Role::any()->toString());

        $this->store = new Database(new SQLite(new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])), new Cache(new None()));
        $this->store
            ->setAuthorization($this->authorization)
            ->setDatabase('documentsList')
            ->setNamespace('documents_list_' . \uniqid());
        $this->store->create();
        $this->store->createCollection(Collection::create(
            id: self::TABLE,
            attributes: [Attribute::string('genre', size: 32), Attribute::integer('year')],
            permissions: [Permission::read(Role::any()), Permission::create(Role::any())],
        ));
        foreach ([['drama', 2000], ['drama', 2002], ['comedy', 1990]] as [$genre, $year]) {
            $this->store->createDocument(self::TABLE, new Document(['genre' => $genre, 'year' => $year]));
        }

        $database = new Document([
            '$id' => self::DATABASE_ID,
            '$sequence' => '1',
            'type' => DATABASE_TYPE_LEGACY,
            'enabled' => true,
        ]);
        $collection = new Document([
            '$id' => self::COLLECTION_ID,
            '$sequence' => '1',
            'enabled' => true,
            'attributes' => [],
            'indexes' => [],
        ]);

        $cache = new ListCacheTestCache();
        $this->dbForProject = $this->createStub(Database::class);
        $this->dbForProject->method('getDocument')->willReturnCallback(
            static fn (string $collectionId): Document => match ($collectionId) {
                'databases' => $database,
                'database_1' => $collection,
                default => new Document(),
            }
        );
        $this->dbForProject->method('getCache')->willReturn($cache);
        $this->dbForProject->method('getAuthorization')->willReturn($this->authorization);
    }

    public function testListReturnsDocuments(): void
    {
        [$listed] = $this->list([Query::equal('genre', ['drama'])->toString()], ttl: 0);

        $this->assertSame(2, $listed->getAttribute('total'));
        $this->assertSame(['drama', 'drama'], \array_map(
            static fn (Document $document): mixed => $document->getAttribute('genre'),
            $listed->getAttribute('documents'),
        ));
    }

    public function testAggregateListReturnsOneRowOfAggregates(): void
    {
        [$listed] = $this->list([
            Query::count('*', 'movies')->toString(),
            Query::sum('year', 'years')->toString(),
        ], ttl: 0);

        $this->assertSame(3, $listed->getAttribute('total'), 'total counts the rows the aggregates read, as before');
        $this->assertSame([['movies' => 3, 'years' => 5992]], $this->rows($listed));
    }

    public function testGroupedListReturnsOneRowPerGroup(): void
    {
        [$listed] = $this->list([
            Query::groupBy(['genre'])->toString(),
            Query::count('*', 'movies')->toString(),
            Query::orderAsc('genre')->toString(),
        ], ttl: 0);

        $this->assertSame([['genre' => 'comedy', 'movies' => 1], ['genre' => 'drama', 'movies' => 2]], $this->rows($listed));
    }

    public function testCachedAggregateListReadsAggregatesOnAMissAndServesThemOnAHit(): void
    {
        $queries = [
            Query::groupBy(['genre'])->toString(),
            Query::count('*', 'movies')->toString(),
            Query::orderAsc('genre')->toString(),
        ];
        $expected = [['genre' => 'comedy', 'movies' => 1], ['genre' => 'drama', 'movies' => 2]];

        [$miss, $missHeaders] = $this->list($queries, ttl: 60);
        [$hit, $hitHeaders] = $this->list($queries, ttl: 60);

        $this->assertSame(['X-Appwrite-Cache' => 'miss'], $missHeaders);
        $this->assertSame($expected, $this->rows($miss));
        $this->assertSame(['X-Appwrite-Cache' => 'hit'], $hitHeaders);
        $this->assertSame($expected, $this->rows($hit));
    }

    /**
     * @param list<string> $queries
     * @return array{Document, array<string, string>}
     */
    private function list(array $queries, int $ttl): array
    {
        $listed = null;
        $headers = [];
        $response = $this->createStub(Response::class);
        $response->method('dynamic')->willReturnCallback(static function (Document $document) use (&$listed): void {
            $listed = $document;
        });
        $response->method('addHeader')->willReturnCallback(static function (string $key, string $value) use (&$headers, $response): Response {
            $headers[$key] = $value;

            return $response;
        });

        $getDatabasesDB = fn (): Database => $this->store;

        (new XList())->action(
            self::DATABASE_ID,
            self::COLLECTION_ID,
            $queries,
            null,
            true,
            $ttl,
            $response,
            $this->dbForProject,
            new User(),
            $getDatabasesDB,
            new Context(),
            new TransactionState($this->dbForProject, $this->authorization, $getDatabasesDB, new User()),
            $this->authorization,
        );

        $this->assertInstanceOf(Document::class, $listed);

        return [$listed, $headers];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(Document $listed): array
    {
        return \array_map(
            static fn (Document $row): array => \array_filter(
                ['genre' => $row->getAttribute('genre'), 'movies' => $row->getAttribute('movies'), 'years' => $row->getAttribute('years')],
                static fn (mixed $value): bool => $value !== null,
            ),
            $listed->getAttribute('documents'),
        );
    }
}
