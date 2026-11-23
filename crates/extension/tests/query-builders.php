<?php

// Parity corpus for the query builders, which are still being moved: run it on the PHP classes and on the
// extension's (_APP_EXTENSION_LIBRARIES=query,query-builders), and diff stdout. It only calls what the
// extension has so far, and grows with it.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Query\Builder;
use Utopia\Query\Query;

fwrite(STDERR, 'Utopia\Query builders: ' . ((new ReflectionClass(Builder\MySQL::class))->isInternal() ? 'utopia extension' : 'PHP') . PHP_EOL);

/** A value as JSON can carry it: a string that is not UTF-8 as its bytes in hex. */
function exported(mixed $value): mixed
{
    if (is_string($value)) {
        return mb_check_encoding($value, 'UTF-8') ? $value : ['hex' => bin2hex($value)];
    }
    if (is_float($value)) {
        return ['float' => var_export($value, true)];
    }
    if (is_array($value)) {
        return ['array' => array_map(fn ($key, $item) => [exported($key), exported($item)], array_keys($value), $value)];
    }
    if ($value instanceof Builder\Statement) {
        return ['statement' => [exported($value->query), exported($value->bindings), $value->readOnly]];
    }
    if (is_object($value)) {
        return ['object' => $value::class];
    }

    return $value;
}

/** What a call returns, or what it throws. */
function outcome(callable $run): array
{
    try {
        return ['value' => exported($run())];
    } catch (Utopia\Query\Exception $e) {
        return ['throws' => $e::class, 'message' => exported($e->getMessage())];
    } catch (Throwable $e) {
        return ['throws' => $e::class, 'message' => exported($e->getMessage())];
    }
}

function emit(string $case, mixed $result): void
{
    echo json_encode([$case, $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
}

// What utopia-php/database's builders do to the package's: wrap a public step and call the parent, take over
// protected ones, add a binding from inside one, count the bindings, and throw.
trait WrapsSteps
{
    /** @var list<string> */
    public array $seen = [];

    public function compileFilter(Query $query): string
    {
        $this->seen[] = $query->getMethod()->value . ':' . $query->getAttribute();

        return parent::compileFilter($query);
    }

    protected function compileIn(string $attribute, array $values, ?string $column = null): string
    {
        return '/* in */ ' . parent::compileIn($attribute, $values, $column);
    }

    protected function compileLike(string $attribute, array $values, string $prefix, string $suffix, bool $not, ?string $column = null): string
    {
        $this->addBinding($prefix . $values[0] . $suffix, $column);

        return $attribute . ($not ? ' NOT' : '') . ' LIKE BINARY ?';
    }
}
trait CountsBindings
{
    public int $bound = 0;

    protected function addBinding(mixed $value, ?string $column = null): void
    {
        $this->bound++;
        parent::addBinding($value, $column);
    }
}
trait ThrowsFromAStep
{
    protected function compileIn(string $attribute, array $values, ?string $column = null): string
    {
        throw new RuntimeException('no IN for ' . $attribute);
    }
}

$dialects = ['MySQL', 'MariaDB', 'PostgreSQL', 'SQLite', 'ClickHouse', 'MongoDB'];
foreach ($dialects as $dialect) {
    foreach (['Wraps' => 'WrapsSteps', 'Counts' => 'CountsBindings', 'Throws' => 'ThrowsFromAStep'] as $name => $trait) {
        eval("class {$name}{$dialect} extends Utopia\\Query\\Builder\\{$dialect} { use {$trait}; }");
    }
}

$filters = [
    'none' => [],
    'equal' => [Query::equal('status', ['active', 'pending'])],
    'equal one' => [Query::equal('status', ['active'])],
    'equal null' => [Query::equal('status', [null, 'a'])],
    'compare' => [Query::greaterThan('age', 18), Query::lessThanEqual('age', 65), Query::notEqual('name', 'x')],
    'like' => [Query::startsWith('name', 'Jo'), Query::endsWith('name', 'n'), Query::notStartsWith('name', 'A'), Query::notEndsWith('name', 'z')],
    'like escapes' => [Query::startsWith('name', '50%_\\!'), Query::endsWith('name', "it's")],
    'nulls' => [Query::isNull('deletedAt'), Query::isNotNull('createdAt')],
    'between' => [Query::between('age', 18, 65), Query::notBetween('score', 1.5, 2.5)],
    'nested' => [Query::or([Query::isNull('deletedAt'), Query::and([Query::lessThan('score', 5), Query::equal('a', [1, 2])])])],
    'find' => [Query::equal('status', ['active', 'pending']), Query::greaterThan('age', 18), Query::startsWith('name', 'Jo'), Query::or([Query::isNull('deletedAt'), Query::lessThan('score', 5)])],
    'bytes' => [Query::equal("n\xffme", ["v\xff"]), Query::startsWith('é', 'ü')],
];
foreach ($dialects as $dialect) {
    foreach ($filters as $name => $queries) {
        $built = [];
        foreach (['Utopia\\Query\\Builder\\' . $dialect, 'Wraps' . $dialect, 'Counts' . $dialect, 'Throws' . $dialect] as $class) {
            $builder = new $class();
            $built[] = outcome(fn () => $builder->from('collection_42')->select(['_uid', 'name'])->filter($queries)->sortDesc('_createdAt')->sortAsc('name')->limit(25)->offset(50)->build());
            $built[] = exported([$builder->seen ?? null, $builder->bound ?? null]);
            $built[] = outcome(fn () => (new $class())->from('t', 'a')->filter($queries)->build());
            $built[] = outcome(fn () => (new $class())->from('t')->select([])->limit(0)->build());
        }
        foreach ($queries as $query) {
            $built[] = outcome(fn () => (new ('Wraps' . $dialect)())->compileFilter($query));
        }
        emit($dialect . ' filter ' . $name, $built);
    }
}

// --- The base builder's methods ---------------------------------------------------------------

use Utopia\Query\NullsPosition;

/** What a builder gives after `$shape` was applied to it: every statement, the raw SQL and the bindings. */
function shaped(string $class, callable $shape): array
{
    $ends = [
        'build' => fn ($b) => $b->build(),
        'raw' => fn ($b) => $b->toRawSql(),
        'bindings' => fn ($b) => $b->getBindings(),
        'explain' => fn ($b) => $b->explain(),
        'analyze' => fn ($b) => $b->explain(true),
        'insert' => fn ($b) => $b->insert(),
        'defaults' => fn ($b) => $b->insertDefaultValues(),
        'update' => fn ($b) => $b->update(),
        'delete' => fn ($b) => $b->delete(),
    ];
    $out = [];
    foreach ($ends as $end) {
        $out[] = outcome(function () use ($class, $shape, $end) {
            $builder = new $class();
            $shape($builder);

            return $end($builder);
        });
    }
    // Twice on one builder, then after a reset.
    $out[] = outcome(function () use ($class, $shape) {
        $builder = new $class();
        $shape($builder);

        return [$builder->build(), $builder->build(), $builder->getBindings(), $builder->reset()->from('r')->build()];
    });

    return $out;
}

$shapes = [
    'empty' => fn ($b) => $b,
    'from' => fn ($b) => $b->from('t'),
    'from alias' => fn ($b) => $b->from('t', 'a'),
    'from none' => fn ($b) => $b->fromNone()->select('1 + ?', [2]),
    'from nothing' => fn ($b) => $b->from(),
    'select columns' => fn ($b) => $b->from('t')->select(['a', 'b.c', '*', 't.*']),
    'select raw' => fn ($b) => $b->from('t')->select('COUNT(*) AS n, ? AS x', ['v'])->select(['a']),
    'select twice' => fn ($b) => $b->from('t')->select(['a'])->select(['b']),
    'distinct' => fn ($b) => $b->from('t')->distinct()->select(['a']),
    'sort' => fn ($b) => $b->from('t')->sortAsc('a')->sortDesc('b', NullsPosition::First)->sortAsc('c', NullsPosition::Last)->sortRandom(),
    'limit' => fn ($b) => $b->from('t')->limit(10),
    'offset' => fn ($b) => $b->from('t')->offset(10),
    'limit offset' => fn ($b) => $b->from('t')->limit(10)->offset(20)->limit(5),
    'negative' => fn ($b) => $b->from('t')->limit(-1)->offset(-5),
    'fetch' => fn ($b) => $b->from('t')->sortAsc('a')->fetch(5),
    'fetch ties' => fn ($b) => $b->from('t')->sortAsc('a')->offset(3)->fetch(5, true),
    'page' => fn ($b) => $b->from('t')->page(3, 10),
    'page default' => fn ($b) => $b->from('t')->page(1),
    'page zero' => fn ($b) => $b->from('t')->page(0),
    'page size zero' => fn ($b) => $b->from('t')->page(2, 0),
    'cursor after' => fn ($b) => $b->from('t')->sortAsc('a')->cursorAfter(['a' => 5, '$id' => 'x']),
    'cursor before' => fn ($b) => $b->from('t')->sortDesc('a')->sortAsc('b')->cursorBefore(['a' => 5, 'b' => 'y']),
    'cursor unsorted' => fn ($b) => $b->from('t')->cursorAfter(['a' => 5]),
    'count' => fn ($b) => $b->from('t')->count(),
    'count alias' => fn ($b) => $b->from('t')->count('a', 'n'),
    'aggregates' => fn ($b) => $b->from('t')->countDistinct('a', 'd')->sum('b', 's')->avg('c')->min('d', 'lo')->max('e', 'hi'),
    'group' => fn ($b) => $b->from('t')->count('*', 'n')->groupBy(['a', 'b'])->having([Query::greaterThan('n', 5)]),
    'having filters' => fn ($b) => $b->from('t')->sum('x', 's')->groupBy(['a'])->having([Query::equal('s', [1, 2]), Query::between('s', 1, 9), Query::isNotNull('s'), Query::notEqual('s', 3)]),
    'having unsupported' => fn ($b) => $b->from('t')->count('*', 'n')->groupBy(['a'])->having([Query::startsWith('n', 'x')]),
    'time bucket' => fn ($b) => $b->from('t')->count('*', 'n')->groupByTimeBucket('createdAt', '1h'),
    'time bucket invalid' => fn ($b) => $b->from('t')->groupByTimeBucket('createdAt', '2h'),
    'join' => fn ($b) => $b->from('t')->join('u', 't.id', 'u.tid'),
    'joins' => fn ($b) => $b->from('t', 'a')->join('u', 'a.id', 'b.tid', '=', 'b')->leftJoin('v', 'a.id', 'v.tid', '!=')->rightJoin('w', 'a.id', 'w.tid', '<', 'c')->filter([Query::equal('b.x', [1])]),
    'join operator' => fn ($b) => $b->from('t')->join('u', 't.id', 'u.tid', 'LIKE'),
    'queries' => fn ($b) => $b->from('t')->queries([Query::select(['a', 'b']), Query::equal('a', [1]), Query::orderDesc('b'), Query::limit(5), Query::offset(2), Query::count('*', 'n'), Query::groupBy(['a'])]),
    'queries and filter' => fn ($b) => $b->from('t')->queries([Query::equal('a', [1])])->filter([Query::equal('b', [2])])->queries([Query::limit(1)]),
    'query joins' => fn ($b) => $b->from('t')->queries([Query::join('u', 'u', [Query::on('t.id', 'u.tid')]), Query::leftJoin('v', '', [Query::on('t.id', 'v.tid'), Query::equal('v.x', [1])]), Query::crossJoin('w', 'w')]),
    'query cursor' => fn ($b) => $b->from('t')->queries([Query::orderAsc('a'), Query::cursorAfter(['a' => 1])]),
    'insert row' => fn ($b) => $b->into('t')->set(['a' => 1, 'b' => 'x', 'c' => null, 'd' => 1.5, 'e' => true]),
    'insert rows' => fn ($b) => $b->into('t')->set(['a' => 1, 'b' => 'x'])->set(['a' => 2, 'b' => 'y']),
    'insert uneven rows' => fn ($b) => $b->into('t')->set(['a' => 1])->set(['b' => 2]),
    'insert empty row' => fn ($b) => $b->into('t')->set([]),
    'insert nested' => fn ($b) => $b->into('t')->set(['a' => [1, 2], 'b' => ['k' => 'v']]),
    'insert alias' => fn ($b) => $b->into('t')->insertAs('new')->set(['a' => 1]),
    'insert conflict' => fn ($b) => $b->into('t')->set(['id' => 1, 'a' => 2])->onConflict(['id'], ['a']),
    'no table' => fn ($b) => $b->set(['a' => 1]),
    'update' => fn ($b) => $b->from('t')->set(['a' => 1, 'b' => null])->filter([Query::equal('id', [5])]),
    'update sorted' => fn ($b) => $b->from('t')->set(['a' => 1])->sortAsc('id')->limit(3),
    'delete' => fn ($b) => $b->from('t')->filter([Query::lessThan('age', 3)])->sortDesc('id')->limit(2),
    'bytes' => fn ($b) => $b->from("t\xff")->select(["c\xfe"])->sortAsc("s\xfd")->set(["k\xfc" => "v\xfb"]),
    'unicode' => fn ($b) => $b->from('té')->select(['ü'])->filter([Query::equal('日本', ['語'])])->set(['ключ' => 'значение']),
    'odd names' => fn ($b) => $b->from('a`b"c')->select(["x'y", 'p.q.r', ''])->sortAsc('s t'),
    'control name' => fn ($b) => $b->from("t\n")->select(['a']),
];
foreach ($dialects as $dialect) {
    foreach ($shapes as $name => $shape) {
        emit($dialect . ' shape ' . $name, [shaped('Utopia\\Query\\Builder\\' . $dialect, $shape), shaped('Wraps' . $dialect, $shape)]);
    }
}

// The compile methods a query calls back, on their own.
$compiled = [
    'compileOrder' => [Query::orderAsc('a'), Query::orderDesc('b', NullsPosition::Last), Query::orderRandom(), Query::equal('a', [1])],
    'compileLimit' => [Query::limit(5), Query::equal('a', [1])],
    'compileOffset' => [Query::offset(5)],
    'compileSelect' => [Query::select(['a', 'b.c', '*']), Query::select([])],
    'compileCursor' => [Query::cursorAfter(['a' => 1]), Query::cursorBefore(['a' => 1])],
    'compileAggregate' => [Query::count('*', 'n'), Query::sum('a'), Query::avg('b', 'm'), Query::countDistinct('c'), Query::equal('a', [1])],
    'compileGroupBy' => [Query::groupBy(['a', 'b']), Query::groupBy([])],
    'compileJoin' => [Query::join('u', 'x', [Query::on('t.id', 'x.tid')]), Query::crossJoin('w', 'w'), Query::equal('a', [1])],
];
foreach ($dialects as $dialect) {
    $out = [];
    foreach ($compiled as $method => $queries) {
        foreach ($queries as $query) {
            $out[] = outcome(function () use ($dialect, $method, $query) {
                $builder = (new ('Utopia\\Query\\Builder\\' . $dialect)())->from('t');

                return [$builder->{$method}($query), $builder->getBindings(), $query->compile($builder)];
            });
        }
    }
    emit($dialect . ' compile methods', $out);
}
