<?php

namespace Tests\Compat\Query;

require_once __DIR__ . '/Replay.php';

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Query\AST\Parser;
use Utopia\Query\AST\Serializer;
use Utopia\Query\Query;
use Utopia\Query\Tokenizer\Tokenizer;

/**
 * Maps tests/compat/query/spec.json operations onto the PHP library. Glue only: no logic.
 *
 * Ported tests are programs (see Replay.php): the calls each PHP test made on
 * the library, recorded by tests/compat/query/record. Every area's operation
 * replays one; they differ only in which part of the library they cover.
 * The other operations are single calls, for fuzzing and benchmarks; those
 * that are one library call run as a one-event program, so results are
 * exported the same way.
 */
final class Adapter implements Base, Benchmarks
{
    private const string FILTER = '{"method":"equal","attribute":"status","values":["active","pending"]}';

    private const string SQL = 'SELECT u.id, u.name, COUNT(o.id) AS orders FROM users AS u LEFT JOIN orders AS o ON o.user_id = u.id WHERE u.status = ? AND u.age > 18 GROUP BY u.id, u.name ORDER BY orders DESC LIMIT 25';

    public function benchmarks(): array
    {
        $select = fn (string $class) => fn (Session $s) => function () use ($class) {
            $builder = new $class();

            return $builder->from('users')
                ->select(['id', 'name', 'email'])
                ->filter([Query::equal('status', ['active']), Query::greaterThan('age', 18), Query::startsWith('name', 'a')])
                ->sortDesc('createdAt')
                ->limit(25)
                ->offset(50)
                ->build();
        };

        return [
            'parse' => ['Parse a query from its JSON (an equal filter with two values)', fn (Session $s) => fn () => Query::parse(self::FILTER)],
            'mysql_select' => ['Build a MySQL select: three filters, an order, a limit and an offset', $select(\Utopia\Query\Builder\MySQL::class)],
            'postgresql_select' => ['Build the same select for PostgreSQL', $select(\Utopia\Query\Builder\PostgreSQL::class)],
            'clickhouse_select' => ['Build the same select for ClickHouse', $select(\Utopia\Query\Builder\ClickHouse::class)],
            'mongodb_find' => ['Build the same query as a MongoDB find command', $select(\Utopia\Query\Builder\MongoDB::class)],
            'tokenize' => ['Tokenize a 190-byte select with a join, grouping and ordering', fn (Session $s) => function () {
                $tokenizer = new Tokenizer();

                return fn () => $tokenizer->tokenize(self::SQL);
            }],
            'classify' => ['Classify a 190-byte select as a read', fn (Session $s) => function () {
                $classifier = new \Utopia\Query\Classifier\MySQL();

                return fn () => $classifier->classifySQL(self::SQL);
            }],
            'ast_roundtrip' => ['Parse the 190-byte select into an AST and serialize it back', fn (Session $s) => function () {
                $tokenizer = new Tokenizer();
                $parser = new Parser();
                $serializer = new Serializer();

                return fn () => $serializer->serialize($parser->parse(Tokenizer::filter($tokenizer->tokenize(self::SQL))));
            }],
            'schema_create' => ['Compile a MySQL CREATE TABLE with four columns, a unique index and a primary key', fn (Session $s) => function () {
                $schema = new \Utopia\Query\Schema\MySQL();

                return function () use ($schema) {
                    $table = $schema->table('users');
                    $table->id();
                    $table->string('email', 255)->unique();
                    $table->integer('age');
                    $table->boolean('active');

                    return $table->create();
                };
            }],
        ];
    }

    private const DIALECTS = [
        'mysql' => 'MySQL',
        'mariadb' => 'MariaDB',
        'postgresql' => 'PostgreSQL',
        'sqlite' => 'SQLite',
        'clickhouse' => 'ClickHouse',
        'mongodb' => 'MongoDB',
    ];

    private static function dialect(array $a, string $namespace): string
    {
        $name = self::DIALECTS[$a['dialect'] ?? ''] ?? throw new Fault('unknown dialect');

        return 'Utopia\\Query\\' . $namespace . '\\' . $name;
    }

    /**
     * @param list<array<string, mixed>> $program
     */
    private static function last(array $program): mixed
    {
        $out = (new Replay())->run($program);

        return $out[\count($out) - 1];
    }

    public function operations(): array
    {
        $replay = fn (array $a, Session $s) => (new Replay())->run($a['program'] ?? [], $a['impls'] ?? []);

        return [
            'query' => $replay,
            'builder' => $replay,
            'schema' => $replay,
            'ast' => $replay,
            'tokenizer' => $replay,
            'classifier' => $replay,
            'hook' => $replay,
            'query.parse' => fn (array $a) => self::last([
                ['static' => 'parse', 'class' => 'Query', 'args' => [$a['query'], $a['allowRaw'] ?? false]],
            ]),
            'query.parse_query' => fn (array $a) => self::last([
                ['static' => 'parseQuery', 'class' => 'Query', 'args' => [$a['query'], $a['allowRaw'] ?? false]],
            ]),
            'tokenizer.tokenize' => fn (array $a) => self::last([
                ['new' => \substr(self::dialect($a, 'Tokenizer'), 13), 'args' => []],
                ['call' => 'tokenize', 'on' => ['$o' => 1], 'args' => [$a['sql']]],
            ]),
            'classifier.classify' => fn (array $a) => self::last([
                ['new' => \substr(self::dialect($a, 'Classifier'), 13), 'args' => []],
                ['call' => $a['sql'] ?? false ? 'classifySQL' : 'classify', 'on' => ['$o' => 1], 'args' => [$a['data']]],
            ]),
            'ast.parse' => function (array $a): string {
                $tokens = Tokenizer::filter((new Tokenizer())->tokenize($a['sql']));

                return (new Serializer())->serialize((new Parser())->parse($tokens));
            },
            'builder.queries' => function (array $a): array {
                $class = self::dialect($a, 'Builder');
                $builder = new $class();
                $queries = \array_map(fn ($q) => Query::parseQuery($q), $a['queries']);
                $builder->from('t')->queries($queries);
                $statement = $builder->build();

                return ['query' => $statement->query, 'bindings' => $statement->bindings];
            },
            'builder.build' => function (array $a): array {
                $class = self::dialect($a, 'Builder');
                $builder = new $class();
                $builder->from('t')->queries(Query::parseQueries($a['queries']));
                $statement = $builder->build();

                return ['query' => $statement->query, 'bindings' => $statement->bindings];
            },
        ];
    }
}
