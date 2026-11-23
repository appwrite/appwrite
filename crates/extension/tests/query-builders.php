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
