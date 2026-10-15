<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database;

use Appwrite\Utopia\Database\Aggregation;
use Appwrite\Utopia\Database\Documents\Aggregate;
use Iterator;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Filter\Callback;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;

final class AggregationTest extends TestCase
{
    private const string ITEMS = 'items';

    private const string OWNERS = 'owners';

    private const string REVERSED = 'reversed';

    private const array PERMISSIONS = ['read("any")', 'create("any")'];

    /**
     * MariaDB, MySQL and PostgreSQL fetch every aggregate and group as text, which a stringifying SQLite reproduces.
     *
     * @return Iterator<string, array{bool}>
     */
    public static function fetches(): Iterator
    {
        yield 'text' => [true];
        yield 'native' => [false];
    }

    #[DataProvider('fetches')]
    public function testAggregatesAreTypedByMethodAndAttribute(bool $stringify): void
    {
        $rows = $this->aggregate($this->database($stringify), [
            Query::count('*', 'rows'),
            Query::countDistinct('label', 'labels'),
            Query::sum('quantity', 'units'),
            Query::sum('price', 'revenue'),
            Query::avg('quantity', 'mean'),
            Query::min('quantity', 'fewest'),
            Query::max('price', 'dearest'),
            Query::min('shippedAt', 'firstShipped'),
            Query::max('label', 'lastLabel'),
        ]);

        $this->assertCount(1, $rows);
        $this->assertIsFloat($rows[0]['mean']);
        $this->assertEqualsWithDelta(7 / 3, $rows[0]['mean'], 0.0001);
        $this->assertSame([
            'rows' => 3,
            'labels' => 2,
            'units' => 7,
            'revenue' => 7.5,
            'mean' => $rows[0]['mean'],
            'fewest' => 1,
            'dearest' => 3.5,
            'firstShipped' => '2023-06-01T00:00:00.000+00:00',
            'lastLabel' => 'b',
        ], $rows[0]);
    }

    public function testStatisticsAreFloats(): void
    {
        $aggregation = Aggregation::from($this->database(true), $this->database(true)->getCollection(self::ITEMS), [], [
            Query::variance('quantity', 'spread'),
            Query::stddev('quantity', 'deviation'),
            Query::avg('quantity', 'mean'),
        ]);

        $row = $aggregation->decode(['spread' => '6.405119350733394e17', 'deviation' => '2', 'mean' => null]);

        $this->assertInstanceOf(Aggregate::class, $row);
        $this->assertSame(['spread' => 6.405119350733394e17, 'deviation' => 2.0, 'mean' => null], $row->getArrayCopy());
    }

    public function testWholeDecimalSumOfIntegersIsAnInteger(): void
    {
        $aggregation = Aggregation::from($this->database(true), $this->database(true)->getCollection(self::ITEMS), [], [
            Query::sum('quantity', 'units'),
            Query::count('*', 'rows'),
        ]);

        $this->assertSame(['units' => 7, 'rows' => 3], $aggregation->decode(['units' => '7.0000', 'rows' => '3'])->getArrayCopy());
    }

    #[DataProvider('fetches')]
    public function testAggregateOverNoRowsKeepsNull(bool $stringify): void
    {
        $rows = $this->aggregate($this->database($stringify), [
            Query::equal('label', ['none']),
            Query::count('*', 'rows'),
            Query::avg('price', 'mean'),
        ]);

        $this->assertSame([['rows' => 0, 'mean' => null]], $rows);
    }

    #[DataProvider('fetches')]
    public function testGroupsAreTypedAsARowTypesThem(bool $stringify): void
    {
        $rows = $this->aggregate($this->database($stringify), [
            Query::count('*', 'rows'),
            Query::groupBy(['active', 'tags', 'shippedAt', 'quantity', 'price']),
            Query::orderAsc('quantity'),
        ]);

        $this->assertSame([
            ['rows' => 1, 'active' => true, 'tags' => ['p', 'q'], 'shippedAt' => '2023-06-01T00:00:00.000+00:00', 'quantity' => 1, 'price' => 1.5],
            ['rows' => 1, 'active' => false, 'tags' => ['p'], 'shippedAt' => '2023-06-02T00:00:00.000+00:00', 'quantity' => 2, 'price' => 2.5],
            ['rows' => 1, 'active' => true, 'tags' => [], 'shippedAt' => '2023-06-01T00:00:00.000+00:00', 'quantity' => 4, 'price' => 3.5],
        ], $rows);
    }

    #[DataProvider('fetches')]
    public function testSystemGroupsUseTheirPublicNames(bool $stringify): void
    {
        $database = $this->database($stringify);
        $rows = $this->aggregate($database, [
            Query::count('*', 'rows'),
            Query::groupBy(['$id', '$sequence', '$createdAt', '$updatedAt', '$permissions']),
            Query::orderAsc('$id'),
        ]);

        $item = $database->getDocument(self::ITEMS, 'first');
        $this->assertSame([
            'rows' => 1,
            '$id' => 'first',
            '$sequence' => (string) $item->getSequence(),
            '$createdAt' => $item->getCreatedAt(),
            '$updatedAt' => $item->getUpdatedAt(),
            '$permissions' => self::PERMISSIONS,
        ], $rows[0]);
        $this->assertSame(['first', 'second', 'third'], \array_column($rows, '$id'));
    }

    /**
     * @param list<string> $groups
     * @param list<string> $keys
     */
    #[DataProvider('joinedGroups')]
    public function testJoinedGroupsAreQualifiedByTheirAlias(array $groups, array $keys): void
    {
        $rows = $this->aggregate($this->database(true), [
            Query::count('*', 'rows'),
            Query::join(self::OWNERS, 'o', [Query::on('ownerId', '$id')]),
            Query::groupBy($groups),
        ]);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(['rows', ...$keys], \array_keys($row));
            $this->assertIsInt($row['rows']);
        }
    }

    /**
     * @return Iterator<string, array{list<string>, list<string>}>
     */
    public static function joinedGroups(): Iterator
    {
        yield 'joined alone' => [['o.label'], ['o.label']];
        yield 'joined beside the main attribute of its name' => [['label', 'o.label'], ['label', 'o.label']];
        yield 'joined system attribute alone' => [['o.$id'], ['o.$id']];
        yield 'joined and main system attributes' => [['$id', 'o.$id'], ['$id', 'o.$id']];
        yield 'joined attribute named bare' => [['rank'], ['o.rank']];
    }

    public function testJoinedAggregatesAreTypedByTheJoinedAttribute(): void
    {
        $rows = $this->aggregate($this->database(true), [
            Query::sum('o.rank', 'ranks'),
            Query::max('rank', 'top'),
            Query::max('o.$createdAt', 'latest'),
            Query::join(self::OWNERS, 'o', [Query::on('ownerId', '$id')]),
            Query::groupBy(['o.$id']),
        ]);

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(['ranks', 'top', 'latest', 'o.$id'], \array_keys($row));
            $this->assertIsInt($row['ranks']);
            $this->assertIsInt($row['top']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}\+00:00$/', $row['latest']);
        }
    }

    public function testUnaliasedAggregatesKeepTheNameTheDatabaseGivesThem(): void
    {
        $rows = $this->aggregate($this->database(true), [
            Query::count('*'),
            Query::sum('quantity'),
            Query::groupBy(['label']),
            Query::orderAsc('label'),
        ]);

        $this->assertSame([
            ['count' => 2, 'sum_quantity' => 3, 'label' => 'a'],
            ['count' => 1, 'sum_quantity' => 4, 'label' => 'b'],
        ], $rows);
    }

    public function testUnaliasedJoinedAggregateKeepsTheNameTheDatabaseGivesIt(): void
    {
        $rows = $this->aggregate($this->database(true), [
            Query::sum('o.rank'),
            Query::join(self::OWNERS, 'o', [Query::on('ownerId', '$id')]),
            Query::groupBy(['o.$id']),
        ]);

        \usort($rows, static fn (array $a, array $b): int => \strcmp($a['o.$id'], $b['o.$id']));
        $this->assertSame([
            ['sum_o_rank' => 6, 'o.$id' => 'ann'],
            ['sum_o_rank' => 4, 'o.$id' => 'ben'],
        ], $rows);
    }

    #[DataProvider('fetches')]
    public function testGroupsAreDecodedByTheFiltersTheirAttributesDeclare(bool $stringify): void
    {
        $database = $this->database($stringify);
        $rows = $this->aggregate($database, [
            Query::count('*', 'rows'),
            Query::groupBy(['code']),
        ]);

        \usort($rows, static fn (array $a, array $b): int => \strcmp($a['code'], $b['code']));
        $this->assertSame([
            ['rows' => 2, 'code' => 'alpha'],
            ['rows' => 1, 'code' => 'beta'],
        ], $rows);
        $this->assertSame('beta', $database->getDocument(self::ITEMS, 'third')->getAttribute('code'));
    }

    public function testJoinedGroupsAreDecodedByTheFiltersOfTheJoinedAttribute(): void
    {
        $rows = $this->aggregate($this->database(true), [
            Query::count('*', 'rows'),
            Query::join(self::OWNERS, 'o', [Query::on('ownerId', '$id')]),
            Query::groupBy(['code', 'o.code']),
        ]);

        \usort($rows, static fn (array $a, array $b): int => \strcmp($a['code'] . $a['o.code'], $b['code'] . $b['o.code']));
        $this->assertSame([
            ['rows' => 1, 'code' => 'alpha', 'o.code' => 'xray'],
            ['rows' => 1, 'code' => 'alpha', 'o.code' => 'yankee'],
            ['rows' => 1, 'code' => 'beta', 'o.code' => 'xray'],
        ], $rows);
    }

    public function testRowsCarryNoSystemAttributeTheyDoNotGroup(): void
    {
        $rows = $this->aggregate($this->database(true), [
            Query::count('*', 'rows'),
            Query::groupBy(['label']),
        ]);

        foreach ($rows as $row) {
            $this->assertSame(['rows', 'label'], \array_keys($row));
        }
    }

    /**
     * @param array<Query> $queries
     * @return list<array<string, mixed>>
     */
    private function aggregate(Database $database, array $queries): array
    {
        $joined = [];
        foreach ($queries as $query) {
            if ($query->getMethod()->isJoin()) {
                $joined[$query->getAlias()] = $database->getCollection($query->getAttribute());
            }
        }

        $aggregation = Aggregation::from($database, $database->getCollection(self::ITEMS), $joined, $queries);

        return \array_map(
            static fn (array $row): array => $aggregation->decode($row)->getArrayCopy(),
            $database->aggregate(self::ITEMS, $queries),
        );
    }

    private function database(bool $stringify): Database
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::any()->toString());

        $pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STRINGIFY_FETCHES => $stringify,
        ]);
        $reverse = static fn (mixed $value): mixed => \is_string($value) ? \strrev($value) : $value;
        $database = new Database(new SQLite($pdo), new Cache(new None()), [new Callback(self::REVERSED, $reverse, $reverse)]);
        $database
            ->setAuthorization($authorization)
            ->setDatabase('aggregation')
            ->setNamespace('aggregation_' . \uniqid());
        $database->create();

        $permissions = [Permission::read(Role::any()), Permission::create(Role::any())];
        $database->createCollection(Collection::create(
            id: self::OWNERS,
            attributes: [
                Attribute::string('label', size: 16),
                Attribute::integer('rank'),
                Attribute::string('code', size: 16, filters: [self::REVERSED]),
            ],
            permissions: $permissions,
        ));
        $database->createCollection(Collection::create(
            id: self::ITEMS,
            attributes: [
                Attribute::string('label', size: 16),
                Attribute::integer('quantity'),
                Attribute::float('price'),
                Attribute::boolean('active'),
                Attribute::string('tags', size: 16, array: true),
                Attribute::datetime('shippedAt'),
                Attribute::string('ownerId', size: 36),
                Attribute::string('code', size: 16, filters: [self::REVERSED]),
            ],
            permissions: $permissions,
        ));

        $database->createDocument(self::OWNERS, new Document(['$id' => 'ann', 'label' => 'x', 'rank' => 3, 'code' => 'xray', '$permissions' => self::PERMISSIONS]));
        $database->createDocument(self::OWNERS, new Document(['$id' => 'ben', 'label' => 'y', 'rank' => 4, 'code' => 'yankee', '$permissions' => self::PERMISSIONS]));

        foreach ([
            ['first', 'a', 1, 1.5, true, ['p', 'q'], '2023-06-01T00:00:00.000+00:00', 'ann', 'alpha'],
            ['second', 'a', 2, 2.5, false, ['p'], '2023-06-02T00:00:00.000+00:00', 'ben', 'alpha'],
            ['third', 'b', 4, 3.5, true, [], '2023-06-01T00:00:00.000+00:00', 'ann', 'beta'],
        ] as [$id, $label, $quantity, $price, $active, $tags, $shippedAt, $ownerId, $code]) {
            $database->createDocument(self::ITEMS, new Document([
                '$id' => $id,
                'label' => $label,
                'quantity' => $quantity,
                'price' => $price,
                'active' => $active,
                'tags' => $tags,
                'shippedAt' => $shippedAt,
                'ownerId' => $ownerId,
                'code' => $code,
                '$permissions' => self::PERMISSIONS,
            ]));
        }

        return $database;
    }
}
