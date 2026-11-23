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
    } catch (TypeError $e) {
        // The wording of an argument's type error names the PHP function, which the extension's does not.
        return ['throws' => TypeError::class];
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

// --- Builders inside builders -----------------------------------------------------------------

// A subquery is a builder of its own: the same class, another dialect's, or a subclass whose overrides have
// to apply inside the statement that holds it.
foreach ($dialects as $dialect) {
    $class = 'Utopia\\Query\\Builder\\' . $dialect;
    $inner = [
        'same' => fn () => (new $class())->from('u')->select(['id'])->filter([Query::equal('x', [1, 2])]),
        'wraps' => fn () => (new ('Wraps' . $dialect)())->from('u')->select(['id'])->filter([Query::equal('x', [1]), Query::startsWith('n', 'a')]),
        'throws' => fn () => (new ('Throws' . $dialect)())->from('u')->filter([Query::equal('x', [1])]),
        'mysql' => fn () => (new Builder\MySQL())->from('u')->select(['id'])->limit(3),
        'postgres' => fn () => (new Builder\PostgreSQL())->from('u')->select(['id'])->filter([Query::equal('x', ['é'])]),
        'empty' => fn () => new $class(),
    ];
    $nested = [
        'fromSub' => fn ($b, $sub) => $b->fromSub($sub, 's')->select(['id'])->filter([Query::greaterThan('id', 5)]),
        'selectSub' => fn ($b, $sub) => $b->from('t')->select(['a'])->selectSub($sub, 'total'),
        'filterWhereIn' => fn ($b, $sub) => $b->from('t')->filter([Query::equal('a', [9])])->filterWhereIn('id', $sub),
        'filterWhereNotIn' => fn ($b, $sub) => $b->from('t')->filterWhereNotIn('id', $sub)->limit(2),
        'filterExists' => fn ($b, $sub) => $b->from('t')->filterExists($sub),
        'filterNotExists' => fn ($b, $sub) => $b->from('t')->filterNotExists($sub)->filter([Query::isNull('z')]),
        'union' => fn ($b, $sub) => $b->from('t')->select(['id'])->filter([Query::equal('a', [7])])->union($sub),
        'unionAll' => fn ($b, $sub) => $b->from('t')->select(['id'])->unionAll($sub)->sortAsc('id')->limit(4),
        'intersect' => fn ($b, $sub) => $b->from('t')->select(['id'])->intersect($sub),
        'intersectAll' => fn ($b, $sub) => $b->from('t')->select(['id'])->intersectAll($sub),
        'except' => fn ($b, $sub) => $b->from('t')->select(['id'])->except($sub),
        'exceptAll' => fn ($b, $sub) => $b->from('t')->select(['id'])->exceptAll($sub),
        'two unions' => fn ($b, $sub) => $b->from('t')->select(['id'])->union($sub)->unionAll($sub),
        'with' => fn ($b, $sub) => $b->with('recent', $sub)->from('recent')->select(['id']),
        'with columns' => fn ($b, $sub) => $b->with('recent', $sub, ['a', 'b'])->from('recent')->filter([Query::equal('a', [1])]),
        'withRecursive' => fn ($b, $sub) => $b->withRecursive('tree', $sub, ['id'])->from('tree'),
        'seed and step' => fn ($b, $sub) => $b->withRecursiveSeedStep('tree', $sub, (new ($b::class)())->from('tree')->select(['id']), ['id'])->from('tree'),
        'fromSelect' => fn ($b, $sub) => $b->into('copy')->fromSelect(['id'], $sub),
        'changed after' => function ($b, $sub) {
            $b->from('t')->filterWhereIn('id', $sub);
            $sub->filter([Query::equal('later', ['yes'])]);

            return $b;
        },
    ];
    foreach ($nested as $name => $shape) {
        $out = [];
        foreach ($inner as $make) {
            foreach ([$class, 'Wraps' . $dialect] as $outer) {
                foreach (['build', 'insertSelect', 'toRawSql', 'getBindings'] as $end) {
                    $out[] = outcome(function () use ($outer, $shape, $make, $end) {
                        $builder = new $outer();
                        $shape($builder, $make());

                        return $builder->{$end}();
                    });
                }
            }
        }
        emit($dialect . ' nested ' . $name, $out);
    }
}

// --- Closures -----------------------------------------------------------------------------------

foreach ($dialects as $dialect) {
    $class = 'Utopia\\Query\\Builder\\' . $dialect;
    $out = [];
    foreach ([$class, 'Wraps' . $dialect, 'Counts' . $dialect] as $made) {
        // when(): the callback is given the builder, only when the condition holds.
        $out[] = outcome(fn () => (new $made())->from('t')->when(true, fn ($b) => $b->filter([Query::equal('a', [1])]))->when(false, fn ($b) => $b->limit(1))->build());
        $out[] = outcome(fn () => (new $made())->from('t')->when(true, function ($b) use ($made) {
            return [$b instanceof $made, $b->limit(3)];
        })->build());
        $out[] = outcome(fn () => (new $made())->from('t')->when(true, fn () => throw new LogicException('in when'))->build());
        // beforeBuild(): each build runs the callbacks, which may change the builder.
        $out[] = outcome(function () use ($made) {
            $runs = 0;
            $builder = (new $made())->from('t')->beforeBuild(function ($b) use (&$runs) {
                $runs++;
                $b->filter([Query::equal('run', [$runs])]);
            })->beforeBuild(fn ($b) => $b->limit(7));

            return [$builder->build(), $builder->build(), $runs, $builder->getBindings(), $builder->toRawSql(), $builder->explain(), $runs];
        });
        $out[] = outcome(fn () => (new $made())->from('t')->beforeBuild(fn () => throw new LogicException('before'))->build());
        // afterBuild(): the statement the last callback returns is the one build() gives.
        $out[] = outcome(function () use ($made) {
            $kept = null;
            $seen = [];
            $builder = (new $made())->from('t')->filter([Query::equal('a', [1])])
                ->afterBuild(function (Builder\Statement $s) use (&$seen) {
                    $seen[] = [$s->query, $s->bindings, $s->readOnly];

                    return new Builder\Statement($s->query . ' /* one */', [...$s->bindings, 'x'], false);
                })
                ->afterBuild(function (Builder\Statement $s) use (&$kept, &$seen) {
                    $seen[] = [$s->query, $s->bindings, $s->readOnly];

                    return $kept = new Builder\Statement($s->query . ' /* two */', $s->bindings, $s->readOnly, namedBindings: ['n' => 1]);
                });
            $built = $builder->build();

            return [$built, $built === $kept, $built->namedBindings, $seen, $builder->explain(), $builder->toRawSql()];
        });
        $out[] = outcome(fn () => (new $made())->from('t')->afterBuild(fn ($s) => $s)->build());
        $out[] = outcome(fn () => (new $made())->from('t')->afterBuild(fn () => throw new LogicException('after'))->build());
        $out[] = outcome(fn () => (new $made())->into('t')->set(['a' => 1])->afterBuild(fn ($s) => new Builder\Statement('changed', []))->insert());
        // setExecutor(): every statement carries it, and execute() builds and runs.
        $out[] = outcome(function () use ($made) {
            $ran = [];
            $builder = (new $made())->from('t')->limit(2)->setExecutor(function (Builder\Statement $s) use (&$ran) {
                $ran[] = $s->query;

                return [['row' => count($ran)]];
            });

            return [$builder->execute(), $builder->build()->execute(), $builder->explain()->execute(), $builder->into('t')->set(['a' => 1])->insert()->execute(), $ran];
        });
        $out[] = outcome(fn () => (new $made())->from('t')->execute());
        $out[] = outcome(fn () => (new $made())->from('t')->setExecutor(fn () => throw new LogicException('executor'))->execute());
        // clone: a copy that shares nothing the original can change, its subqueries included.
        $out[] = outcome(function () use ($made, $class) {
            $sub = (new $class())->from('u')->select(['id']);
            $original = (new $made())->from('t')->filter([Query::equal('a', [1])])->filterWhereIn('id', $sub)->setExecutor(fn () => 5)->beforeBuild(fn ($b) => $b->limit(9));
            $copy = $original->clone();
            $other = clone $original;
            $original->filter([Query::equal('only', ['original'])]);
            $sub->filter([Query::equal('sub', ['changed'])]);
            $copy->filter([Query::equal('only', ['copy'])]);

            return [$copy::class, $copy !== $original, $original->build(), $copy->build(), $other->build(), $copy->execute(), $copy->seen ?? $copy->bound ?? null];
        });
    }
    emit($dialect . ' closures', $out);
}

// --- Joins written in a closure, hooks and windows ----------------------------------------------

use Utopia\Query\Builder\Condition;
use Utopia\Query\Builder\JoinType;
use Utopia\Query\Builder\WindowFrame;
use Utopia\Query\Hook;

/** A hook of every kind that records what it was asked. */
class RecordingHook implements Hook\Filter, Hook\Attribute, Hook\Join\Filter
{
    /** @var list<string> */
    public array $asked = [];

    public function filter(string $table): Condition
    {
        $this->asked[] = 'filter ' . $table;

        return new Condition('tenant = ?', [42]);
    }

    public function resolve(string $attribute): string
    {
        $this->asked[] = 'resolve ' . $attribute;

        return $attribute === 'renamed' ? 'actual' : $attribute;
    }

    public function filterJoin(string $table, JoinType $joinType): ?Hook\Join\Condition
    {
        $this->asked[] = 'join ' . $table . ' ' . $joinType->name;

        return match ($table) {
            'none' => null,
            'on' => new Hook\Join\Condition(new Condition('on.tenant = ?', [1]), Hook\Join\Placement::On),
            default => new Hook\Join\Condition(new Condition($table . '.tenant = ?', [2])),
        };
    }
}
class ThrowingHook implements Hook\Filter
{
    public function filter(string $table): Condition
    {
        throw new DomainException('hook refused ' . $table);
    }
}
class FilterOnlyHook implements Hook\Filter
{
    public function filter(string $table): Condition
    {
        return new Condition("deleted IS NULL /* {$table} */", []);
    }
}
class MarkerHook implements Hook
{
}

foreach ($dialects as $dialect) {
    $class = 'Utopia\\Query\\Builder\\' . $dialect;
    $out = [];
    foreach ([$class, 'Wraps' . $dialect] as $made) {
        $joins = [
            fn ($b) => $b->from('t')->joinWhere('u', fn ($j) => $j->on('t.id', 'u.tid')),
            fn ($b) => $b->from('t')->joinWhere('u', fn ($j) => $j->on('t.id', 'u.tid', '!=')->on('t.a', 'u.a')->where('u.x', '>', 5)->onRaw('u.y = ?', [1])->whereRaw('u.z IN (?, ?)', [2, 3]), JoinType::Left, 'x'),
            fn ($b) => $b->from('t')->joinWhere('u', fn ($j) => $j->on('t.id', 'u.tid'), JoinType::Right)->joinWhere('v', fn ($j) => $j->where('v.a', '=', 'b'), JoinType::FullOuter)->joinWhere('w', fn ($j) => $j, JoinType::Cross, 'c')->joinWhere('n', fn ($j) => $j, JoinType::Natural),
            fn ($b) => $b->from('t')->joinWhere('u', fn ($j) => $j, JoinType::Inner),
            fn ($b) => $b->from('t')->joinWhere('u', fn ($j) => $j->on('bad name', 'u.tid')),
            fn ($b) => $b->from('t')->joinWhere('u', fn ($j) => $j->on('t.id', 'u.tid', 'LIKE')),
            fn ($b) => $b->from('t')->joinWhere('u', fn ($j) => throw new LogicException('in join')),
            fn ($b) => $b->from('t')->filter([Query::equal('a', [1])])->joinWhere('u', fn ($j) => $j->on('t.id', 'u.tid')->where('u.b', '<', 9))->filter([Query::equal('c', [2])])->limit(3),
        ];
        foreach ($joins as $shape) {
            $out[] = outcome(function () use ($made, $shape) {
                $builder = new $made();
                $shape($builder);

                return [$builder->build(), $builder->toRawSql()];
            });
        }
        $hooked = [
            fn ($b, $h) => $b->from('t')->addHook($h)->filter([Query::equal('renamed', [1]), Query::greaterThan('other', 2)])->sortAsc('renamed')->select(['renamed', 'plain']),
            fn ($b, $h) => $b->from('t')->addHook($h)->join('u', 't.id', 'u.tid')->leftJoin('on', 't.id', 'on.tid')->rightJoin('none', 't.id', 'none.tid'),
            fn ($b, $h) => $b->from('t')->addHook($h)->addHook($h)->filter([Query::equal('a', [1])]),
            fn ($b, $h) => $b->from('t')->addHook($h)->set(['renamed' => 1])->filter([Query::equal('id', [2])]),
            fn ($b, $h) => $b->into('t')->addHook($h)->set(['renamed' => 1, 'plain' => 2]),
            fn ($b, $h) => $b->from('t')->addHook($h)->count('renamed', 'n')->groupBy(['renamed'])->having([Query::greaterThan('n', 1)]),
        ];
        foreach ($hooked as $shape) {
            foreach (['build', 'update', 'delete', 'insert'] as $end) {
                $out[] = outcome(function () use ($made, $shape, $end) {
                    $hook = new RecordingHook();
                    $builder = new $made();
                    $shape($builder, $hook);

                    return [$builder->{$end}(), $hook->asked];
                });
            }
        }
        $out[] = outcome(fn () => (new $made())->from('t')->addHook(new Hook\Filter\Tenant(['a', 'b']))->filter([Query::equal('x', [1])])->join('u', 't.id', 'u.tid')->build());
        $out[] = outcome(fn () => (new $made())->from('t')->addHook(new Hook\Filter\Tenant([7], 'org'))->build());
        $out[] = outcome(fn () => (new $made())->from('t')->addHook(new Hook\Attribute\Map(['$id' => '_uid', 'name' => 'full_name']))->select(['$id', 'name', 'age'])->filter([Query::equal('$id', ['x'])])->sortDesc('name')->build());
        $out[] = outcome(fn () => (new $made())->from('t')->addHook(new FilterOnlyHook())->addHook(new MarkerHook())->join('u', 't.id', 'u.tid')->build());
        $out[] = outcome(fn () => (new $made())->from('t')->addHook(new ThrowingHook())->build());
        $windows = [
            fn ($b) => $b->from('t')->selectWindow('ROW_NUMBER()', 'rn'),
            fn ($b) => $b->from('t')->select(['a'])->selectWindow('RANK()', 'r', ['dept'], ['salary', '-age']),
            fn ($b) => $b->from('t')->selectWindow('SUM(amount)', 's', ['a'], ['b'], null, new WindowFrame('ROWS', 'UNBOUNDED PRECEDING', 'CURRENT ROW')),
            fn ($b) => $b->from('t')->selectWindow('AVG(x)', 'm', null, null, 'w')->window('w', ['a', 'b'], ['c'], new WindowFrame('RANGE', 'CURRENT ROW')),
            fn ($b) => $b->from('t')->window('w1', ['a'])->window('w2', null, ['-b'])->selectWindow('COUNT(*)', 'n', windowName: 'w1'),
            fn ($b) => $b->from('t')->selectWindow('DROP TABLE x', 'bad'),
            fn ($b) => $b->from('t')->selectWindow('ROW_NUMBER()', 'r', [], []),
        ];
        foreach ($windows as $shape) {
            $out[] = outcome(function () use ($made, $shape) {
                $builder = new $made();
                $shape($builder);

                return $builder->build();
            });
        }
    }
    emit($dialect . ' joins, hooks and windows', $out);
}

// --- More of the steps a subclass overrides -----------------------------------------------------

use Utopia\Query\Builder\JoinBuilder;
use Utopia\Query\Method;

// Each override tags what it returns and calls the parent, so the statement shows which ran and in what order.
trait TagsSteps
{
    /** @var list<string> */
    public array $ran = [];

    protected function compileNotIn(string $attribute, array $values, ?string $column = null): string
    {
        $this->ran[] = 'notIn';

        return '/*notIn*/' . parent::compileNotIn($attribute, $values, $column);
    }

    protected function compileRegex(string $attribute, array $values, ?string $column = null): string
    {
        $this->ran[] = 'regex';

        return '/*regex*/' . parent::compileRegex($attribute, $values, $column);
    }

    protected function compileContains(string $attribute, array $values, ?string $column = null): string
    {
        $this->ran[] = 'contains';

        return '/*contains*/' . parent::compileContains($attribute, $values, $column);
    }

    protected function compileContainsAll(string $attribute, array $values, ?string $column = null): string
    {
        $this->ran[] = 'containsAll';

        return '/*containsAll*/' . parent::compileContainsAll($attribute, $values, $column);
    }

    protected function compileNotContains(string $attribute, array $values, ?string $column = null): string
    {
        $this->ran[] = 'notContains';

        return '/*notContains*/' . parent::compileNotContains($attribute, $values, $column);
    }

    protected function compileJoinWithBuilder(Query $query, JoinBuilder $joinBuilder): string
    {
        $this->ran[] = 'join ' . count($joinBuilder->ons) . ' ' . count($joinBuilder->wheres);

        return '/*join*/' . parent::compileJoinWithBuilder($query, $joinBuilder);
    }

    protected function resolveAttribute(string $attribute): string
    {
        return parent::resolveAttribute($attribute === 'alias' ? 'actual' : $attribute);
    }

    /** The protected helpers a subclass calls. */
    public function helpers(): array
    {
        $this->addBinding('kept');

        return [
            $this->quote('a.b'), $this->quote('*'), $this->quoteLiteral('a.b'), $this->resolveAttribute('alias'), $this->resolveAndWrap('alias'),
            $this->resolveAndWrap('t.c'), $this->getLikeKeyword(), $this->getBindingValues(),
        ];
    }
}
trait TagsSqlSteps
{
    protected function compileSearchExpression(string $attribute, array $values, bool $not): string
    {
        $this->ran[] = 'search';

        return '/*search*/' . parent::compileSearchExpression($attribute, $values, $not);
    }

    protected function compileSpatialFilter(Method $method, string $attribute, Query $query): string
    {
        $this->ran[] = 'spatial ' . $method->value;

        return '/*spatial*/' . parent::compileSpatialFilter($method, $attribute, $query);
    }

    protected function compileArrayFilter(Method $method, string $attribute, Query $query): string
    {
        $this->ran[] = 'array ' . $method->value;

        return '/*array*/' . parent::compileArrayFilter($method, $attribute, $query);
    }

    protected function compileJsonContainsExpression(string $attribute, array $values, bool $not): string
    {
        $this->ran[] = 'jsonContains';

        return '/*jsonContains*/' . parent::compileJsonContainsExpression($attribute, $values, $not);
    }

    protected function compileJsonOverlapsExpression(string $attribute, array $values): string
    {
        $this->ran[] = 'jsonOverlaps';

        return '/*jsonOverlaps*/' . parent::compileJsonOverlapsExpression($attribute, $values);
    }
}
foreach ($dialects as $dialect) {
    $sql = in_array($dialect, ['MySQL', 'MariaDB', 'PostgreSQL', 'SQLite'], true) ? ' use TagsSqlSteps;' : '';
    eval("class Tags{$dialect} extends Utopia\\Query\\Builder\\{$dialect} { use TagsSteps;{$sql} }");
}

$array = function (Query $query): Query {
    $query->setOnArray(true);

    return $query;
};
$stepped = [
    'not equal' => [Query::notEqual('a', 'x'), Query::notEqual('b', [1, 2]), Query::notEqual('c', null)],
    'regex' => [Query::regex('a', '^x.*$')],
    'contains' => [new Query(Method::Contains, 'a', ['x']), new Query(Method::Contains, 'b', ['x', 'y']), Query::containsAny('c', ['p', 'q'])],
    'contains all' => [Query::containsAll('a', ['x', 'y'])],
    'not contains' => [Query::notContains('a', ['x']), Query::notContains('b', ['x', 'y'])],
    'search' => [Query::search('body', 'hello world'), Query::notSearch('body', 'spam')],
    'on arrays' => [$array(new Query(Method::Contains, 'tags', ['a', 'b'])), $array(Query::containsAll('tags', ['a'])), $array(Query::notContains('tags', ['z'])), $array(Query::containsAny('tags', ['q']))],
    'spatial' => [Query::distanceLessThan('loc', [1.5, 2.5], 100), Query::intersects('area', [[0, 0], [1, 1]]), Query::notIntersects('area', [1, 2])],
    'alias' => [Query::equal('alias', [1]), Query::startsWith('alias', 'x')],
    'nested' => [Query::or([Query::notEqual('a', 1), Query::and([new Query(Method::Contains, 'b', ['x']), Query::regex('c', 'y')])])],
];
foreach ($dialects as $dialect) {
    $out = [];
    foreach ($stepped as $queries) {
        foreach (['Utopia\\Query\\Builder\\' . $dialect, 'Tags' . $dialect] as $class) {
            $out[] = outcome(function () use ($class, $queries) {
                $builder = (new $class())->from('t')->filter($queries);

                return [$builder->build(), $builder->ran ?? null];
            });
            foreach ($queries as $query) {
                $out[] = outcome(fn () => (new $class())->from('t')->compileFilter($query));
            }
        }
    }
    $out[] = outcome(function () use ($dialect) {
        $builder = (new ('Tags' . $dialect)())->from('t')->joinWhere('u', fn ($j) => $j->on('t.id', 'u.tid')->where('u.a', '=', 1)->onRaw('u.b > ?', [2]))->joinWhere('v', fn ($j) => $j, JoinType::Left, 'w');

        return [$builder->build(), $builder->ran];
    });
    $out[] = outcome(fn () => (new ('Tags' . $dialect)())->from('t', 'x')->helpers());
    $out[] = outcome(fn () => (new ('Tags' . $dialect)())->from('t')->join('u', 't.id', 'u.tid')->helpers());
    emit($dialect . ' more steps', $out);
}

// --- What only some dialects have --------------------------------------------------------------

// A shape is applied where the dialect has every method it calls, so each dialect answers for its own.
$featured = [
    'selectRaw' => [['selectRaw'], fn ($b) => $b->from('t')->selectRaw('COUNT(*) AS n')->selectRaw('? + x AS y', [5])->select(['a'])],
    'whereRaw' => [['whereRaw'], fn ($b) => $b->from('t')->whereRaw('a = ? OR b = ?', [1, 'x'])->filter([Query::equal('c', [2])])->whereRaw('d IS NULL')],
    'orderByRaw' => [['orderByRaw'], fn ($b) => $b->from('t')->orderByRaw('FIELD(a, ?, ?)', [1, 2])->sortAsc('b')],
    'groupByRaw' => [['groupByRaw', 'havingRaw'], fn ($b) => $b->from('t')->count('*', 'n')->groupByRaw('DATE(created) , ?', ['x'])->havingRaw('COUNT(*) > ?', [3])],
    'setRaw' => [['setRaw'], fn ($b) => $b->from('t')->set(['a' => 1])->setRaw('n', 'n + ?', [1])->setRaw('m', 'NOW()')->filter([Query::equal('id', [9])])],
    'setRaw alone' => [['setRaw'], fn ($b) => $b->from('t')->setRaw('n', 'n + 1')],
    'insert expression' => [['insertColumnExpression'], fn ($b) => $b->into('t')->set(['a' => 1, 'g' => 'POINT(1 2)'])->insertColumnExpression('g', 'ST_GeomFromText(?, ?)', [4326])],
    'conflict raw' => [['conflictSetRaw'], fn ($b) => $b->into('t')->set(['id' => 1, 'n' => 2, 'm' => 3])->onConflict(['id'], ['n', 'm'])->conflictSetRaw('n', 'n + ?', [1])],
    'for update' => [['forUpdate'], fn ($b) => $b->from('t')->filter([Query::equal('id', [1])])->forUpdate()],
    'for share' => [['forShare'], fn ($b) => $b->from('t')->forShare()->limit(1)],
    'upsert' => [['upsert'], fn ($b) => $b->into('t')->set(['id' => 1, 'a' => 2, 'b' => 3])->onConflict(['id'], ['a', 'b'])],
    'upsert rows' => [['upsert'], fn ($b) => $b->into('t')->set(['id' => 1, 'a' => 2])->set(['id' => 2, 'a' => 3])->onConflict(['id'], ['a'])],
    'upsert no keys' => [['upsert'], fn ($b) => $b->into('t')->set(['id' => 1, 'a' => 2])],
    'upsert no update' => [['upsert'], fn ($b) => $b->into('t')->set(['id' => 1])->onConflict(['id'], [])],
    'upsert select' => [['upsertSelect'], fn ($b) => $b->into('t')->fromSelect(['id', 'a'], (new ($b::class)())->from('u')->select(['id', 'a']))->onConflict(['id'], ['a'])],
    'returning' => [['returning'], fn ($b) => $b->into('t')->set(['a' => 1])->returning()],
    'returning columns' => [['returning'], fn ($b) => $b->from('t')->set(['a' => 1])->filter([Query::equal('id', [1])])->returning(['id', 'a'])],
    'returning upsert' => [['returning', 'upsert'], fn ($b) => $b->into('t')->set(['id' => 1, 'a' => 2])->onConflict(['id'], ['a'])->returning(['id'])],
    'updateJoin' => [['updateJoin'], fn ($b) => $b->from('t')->set(['a' => 1])->updateJoin('u', 't.id', 'u.tid', 'x')->filter([Query::equal('x.b', [2])])],
    'deleteJoin' => [['deleteJoin'], fn ($b) => $b->from('t')->deleteJoin('t', 'u', 't.id', 'u.tid')->filter([Query::equal('u.b', [2])])],
    'updateFrom' => [['updateFrom'], fn ($b) => $b->from('t')->set(['a' => 1])->updateFrom('u', 'x')->whereRaw('t.id = x.tid')],
    'deleteUsing' => [['deleteUsing'], fn ($b) => $b->from('t')->deleteUsing('u', 't.id = u.tid AND u.a = ? AND u.b = ?', 1, 'x')->filter([Query::equal('c', [3])])],
];
$ends = [
    'build' => fn ($b) => $b->build(),
    'insert' => fn ($b) => $b->insert(),
    'update' => fn ($b) => $b->update(),
    'delete' => fn ($b) => $b->delete(),
    'upsert' => fn ($b) => $b->upsert(),
    'upsertSelect' => fn ($b) => $b->upsertSelect(),
    'insertOrIgnore' => fn ($b) => $b->insertOrIgnore(),
    'insertSelect' => fn ($b) => $b->insertSelect(),
    'raw' => fn ($b) => $b->toRawSql(),
];
foreach ($dialects as $dialect) {
    foreach ($featured as $name => [$needs, $shape]) {
        $out = [];
        foreach (['Utopia\\Query\\Builder\\' . $dialect, 'Wraps' . $dialect] as $class) {
            if (array_filter($needs, fn ($method) => ! method_exists($class, $method))) {
                $out[] = 'no such method';

                continue;
            }
            foreach ($ends as $method => $end) {
                $out[] = ! method_exists($class, $method === 'raw' ? 'toRawSql' : $method) ? 'no such end' : outcome(function () use ($class, $shape, $end) {
                    $builder = new $class();
                    $shape($builder);

                    return $end($builder);
                });
            }
        }
        emit($dialect . ' feature ' . $name, $out);
    }
    // explain() takes what the dialect's EXPLAIN takes.
    $explained = [];
    $parameters = count((new ReflectionMethod('Utopia\\Query\\Builder\\' . $dialect, 'explain'))->getParameters());
    foreach ([[], [true], [false, 'JSON'], [true, 'json'], [true, true, true, 'YAML'], [false, false, true], [true, 'tree'], [false, 'bad format']] as $arguments) {
        if (count($arguments) > $parameters) {
            continue;
        }
        $explained[] = outcome(fn () => (new ('Utopia\\Query\\Builder\\' . $dialect)())->from('t')->filter([Query::equal('a', [1])])->explain(...$arguments));
    }
    emit($dialect . ' explain', $explained);
}

// A subclass's own build(), and the protected properties it reads and writes.
trait OwnsBuild
{
    public int $builds = 0;

    public function build(): Builder\Statement
    {
        $this->builds++;
        $built = parent::build();

        return new Builder\Statement('/*own*/' . $built->query, $built->bindings, $built->readOnly);
    }
}
trait OwnsState
{
    /** @return array<mixed> */
    public function peek(): array
    {
        // The cache has no order worth keeping: the extension's is a hash map.
        $resolved = $this->resolvedAttributeCache;
        ksort($resolved);

        return [
            array_map(fn (Builder\Binding $binding) => [$binding->value, $binding->column], $this->bindings),
            array_map(fn (Query $query) => $query->toString(), $this->pendingQueries),
            $resolved,
        ];
    }

    public function wipe(): static
    {
        $this->bindings = [];
        $this->resolvedAttributeCache = [];

        return $this;
    }

    public function rewrite(): static
    {
        $this->pendingQueries = array_map(
            fn (Query $query) => $query->getMethod() === Utopia\Query\Method::Equal ? Query::notEqual($query->getAttribute(), $query->getValues()[0]) : $query,
            $this->pendingQueries,
        );

        return $this;
    }

    public function poke(string $attribute): static
    {
        $this->pendingQueries[] = Query::greaterThan($attribute, 7);
        $this->bindings[] = new Builder\Binding('pushed', 'col');
        $this->resolvedAttributeCache['seen'] = 'as';
        $this->pendingQueries[0] = Query::select(['z']);
        $this->bindings[0] = new Builder\Binding($attribute, null);
        unset($this->resolvedAttributeCache['gone']);

        return $this;
    }

    public function grow(): static
    {
        $this->pendingQueries = [...$this->pendingQueries, Query::greaterThan('grown', 7)];
        $this->bindings = [...$this->bindings, new Builder\Binding('extra', 'col')];

        return $this;
    }
}
foreach ($dialects as $dialect) {
    eval("class Owns{$dialect} extends Utopia\\Query\\Builder\\{$dialect} { use OwnsBuild; use OwnsState; }");
    $class = 'Owns' . $dialect;
    $start = function () use ($class) {
        // What a query holds beyond its constructor's arguments survives being read and written back.
        $tags = new Query(Utopia\Query\Method::Contains, 'tags', ['x', 'y']);
        $tags->setOnArray(true);
        $typed = Query::equal('data.k', ['v']);
        $typed->setAttributeType('object');

        return (new $class())->from('t')->select(['a'])->filter([Query::equal('a', [1]), Query::lessThan('b', 2), $tags, $typed])->sortAsc('a');
    };
    emit($dialect . ' own build', [
        outcome(fn () => $start()->build()),
        outcome(fn () => method_exists($class, 'toRawSql') ? $start()->toRawSql() : 'none'),
        outcome(fn () => (new ReflectionMethod($class, 'explain'))->getNumberOfRequiredParameters() === 0 ? $start()->explain() : 'none'),
        outcome(function () use ($start) {
            $builder = $start();
            $builder->build();
            method_exists($builder, 'toRawSql') && $builder->toRawSql();

            return $builder->builds;
        }),
    ]);
    emit($dialect . ' own state', [
        outcome(fn () => $start()->peek()),
        outcome(function () use ($start) {
            $builder = $start();
            $builder->build();

            return $builder->peek();
        }),
        outcome(function () use ($start) {
            $builder = $start();
            $builder->build();

            return [$builder->wipe()->peek(), $builder->getBindings()];
        }),
        outcome(fn () => $start()->rewrite()->build()),
        outcome(fn () => $start()->rewrite()->peek()),
        outcome(fn () => $start()->poke('pushed')->peek()),
        outcome(fn () => $start()->poke('pushed')->build()),
        outcome(fn () => $start()->poke('one')->limit(3)->poke('two')->peek()),
        outcome(fn () => $start()->poke('one')->poke('two')->build()),
        outcome(fn () => $start()->grow()->peek()),
        outcome(fn () => $start()->grow()->build()),
        outcome(fn () => array_map(fn (string $property) => (new ReflectionProperty($class, $property))->isProtected(), ['bindings', 'pendingQueries', 'resolvedAttributeCache'])),
    ]);
}

// The interfaces a builder implements, of those the extension has so far, and what a subclass can
// rely on: fluent methods return static, and a constructor need not call the parent's.
$features = ['Aggregates', 'CTEs', 'Deletes', 'Hooks', 'InsertOrIgnore', 'Inserts', 'Joins', 'MariaDB\\Returning', 'PostgreSQL\\Returning', 'Selects', 'Unions', 'Updates', 'Upsert', 'UpsertSelect', 'Windows'];
foreach ($dialects as $dialect) {
    eval("class Built{$dialect} extends Utopia\\Query\\Builder\\{$dialect} { public function __construct(public bool \$flag = false) {} public function from(string \$table = '', string \$alias = ''): static { return parent::from('own_' . \$table, \$alias); } }");
    $class = 'Built' . $dialect;
    emit($dialect . ' as a class', [
        array_values(array_filter($features, fn (string $feature) => is_subclass_of($class, 'Utopia\\Query\\Builder\\Feature\\' . $feature))),
        array_map(fn (string $method) => (string) (new ReflectionMethod('Utopia\\Query\\Builder\\' . $dialect, $method))->getReturnType(), ['from', 'select', 'filter', 'limit', 'set']),
        (string) (new ReflectionMethod('Utopia\\Query\\Builder\\Feature\\Selects', 'from'))->getReturnType(),
        outcome(fn () => (new $class(true))->from('t')->select(['a'])->build()),
        outcome(fn () => (clone new $class())->from('t')->build()),
        outcome(fn () => (new $class(true))->flag),
    ]);
}

// Methods called by name with each argument list, on every dialect that has them, ended every way.
$calls = [
    'crossJoin' => [['u'], ['u', 'x'], ['bad`name', 'x y']],
    'naturalJoin' => [['u'], ['u', 'x']],
    'fullOuterJoin' => [['u', 't.id', 'u.tid'], ['u', 't.id', 'u.tid', '<', 'x'], ['u', 't.id', 'u.tid', 'bad']],
    'forUpdateSkipLocked' => [[]], 'forUpdateNoWait' => [[]], 'forShareSkipLocked' => [[]], 'forShareNoWait' => [[]],
    'stddev' => [['a'], ['a', 's'], ['a b', 'x']], 'stddevPop' => [['a'], ['a', 's']], 'stddevSamp' => [['a', 's']],
    'variance' => [['a'], ['a', 'v']], 'varPop' => [['a', 'v']], 'varSamp' => [['a']],
    'bitAnd' => [['a'], ['a', 'x']], 'bitOr' => [['a', 'x']], 'bitXor' => [['a']],
    'selectCast' => [['a', 'INT'], ['a', 'DECIMAL(10,2)', 'd'], ['a', 'drop table'], ['a', 'INT', 'bad alias']],
    'whereColumn' => [['a', '=', 'b'], ['t.a', '<=', 'u.b'], ['a', 'LIKE', 'b'], ['a', ';', 'b']],
    'withRollup' => [[]], 'withCube' => [[]], 'withTotals' => [[]],
    'hint' => [['NO_INDEX_MERGE(t)'], ['max_threads=4'], ['bad;hint'], ['']],
    'countWhen' => [['a > ?', 'n', 1], ['a > 1'], ['a > ? AND b = ?', '', 1, 'x'], ['a', 'bad alias']],
    'sumWhen' => [['a', 'b > ?', 's', 1], ['a', 'b > 1']], 'avgWhen' => [['a', 'b > ?', 's', 1.5]],
    'minWhen' => [['a', 'b IS NULL']], 'maxWhen' => [['a', 'b = ?', 'm', true]],
    'jsonArrayAgg' => [['a'], ['a', 'j']], 'jsonObjectAgg' => [['k', 'v'], ['k', 'v', 'o']],
    'filterJsonContains' => [['tags', 'x'], ['tags', ['x', 'y']], ['tags', 5], ['tags', null], ['tags', ['k' => 'v']]],
    'filterJsonNotContains' => [['tags', 'x'], ['tags', [1, 2]]],
    'filterJsonOverlaps' => [['tags', ['x', 'y']], ['tags', []], ['tags', [1, 'a', true, null]]],
    'filterJsonPath' => [['data', 'a.b', '=', 1], ['data', '$.a[0]', '>', 'x'], ['data', 'a', 'bad', 1], ['data', "a'b", '=', 1]],
    'setJsonAppend' => [['tags', ['x']], ['tags', []], ['tags', ['x', 2, null]]],
    'setJsonPrepend' => [['tags', ['x', 'y']]],
    'setJsonInsert' => [['tags', 0, 'x'], ['tags', 3, ['a' => 1]], ['tags', -1, 'x']],
    'setJsonRemove' => [['tags', 'x'], ['tags', 5], ['tags', ['a']]],
    'setJsonIntersect' => [['tags', ['x', 'y']]], 'setJsonDiff' => [['tags', ['x']]], 'setJsonUnique' => [['tags']],
    'setJsonPath' => [['data', '$.a.b', 1], ['data', 'a', 'x'], ['data', "$.a'", null]],
    'filterSearch' => [['body', 'hello world'], ['body', ''], ['body', '+a -b "c d"*']],
    'filterNotSearch' => [['body', 'hello'], ['body', 'a@b.c']],
    'tablesample' => [[10.0], [0.5, 'SYSTEM'], [150.0], [10, 'bad'], [-1.0]],
    'nextVal' => [['seq'], ['seq', 'n'], ['bad seq']], 'currVal' => [['seq', 'c']],
    'filterDistance' => [['loc', [1.0, 2.0], '<', 100.0], ['loc', [1, 2], '>=', 5, true], ['loc', [1, 2], 'bad', 5.0], ['loc', [], '<', 1.0]],
    'filterIntersects' => [['area', [1, 2]], ['area', [[0, 0], [1, 1]]], ['area', [[[0, 0], [1, 0], [1, 1], [0, 0]]]], ['area', []]],
    'filterNotIntersects' => [['area', [1, 2]]], 'filterCrosses' => [['area', [[0, 0], [1, 1]]]], 'filterNotCrosses' => [['area', [1, 2]]],
    'filterOverlaps' => [['area', [[[0, 0], [1, 0], [1, 1], [0, 0]]]]], 'filterNotOverlaps' => [['area', [1, 2]]],
    'filterTouches' => [['area', [1, 2]]], 'filterNotTouches' => [['area', [1, 2]]],
    'filterCovers' => [['area', [1, 2]]], 'filterNotCovers' => [['area', [1, 2]]],
    'filterSpatialEquals' => [['area', [1, 2]]], 'filterNotSpatialEquals' => [['area', [1, 2]]],
];
$finishes = ['build', 'update', 'delete', 'insert', 'toRawSql'];
foreach ($dialects as $dialect) {
    foreach ($calls as $method => $lists) {
        foreach (['Utopia\\Query\\Builder\\' . $dialect, 'Wraps' . $dialect] as $class) {
            // MongoDB's hint() takes a key pattern too, and is not moved yet.
            if (! method_exists($class, $method) || ($dialect === 'MongoDB' && $method === 'hint')) {
                continue;
            }
            $out = [];
            foreach ($lists as $arguments) {
                foreach ($finishes as $finish) {
                    $out[] = ! method_exists($class, $finish) ? 'none' : outcome(function () use ($class, $method, $arguments, $finish) {
                        $builder = (new $class())->from('t', 't')->select(['a'])->set(['z' => 1])->filter([Query::equal('c', [1])]);
                        $builder->{$method}(...$arguments);

                        return [$builder->{$finish}(), $builder->getBindings()];
                    });
                }
            }
            emit($class . ' ' . $method, $out);
        }
    }
    // The transaction statements.
    $class = 'Utopia\\Query\\Builder\\' . $dialect;
    if (method_exists($class, 'begin')) {
        emit($dialect . ' transactions', [
            outcome(fn () => (new $class())->begin()), outcome(fn () => (new $class())->commit()), outcome(fn () => (new $class())->rollback()),
            outcome(fn () => (new $class())->savepoint('sp')), outcome(fn () => (new $class())->savepoint('bad name')), outcome(fn () => (new $class())->savepoint('')),
            outcome(fn () => (new $class())->releaseSavepoint('sp')), outcome(fn () => (new $class())->rollbackToSavepoint('sp')), outcome(fn () => (new $class())->rollbackToSavepoint('a;b')),
        ]);
    }
}

// Every public method's signature, and every interface a builder implements. A subclass that overrides a
// method with PHP's types must load, and `instanceof` a feature must answer as PHP does.
foreach ($dialects as $dialect) {
    $class = new ReflectionClass('Utopia\\Query\\Builder\\' . $dialect);
    $signatures = [];
    foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        // PHP declares __clone() and no constructor; the extension clones in its handler and has a constructor.
        if (in_array($method->getName(), ['__clone', '__construct'], true)) {
            continue;
        }
        $parameters = array_map(function (ReflectionParameter $parameter): string {
            $default = '';
            if ($parameter->isDefaultValueAvailable()) {
                $value = $parameter->getDefaultValue();
                $default = ' = ' . ($value instanceof UnitEnum ? $value::class . '::' . $value->name : json_encode($value));
            }

            return ($parameter->getType() ?? '') . ' ' . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName() . $default;
        }, $method->getParameters());
        $signatures[$method->getName()] = ($method->isStatic() ? 'static ' : '') . '(' . implode(', ', $parameters) . '): ' . ($method->getReturnType() ?? '');
    }
    ksort($signatures);
    $interfaces = $class->getInterfaceNames();
    sort($interfaces);
    emit($dialect . ' signatures', [$signatures, $interfaces]);
}

// The protected helpers of a build: a subclass that overrides every one it has, declared with the signature
// reflection gives, tags what each returns and records the order they ran in. Then each is called on its own.
$helpers = [
    'compileRandom' => [[]], 'buildTableClause' => [[]], 'buildAfterGroupByClause' => [[]], 'buildAfterOrderByClause' => [[]],
    'buildSettingsClause' => [[]], 'wrapUnionMember' => [['SELECT 1'], ['']],
    'shouldEmitOffset' => [[null, null], [5, null], [null, 5], [0, 10], [3, 10]],
    'compileHavingCondition' => [[Query::greaterThan('total', 5), 'SUM(`x`)'], [Query::between('n', 1, 2), 'COUNT(*)']],
    'compileGroupByTimeBucket' => [['created', '1h'], ['created', '1d'], ['created', 'bad']],
    'compileLikePredicate' => [['`a`'], ['`a`', true]], 'likeEscape' => [[]],
    'compileComparison' => [['`a`', '>', [5]], ['`a`', '=', ['x', 'y'], 'a']],
    'enclose' => [['a = 1'], ['']], 'escapeLikeValue' => [['50%_!\\'], [5], [1.5], [true], [null], [['a' => '%']]],
    'compileSpatialDistance' => [[Utopia\Query\Method::DistanceLessThan, '`loc`', [[[1.0, 2.0], 100.0, false]]], [Utopia\Query\Method::DistanceEqual, '`loc`', [[[1, 2], 5, true]]]],
    'compileSpatialPredicate' => [['ST_Intersects', '`area`', [[1, 2]], false], ['ST_Touches', '`area`', [[[0, 0], [1, 1]]], true]],
    'compileSpatialCoversPredicate' => [['`area`', [[1, 2]], false], ['`area`', [[1, 2]], true]],
    'compileJsonPathExpression' => [['`data`', ['a.b', '=', 1]], ['`data`', ['$.a', '>', 'x']]],
    'compileJsonFilter' => [[Utopia\Query\Method::JsonContains, '`tags`', Query::equal('tags', ['x'])], [Utopia\Query\Method::JsonOverlaps, '`tags`', Query::equal('tags', [['x', 'y']])]],
    'compileConflictHeader' => [[]], 'compileConflictAssignment' => [['`a`'], ['']], 'compileConflictClause' => [[]],
    'groupConcatExpr' => [['`a`', ''], ['`a`', 'ORDER BY `b` ASC']], 'jsonArrayAggExpr' => [['`a`']], 'jsonObjectAggExpr' => [['`k`', '`v`']],
    'geomFromText' => [[4326], [0]],
    'compileObjectFilter' => [[Query::equal('data.k', ['v'])], [new Query(Utopia\Query\Method::Contains, 'data.tags', ['x'])], [Query::greaterThan('data.n', 3)]],
    'compileJsonbContainment' => [['"data"', ['x'], false, false], ['"data"', ['x', 2], true, true], ['"data"', [], false, true]],
    'buildJsonbPath' => [['data'], ['data.a.b'], ["data.a'b"]],
];
$declaration = function (ReflectionMethod $method): string {
    $parameters = [];
    $passed = [];
    foreach ($method->getParameters() as $parameter) {
        $default = $parameter->isDefaultValueAvailable() ? ' = ' . var_export($parameter->getDefaultValue(), true) : '';
        $parameters[] = ($parameter->getType() ?? '') . ' ' . ($parameter->isPassedByReference() ? '&' : '') . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName() . $default;
        $passed[] = ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName();
    }
    $name = $method->getName();

    $returns = (string) $method->getReturnType();
    $back = $returns === 'void' ? '' : " return is_string(\$out) ? '/*{$name}*/' . \$out : \$out;";

    return "protected function {$name}(" . implode(', ', $parameters) . '): ' . $returns . " { \$this->ran[] = '{$name}'; \$out = parent::{$name}(" . implode(', ', $passed) . ");{$back} }";
};
$builds = [
    'filters' => fn ($b) => $b->from('t')->select(['a'])->filter([Query::startsWith('n', 'a%'), new Query(Utopia\Query\Method::Contains, 'n', ['x']), Query::greaterThan('a', 1), Query::between('b', 1, 2)])->build(),
    'random page' => fn ($b) => $b->from('t')->sortRandom()->limit(5)->offset(10)->build(),
    'offset alone' => fn ($b) => $b->from('t')->offset(10)->build(),
    'grouped' => fn ($b) => $b->from('t')->sum('x', 'total')->groupBy(['c'])->having([Query::greaterThan('total', 5)])->build(),
    'bucketed' => fn ($b) => $b->from('t')->count('*', 'n')->groupByTimeBucket('created', '1h')->build(),
    'union' => fn ($b) => $b->from('t')->select(['a'])->union((new ($b::class)())->from('u')->select(['a']))->build(),
    'spatial' => fn ($b) => $b->from('t')->filterIntersects('area', [1, 2])->filterCovers('area', [1, 2])->filterDistance('loc', [1, 2], '<', 5.0)->build(),
    'json' => fn ($b) => $b->from('t')->filterJsonContains('tags', 'x')->filterJsonPath('data', 'a.b', '=', 1)->build(),
    'upsert' => fn ($b) => $b->into('t')->set(['id' => 1, 'a' => 2])->onConflict(['id'], ['a'])->upsert(),
    'aggregates' => fn ($b) => $b->from('t')->groupConcat('a', ',', 'g', ['b'])->jsonArrayAgg('a', 'j')->jsonObjectAgg('k', 'v', 'o')->build(),
    'update' => fn ($b) => $b->from('t')->set(['a' => 1, 'b' => 'x'])->filter([Query::equal('id', [1])])->sortAsc('id')->limit(1)->update(),
    'insert' => fn ($b) => $b->into('t')->set(['a' => 1])->set(['a' => 2])->insert(),
    'delete' => fn ($b) => $b->from('t')->filter([Query::equal('id', [1])])->delete(),
    'case' => fn ($b) => $b->from('t')->selectCase((new Builder\Case\Expression())->when('a', Builder\Case\Operator::GreaterThan, 5, 'big')->whenIn('a', [1, 2], 'few')->whenRaw('a < ?', 'neg', [0])->else('small')->alias('size'))->build(),
    'returning' => fn ($b) => $b->into('t')->set(['a' => 1])->returning(['id'])->insert(),
    'vector' => fn ($b) => $b->from('t')->orderByVectorDistance('embedding', [0.1, 0.2])->limit(3)->build(),
    'joined' => fn ($b) => $b->from('t')->join('u', 't.id', 'u.tid')->crossJoin('v')->rightJoin('w', 't.id', 'w.tid')->filter([Query::equal('a', [1])])->build(),
    'raw' => fn ($b) => $b->from('t')->whereRaw('a = ? OR b = ?', [1, 2])->filter([Query::raw('c > ?', [3])])->count('*', 'n')->groupBy(['c'])->havingRaw('COUNT(*) > ?', [1])->build(),
    'hooked joins' => fn ($b) => $b->from('t')->addHook(new RecordingHook())->join('on', 't.id', 'on.tid')->leftJoin('u', 't.id', 'u.tid')->crossJoin('x')->build(),
    'rolled up and reset' => fn ($b) => [$b->from('t')->count('*', 'n')->groupBy(['c'])->withRollup()->build(), $b->reset()->from('u')->build()],
    'object' => function ($b) {
        $typed = Query::equal('data.k', ['v']);
        $typed->setAttributeType('object');

        return $b->from('t')->filter([$typed])->build();
    },
];
// quote(), getLikeKeyword(), getBindingValues() and geometryToWkt() changed by a subclass: every
// statement kind asks them, as does a method called first on a new object or on a clone.
class QuotesItsOwn extends Builder\MySQL
{
    protected function quote(string $identifier): string
    {
        if ($identifier === 'boom') {
            throw new RuntimeException('no ' . $identifier);
        }

        return '<' . parent::quote($identifier) . '>';
    }
}
class QuotesClickHouse extends Builder\ClickHouse
{
    protected function quote(string $identifier): string
    {
        return '<' . $identifier . '>';
    }
}
class LikesItsOwn extends Builder\PostgreSQL
{
    protected function getLikeKeyword(): string
    {
        return parent::getLikeKeyword() . ' /* own */';
    }
}
class BindsItsOwn extends Builder\MySQL
{
    protected function getBindingValues(): array
    {
        return [...parent::getBindingValues(), 'own'];
    }
}
class BindsMongoDB extends Builder\MongoDB
{
    protected function getBindingValues(): array
    {
        return array_reverse(parent::getBindingValues());
    }
}
class ShapesItsOwn extends Builder\PostgreSQL
{
    protected function geometryToWkt(array $geometry): string
    {
        return 'SRID=4326;' . parent::geometryToWkt($geometry);
    }
}
$mixed = [Query::equal('x', [1]), Query::startsWith('y', 'a'), Query::orderAsc('z')];
foreach ([QuotesItsOwn::class, QuotesClickHouse::class, LikesItsOwn::class, BindsItsOwn::class, BindsMongoDB::class, ShapesItsOwn::class] as $own) {
    emit($own . ' select', outcome(fn () => (new $own())->from('t', 'al')->select(['a', 'u.b'])->filter($mixed)->groupBy(['a'])->build()));
    emit($own . ' insert', outcome(fn () => (new $own())->into('t')->set(['a' => 1, 'b' => 2])->insert()));
    emit($own . ' update', outcome(fn () => (new $own())->from('t')->set(['a' => 1])->filter($mixed)->update()));
    emit($own . ' delete', outcome(fn () => (new $own())->from('t')->filter($mixed)->delete()));
    emit($own . ' getBindings() first', outcome(fn () => (new $own())->getBindings()));
    emit($own . ' getBindings() after a build', outcome(function () use ($own, $mixed) {
        $builder = (new $own())->from('t')->filter($mixed);

        return [$builder->build(), $builder->getBindings(), (clone $builder)->getBindings()];
    }));
}
emit('quote() that throws', outcome(fn () => (new QuotesItsOwn())->from('boom')->build()));
emit('quote() in joins and locks', outcome(fn () => (new QuotesItsOwn())->from('t')->join('o', 't.id', 'o.tid')->sortDesc('o.c')->forUpdateOf('t')->build()));
emit('quote() in savepoints', outcome(fn () => [(new QuotesItsOwn())->savepoint('s'), (new QuotesItsOwn())->releaseSavepoint('s'), (clone new QuotesItsOwn())->rollbackToSavepoint('s')]));
emit('geometryToWkt() in predicates', outcome(fn () => (new ShapesItsOwn())->from('t')->filterIntersects('g', [1, 2])->filterDistance('g', [1, 2], '<', 5.0)->build()));

foreach ($dialects as $dialect) {
    $base = new ReflectionClass('Utopia\\Query\\Builder\\' . $dialect);
    $overrides = [];
    $dispatched = ['quote', 'getLikeKeyword', 'getBindingValues', 'geometryToWkt', 'validateTable', 'validateRows', 'validateAndGetColumns', 'addBindings', 'compileCase', 'compileInsertBody', 'compileAssignments', 'compileWhereClauses', 'compileOrderAndLimit', 'buildAfterJoinsClause', 'compileVectorOrderExpression', 'resolveJoinFilterPlacement', 'appendReturning', 'resetReturning', 'resetGroupByModifier', 'applyNamedTypedBindings'];
    foreach ([...array_keys($helpers), ...$dispatched] as $helper) {
        if ($base->hasMethod($helper)) {
            $overrides[] = $declaration($base->getMethod($helper));
        }
    }
    eval("class Helps{$dialect} extends Utopia\\Query\\Builder\\{$dialect} { public array \$ran = []; public function call(string \$name, array \$arguments): mixed { return parent::{\$name}(...\$arguments); } " . implode(' ', $overrides) . ' }');
    $class = 'Helps' . $dialect;
    foreach ($builds as $name => $build) {
        emit($dialect . ' helpers in ' . $name, outcome(function () use ($class, $build) {
            $builder = new $class();
            $built = outcome(fn () => $build($builder));

            return [$built, $builder->ran, $builder->getBindings()];
        }));
    }
    foreach ($helpers as $helper => $lists) {
        if (! $base->hasMethod($helper)) {
            continue;
        }
        emit($dialect . ' helper ' . $helper, array_map(fn (array $arguments) => outcome(function () use ($class, $helper, $arguments) {
            $builder = (new $class())->from('t', 't')->into('t')->set(['id' => 1, 'a' => 2])->onConflict(['id'], ['a']);

            return [$builder->call($helper, $arguments), $builder->getBindings()];
        }), $lists));
    }
}

// The rest of the protected helpers, called on their own.
$case = (new Builder\Case\Expression())->when('a', Builder\Case\Operator::GreaterThan, 5, 'big')->whenNull('a', 'none')->else('small')->alias('size');
$called = [
    'validateTable' => [[]], 'validateRows' => [['insert'], ['upsert']], 'validateAndGetColumns' => [[]],
    'addBindings' => [[[1, 'a', null, 1.5]], [[]]],
    'compileCase' => [[$case], [new Builder\Case\Expression()]],
    'compileInsertBody' => [[]], 'compileAssignments' => [[]], 'compileVectorOrderExpression' => [[]],
    'resolveJoinFilterPlacement' => [[Utopia\Query\Hook\Join\Placement::On, false], [Utopia\Query\Hook\Join\Placement::Where, false], [Utopia\Query\Hook\Join\Placement::On, true], [Utopia\Query\Hook\Join\Placement::Where, true]],
    'createTokenizer' => [[]], 'createAstSerializer' => [[]],
    'buildAfterJoinsClause' => [[new Builder\ParsedQuery()]],
    'appendReturning' => [[new Builder\Statement('INSERT INTO t (a) VALUES (?)', [1])]],
    'applyNamedTypedBindings' => [[new Builder\Statement('SELECT * FROM t WHERE a = ?', [1])]],
    'resetReturning' => [[]], 'resetGroupByModifier' => [[]],
    'getSpatialTypeFromWkt' => [['POINT(1 2)'], [' linestring(0 0, 1 1)'], ['POLYGON((0 0, 1 0, 1 1, 0 0))'], ['circle'], ['']],
];
foreach ($dialects as $dialect) {
    eval("class Calls{$dialect} extends Utopia\\Query\\Builder\\{$dialect} { public function call(string \$name, array \$arguments): mixed { \$out = \$this->{\$name}(...\$arguments); return is_object(\$out) && ! \$out instanceof Utopia\\Query\\Builder\\Statement && ! \$out instanceof UnitEnum && ! \$out instanceof Utopia\\Query\\Builder\\Condition ? \$out::class : (\$out instanceof Utopia\\Query\\Builder\\Condition ? [\$out->expression, \$out->bindings] : \$out); } public function parts(string \$name): array { \$parts = ['SELECT 1']; \$this->{\$name}(\$parts); return \$parts; } }");
    $class = 'Calls' . $dialect;
    $starts = [
        'empty' => fn () => new $class(),
        'set' => fn () => (new $class())->from('t', 't')->into('t')->set(['id' => 1, 'a' => 'x'])->filter([Query::equal('c', [1]), Query::greaterThan('d', 2)])->sortAsc('a')->limit(5)->offset(2),
    ];
    foreach ($called as $helper => $lists) {
        if (! method_exists($class, $helper)) {
            continue;
        }
        $out = [];
        foreach ($starts as $name => $start) {
            // PHP reads the first row without looking, and warns when there is none.
            if ($helper === 'validateAndGetColumns' && $name === 'empty') {
                continue;
            }
            foreach ($lists as $arguments) {
                $out[] = outcome(function () use ($start, $helper, $arguments) {
                    $builder = $start();

                    return [$builder->call($helper, $arguments), $builder->getBindings()];
                });
            }
        }
        emit($dialect . ' calls ' . $helper, $out);
    }
    foreach (['compileWhereClauses', 'compileOrderAndLimit'] as $helper) {
        emit($dialect . ' calls ' . $helper, array_map(fn ($start) => outcome(function () use ($start, $helper) {
            $builder = $start();

            return [$builder->parts($helper), $builder->getBindings()];
        }), array_values($starts)));
    }
    emit($dialect . ' clone method', outcome(fn () => (new ReflectionMethod($class, '__clone'))->isPublic()));
}

// A subclass's own tokenizer reads a CTE in toAst(), and its own serializer writes what fromAst() keeps raw.
class TaggingSerializer extends Utopia\Query\AST\Serializer
{
    #[Override]
    public function serializeExpression(Utopia\Query\AST\Expression $expression): string
    {
        // Not through the parent: PHP's serializer calls this for every nested expression, and the
        // extension's writes them itself, which is the serializers' own known difference.
        return 'own_' . (new ReflectionClass($expression))->getShortName();
    }
}
class CountingTokenizer extends Utopia\Query\Tokenizer\Tokenizer
{
    public static int $read = 0;

    #[Override]
    public function tokenize(string $sql): array
    {
        self::$read++;

        return parent::tokenize(str_replace('renamed', 'actual', $sql));
    }
}
foreach ($dialects as $dialect) {
    eval("class Makes{$dialect} extends Utopia\\Query\\Builder\\{$dialect} { protected function createTokenizer(): Utopia\\Query\\Tokenizer\\Tokenizer { return new CountingTokenizer(); } protected function createAstSerializer(): Utopia\\Query\\AST\\Serializer { return new TaggingSerializer(); } }");
    $class = 'Makes' . $dialect;
    $serializer = new Utopia\Query\AST\Serializer();
    emit($dialect . ' factories', [
        outcome(function () use ($class, $serializer) {
            CountingTokenizer::$read = 0;
            $inner = (new $class())->from('u')->select(['renamed'])->filter([Query::equal('a', [1])]);
            $ast = (new $class())->from('c')->with('c', $inner)->toAst();

            return [$serializer->serialize($ast), CountingTokenizer::$read];
        }),
        outcome(function () use ($class) {
            $parser = new Utopia\Query\AST\Parser();
            $tokens = Utopia\Query\Tokenizer\Tokenizer::filter((new Utopia\Query\Tokenizer\Tokenizer())->tokenize('SELECT a, UPPER(b) AS u FROM t WHERE LOWER(c) = 1 AND d = 2 ORDER BY LENGTH(e)'));
            $built = $class::fromAst($parser->parse($tokens));

            return [$built::class, $built->build()];
        }),
    ]);
}
