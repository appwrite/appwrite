<?php

namespace Tests\Query\Regression;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder;
use Utopia\Query\Builder\ClickHouse as ClickHouseBuilder;
use Utopia\Query\Builder\Condition;
use Utopia\Query\Builder\JoinType;
use Utopia\Query\Builder\MongoDB as MongoBuilder;
use Utopia\Query\Builder\MySQL as MySQLBuilder;
use Utopia\Query\Builder\ParsedQuery;
use Utopia\Query\Builder\PostgreSQL as PostgreSQLBuilder;
use Utopia\Query\Builder\SQLite as SQLiteBuilder;
use Utopia\Query\Exception\UnsupportedException;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Hook\Attribute;
use Utopia\Query\Hook\Filter as FilterHook;
use Utopia\Query\Hook\Join\Condition as JoinHookCondition;
use Utopia\Query\Hook\Join\Filter as JoinFilterHook;
use Utopia\Query\Query;
use Utopia\Query\Schema\ClickHouse as ClickHouseSchema;
use Utopia\Query\Schema\Table;
use Utopia\Query\Tokenizer\MySQL as MySQLTokenizer;

/**
 * Focused regression tests for correctness-oriented fix commits.
 *
 * Each test exercises the invariant restored by the commit and would fail on
 * the pre-fix code. Most commits also have co-located tests in the per-class
 * suites added alongside the original fix — duplicating the key ones here
 * gives a single, discoverable regression catalogue for correctness fixes
 * (matching the layout of SecurityRegressionTest).
 *
 * Commits covered directly in this file:
 *
 *  - f20878e  fix(builder): allow backticks in MySQL index hints
 *      testMySqlHintAcceptsBacktickQuotedIndex
 *  - 1845417  fix(builder): SQLite UNION emits bare compound SELECT
 *      testSQLiteUnionEmitsBareCompound
 *  - 119d1c3  fix(schema): ClickHouse MergeTree ORDER BY fallback + reject empty ALTER
 *      testClickHouseAlterRejectsEmptyAlterations
 *  - ef3e789  fix(tokenizer): emit valid MySQL double-dash comment after hash replacement
 *      testMySqlHashCommentReplacementEmitsValidDoubleDash
 *  - 11544e7  fix(mongodb): plumb arrayFilter options through Statement to update driver
 *      testStatementCarriesMongoArrayFilters
 *  - 5607a72  fix: restore bindings order in MongoDB update()
 *      testMongoUpdateBindingsOrder
 *  - 4594162  fix: include elemMatch attribute in fingerprint shape
 *      testFingerprintDistinguishesElemMatchAttribute
 *  - bd6af2e  fix: recurse into logical queries in fingerprint
 *      testFingerprintRecursesIntoLogicalQueries
 *
 * Cycle-1 review fixes:
 *
 *  - fix(builder): drop desynced orderAttributes/orderTypes on ParsedQuery
 *      testParsedQueryHasNoOrderAttributesField
 *      testParsedQueryHasNoOrderTypesField
 *      testOrderingStillEmittedThroughPendingQueries
 *  - fix(builder): clear transient alias-qualification state on reset()
 *      testResetClearsAliasQualificationState
 *      testResetPreservesUserInstalledHooks
 *  - fix(builder): throw when OFFSET is requested without LIMIT on MySQL-family dialects
 *      testOffsetWithoutLimitThrowsOnMySQL
 *      testOffsetWithLimitStillWorksOnMySQL
 *      testOffsetWithoutLimitStillWorksOnPostgreSQL
 *  - fix(builder): throw on unsupported join method instead of silently falling through
 *      testUnsupportedJoinMethodMatchThrows
 *  - fix(builder): base upsert() throws UnsupportedException
 *      testBaseUpsertThrowsUnsupported
 *  - fix(builder): drop duplicate forUpdate() from base Builder so MongoDB lacks it
 *      testMongoDbHasNoForUpdate
 *  - fix(builder): JSON_THROW_ON_ERROR when encoding JSON contains/overlaps payloads
 *      testMysqlJsonContainsRejectsInvalidUtf8
 *      testMysqlJsonOverlapsRejectsInvalidUtf8
 *  - fix(builder): whitelist direction argument on ClickHouse orderWithFill
 *      testOrderWithFillRejectsInjectionInDirection
 *
 * Commits already covered by their originating test file (noted here for
 * traceability, not re-asserted):
 *
 *  - dadf58e  fix(ast): route nested Select subqueries through Walker::walk()
 *      tests/Query/AST/WalkerTest.php::testFilterInjectorAppliedToExistsSubquery
 *  - 342c994  fix: protect double-quoted identifiers in MySQL hash comment replacement
 *      tests/Query/Tokenizer/MySQLTest.php::testHashCommentInsideDoubleQuotedIdentifier
 */
class CorrectnessRegressionTest extends TestCase
{
    use AssertsBindingCount;

    public function testMySqlHintAcceptsBacktickQuotedIndex(): void
    {
        // Pre-fix: backticks were stripped from the accepted character class,
        // so real MySQL index hints like INDEX(`t` `idx`) were rejected.
        $result = (new MySQLBuilder())
            ->from('users')
            ->hint('INDEX(`users` `idx_users_age`)')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT /*+ INDEX(`users` `idx_users_age`) */ * FROM `users`', $result->query);
    }

    public function testSQLiteUnionEmitsBareCompound(): void
    {
        // Pre-fix: the UNION wrapper emitted `(SELECT ...) UNION (SELECT ...)`,
        // which SQLite rejects — SQLite requires bare compound selects.
        $other = (new SQLiteBuilder())
            ->from('archived_users')
            ->select(['id']);

        $result = (new SQLiteBuilder())
            ->from('users')
            ->select(['id'])
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringStartsNotWith('(', $result->query);
        $this->assertStringNotContainsString(') UNION (', $result->query);
        $this->assertSame('SELECT `id` FROM `users` UNION SELECT `id` FROM `archived_users`', $result->query);
    }

    public function testClickHouseAlterRejectsEmptyAlterations(): void
    {
        // Pre-fix: `ALTER TABLE t` with no alterations would emit invalid SQL;
        // post-fix the builder throws up-front.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('ALTER TABLE requires at least one alteration.');

        $schema = new ClickHouseSchema();
        $schema->table('events')->alter();
    }

    public function testMySqlHashCommentReplacementEmitsValidDoubleDash(): void
    {
        // Pre-fix: replaceHashComments replaced `#` with `--` but did not emit
        // the trailing space, so `#cmt\n` became `--cmt\n`, which the retokenizer
        // could then misparse. Post-fix, the replacement is `-- cmt\n`, leaving
        // the rest of the SQL tokenizable.
        $tokenizer = new MySQLTokenizer();
        $tokens = $tokenizer->tokenize("SELECT 1 # tail\nFROM `users`");

        $joined = \implode(' ', \array_map(fn ($t) => $t->value, $tokens));
        $this->assertSame('SELECT   1   --  tail 
 FROM   `users` ', $joined);
    }

    public function testStatementCarriesMongoArrayFilters(): void
    {
        // Pre-fix: arrayFilter() wrapped the condition under the identifier
        // key, producing [['elem' => ['elem.grade' => ...]]]. Post-fix, the
        // Statement payload carries the flat filter MongoDB expects.
        $result = (new MongoBuilder())
            ->from('students')
            ->set(['grades.$[elem].mean' => 0])
            ->arrayFilter('elem', ['elem.grade' => ['$gte' => 85]])
            ->filter([Query::equal('_id', ['abc'])])
            ->update();

        /** @var array<string, mixed> $op */
        $op = \json_decode($result->query, true);
        $this->assertArrayHasKey('options', $op);
        /** @var array<string, mixed> $options */
        $options = $op['options'];
        $this->assertArrayHasKey('arrayFilters', $options);
        /** @var list<array<string, mixed>> $filters */
        $filters = $options['arrayFilters'];
        $this->assertCount(1, $filters);
        $this->assertArrayHasKey('elem.grade', $filters[0]);
        $this->assertArrayNotHasKey('elem', $filters[0]);
    }

    public function testMongoUpdateBindingsOrder(): void
    {
        // Pre-fix: update() built update operators before filters, but binding
        // replacement walks serialized keys filter-first — causing the wrong
        // bindings to land in each slot. Post-fix, bindings are filter-first,
        // then update.
        $result = (new MongoBuilder())
            ->from('users')
            ->set(['city' => 'New York'])
            ->filter([Query::equal('name', ['Alice'])])
            ->update();

        $this->assertSame(['Alice', 'New York'], $result->bindings);
    }

    public function testFingerprintDistinguishesElemMatchAttribute(): void
    {
        // Pre-fix: the fingerprint shape ignored the elemMatch attribute, so
        // elemMatch('tags', ...) and elemMatch('categories', ...) with the
        // same inner shape produced identical fingerprints.
        $elemTags = Query::elemMatch('tags', [Query::equal('name', ['x'])]);
        $elemCategories = Query::elemMatch('categories', [Query::equal('name', ['x'])]);

        $this->assertNotSame(
            Query::fingerprint([$elemTags]),
            Query::fingerprint([$elemCategories]),
        );
    }

    public function testFingerprintRecursesIntoLogicalQueries(): void
    {
        // Pre-fix: AND/OR logical queries were fingerprinted by method alone,
        // so AND([equal('a', ...)]) collided with AND([equal('b', ...)]).
        // Post-fix, inner child shapes are recursed into.
        $andA = Query::and([Query::equal('name', ['x'])]);
        $andB = Query::and([Query::equal('email', ['x'])]);

        $this->assertNotSame(
            Query::fingerprint([$andA]),
            Query::fingerprint([$andB]),
        );

        // AND and OR with the same child shape must still differ.
        $orA = Query::or([Query::equal('name', ['x'])]);
        $this->assertNotSame(
            Query::fingerprint([$andA]),
            Query::fingerprint([$orA]),
        );
    }

    public function testParsedQueryHasNoOrderAttributesField(): void
    {
        $this->assertFalse(
            \property_exists(ParsedQuery::class, 'orderAttributes'),
            'ParsedQuery::$orderAttributes must be removed; nothing in the compile pipeline reads it.',
        );
    }

    public function testParsedQueryHasNoOrderTypesField(): void
    {
        $this->assertFalse(
            \property_exists(ParsedQuery::class, 'orderTypes'),
            'ParsedQuery::$orderTypes must be removed; nothing in the compile pipeline reads it.',
        );
    }

    public function testOrderingStillEmittedThroughPendingQueries(): void
    {
        // Removing orderAttributes/orderTypes must not regress ORDER BY
        // emission — the compiler reads order queries from pendingQueries
        // directly via Query::getByType in compileOrderAndLimit.
        $plan = (new MySQLBuilder())
            ->from('t')
            ->queries([
                Query::orderAsc('name'),
                Query::orderDesc('age'),
                Query::orderRandom(),
            ])
            ->build();

        $this->assertSame('SELECT * FROM `t` ORDER BY `name` ASC, `age` DESC, RAND()', $plan->query);
    }

    public function testResetClearsAliasQualificationState(): void
    {
        // prepareAliasQualification() sets $qualify and $aggregationAliases
        // on each build(). reset() must clear both so the builder is in a
        // clean state between builds — the values are per-build transient
        // and not part of the user-facing API surface.
        $builder = new MySQLBuilder();
        $builder
            ->from('users', 'u')
            ->queries([
                Query::join('orders', 'id', 'user_id', '=', 'o'),
                Query::sum('amount', 'total'),
            ])
            ->build();

        $qualify = new \ReflectionProperty(Builder::class, 'qualify');
        $aggregationAliases = new \ReflectionProperty(Builder::class, 'aggregationAliases');

        $this->assertTrue($qualify->getValue($builder));
        $this->assertNotSame([], $aggregationAliases->getValue($builder));

        $builder->reset();

        $this->assertFalse($qualify->getValue($builder), 'reset() must clear $qualify.');
        $this->assertSame([], $aggregationAliases->getValue($builder), 'reset() must clear $aggregationAliases.');
    }

    public function testResetPreservesUserInstalledHooks(): void
    {
        // Hooks and the executor are user-installed infrastructure, orthogonal
        // to per-query state. They MUST survive reset() — this is the
        // contract established by testResetPreservesConditionProviders and
        // testResetPreservesAttributeResolver in MySQLTest.
        $builder = new MySQLBuilder();
        $filterHook = new class () implements FilterHook {
            public int $calls = 0;

            public function filter(string $table): Condition
            {
                $this->calls++;

                return new Condition('1 = 1', []);
            }
        };
        $attributeHook = new class () implements Attribute {
            public int $calls = 0;

            public function resolve(string $attribute): string
            {
                $this->calls++;

                return $attribute;
            }
        };
        $joinHook = new class () implements JoinFilterHook {
            public int $calls = 0;

            public function filterJoin(string $table, JoinType $joinType): ?JoinHookCondition
            {
                $this->calls++;

                return null;
            }
        };

        $builder->from('t')->addHook($filterHook)->addHook($attributeHook)->addHook($joinHook);
        $builder->reset();

        $builder
            ->from('users', 'u')
            ->queries([
                Query::equal('id', [1]),
                Query::join('orders', 'id', 'user_id', '=', 'o'),
            ])
            ->build();

        $this->assertGreaterThan(0, $filterHook->calls, 'Filter hook must survive reset().');
        $this->assertGreaterThan(0, $attributeHook->calls, 'Attribute hook must survive reset().');
        $this->assertGreaterThan(0, $joinHook->calls, 'Join filter hook must survive reset().');
    }

    public function testOffsetWithoutLimitThrowsOnMySQL(): void
    {
        $builder = (new MySQLBuilder())->from('t')->queries([Query::offset(5)]);

        $this->expectException(ValidationException::class);
        $builder->build();
    }

    public function testOffsetWithLimitStillWorksOnMySQL(): void
    {
        $plan = (new MySQLBuilder())
            ->from('t')
            ->queries([Query::limit(10), Query::offset(5)])
            ->build();

        $this->assertSame('SELECT * FROM `t` LIMIT ? OFFSET ?', $plan->query);
    }

    public function testOffsetWithoutLimitStillWorksOnPostgreSQL(): void
    {
        $plan = (new PostgreSQLBuilder())
            ->from('t')
            ->queries([Query::offset(5)])
            ->build();

        $this->assertSame('SELECT * FROM "t" OFFSET ?', $plan->query);
        $this->assertStringNotContainsString('LIMIT ?', $plan->query);
    }

    /**
     * The join-type match arm used to have `default => JoinType::Inner`, which
     * silently downgraded unknown join methods to INNER. Every real join enum
     * member is already listed in the match, so the only reliable regression
     * test is to read the compiled source and verify the default arm now
     * throws UnsupportedException — any future join method added to
     * Method::isJoin() but missed in the match will fail loudly.
     */
    public function testUnsupportedJoinMethodMatchThrows(): void
    {
        $reflection = new \ReflectionMethod(Builder::class, 'buildJoinsClause');
        $source = $this->readMethodSource($reflection);

        $this->assertStringNotContainsString(
            'default => JoinType::Inner',
            $source,
            'buildJoinsClause must not silently coerce unknown join methods to INNER.',
        );
        $this->assertMatchesRegularExpression(
            '/default\s*=>\s*throw\s+new\s+UnsupportedException/',
            $source,
            'buildJoinsClause must throw UnsupportedException on unknown join methods.',
        );
    }

    public function testMongoDbHasNoForUpdate(): void
    {
        $this->assertFalse(
            \method_exists(MongoBuilder::class, 'forUpdate'),
            'MongoDB must not expose forUpdate(); the base Builder duplicate is removed and MongoDB does not use the Locking trait.',
        );
    }

    public function testMysqlJsonContainsRejectsInvalidUtf8(): void
    {
        $query = Query::containsAll('tags', ["\xB1\x31"]);
        $query->setOnArray(true);

        $this->expectException(ValidationException::class);
        (new MySQLBuilder())
            ->from('t')
            ->queries([$query])
            ->build();
    }

    public function testMysqlJsonOverlapsRejectsInvalidUtf8(): void
    {
        $query = Query::containsAny('tags', ["\xB1\x31"]);
        $query->setOnArray(true);

        $this->expectException(ValidationException::class);
        (new MySQLBuilder())
            ->from('t')
            ->queries([$query])
            ->build();
    }

    public function testOrderWithFillRejectsInjectionInDirection(): void
    {
        $builder = (new ClickHouseBuilder())->from('t');

        $this->expectException(ValidationException::class);
        $builder->orderWithFill('ts', 'ASC, 1; DROP TABLE t');
    }

    private function readMethodSource(\ReflectionMethod $method): string
    {
        $file = $method->getFileName();
        $this->assertIsString($file);
        $lines = \file($file, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);

        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();

        return \implode("\n", \array_slice($lines, $start, $end - $start));
    }
}
