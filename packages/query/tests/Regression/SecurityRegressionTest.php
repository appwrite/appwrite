<?php

namespace Utopia\Query\Tests\Regression;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Query\AST\Call\Func;
use Utopia\Query\AST\Expression\Binary;
use Utopia\Query\AST\Expression\Cast;
use Utopia\Query\AST\Literal;
use Utopia\Query\AST\Parser;
use Utopia\Query\AST\Raw;
use Utopia\Query\AST\Reference\Column;
use Utopia\Query\AST\Serializer;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\AST\Visitor\FilterInjector;
use Utopia\Query\Builder\JoinBuilder;
use Utopia\Query\Builder\JoinType;
use Utopia\Query\Builder\MongoDB as MongoDBBuilder;
use Utopia\Query\Builder\MySQL as MySQLBuilder;
use Utopia\Query\Builder\PostgreSQL as PostgreSQLBuilder;
use Utopia\Query\Builder\SQLite as SQLiteBuilder;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Classifier\MongoDB as MongoDBClassifier;
use Utopia\Query\Classifier\MySQL as MySQLClassifier;
use Utopia\Query\Classifier\PostgreSQL as PostgreSQLClassifier;
use Utopia\Query\Exception\UnsupportedException;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Hook\Attribute\Map;
use Utopia\Query\Hook\Filter\Tenant;
use Utopia\Query\Method;
use Utopia\Query\Query;
use Utopia\Query\Schema\ClickHouse as ClickHouseSchema;
use Utopia\Query\Schema\ClickHouse\Engine;
use Utopia\Query\Schema\ClickHouse\IndexAlgorithm;
use Utopia\Query\Schema\Index;
use Utopia\Query\Schema\MySQL as MySQLSchema;
use Utopia\Query\Schema\ParameterDirection;
use Utopia\Query\Schema\PostgreSQL as PostgreSQLSchema;
use Utopia\Query\Tokenizer\Tokenizer;
use Utopia\Query\Type;

/**
 * Focused regression tests for security and correctness fix commits.
 *
 * Each test exercises the exact attack vector the commit closed and would fail
 * on the pre-fix code. Mapping commit -> method(s):
 *
 *  - d203ed7  fix(security): DDL input validation + wire-parser state machine
 *      testAlterColumnTypeRejectsSemicolonInUsingExpression
 *      testAlterColumnTypeRejectsDisallowedTypeCharacters
 *      testCreatePartitionRejectsCommentInjection
 *      testExtractKeywordIgnoresKeywordInsideStringLiteral
 *      testExtractKeywordIgnoresKeywordInsideBlockComment
 *      testClassifyDeleteInsideCteIsWrite
 *  - 5662d27  fix: cast/window selectors + mongo field names + parser depth + tokenizer bounds
 *      testMongoBuilderRejectsDollarPrefixedFieldName
 *      testMongoBuilderRejectsEmptyFieldName
 *  - (this branch)  fix: MongoDB silently dropped Hook\Filter
 *      testMongoBuilderRejectsFilterHookInsteadOfDroppingIt
 *      testMongoBuilderRejectsJoinFilterHook
 *      testSqlBuildersStillScopeByTenantHook
 *      testMongoBuilderStillAppliesAttributeHooks
 *  - c5a4ed3  fix: escape backslashes in DDL string literals
 *      testMySqlCreateTypeEnumEscapesTrailingBackslash
 *      testPostgreSqlCreateCollationRejectsInvalidOptionKey
 *      testPostgreSqlTablesampleRejectsInvalidMethod
 *  - 4eb2996  fix(ast): binary associativity + unary spacing + literal escaping
 *      (already covered directly in tests/Query/AST/SerializerTest.php by the
 *      commit itself; adding a belt-and-braces case for string literal backslash)
 *      testDdlStringLiteralEscapesBackslashes
 *  - ff64121  fix(query): reject Method::Raw from parse() by default
 *      testQueryParseRejectsRawByDefault
 *      testQueryParseAcceptsRawWhenAllowRawTrue
 *      testQueryParseRejectsRawNestedInsideOr
 *  - (this branch)  fix(query): reject SQL in AST function names and cast types
 *      testParserRejectsSqlInQuotedFunctionNameOrCastType
 *      testParserKeepsInjectedFilterForQuotedFunctionName
 *      testFuncRejectsSqlInName
 *      testCastRejectsSqlInType
 *      testCastAcceptsParameterisedType
 *      testToAstRejectsUnsupportedFilterInsteadOfEmittingRaw
 *      testFuncRejectsTrailingWhitespaceInName
 *      testCastRejectsTrailingWhitespaceInType
 *      testCastAcceptsMultiWordType
 *  - (this branch)  fix(query): enclose raw conditions in parentheses
 *      testRawFilterOrCannotEscapeTenantScope
 *      testWhereRawOrCannotEscapeTenantScope
 *      testJoinWhereRawOrCannotEscapeJoinedTenantScope
 *      testJoinOnRawOrStaysInsideOnClause
 *      testHavingRawOrStaysInsideHavingClause
 *      testToAstEnclosesRawFilter
 *      testFilterInjectorEnclosesRawCondition
 *      testUpdateFromWhereOrCannotEscapeTenantScope
 *      testDeleteUsingOrCannotEscapeTenantScope
 *  - (this branch)  fix(query): escape backslashes in toRawSql()
 *      testToRawSqlEscapesBackslashBeforeQuote
 *      testToRawSqlKeepsBackslashLiteralInStandardStrings
 *      testClickHouseReplicatedEngineEscapesBackslash
 *      testClickHouseSkipIndexEscapesBackslashInStringArgument
 *  - (this branch)  fix(query): reject a trailing newline in allowlisted names
 *      testJsonPathRejectsTrailingNewline
 *      testJoinBuilderRejectsTrailingNewlineInColumn
 *      testTenantRejectsTrailingNewlineInColumn
 */
class SecurityRegressionTest extends TestCase
{
    public function testAlterColumnTypeRejectsSemicolonInUsingExpression(): void
    {
        $schema = new PostgreSQLSchema();

        $this->expectException(ValidationException::class);
        $schema->alterColumnType('users', 'age', 'INTEGER', 'age::integer; DROP TABLE users');
    }

    public function testAlterColumnTypeRejectsDisallowedTypeCharacters(): void
    {
        $schema = new PostgreSQLSchema();

        $this->expectException(ValidationException::class);
        $schema->alterColumnType('users', 'age', "INTEGER'; DROP TABLE users; --");
    }

    public function testCreatePartitionRejectsCommentInjection(): void
    {
        $schema = new PostgreSQLSchema();

        $this->expectException(ValidationException::class);
        $schema->createPartition('orders', 'p1', "FOR VALUES IN ('a') /* evil */");
    }

    public function testExtractKeywordIgnoresKeywordInsideStringLiteral(): void
    {
        $classifier = new MySQLClassifier();

        // The keyword hidden inside the quoted string must not leak out.
        // Pre-fix naive byte-scan would see "DELETE" as the first word after
        // the SELECT in position, but extractKeyword should still report SELECT.
        $this->assertSame('SELECT', $classifier->extractKeyword("SELECT 'DELETE FROM users' AS x"));
    }

    public function testExtractKeywordIgnoresKeywordInsideBlockComment(): void
    {
        $classifier = new MySQLClassifier();

        $this->assertSame('SELECT', $classifier->extractKeyword('/* DELETE FROM users */ SELECT 1'));
    }

    public function testCteClassifierIgnoresKeywordHiddenInStringLiteral(): void
    {
        $classifier = new PostgreSQLClassifier();

        // Pre-fix: a naive byte-scan could match INSERT inside the string and
        // misclassify as Write. With the state machine, the quoted literal is
        // skipped and the outer SELECT is the classifying keyword (Read).
        $sql = "WITH x AS (SELECT 'INSERT INTO users VALUES(1)' AS s) SELECT * FROM x";
        $this->assertSame(Type::Read, $classifier->classifySQL($sql));
    }

    public function testMongoBuilderRejectsDollarPrefixedFieldNameInPush(): void
    {
        $builder = new MongoDBBuilder();
        $builder->from('users');

        $this->expectException(ValidationException::class);
        $builder->push('$where', 'value');
    }

    public function testMongoBuilderRejectsEmptyFieldNameInSet(): void
    {
        $builder = new MongoDBBuilder();
        $builder->from('users');

        $this->expectException(ValidationException::class);
        $builder->set(['' => 'value']);
    }

    public function testMySqlCreateTableEnumEscapesTrailingBackslash(): void
    {
        $schema = new MySQLSchema();
        $plan = $schema->table('widgets')
            ->enum('grade', ['A', 'B', "bad\\"])
            ->create();

        // Pre-fix: the trailing backslash could escape the closing quote. After
        // the fix it must appear doubled inside the literal.
        $this->assertSame("CREATE TABLE `widgets` (`grade` ENUM('A','B','bad\\\\') NOT NULL)", $plan->query);
    }

    public function testPostgreSqlCreateCollationRejectsInvalidOptionKey(): void
    {
        $schema = new PostgreSQLSchema();

        $this->expectException(ValidationException::class);
        // Key with spaces/quotes would break out of option list if not validated
        $schema->createCollation('c1', ["provider = 'x', danger" => 'icu']);
    }

    public function testPostgreSqlTablesampleRejectsInvalidMethod(): void
    {
        $builder = new PostgreSQLBuilder();
        $builder->from('users');

        $this->expectException(ValidationException::class);
        $builder->tablesample(10.0, "BERNOULLI); DROP TABLE users; --");
    }

    public function testDdlStringLiteralEscapesBackslashes(): void
    {
        // Belt-and-braces: a default column value ending in backslash must be
        // serialised with the backslash doubled so the closing quote cannot be
        // escaped by the payload under MySQL default SQL mode.
        $schema = new MySQLSchema();
        $plan = $schema->table('notes')
            ->string('body')->default("evil\\")
            ->create();

        $this->assertSame("CREATE TABLE `notes` (`body` VARCHAR(255) NOT NULL DEFAULT 'evil\\\\')", $plan->query);
    }

    public function testQueryParseRejectsRawByDefault(): void
    {
        $this->expectException(ValidationException::class);
        Query::parseQuery([
            'method' => Method::Raw->value,
            'values' => ["SELECT * FROM users; DROP TABLE users"],
        ]);
    }

    public function testQueryParseAcceptsRawWhenAllowRawTrue(): void
    {
        $query = Query::parseQuery([
            'method' => Method::Raw->value,
            'values' => ['trusted_raw'],
        ], allowRaw: true);

        $this->assertSame(Method::Raw, $query->getMethod());
    }

    public function testQueryParseRejectsRawNestedInsideOr(): void
    {
        $this->expectException(ValidationException::class);
        Query::parseQuery([
            'method' => Method::Or->value,
            'values' => [
                ['method' => Method::Equal->value, 'attribute' => 'a', 'values' => [1]],
                ['method' => Method::Raw->value, 'values' => ['DROP TABLE users']],
            ],
        ]);
    }

    public function testSelectWindowRejectsInjectionInsideParens(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid window function');

        new MySQLBuilder()
            ->from('t')
            ->selectWindow('ROW_NUMBER(1); DROP TABLE x;-- )', 'w');
    }

    public function testSelectWindowRejectsSemicolonInsideArgs(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid window function');

        new MySQLBuilder()
            ->from('t')
            ->selectWindow('SUM(a; DROP TABLE x)', 'w');
    }

    public function testSelectWindowAcceptsValidArgs(): void
    {
        $builder = new PostgreSQLBuilder()
            ->from('t')
            ->selectWindow('SUM("amount")', 'w', ['dept']);

        $this->assertInstanceOf(PostgreSQLBuilder::class, $builder);
    }

    public function testCreateIndexRejectsDoubleQuoteInCollation(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid collation');

        new Index(
            name: 'idx_name',
            columns: ['name'],
            collations: ['name' => '"en_US"'],
        );
    }

    public function testCreateProcedureRejectsCommaInjectionInType(): void
    {
        $schema = new PostgreSQLSchema();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid procedure parameter type');

        $schema->createProcedure('p', [
            [ParameterDirection::In, 'x', 'VARCHAR(255), DROP TABLE x --'],
        ], 'SELECT 1');
    }

    public function testSelectCastRejectsCommaInjectionInType(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid cast type');

        new MySQLBuilder()
            ->from('t')
            ->selectCast('c', 'VARCHAR(255), DROP TABLE x --', 'a');
    }

    public function testSelectCastRejectsQuoteInjectionInType(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid cast type');

        new MySQLBuilder()
            ->from('t')
            ->selectCast('c', "INT'; DROP TABLE x; --", 'a');
    }

    public function testSelectCastRejectsCommentSequenceInType(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid cast type');

        new MySQLBuilder()
            ->from('t')
            ->selectCast('c', 'INT /* danger */', 'a');
    }

    public function testAlterColumnTypeRejectsCommaInjectionInType(): void
    {
        $schema = new PostgreSQLSchema();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid column type');

        $schema->alterColumnType('users', 'age', 'INTEGER, DROP TABLE users --');
    }

    public function testSelectCastAcceptsStructuredType(): void
    {
        $result = new MySQLBuilder()
            ->from('t')
            ->selectCast('c', 'DECIMAL(10, 2)', 'a')
            ->build();

        $this->assertSame('SELECT CAST(`c` AS DECIMAL(10, 2)) AS `a` FROM `t`', $result->query);
    }

    public function testExtractFirstBsonKeyRejectsOutOfBoundsDocLength(): void
    {
        // OP_MSG header (16 bytes) + section kind + BSON with bogus doc length
        $bsonBody = "\x10" . 'find' . "\x00" . \pack('V', 1) . "\x00";
        $bson = \pack('V', 0x7FFFFFFF) . $bsonBody;
        $sectionKind = "\x00";
        $flags = \pack('V', 0);
        $body = $flags . $sectionKind . $bson;
        $header = \pack('V', 16 + \strlen($body))
            . \pack('V', 1)
            . \pack('V', 0)
            . \pack('V', 2013);
        $data = $header . $body;

        $classifier = new MongoDBClassifier();
        // Malformed packet must not produce a classification — extractFirstBsonKey
        // must bail on the out-of-bounds docLen instead of scanning past it.
        $this->assertSame(Type::Unknown, $classifier->classify($data));
    }

    public function testQuoteRejectsNullByteInIdentifier(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Identifier contains control character');

        new MySQLBuilder()->from("users\x00 DROP TABLE x")->build();
    }

    public function testQuoteRejectsControlByteInIdentifier(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Identifier contains control character');

        new MySQLBuilder()->from("users\x1f")->build();
    }

    public function testQuoteAcceptsValidIdentifier(): void
    {
        $result = new MySQLBuilder()->from('users')->build();

        $this->assertSame('SELECT * FROM `users`', $result->query);
    }

    public function testJoinOnRejectsInjectionInLeftIdentifier(): void
    {
        $join = new JoinBuilder();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid column name');

        $join->on('users.id); DROP TABLE users; --', 'orders.user_id');
    }

    public function testJoinOnRejectsInjectionInRightIdentifier(): void
    {
        $join = new JoinBuilder();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid column name');

        $join->on('users.id', 'orders.user_id OR 1=1');
    }

    public function testJoinOnAcceptsValidIdentifiers(): void
    {
        $join = new JoinBuilder();
        $join->on('users.id', 'orders.user_id');

        $this->assertCount(1, $join->ons);
    }
    /**
     * A Hook\Filter returns a Condition -- a raw SQL expression plus bindings --
     * which cannot be placed in a MongoDB operation document. The builder used
     * to accept the hook and drop it, so a Hook\Filter\Tenant that correctly
     * scoped MySQL left every MongoDB query unscoped and returned other
     * tenants' documents. It must refuse the hook instead.
     */
    public function testMongoBuilderRejectsFilterHookInsteadOfDroppingIt(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('Filter hooks are not supported on the MongoDB builder');

        new MongoDBBuilder()->addHook(new Tenant(['7']));
    }

    public function testMongoBuilderRejectsJoinFilterHook(): void
    {
        // Tenant implements both Hook\Filter and Hook\Join\Filter.
        $this->expectException(UnsupportedException::class);

        new MongoDBBuilder()->addHook(new Tenant(['7'], 'org_id'));
    }

    /** The SQL builders must keep scoping, so the fix cannot regress them. */
    public function testSqlBuildersStillScopeByTenantHook(): void
    {
        foreach ([MySQLBuilder::class, PostgreSQLBuilder::class] as $builderClass) {
            $result = new $builderClass()
                ->addHook(new Tenant(['7']))
                ->from('docs')
                ->select(['a'])
                ->build();

            $this->assertStringContainsString('tenant_id', $result->query);
            $this->assertContains('7', $result->bindings);
        }
    }

    /** Attribute hooks are dialect-neutral and must still apply on MongoDB. */
    public function testMongoBuilderStillAppliesAttributeHooks(): void
    {
        $result = new MongoDBBuilder()
            ->addHook(new Map(['a' => 'renamed']))
            ->from('docs')
            ->filter([Query::equal('a', ['x'])])
            ->build();

        $this->assertStringContainsString('renamed', $result->query);
        $this->assertStringNotContainsString('"a"', $result->query);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function quotedInjectionProvider(): array
    {
        return [
            'function name' => ['SELECT name FROM users WHERE "1=1 OR TRUE OR LOWER"(name) = 1'],
            'double colon cast type' => ['SELECT name FROM users WHERE name::"TEXT) OR 1=1 OR CAST(name AS TEXT" = 1'],
            'cast type' => ['SELECT name FROM users WHERE CAST(name AS "TEXT) OR (1=1") = 1'],
        ];
    }

    /**
     * A quoted identifier may hold any text. Used as a function name or cast
     * type it was serialized verbatim, so it carried SQL past ColumnValidator
     * and, through OR precedence, neutralized the predicate FilterInjector
     * ANDs onto the WHERE clause.
     */
    #[DataProvider('quotedInjectionProvider')]
    public function testParserRejectsSqlInQuotedFunctionNameOrCastType(string $sql): void
    {
        $this->expectException(ValidationException::class);

        $this->parse($sql);
    }

    public function testParserKeepsInjectedFilterForQuotedFunctionName(): void
    {
        $statement = $this->parse('SELECT name FROM users WHERE "lower"(name) = 1');
        $statement = new FilterInjector(new Binary(new Column('tenant'), '=', new Literal(7)))->visitSelect($statement);

        $this->assertSame(
            'SELECT `name` FROM `users` WHERE LOWER(`name`) = 1 AND `tenant` = 7',
            new Serializer()->serialize($statement),
        );
    }

    public function testFuncRejectsSqlInName(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid function name');

        new Func('NOW() OR 1=1 OR NOW');
    }

    public function testCastRejectsSqlInType(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid cast type');

        new Cast(new Column('price'), 'TEXT) OR (1=1');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function trailingWhitespaceFunctionNameProvider(): array
    {
        return [
            'newline' => ["NOW\n"],
            'carriage return and newline' => ["NOW\r\n"],
            'space' => ['NOW '],
            'tab' => ["NOW\t"],
        ];
    }

    #[DataProvider('trailingWhitespaceFunctionNameProvider')]
    public function testFuncRejectsTrailingWhitespaceInName(string $name): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid function name');

        new Func($name);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function trailingWhitespaceCastTypeProvider(): array
    {
        return [
            'newline after word' => ["INT\n"],
            'newline after arguments' => ["DECIMAL(10, 2)\n"],
            'space after word' => ['INT '],
            'space after arguments' => ['DECIMAL(10, 2) '],
        ];
    }

    #[DataProvider('trailingWhitespaceCastTypeProvider')]
    public function testCastRejectsTrailingWhitespaceInType(string $type): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid cast type');

        new Cast(new Column('price'), $type);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function multiWordCastTypeProvider(): array
    {
        return [
            'two words' => ['DOUBLE PRECISION'],
            'tab separated' => ["DOUBLE\tPRECISION"],
            'four words' => ['TIMESTAMP WITH TIME ZONE'],
            'words and arguments' => ['CHARACTER VARYING (255)'],
            'whitespace inside arguments' => ["NUMERIC( 10 ,\n2 )"],
        ];
    }

    #[DataProvider('multiWordCastTypeProvider')]
    public function testCastAcceptsMultiWordType(string $type): void
    {
        $this->assertSame($type, new Cast(new Column('price'), $type)->type);
    }

    public function testCastAcceptsParameterisedType(): void
    {
        $cast = new Cast(new Column('price'), 'DECIMAL(10, 2)');

        $this->assertSame('CAST(`price` AS DECIMAL(10, 2))', new Serializer()->serializeExpression($cast));
    }

    /**
     * toAst() wrapped the attribute of every filter method it does not model
     * in a Raw node, which the Serializer emits verbatim, while build() throws
     * for the same methods.
     */
    public function testToAstRejectsUnsupportedFilterInsteadOfEmittingRaw(): void
    {
        $builder = new MySQLBuilder()
            ->from('users')
            ->filter([Query::search('name) OR (1=1', 'term')]);

        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('Unsupported filter type: search');

        $builder->toAst();
    }

    /**
     * Conditions are joined with AND, which binds tighter than OR. A raw
     * `a = ? OR b = ?` beside the Tenant hook's condition compiled to
     * `a = ? OR b = ? AND tenant_id IN (?)`, which matches every tenant's rows
     * where `a = ?`.
     */
    public function testRawFilterOrCannotEscapeTenantScope(): void
    {
        $result = new MySQLBuilder()
            ->addHook(new Tenant(['7']))
            ->from('docs')
            ->filter([Query::raw('a = ? OR b = ?', [1, 2])])
            ->build();

        $this->assertSame('SELECT * FROM `docs` WHERE (a = ? OR b = ?) AND tenant_id IN (?)', $result->query);
        $this->assertSame([1, 2, '7'], $result->bindings);
    }

    /**
     * @return array<string, array{Closure(MySQLBuilder): Statement, string, list<mixed>}>
     */
    public static function whereRawStatementProvider(): array
    {
        return [
            'select' => [
                fn (MySQLBuilder $builder): Statement => $builder->build(),
                'SELECT * FROM `docs` WHERE tenant_id IN (?) AND (a = ? OR b = ?)',
                ['7', 1, 2],
            ],
            'update' => [
                fn (MySQLBuilder $builder): Statement => $builder->set(['c' => 3])->update(),
                'UPDATE `docs` SET `c` = ? WHERE tenant_id IN (?) AND (a = ? OR b = ?)',
                [3, '7', 1, 2],
            ],
            'delete' => [
                fn (MySQLBuilder $builder): Statement => $builder->delete(),
                'DELETE FROM `docs` WHERE tenant_id IN (?) AND (a = ? OR b = ?)',
                ['7', 1, 2],
            ],
        ];
    }

    /**
     * `tenant_id IN (?) AND a = ? OR b = ?` reads, updates or deletes every
     * tenant's rows where `b = ?`.
     *
     * @param  Closure(MySQLBuilder): Statement  $statement
     * @param  list<mixed>  $bindings
     */
    #[DataProvider('whereRawStatementProvider')]
    public function testWhereRawOrCannotEscapeTenantScope(Closure $statement, string $query, array $bindings): void
    {
        $builder = new MySQLBuilder()
            ->addHook(new Tenant(['7']))
            ->from('docs')
            ->whereRaw('a = ? OR b = ?', [1, 2]);

        $result = $statement($builder);

        $this->assertSame($query, $result->query);
        $this->assertSame($bindings, $result->bindings);
    }

    /**
     * A left join takes the Tenant hook's condition in its ON clause, after
     * the join's own conditions. A bare raw OR there joined the rows of other
     * tenants for which `orders.x = orders.y`.
     */
    public function testJoinWhereRawOrCannotEscapeJoinedTenantScope(): void
    {
        $result = new MySQLBuilder()
            ->addHook(new Tenant(['7']))
            ->from('docs')
            ->joinWhere('orders', function (JoinBuilder $join): void {
                $join->on('docs.id', 'orders.doc_id')
                    ->whereRaw('orders.x = orders.y OR 1 = 1');
            }, JoinType::Left)
            ->build();

        $this->assertSame(
            'SELECT * FROM `docs` LEFT JOIN `orders` ON `docs`.`id` = `orders`.`doc_id` AND (orders.x = orders.y OR 1 = 1) AND tenant_id IN (?) WHERE tenant_id IN (?)',
            $result->query,
        );
        $this->assertSame(['7', '7'], $result->bindings);
    }

    public function testJoinOnRawOrStaysInsideOnClause(): void
    {
        $result = new MySQLBuilder()
            ->from('docs')
            ->joinWhere('orders', function (JoinBuilder $join): void {
                $join->on('docs.id', 'orders.doc_id')
                    ->onRaw('orders.x = ? OR 1 = 1', [5]);
            })
            ->build();

        $this->assertSame(
            'SELECT * FROM `docs` JOIN `orders` ON `docs`.`id` = `orders`.`doc_id` AND (orders.x = ? OR 1 = 1)',
            $result->query,
        );
        $this->assertSame([5], $result->bindings);
    }

    public function testHavingRawOrStaysInsideHavingClause(): void
    {
        $result = new MySQLBuilder()
            ->from('orders')
            ->count('*', 'cnt')
            ->groupBy(['user_id'])
            ->having([Query::greaterThan('cnt', 1)])
            ->havingRaw('SUM(total) > ? OR COUNT(*) > ?', [100, 10])
            ->build();

        $this->assertSame(
            'SELECT COUNT(*) AS `cnt` FROM `orders` GROUP BY `user_id` HAVING COUNT(*) > ? AND (SUM(total) > ? OR COUNT(*) > ?)',
            $result->query,
        );
        $this->assertSame([1, 100, 10], $result->bindings);
    }

    /** toAst() turns a raw filter into a Raw node, which hides its operators the same way. */
    public function testToAstEnclosesRawFilter(): void
    {
        $statement = new MySQLBuilder()
            ->from('docs')
            ->filter([Query::raw('a = 1 OR b = 2'), Query::equal('tenant', [7])])
            ->toAst();

        $this->assertSame(
            'SELECT * FROM `docs` WHERE (a = 1 OR b = 2) AND `tenant` = 7',
            new Serializer()->serialize($statement),
        );
    }

    public function testFilterInjectorEnclosesRawCondition(): void
    {
        $statement = $this->parse('SELECT name FROM users WHERE a = 1');
        $statement = new FilterInjector(new Raw('tenant = 7 OR tenant IS NULL'))->visitSelect($statement);

        $this->assertSame(
            'SELECT `name` FROM `users` WHERE `a` = 1 AND (tenant = 7 OR tenant IS NULL)',
            new Serializer()->serialize($statement),
        );
    }

    /**
     * The UPDATE ... FROM condition followed the WHERE clause bare, as
     * `tenant_id IN (?) AND other.id = docs.ref OR other.owner = ?`, and its
     * bindings went in ahead of the WHERE clause's, so the tenant placeholder
     * took 'u1'.
     */
    public function testUpdateFromWhereOrCannotEscapeTenantScope(): void
    {
        $result = new PostgreSQLBuilder()
            ->addHook(new Tenant(['7']))
            ->from('docs')
            ->set(['c' => 3])
            ->updateFrom('other')
            ->updateFromWhere('other.id = docs.ref OR other.owner = ?', 'u1')
            ->update();

        $this->assertSame(
            'UPDATE "docs" SET "c" = ? FROM "other" WHERE tenant_id IN (?) AND (other.id = docs.ref OR other.owner = ?)',
            $result->query,
        );
        $this->assertSame([3, '7', 'u1'], $result->bindings);
    }

    public function testDeleteUsingOrCannotEscapeTenantScope(): void
    {
        $result = new PostgreSQLBuilder()
            ->addHook(new Tenant(['7']))
            ->from('docs')
            ->deleteUsing('other', 'other.id = docs.ref OR other.owner = ?', 'u1')
            ->delete();

        $this->assertSame(
            'DELETE FROM "docs" USING "other" WHERE tenant_id IN (?) AND (other.id = docs.ref OR other.owner = ?)',
            $result->query,
        );
        $this->assertSame(['7', 'u1'], $result->bindings);
    }

    /**
     * toRawSql() doubled quotes only, so under MySQL's backslash escaping the
     * value `\' OR 1=1 -- ` closed its literal: `'\'' OR 1=1 -- '`.
     */
    public function testToRawSqlEscapesBackslashBeforeQuote(): void
    {
        $sql = new MySQLBuilder()
            ->from('t')
            ->filter([Query::equal('name', ["\\' OR 1=1 -- "])])
            ->toRawSql();

        $this->assertSame("SELECT * FROM `t` WHERE `name` IN ('\\\\'' OR 1=1 -- ')", $sql);
    }

    /**
     * @return array<string, array{PostgreSQLBuilder|SQLiteBuilder, string}>
     */
    public static function standardStringBuilderProvider(): array
    {
        return [
            'postgresql' => [new PostgreSQLBuilder(), "SELECT * FROM \"t\" WHERE \"name\" IN ('a\\b''c')"],
            'sqlite' => [new SQLiteBuilder(), "SELECT * FROM `t` WHERE `name` IN ('a\\b''c')"],
        ];
    }

    /** PostgreSQL and SQLite read a backslash literally, so doubling it misrenders the value. */
    #[DataProvider('standardStringBuilderProvider')]
    public function testToRawSqlKeepsBackslashLiteralInStandardStrings(PostgreSQLBuilder|SQLiteBuilder $builder, string $sql): void
    {
        $raw = $builder
            ->from('t')
            ->filter([Query::equal('name', ["a\\b'c"])])
            ->toRawSql();

        $this->assertSame($sql, $raw);
    }

    /** ClickHouse reads a backslash as an escape, so a path ending in one closed its literal. */
    public function testClickHouseReplicatedEngineEscapesBackslash(): void
    {
        $result = new ClickHouseSchema()
            ->table('events')
            ->integer('id')->primary()
            ->engine(Engine::ReplicatedMergeTree, "/tables/a\\", "r\\")
            ->create();

        $this->assertSame(
            "CREATE TABLE `events` (`id` Int32) ENGINE = ReplicatedMergeTree('/tables/a\\\\', 'r\\\\') ORDER BY (`id`)",
            $result->query,
        );
    }

    public function testClickHouseSkipIndexEscapesBackslashInStringArgument(): void
    {
        $result = new ClickHouseSchema()
            ->table('events')
            ->integer('id')->primary()
            ->string('country')
            ->index(['country'], algorithm: IndexAlgorithm::Set, algorithmArgs: ["a\\"])
            ->create();

        $this->assertSame(
            "CREATE TABLE `events` (`id` Int32, `country` String, INDEX `idx_country` `country` TYPE set('a\\\\') GRANULARITY 1) ENGINE = MergeTree() ORDER BY (`id`)",
            $result->query,
        );
    }

    /**
     * @return array<string, array{MySQLBuilder|PostgreSQLBuilder|SQLiteBuilder}>
     */
    public static function jsonPathBuilderProvider(): array
    {
        return [
            'mysql' => [new MySQLBuilder()],
            'postgresql' => [new PostgreSQLBuilder()],
            'sqlite' => [new SQLiteBuilder()],
        ];
    }

    /** A `$` anchor without the D modifier also matches before a final "\n". */
    #[DataProvider('jsonPathBuilderProvider')]
    public function testJsonPathRejectsTrailingNewline(MySQLBuilder|PostgreSQLBuilder|SQLiteBuilder $builder): void
    {
        $builder->from('t')->filter([Query::jsonPath('data', "a.b\n", '=', 1)]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid JSON path');

        $builder->build();
    }

    /**
     * @return array<string, array{Closure(JoinBuilder): JoinBuilder}>
     */
    public static function joinBuilderColumnProvider(): array
    {
        return [
            'on left' => [fn (JoinBuilder $join): JoinBuilder => $join->on("users.id\n", 'orders.user_id')],
            'on right' => [fn (JoinBuilder $join): JoinBuilder => $join->on('users.id', "orders.user_id\n")],
            'where' => [fn (JoinBuilder $join): JoinBuilder => $join->where("orders.status\n", '=', 'active')],
        ];
    }

    /**
     * @param  Closure(JoinBuilder): JoinBuilder  $condition
     */
    #[DataProvider('joinBuilderColumnProvider')]
    public function testJoinBuilderRejectsTrailingNewlineInColumn(Closure $condition): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid column name');

        $condition(new JoinBuilder());
    }

    public function testTenantRejectsTrailingNewlineInColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid column name');

        new Tenant(['7'], "tenant_id\n");
    }

    private function parse(string $sql): Select
    {
        return new Parser()->parse(Tokenizer::filter(new Tokenizer()->tokenize($sql)));
    }
}
