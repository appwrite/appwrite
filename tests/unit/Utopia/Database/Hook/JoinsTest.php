<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Hook;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Hook\Joins;
use Closure;
use Iterator;
use Override;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Permission;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;

final class JoinsTest extends TestCase
{
    private const string REGISTRY = 'database_1';

    private Authorization $authorization;

    /** @var list<string> */
    private array $reads = [];

    protected function setUp(): void
    {
        $this->authorization = new Authorization();
        $this->authorization->addRole(Role::any()->toString());
        $this->authorization->addRole(Role::users()->toString());
        $this->authorization->addRole(Role::user('reader')->toString());
        $this->reads = [];
    }

    #[DataProvider('resolvable')]
    public function testJoinReadsTheTableOfTheCollectionItNames(string $joined, string $table): void
    {
        $this->assertSame($table, $this->rewriteJoin($joined)->getAttribute());
    }

    /**
     * @return Iterator<string, array{string, string}>
     */
    public static function resolvable(): Iterator
    {
        yield 'readable at collection level' => ['shared', 'database_1_collection_2'];
        yield 'document security without collection read' => ['owned', 'database_1_collection_3'];
        yield 'without collection read or document security, which the database authorizes' => ['closed', 'database_1_collection_4'];
    }

    public function testJoinOfADisabledCollectionIsNotFound(): void
    {
        $refusal = $this->refusal('disabled');

        $this->assertSame(Exception::COLLECTION_NOT_FOUND, $refusal->getType());
        $this->assertSame(404, $refusal->getCode());
        $this->assertStringContainsString("'disabled'", $refusal->getMessage(), 'the refusal names the collection the caller joined');
    }

    public function testJoinOfADisabledTableIsTableNotFound(): void
    {
        $refusal = $this->refusal('disabled', $this->joins(notFound: static fn (): string => Exception::TABLE_NOT_FOUND));

        $this->assertSame(Exception::TABLE_NOT_FOUND, $refusal->getType(), 'the refusal names the resource the request serves');
        $this->assertSame(404, $refusal->getCode());
    }

    public function testNotFoundTypeIsOnlyResolvedWhenRefusing(): void
    {
        $joins = $this->joins(notFound: function (): string {
            $this->fail('the not-found type must not be resolved when nothing is refused');
        });

        $this->assertSame('database_1_collection_2', $this->rewriteJoin('shared', $joins)->getAttribute());
    }

    #[DataProvider('privilegedRoles')]
    public function testPrivilegedCallerJoinsADisabledCollection(string $role): void
    {
        $this->authorization->addRole($role);

        $this->assertSame('database_1_collection_5', $this->rewriteJoin('disabled')->getAttribute(), 'API keys and privileged users read every collection of their project');
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function privilegedRoles(): Iterator
    {
        yield 'key' => [User::ROLE_KEYS];
        yield 'owner' => [User::ROLE_OWNER];
    }

    public function testJoinByPhysicalNameIsLookedUpBySequence(): void
    {
        $this->assertSame('database_1_collection_3', $this->rewriteJoin('database_1_collection_3')->getAttribute());
        $this->assertSame(['getDocument', 'find'], $this->reads, 'a table name no collection is called by falls back to its sequence');
    }

    #[DataProvider('publicIdsShapedLikeTables')]
    public function testJoinByAPublicIdShapedLikeATableReadsTheTableOfTheCollectionCalledThat(string $joined, string $table): void
    {
        $this->assertSame($table, $this->rewriteJoin($joined)->getAttribute());
        $this->assertSame(['getDocument'], $this->reads, 'a collection called by the name is found without a sequence lookup');
    }

    /**
     * @return Iterator<string, array{string, string}>
     */
    public static function publicIdsShapedLikeTables(): Iterator
    {
        yield 'shaped like the table of another collection' => ['database_1_collection_2', 'database_1_collection_6'];
        yield 'shaped like the table of no collection' => ['database_1_collection_8', 'database_1_collection_7'];
    }

    public function testRewritingItsOwnOutputOfPublicIdsShapedLikeTablesChangesNothingAndReadsNothing(): void
    {
        $joins = $this->joins();
        $once = $joins->rewrite(Event::DocumentFind, $this->customers(), [$this->join('database_1_collection_2'), $this->join('database_1_collection_8')]);
        $reads = $this->reads;

        $twice = $joins->rewrite(Event::DocumentFind, $this->customers(), $once);

        $this->assertSame(['database_1_collection_6', 'database_1_collection_7'], $this->tables($twice));
        $this->assertSame($once, $twice, 'a join already pointing at its table is left as it is');
        $this->assertSame($reads, $this->reads, 'a table name resolves to itself without another registry read');
    }

    public function testRewritingItsOwnOutputKeepsATableAnotherCollectionIsCalledBy(): void
    {
        $joins = $this->joins();
        $once = $joins->rewrite(Event::DocumentFind, $this->customers(), [$this->join('shared')]);
        $reads = $this->reads;

        $twice = $joins->rewrite(Event::DocumentFind, $this->customers(), $once);

        $this->assertSame('database_1_collection_2', $twice[0]->getAttribute(), 'the table of shared is not taken for the collection called database_1_collection_2');
        $this->assertSame($reads, $this->reads, 'a table name resolves to itself without another registry read');
    }

    #[DataProvider('unknownPhysicalNames')]
    public function testJoinByPhysicalNameGetsTheSameChecks(string $joined): void
    {
        $this->assertSame(Exception::COLLECTION_NOT_FOUND, $this->refusal($joined)->getType());
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function unknownPhysicalNames(): Iterator
    {
        yield 'disabled' => ['database_1_collection_5'];
        yield 'unknown sequence' => ['database_1_collection_9'];
        yield 'not a sequence' => ['database_1_collection_x'];
        yield 'another database' => ['database_2_collection_2'];
    }

    public function testQueriesWithoutJoinsAreReturnedAsGiven(): void
    {
        $queries = [Query::equal('name', ['Ada']), Query::limit(5)];

        $this->assertSame($queries, $this->joins()->rewrite(Event::DocumentFind, $this->customers(), $queries));
        $this->assertSame([], $this->reads, 'a read without joins makes no registry read');
    }

    public function testCallerQueriesAreNotModified(): void
    {
        $join = $this->join('shared');

        $this->joins()->rewrite(Event::DocumentFind, $this->customers(), [$join]);

        $this->assertSame('shared', $join->getAttribute(), 'the caller keeps its own query');
    }

    public function testQueriesOtherThanJoinsAreReturnedAsTheSameInstances(): void
    {
        $filter = Query::equal('name', ['Ada']);
        $limit = Query::limit(5);

        $rewritten = $this->joins()->rewrite(Event::DocumentFind, $this->customers(), [$filter, $this->join('shared'), $limit]);

        $this->assertSame($filter, $rewritten[0]);
        $this->assertSame($limit, $rewritten[2]);
    }

    public function testRewritingItsOwnOutputChangesNothingAndReadsNothing(): void
    {
        $joins = $this->joins();
        $once = $joins->rewrite(Event::DocumentFind, $this->customers(), [$this->join('shared'), $this->join('owned')]);
        $reads = $this->reads;

        $twice = $joins->rewrite(Event::DocumentFind, $this->customers(), $once);

        $this->assertSame(['database_1_collection_2', 'database_1_collection_3'], $this->tables($twice));
        $this->assertSame($once, $twice, 'a join already pointing at its table is left as it is');
        $this->assertSame($reads, $this->reads, 'a table name resolves to itself without another registry read');
    }

    public function testJoinOfAPublicIdEqualToTheTableOfAnEarlierJoinReadsTheTableOfTheCollectionCalledThat(): void
    {
        $rewritten = $this->joins()->rewrite(Event::DocumentFind, $this->customers(), [$this->join('shared'), $this->join('database_1_collection_2', 'other')]);

        $this->assertSame(['database_1_collection_2', 'database_1_collection_6'], $this->tables($rewritten), 'the table of shared does not stand for the collection called by that name');
    }

    public function testJoinOfAPublicIdEqualToTheTableOfAnEarlierReadReadsTheTableOfTheCollectionCalledThat(): void
    {
        $joins = $this->joins();
        $this->rewriteJoin('shared', $joins);

        $this->assertSame('database_1_collection_6', $this->rewriteJoin('database_1_collection_2', $joins)->getAttribute(), 'an earlier read joining shared does not make its table stand for the collection called by that name');
    }

    public function testJoinOfAPublicIdKeepsItsTableAfterAReadOfTheCollectionWhoseTableItEquals(): void
    {
        $joins = $this->joins();
        $this->rewriteJoin('database_1_collection_2', $joins);
        $this->rewriteJoin('shared', $joins);

        $this->assertSame('database_1_collection_6', $this->rewriteJoin('database_1_collection_2', $joins)->getAttribute(), 'joining shared does not take over the name another collection is called by');
    }

    public function testRewritingItsOwnOutputOfJoinsOfATableAndThePublicIdEqualToItChangesNothingAndReadsNothing(): void
    {
        $joins = $this->joins();
        $once = $joins->rewrite(Event::DocumentFind, $this->customers(), [$this->join('shared'), $this->join('database_1_collection_2', 'other')]);
        $reads = $this->reads;

        $twice = $joins->rewrite(Event::DocumentFind, $this->customers(), $once);

        $this->assertSame(['database_1_collection_2', 'database_1_collection_6'], $this->tables($twice));
        $this->assertSame($once, $twice, 'a join already pointing at its table is left as it is');
        $this->assertSame($reads, $this->reads, 'a join the hook pointed at its table is not looked up again');
    }

    public function testRewrittenJoinNamingAnotherCollectionIsResolvedAgain(): void
    {
        $joins = $this->joins();
        $once = $joins->rewrite(Event::DocumentFind, $this->customers(), [$this->join('shared')]);
        $once[0]->setAttribute('owned');

        $this->assertSame('database_1_collection_3', $joins->rewrite(Event::DocumentFind, $this->customers(), $once)[0]->getAttribute(), 'a join changed after it was rewritten is resolved by the name it now holds');
    }

    public function testCursorOverAJoinPagesWithoutLookingTheJoinUpAgain(): void
    {
        $store = $this->shop();

        $rows = [];
        foreach ($store->cursor('database_1_collection_1', [$this->join('shared'), Query::select(['name', 'ord.$id', 'ord.total']), Query::orderAsc('ord.total')], batchSize: 1) as $row) {
            $rows[] = [$row->getId(), $row->getAttribute('ord.total')];
        }

        $this->assertSame([['ada', 10], ['bob', 15], ['ada', 20]], $rows, 'every page reads the table of shared');
        $this->assertSame(['getDocument'], $this->reads, 'each page after the first reuses the join the hook already pointed at its table');
    }

    public function testRepeatedJoinOfTheSameCollectionReadsTheRegistryOnce(): void
    {
        $joins = $this->joins();

        $this->rewriteJoin('shared', $joins);
        $this->rewriteJoin('shared', $joins);

        $this->assertSame(['getDocument'], $this->reads);
    }

    /**
     * @param  Closure(): string|null  $notFound
     */
    private function joins(?Closure $notFound = null): Joins
    {
        return new Joins(
            dbForProject: $this->registry(),
            database: new Document(['$id' => 'shop', '$sequence' => '1']),
            authorization: $this->authorization,
            user: new User(),
            notFound: $notFound ?? static fn (): string => Exception::COLLECTION_NOT_FOUND,
        );
    }

    private function join(string $joined, string $alias = 'ord'): Query
    {
        return Query::join($joined, $alias, [Query::on('$id', 'customerId')]);
    }

    /**
     * @param  array<Query>  $queries
     * @return list<string>
     */
    private function tables(array $queries): array
    {
        return \array_values(\array_map(static fn (Query $query): string => $query->getAttribute(), $queries));
    }

    private function shop(): Database
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::any()->toString());

        $store = new Database(new SQLite(new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])), new Cache(new None()));
        $store
            ->setAuthorization($authorization)
            ->setDatabase('joins')
            ->setNamespace('joins_' . \uniqid());
        $store->create();
        $store->createCollection(Collection::create(
            id: 'database_1_collection_1',
            attributes: [Attribute::string('name', size: 64)],
            permissions: [Permission::read(Role::any()), Permission::create(Role::any())],
        ));
        $store->createCollection(Collection::create(
            id: 'database_1_collection_2',
            attributes: [Attribute::string('customerId', size: 64), Attribute::integer('total')],
            permissions: [Permission::read(Role::any()), Permission::create(Role::any())],
        ));
        foreach (['ada' => 'Ada', 'bob' => 'Bob'] as $id => $name) {
            $store->createDocument('database_1_collection_1', new Document(['$id' => $id, 'name' => $name]));
        }
        foreach ([['ada', 10], ['bob', 15], ['ada', 20]] as [$customerId, $total]) {
            $store->createDocument('database_1_collection_2', new Document(['customerId' => $customerId, 'total' => $total]));
        }

        return $store->addHook($this->joins());
    }

    private function customers(): Collection
    {
        return Collection::create(id: 'database_1_collection_1');
    }

    private function rewriteJoin(string $joined, ?Joins $joins = null): Query
    {
        $rewritten = ($joins ?? $this->joins())->rewrite(Event::DocumentFind, $this->customers(), [$this->join($joined)]);

        $this->assertCount(1, $rewritten);

        return $rewritten[0];
    }

    private function refusal(string $joined, ?Joins $joins = null): Exception
    {
        try {
            $this->rewriteJoin($joined, $joins);
        } catch (Exception $refusal) {
            return $refusal;
        }

        $this->fail('the join of ' . $joined . ' must be refused');
    }

    private function registry(): Database
    {
        $record = function (string $method): void {
            $this->reads[] = $method;
        };

        return new class ([self::REGISTRY => [
            self::collection('customers', '1', [Permission::read(Role::any())], documentSecurity: true),
            self::collection('shared', '2', [Permission::read(Role::any())], documentSecurity: false),
            self::collection('owned', '3', [Permission::create(Role::any())], documentSecurity: true),
            self::collection('closed', '4', [Permission::create(Role::any())], documentSecurity: false),
            self::collection('disabled', '5', [Permission::read(Role::any())], documentSecurity: true, enabled: false),
            self::collection('database_1_collection_2', '6', [Permission::read(Role::any())], documentSecurity: false),
            self::collection('database_1_collection_8', '7', [Permission::read(Role::any())], documentSecurity: false),
        ]], $record) extends Database {
            /**
             * @param  array<string, list<Document>>  $registries
             * @param  Closure(string): void  $record
             */
            public function __construct(private readonly array $registries, private readonly Closure $record)
            {
                parent::__construct(new Memory(), new Cache(new None()));
            }

            #[Override]
            public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): Document
            {
                ($this->record)('getDocument');

                foreach ($this->registries[$collection] ?? [] as $entry) {
                    if ($entry->getId() === $id) {
                        return $entry;
                    }
                }

                return new Document();
            }

            #[Override]
            public function find(string $collection, array $queries = [], PermissionType $forPermission = PermissionType::Read): array
            {
                ($this->record)('find');

                $sequences = [];
                foreach ($queries as $query) {
                    if ($query->getAttribute() === '$sequence') {
                        $sequences = $query->getValues();
                    }
                }

                return \array_values(\array_filter(
                    $this->registries[$collection] ?? [],
                    static fn (Document $entry): bool => \in_array($entry->getSequence(), $sequences, true),
                ));
            }
        };
    }

    /**
     * @param  list<string>  $permissions
     */
    private static function collection(string $id, string $sequence, array $permissions, bool $documentSecurity, bool $enabled = true): Document
    {
        return new Document([
            '$id' => $id,
            '$sequence' => $sequence,
            '$permissions' => $permissions,
            'enabled' => $enabled,
            'documentSecurity' => $documentSecurity,
        ]);
    }
}
