<?php

// Parity corpus for Utopia\Query\Query (packages/query): parsing, construction, the factories,
// the instance methods, the list helpers, reflection, and PHP subclasses (utopia-php/database's
// Query, and test subclasses overriding what Query.php reaches through `static::` and `$this->`).
// Run it on the PHP class and on the utopia extension's (_APP_EXTENSION_LIBRARIES=query), and diff
// stdout: every line must match. Stderr names the implementation that ran.
//
// Waived differences, normalized here and listed in the extension's README:
// - the protected constant LOGICAL_TYPES (enum cases; an internal class cannot hold them) is not
//   declared by the extension;
// - a TypeError raised by Query.php's own call of one of its methods (parseQueries() calling
//   parse() with a non-string) names Query.php's file and line in PHP, nothing in the extension;
// - reflection: the class and its methods are internal (no file, lines or doc comments); the
//   private helpers (parseJoin, createJoin, assertJoinCondition, toInteger) are not methods of the
//   extension's class; contains() carries #[\Deprecated] but is not flagged deprecated.

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/fixtures/query/strict.php';

use Utopia\Query\Compiler;
use Utopia\Query\Exception as QueryException;
use Utopia\Query\Method;
use Utopia\Query\NullsPosition;
use Utopia\Query\Query;

$internal = (new ReflectionClass(Query::class))->isInternal();
fwrite(STDERR, 'Utopia\\Query\\Query: ' . ($internal ? 'utopia extension' : 'PHP') . PHP_EOL);
// A run meant for the extension that gets the PHP class would compare PHP with PHP and pass.
$requested = in_array('query', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' query, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

/** Warnings and deprecations raised by the current case. */
$warnings = [];
set_error_handler(function (int $level, string $message) use (&$warnings): bool {
    $warnings[] = [$level, $message];

    return true;
});

/** $value as JSON can show it: strings that are not UTF-8 as hex, floats as var_export(), objects by class and state. */
function exported(mixed $value, int $depth = 0): mixed
{
    if ($depth > 64) {
        return '…';
    }
    if (is_string($value)) {
        return mb_check_encoding($value, 'UTF-8') ? $value : ['hex' => bin2hex($value)];
    }
    if (is_float($value)) {
        return ['float' => is_nan($value) || is_infinite($value) ? (string) $value : var_export($value, true)];
    }
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[] = [$k, exported($v, $depth + 1)];
        }

        return ['array' => $out];
    }
    if ($value instanceof UnitEnum) {
        return ['enum' => $value::class . '::' . $value->name];
    }
    if ($value instanceof Query) {
        $properties = [];
        foreach ((array) $value as $key => $v) {
            $at = strrpos((string) $key, "\0");
            $properties[] = [$at === false ? $key : substr($key, $at + 1), exported($v, $depth + 1)];
        }

        return ['query' => $value::class, 'properties' => $properties];
    }
    if ($value instanceof ArrayObject) {
        return ['object' => class_name($value), 'copy' => exported($value->getArrayCopy(), $depth + 1)];
    }
    if (is_object($value)) {
        return ['object' => class_name($value), 'vars' => exported(get_object_vars($value), $depth + 1)];
    }

    return $value;
}

/** An exception message with the waived "called in Query.php" suffix and closure line dropped. */
function message(string $message): string
{
    $message = preg_replace('/, called in \S*packages\/query\/src\/Query\.php on line \d+/', '', $message);

    return preg_replace('/\{closure:[^}]*\}/', '{closure}', $message);
}

/** A class name, anonymous ones without the counter that differs between runs. */
function class_name(object $value): string
{
    $name = $value::class;
    $at = strpos($name, "\0");

    return $at === false ? $name : substr($name, 0, $at);
}

/** The observable outcome of $run: its value or exception, and the warnings it raised. */
function outcome(callable $run): array
{
    global $warnings;
    $warnings = [];
    try {
        $result = ['value' => exported($run())];
    } catch (Throwable $e) {
        $result = ['throws' => $e::class, 'message' => exported(message($e->getMessage())), 'code' => $e->getCode()];
        if ($e->getPrevious() !== null) {
            $result['previous'] = [$e->getPrevious()::class, message($e->getPrevious()->getMessage())];
        }
    }
    if ($warnings !== []) {
        $result['warnings'] = $warnings;
    }

    return $result;
}

function emit(string $case, mixed $input, array $result): void
{
    echo json_encode([$case, exported($input), $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
}

/** What a parsed (or built) query shows: its tree, its array and JSON forms, its shape, and its deep clone. */
function described(Query $query): array
{
    $clone = clone $query;
    $shared = false;
    foreach ($query->getValues() as $i => $value) {
        if ($value instanceof Query && ($clone->getValues()[$i] ?? null) === $value) {
            $shared = true;
        }
    }

    return [
        'query' => exported($query),
        'toArray' => outcome(fn () => $query->toArray()),
        'toString' => outcome(fn () => $query->toString()),
        'shape' => outcome(fn () => $query->shape()),
        'clone' => [exported($clone) === exported($query), $shared],
    ];
}

// Subclasses -------------------------------------------------------------------------------------

/** Overrides what Query.php reaches through `static::`, and records the calls. */
class Recording extends Query
{
    public static array $calls = [];

    public string $tag = '';

    public function __construct(Method|string $method, string $attribute = '', array $values = [], string $alias = '')
    {
        self::$calls[] = 'new:' . (is_string($method) ? $method : $method->value) . ':' . func_num_args();
        $this->tag = 'built';
        parent::__construct($method, $attribute, $values, $alias);
    }

    public static function parse(string $query, bool $allowRaw = false): static
    {
        self::$calls[] = 'parse:' . func_num_args();

        return parent::parse($query, $allowRaw);
    }

    public static function parseQuery(array $query, bool $allowRaw = false): static
    {
        self::$calls[] = 'parseQuery:' . json_encode($query['method'] ?? null);

        return parent::parseQuery($query, $allowRaw);
    }

    public static function isMethod(string $value): bool
    {
        self::$calls[] = 'isMethod:' . $value;

        return $value === 'shout' || parent::isMethod($value);
    }

    public static function on(string $left, string $right, string $operator = '='): static
    {
        self::$calls[] = 'on:' . func_num_args();

        return parent::on(strtoupper($left), $right, $operator);
    }

    public static function lessThan(string $attribute, mixed $value): static
    {
        self::$calls[] = 'lessThan';

        return new static(Method::LessThan, $attribute, [$value, 'wide']);
    }

    public static function between(string $attribute, mixed $start, mixed $end): static
    {
        self::$calls[] = 'between';

        return parent::between($attribute, (string) $start, (string) $end);
    }

    public static function limit(int $value): static
    {
        return parent::limit($value * 2);
    }

    public static function validate(array $queries, array $allowedAttributes): array
    {
        self::$calls[] = 'validate:' . count($queries);

        return parent::validate($queries, $allowedAttributes);
    }
}

/** Overrides what Query.php reaches through `$this->` and `$query->`. */
class Overriding extends Query
{
    public function toArray(): array
    {
        return ['overridden' => true] + parent::toArray();
    }

    public function shape(): string
    {
        return 'shape!' . parent::shape();
    }

    public function isNestedJoin(): bool
    {
        return $this->attribute === 'nested' || parent::isNestedJoin();
    }

    public function getMethod(): Method
    {
        return $this->attribute === 'swap' ? Method::Limit : parent::getMethod();
    }

    public function getValues(): array
    {
        return $this->attribute === 'swap' ? [7] : parent::getValues();
    }

    public function getAttribute(): string
    {
        return strtoupper(parent::getAttribute());
    }
}

/** Reads and writes the protected properties as utopia-php/database's Query does. */
class Direct extends Query
{
    public function __construct()
    {
        // No parent constructor: $method stays uninitialized.
    }

    public function poke(mixed $method, mixed $values): static
    {
        $this->method = $method;
        $this->values = $values;

        return $this;
    }

    public function peek(): array
    {
        return [$this->method ?? null, $this->attribute, $this->attributeType, $this->onArray, $this->values, $this->alias, $this->aliasDefaulted];
    }
}

/** A property hook on $values: the extension may not reach the slot directly. */
class Hooked extends Query
{
    public array $log = [];

    protected array $values = [] {
        set(array $value) {
            $this->log[] = count($value);
            $this->values = $value;
        }
    }
}

/** Records the compile calls it gets. */
class RecordingCompiler implements Compiler
{
    public function compileFilter(Query $query): string { return 'filter:' . $query->getMethod()->value; }
    public function compileOrder(Query $query): string { return 'order:' . $query->getMethod()->value; }
    public function compileLimit(Query $query): string { return 'limit'; }
    public function compileOffset(Query $query): string { return 'offset'; }
    public function compileSelect(Query $query): string { return 'select'; }
    public function compileCursor(Query $query): string { return 'cursor'; }
    public function compileAggregate(Query $query): string { return 'aggregate:' . $query->getMethod()->value; }
    public function compileGroupBy(Query $query): string { return 'groupBy'; }
    public function compileJoin(Query $query): string { return 'join:' . $query->getAttribute(); }
}

$database = class_exists(\Utopia\Database\Query::class);
$subclasses = [Query::class, Recording::class, Overriding::class];
if ($database) {
    $subclasses[] = \Utopia\Database\Query::class;
}

// Reflection --------------------------------------------------------------------------------------

$class = new ReflectionClass(Query::class);
$private = ['parseJoin', 'createJoin', 'assertJoinCondition', 'toInteger'];
foreach ($class->getMethods() as $method) {
    if (in_array($method->getName(), $private, true)) {
        continue;
    }
    $params = [];
    foreach ($method->getParameters() as $p) {
        $params[] = [$p->getName(), (string) $p->getType(), $p->isOptional(), $p->isDefaultValueAvailable() ? exported($p->getDefaultValue()) : null, $p->isPromoted()];
    }
    $attributes = array_map(fn ($a) => [$a->getName(), $a->getArguments()], $method->getAttributes());
    emit('reflection.method', $method->getName(), ['static' => $method->isStatic(), 'public' => $method->isPublic(), 'params' => $params, 'required' => $method->getNumberOfRequiredParameters(), 'returns' => (string) $method->getReturnType(), 'attributes' => $attributes]);
}
foreach ($class->getProperties() as $property) {
    emit('reflection.property', $property->getName(), [(string) $property->getType(), $property->isProtected(), $property->hasDefaultValue() ? exported($property->getDefaultValue()) : 'none', $property->isPromoted(), $property->isReadOnly()]);
}
foreach ($class->getReflectionConstants() as $constant) {
    if ($constant->getName() === 'LOGICAL_TYPES') {
        continue;
    }
    emit('reflection.constant', $constant->getName(), [exported($constant->getValue()), $constant->isPublic(), (string) $constant->getType()]);
}
emit('reflection.class', Query::class, [$class->isFinal(), $class->isAbstract(), $class->getParentClass() === false, $class->getInterfaceNames(), $class->getConstructor()?->getName()]);

// Parsing -------------------------------------------------------------------------------------

$inputs = json_decode((string) file_get_contents(__DIR__ . '/fixtures/query/inputs.json'), true, flags: JSON_THROW_ON_ERROR);

$json = $inputs['parse'];
// Edge cases of the JSON and of each field.
array_push(
    $json,
    '',
    ' ',
    'null',
    'true',
    '1',
    '"equal"',
    '[]',
    '{}',
    '[1,2]',
    '{"method":null}',
    '{"method":"equal","attribute":null,"values":null,"alias":null}',
    '{"method":"equal","attribute":"0","values":[0]}',
    '{"method":"equal","attribute":"a","values":{"x":1,"y":2}}',
    '{"method":"equal","attribute":"a","values":{"5":1,"2":2}}',
    '{"method":"equal","attribute":"a","values":[9223372036854775807,9223372036854775808,-9223372036854775808,-9223372036854775809]}',
    '{"method":"equal","attribute":"a","values":[1.0,-0.0,0.1,1e300,1E-7,123456789012345678901234567890,1.5e-320]}',
    '{"method":"equal","attribute":"a","values":["é","😀","\u0000","\/","\\\\"]}',
    "{\"method\":\"equal\",\"attribute\":\"\xff\",\"values\":[]}",
    '{"method":"equal","attribute":"a","values":["\ud800"]}',
    '{"method":"equal","attribute":"a","values":[1],}',
    '{"method":"equal","method":"limit","values":[1]}',
    '{"method":"EQUAL","attribute":"a","values":[1]}',
    '{"method":" equal","attribute":"a","values":[1]}',
    '{"method":"equal","attribute":["a"],"values":[1]}',
    '{"method":"equal","attribute":1.5,"values":[1]}',
    '{"method":"equal","attribute":true,"values":[1]}',
    '{"method":"equal","attribute":"a","values":"x"}',
    '{"method":"equal","attribute":"a","values":1}',
    '{"method":"equal","attribute":"a","values":[],"alias":[]}',
    '{"method":"equal","attribute":"a","values":[],"alias":false}',
    '{"method":"distinct"}',
    '{"method":"distinct","attribute":"a"}',
    '{"method":"distinct","values":[1]}',
    '{"method":"distinct","attribute":""}',
    '{"method":"raw","attribute":"1=1"}',
    '{"method":"count","attribute":"*","values":["legacy"]}',
    '{"method":"count","attribute":"*","values":[5]}',
    '{"method":"count","attribute":"*","values":[null]}',
    '{"method":"sum","attribute":"p","values":["x"],"alias":"y"}',
    '{"method":"and","values":[]}',
    '{"method":"and","values":[{"method":"equal","attribute":"a","values":[1]},{"method":"or","values":[{"method":"isNull","attribute":"b"},{"method":"between","attribute":"c","values":[1,2]}]}]}',
    '{"method":"or","values":["{\"method\":\"equal\",\"attribute\":\"a\",\"values\":[1]}",{"method":"isNull","attribute":"b"}]}',
    '{"method":"or","values":["not json"]}',
    '{"method":"or","values":["[]"]}',
    '{"method":"or","values":["\"x\""]}',
    '{"method":"or","values":["{\"method\":\"raw\",\"attribute\":\"1=1\"}"]}',
    '{"method":"or","values":[42]}',
    '{"method":"or","values":[null]}',
    '{"method":"or","values":[true]}',
    '{"method":"or","values":[1.5]}',
    '{"method":"or","values":{"k":{"method":"isNull","attribute":"b"}}}',
    '{"method":"elemMatch","attribute":"tags","values":[{"method":"equal","attribute":"n","values":["x"]}]}',
    '{"method":"relationship","attribute":"author","values":[{"method":"equal","attribute":"n","values":["x"]}]}',
    '{"method":"having","values":[{"method":"greaterThan","attribute":"c","values":[1]}]}',
    '{"method":"join","attribute":"orders","values":[{"method":"on","values":["$id","=","orderId"]}]}',
    '{"method":"join","attribute":"orders","alias":"o","values":[{"method":"on","values":["$id","=","orderId"]}]}',
    '{"method":"join","attribute":"orders","values":["o",{"method":"on","values":["$id","=","orderId"]}]}',
    '{"method":"join","attribute":"orders","values":["$id","=","orderId"]}',
    '{"method":"join","attribute":"orders","values":["$id","=","orderId","o"]}',
    '{"method":"join","attribute":"orders","values":["$id","=","orderId","o","extra"]}',
    '{"method":"join","attribute":"orders","values":["a","b"]}',
    '{"method":"join","attribute":"orders","values":[1]}',
    '{"method":"join","attribute":"orders","values":[]}',
    '{"method":"join","attribute":"","values":[{"method":"on","values":["a","=","b"]}]}',
    '{"method":"join","attribute":"orders","values":[{"method":"equal","attribute":"x","values":[1]}]}',
    '{"method":"join","attribute":"orders","values":[{"method":"and","values":[{"method":"on","values":["a","=","b"]},{"method":"isNull","attribute":"x"}]}]}',
    '{"method":"join","attribute":"orders","values":[{"method":"limit","values":[1]}]}',
    '{"method":"crossJoin","attribute":"t"}',
    '{"method":"crossJoin","attribute":"t","values":["alias"]}',
    '{"method":"crossJoin","attribute":"t","values":[{"method":"on","values":["a","=","b"]}]}',
    '{"method":"naturalJoin","attribute":"t","alias":"n"}',
    '{"method":"leftJoin","attribute":"t","values":["t",{"method":"on","values":["a","=","b"]}]}',
    '{"method":"cursorAfter","values":["doc1"]}',
    '{"method":"cursorAfter","values":[{"$id":"doc1","n":1}]}',
    '{"method":"orderAsc"}',
    '{"method":"orderDesc","attribute":"a","values":["FIRST"]}',
    '{"method":"limit","values":[25]}',
    '{"method":"offset","values":["25"]}',
    '{"method":"select","values":["a","b.*"]}',
    '{"method":"groupByTimeBucket","attribute":"t","values":["1h"]}',
    '{"method":"search","attribute":"a","values":["x"],"extra":1}',
    str_repeat('[', 600) . str_repeat(']', 600),
    '{"method":"and","values":[' . str_repeat('{"method":"and","values":[', 200) . str_repeat(']}', 200) . ']}',
);
// Every method with each kind of attribute, values and alias, as the compat suite's fuzzer builds them.
$methods = array_merge(array_map(fn (Method $m) => $m->value, Method::cases()), ['unknown', '', 'EQUAL']);
$valueForms = ['[]', '[1]', '["a"]', '["a",null]', '[1.5,true]', '{"a":1}', '"x"', '[[1,2]]', '[{"method":"equal","attribute":"a","values":[1]}]', '["a","=","b"]', '[-0.0]', '[9223372036854775807]', '[1e300]', '["$.a","=",1]', '[["POINT(1 2)",5,true]]', 'null'];
$aliasForms = ['}', ',"alias":"x"}', ',"alias":1}'];
foreach ($methods as $i => $method) {
    foreach ($valueForms as $j => $values) {
        $alias = $aliasForms[($i + $j) % 3];
        $attribute = ['', 'a1', '0', 'name'][($i * 7 + $j) % 4];
        $json[] = '{"method":' . json_encode($method) . ',"attribute":' . json_encode($attribute) . ',"values":' . $values . $alias;
    }
}

foreach ($json as $input) {
    foreach ($subclasses as $subclass) {
        Recording::$calls = [];
        $result = outcome(fn () => described($subclass::parse($input)));
        $result['calls'] = Recording::$calls;
        emit('parse ' . $subclass, $input, $result);
    }
    emit('parse allowRaw', $input, outcome(fn () => described(Query::parse($input, true))));
}

$arrays = $inputs['parseQuery'];
array_push(
    $arrays,
    [],
    ['method' => 1],
    ['method' => ['equal']],
    ['method' => 'equal', 'attribute' => 'a', 'values' => [new ArrayObject(['x' => 1]), NullsPosition::First, Method::And]],
    ['method' => 'and', 'values' => [new ArrayObject([])]],
    ['method' => 'and', 'values' => [Query::equal('a', [1])]],
    ['method' => 'or', 'values' => ['x' => ['method' => 'isNull', 'attribute' => 'a'], 7 => '{"method":"isNotNull","attribute":"b"}']],
    ['method' => 'or', 'values' => [['method' => 'raw', 'attribute' => '1=1']]],
    ['method' => 'shout', 'attribute' => 'a'],
    ['method' => 'join', 'attribute' => 'orders', 'values' => ['$id', '=', 'orderId']],
    ['method' => 'cursorAfter', 'values' => [new ArrayObject(['$id' => 'd'])]],
);
$reference = ['method' => 'equal', 'attribute' => 'a', 'values' => [1]];
$alias = &$reference['values'];
$arrays[] = $reference;
foreach ($arrays as $input) {
    foreach ($subclasses as $subclass) {
        Recording::$calls = [];
        $result = outcome(fn () => described($subclass::parseQuery($input)));
        $result['calls'] = Recording::$calls;
        emit('parseQuery ' . $subclass, $input, $result);
    }
    emit('parseQuery allowRaw', $input, outcome(fn () => described(Query::parseQuery($input, true))));
}

$lists = [
    [],
    ['{"method":"equal","attribute":"a","values":[1]}', '{"method":"limit","values":[5]}'],
    ['x' => '{"method":"isNull","attribute":"a"}', 3 => '{"method":"offset","values":[1]}'],
    ['{"method":"equal","attribute":"a","values":[1]}', 'nope'],
    [1, '{"method":"limit","values":[5]}'],
    [['method' => 'equal']],
    [null],
    [1.5],
    ['{"method":"raw","attribute":"1=1"}'],
];
foreach ($lists as $input) {
    foreach ($subclasses as $subclass) {
        Recording::$calls = [];
        $result = outcome(fn () => array_map(described(...), $subclass::parseQueries($input)));
        $result['calls'] = Recording::$calls;
        emit('parseQueries ' . $subclass, $input, $result);
    }
    emit('parseQueries allowRaw', $input, outcome(fn () => array_map(described(...), Query::parseQueries($input, true))));
}

foreach (['equal', 'EQUAL', '', 'raw', 'shout', 'and'] as $input) {
    foreach ($subclasses as $subclass) {
        emit('isMethod ' . $subclass, $input, outcome(fn () => $subclass::isMethod($input)));
    }
}

// Construction and the factories ------------------------------------------------------------------

$document = class_exists(\Utopia\Database\Document::class) ? new \Utopia\Database\Document(['$id' => 'doc1', 'n' => 1]) : new ArrayObject(['$id' => 'doc1']);
$stringable = new class () {
    public function __toString(): string
    {
        return 'str';
    }
};
$args = [
    [],
    ['a'],
    ['a', [1, 2]],
    ['a', ['x' => 1]],
    ['a', 'v'],
    ['a', 5],
    ['a', 1.5],
    ['a', true],
    ['a', null],
    ['a', [1, 2], 3],
    ['a', 'x', 'y'],
    ['a', [1, 2], 10, true],
    ['a', [1, 2], 10.5],
    ['a', [1, 2], '10'],
    ['a', 'b', 'c', 'd'],
    [5],
    ['5'],
    ['abc'],
    [5.0],
    [5.5],
    [null],
    [true],
    [[1]],
    [['x' => 1]],
    [$document],
    [$stringable],
    [$stringable, $stringable],
    ['a', NullsPosition::Last],
    ['', null],
    ['a', ''],
    ['', ''],
    ['a', 'a'],
    ['a', 'b', [Query::on('x', 'y')]],
    ['a', '', [Query::on('x', 'y')]],
    ['', '', [Query::on('x', 'y')]],
    ['a', 'b', []],
    ['a', 'b', ['k' => Query::on('x', 'y'), 9 => Query::and([Query::on('p', 'q'), Query::equal('z', [1])])]],
    ['a', 'b', [Query::equal('x', [1])]],
    ['a', 'b', [Query::and([Query::on('x', 'y'), 'nope'])]],
    ['a', 'b', ['nope']],
    ['a', '1h'],
    ['a', '2h'],
    [[Query::equal('a', [1]), 'x']],
    ['SELECT ?', [1]],
];
$factories = array_values(array_filter(
    $class->getMethods(ReflectionMethod::IS_STATIC),
    fn (ReflectionMethod $m) => $m->isPublic() && !in_array($m->getName(), ['parse', 'parseQuery', 'parseQueries', 'isMethod', 'fingerprint', 'getByType', 'getCursorQueries', 'groupByType', 'merge', 'diff', 'validate', 'page'], true),
));
foreach ($factories as $factory) {
    $name = $factory->getName();
    foreach ($args as $list) {
        foreach ([Query::class, Recording::class] as $subclass) {
            Recording::$calls = [];
            $result = outcome(fn () => described($subclass::$name(...$list)));
            $result['calls'] = Recording::$calls;
            emit('factory ' . $subclass . '::' . $name, $list, $result);
        }
    }
    emit('factory strict ' . $name, $args[5], outcome(fn () => described(strict_factory($name, $args[5]))));
    emit('factory strict ' . $name, $args[16], outcome(fn () => described(strict_factory($name, $args[16]))));
}
emit('factory named', 'select', outcome(fn () => described(Query::select(attributes: ['a']))));
emit('factory named', 'orderAsc', outcome(fn () => described(Query::orderAsc(nulls: NullsPosition::First))));
emit('factory named', 'count', outcome(fn () => described(Query::count(alias: 'n'))));
emit('factory named unknown', 'equal', outcome(fn () => Query::equal(attribute: 'a', value: [1])));

$constructions = [
    [Method::Equal, 'a', [1]],
    ['equal', 'a', [1], 'x'],
    ['nope'],
    [''],
    [5],
    [null],
    [[]],
    [Method::Join, 'orders', [], 'orders'],
    [Method::Join, 'orders', [], 'o'],
    [Method::Equal, 'a', [], 'a'],
    [NullsPosition::First],
    [$stringable, $stringable],
    [Method::Equal, 1, 'x'],
];
foreach ($constructions as $list) {
    foreach ([Query::class, Recording::class] as $subclass) {
        Recording::$calls = [];
        $result = outcome(fn () => described(new $subclass(...$list)));
        $result['calls'] = Recording::$calls;
        emit('new ' . $subclass, $list, $result);
    }
    emit('new strict', $list, outcome(fn () => described(strict_new($list))));
}

// Instance methods -------------------------------------------------------------------------------

$samples = [
    'equal' => fn () => Query::equal('a', [1, 2]),
    'join' => fn () => Query::join('orders', 'o', [Query::on('$id', 'orderId')]),
    'joinDefault' => fn () => Query::join('orders', '', [Query::on('$id', 'orderId')]),
    'crossJoin' => fn () => Query::crossJoin('t', ''),
    'nested' => fn () => Query::or([Query::equal('a', [1]), Query::and([Query::isNull('b'), Query::greaterThan('c', 2)])]),
    'cursor' => fn () => Query::cursorAfter($GLOBALS['document']),
    'nan' => fn () => Query::equal('a', [NAN]),
    'inf' => fn () => Query::equal('a', [INF]),
    'utf8' => fn () => Query::equal("\xff", ["\xfe"]),
    'object' => fn () => Query::equal('a', [new ArrayObject(['k' => 'v']), new stdClass(), NullsPosition::First]),
    'json' => fn () => Query::equal('a', [new class () implements JsonSerializable {
        public function jsonSerialize(): mixed
        {
            return ['serialized' => 1.0];
        }
    }]),
    'throwing' => fn () => Query::equal('a', [new class () implements JsonSerializable {
        public function jsonSerialize(): mixed
        {
            throw new RuntimeException('no');
        }
    }]),
    'deep' => fn () => Query::equal('a', [json_decode(str_repeat('[', 600) . str_repeat(']', 600), true, 1000)]),
    'floats' => fn () => Query::equal('a', [0.1, 1e100, -0.0, 7.0, 1.5e-7]),
    'spatial' => fn () => Query::distanceLessThan('loc', [1, 2], 5),
    'overriding' => fn () => Overriding::or([Overriding::equal('nested', [1]), Overriding::join('t', 'u', [Overriding::on('a', 'b')])]),
    'swap' => fn () => new Overriding(Method::Equal, 'swap', [1]),
    'hooked' => fn () => Hooked::and([Hooked::equal('a', [1])]),
    'direct' => fn () => new Direct(),
    'zeroAttribute' => fn () => Query::equal('0', []),
];
$calls = [
    'getMethod' => [],
    'getAttribute' => [],
    'getValues' => [],
    'getValue' => [],
    'getValue default' => ['d'],
    'isNestedJoin' => [],
    'getAlias' => [],
    'isAliasDefaulted' => [],
    'getJoinOnQueries' => [],
    'isSpatialQuery' => [],
    'isNested' => [],
    'onArray' => [],
    'getAttributeType' => [],
    'shape' => [],
    'toArray' => [],
    'toString' => [],
    'compile' => [new RecordingCompiler()],
];
foreach ($samples as $sample => $make) {
    emit('sample', $sample, outcome(fn () => exported($make())));
    foreach ($calls as $call => $list) {
        $method = explode(' ', $call)[0];
        emit('call ' . $call, $sample, outcome(fn () => $make()->$method(...$list)));
    }
    emit('clone', $sample, outcome(fn () => described(clone $make())));
    emit('fingerprint', $sample, outcome(fn () => Query::fingerprint([$make()])));
}

$setters = [
    ['setMethod', [Method::Limit]],
    ['setMethod', ['limit']],
    ['setMethod', ['nope']],
    ['setMethod', [5]],
    ['setAttribute', ['b']],
    ['setAttribute', [5]],
    ['setAttribute', [null]],
    ['setAttribute', [[]]],
    ['setValues', [[1, 'x' => 2]]],
    ['setValues', ['x']],
    ['setValue', [[1]]],
    ['setValue', [null]],
    ['setOnArray', [true]],
    ['setOnArray', ['yes']],
    ['setOnArray', [0]],
    ['setAttributeType', ['point']],
    ['setAttributeType', [1.5]],
];
foreach (['equal', 'hooked', 'direct'] as $sample) {
    foreach ($setters as [$setter, $list]) {
        emit('set ' . $setter, [$sample, $list], outcome(function () use ($samples, $sample, $setter, $list) {
            $query = $samples[$sample]();
            $returned = $query->$setter(...$list);

            return [$returned === $query ? 'this' : exported($returned), exported($query), $query instanceof Hooked ? $query->log : null];
        }));
    }
}
emit('direct', 'peek uninitialized', outcome(fn () => (new Direct())->peek()));
emit('direct', 'poke', outcome(fn () => (new Direct())->poke(Method::Equal, [1])->peek()));
emit('direct', 'poke string', outcome(fn () => (new Direct())->poke('equal', [1])));
emit('direct', 'poke then toString', outcome(fn () => (new Direct())->poke(Method::Equal, [Query::isNull('x')])->toString()));
emit('direct', 'unset then read', outcome(function () {
    $query = Query::equal('a', [1]);
    // Bound to a subclass's scope: binding a closure to an internal class's is refused.
    $unset = Closure::bind(function () {
        unset($this->attribute);
    }, $query, Direct::class);
    $unset();

    return $query->toArray();
}));
emit('direct', 'reference', outcome(function () {
    $query = Query::equal('a', [1]);
    $get = Closure::bind(function &() {
        return $this->values;
    }, $query, Direct::class);
    $values = &$get();
    $values[] = 2;
    $query->setValues([3]);

    return [$values, $query->getValues()];
}));
emit('direct', 'var_dump', outcome(function () {
    ob_start();
    var_dump(Query::or([Query::equal('a', [1])]));

    return preg_replace('/#\d+ /', '# ', (string) ob_get_clean());
}));
emit('direct', 'serialize', outcome(fn () => serialize(Query::or([Query::equal('a', [1])]))));
emit('direct', 'unserialize', outcome(fn () => exported(unserialize(serialize(Query::or([Query::equal('a', [1])]))))));
emit('direct', 'compare', outcome(fn () => [Query::equal('a', [1]) == Query::equal('a', [1]), Query::equal('a', [1]) == Query::equal('a', [2])]));
emit('direct', 'json_encode', outcome(fn () => json_encode(Query::equal('a', [1]))));
emit('direct', 'get_object_vars', outcome(fn () => array_keys(Closure::bind(fn () => get_object_vars($this), Query::equal('a', [1]), Direct::class)())));
emit('direct', 'clone isolates', outcome(function () {
    $query = Query::and([Query::equal('a', [1])]);
    $copy = clone $query;
    $copy->getValues()[0]->setAttribute('changed');

    return [$query->toString(), $copy->toString()];
}));

// Lists ------------------------------------------------------------------------------------------

$queries = fn () => [
    Query::equal('a', [1]),
    Query::limit(10),
    Query::offset(5),
    Query::limit(20),
    Query::cursorAfter($GLOBALS['document']),
    Query::cursorBefore(['$id' => 'x']),
    Query::select(['a', 'b']),
    Query::orderAsc('a'),
    Query::orderRandom(),
    Query::count('*', 'n'),
    Query::sum('p'),
    Query::groupBy(['a', 'b']),
    Query::groupByTimeBucket('t', '1d'),
    Query::having([Query::greaterThan('n', 1)]),
    Query::distinct(),
    Query::join('o', 'x', [Query::on('a', 'b')]),
    Query::union([Query::equal('a', [1])]),
    Query::relationship('author', [Query::equal('n', ['x'])]),
    Query::or([Query::equal('z', [1]), Query::and([Query::equal('w', [2])])]),
];
$limits = [[Query::limit(5)], [new Query(Method::Limit, '', ['7'])], [new Query(Method::Limit, '', ['7.9'])], [new Query(Method::Limit, '', ['abc'])], [new Query(Method::Limit, '', [1e30])], [new Query(Method::Limit, '', [])], [new Query(Method::Offset, '', [' 12'])], [new Query(Method::Offset, '', [true])]];
foreach ($subclasses as $subclass) {
    emit('getByType', $subclass, outcome(fn () => $subclass::getByType($queries(), [Method::Limit, Method::Equal])));
    emit('getByType noclone', $subclass, outcome(function () use ($subclass, $queries) {
        $list = $queries();
        $found = $subclass::getByType($list, [Method::Equal], false);

        return $found[0] === $list[0];
    }));
    emit('getCursorQueries', $subclass, outcome(fn () => $subclass::getCursorQueries($queries())));
    emit('groupByType', $subclass, outcome(fn () => $subclass::groupByType($queries())));
    emit('groupByType mixed', $subclass, outcome(fn () => $subclass::groupByType([1, 'x', null, Query::equal('a', [1]), new Overriding(Method::Equal, 'swap', [1])])));
    foreach ($limits as $i => $list) {
        emit('groupByType limit', [$subclass, $i], outcome(fn () => $subclass::groupByType($list)));
    }
    emit('merge', $subclass, outcome(fn () => $subclass::merge($queries(), [Query::limit(1), Query::equal('b', [2]), Query::cursorBefore(['$id' => 'y'])])));
    emit('merge keyed', $subclass, outcome(fn () => $subclass::merge(['x' => Query::equal('a', [1]), 5 => Query::isNull('b')], [Query::isNotNull('c')])));
    emit('merge bad', $subclass, outcome(fn () => $subclass::merge(['x'], [Query::limit(1)])));
    emit('merge bad b', $subclass, outcome(fn () => $subclass::merge([], ['x'])));
    emit('diff', $subclass, outcome(fn () => $subclass::diff($queries(), [Query::limit(10), Query::equal('a', [1]), Query::equal('a', ['1'])])));
    emit('diff bad', $subclass, outcome(fn () => $subclass::diff([Query::limit(1)], ['x'])));
    emit('validate', $subclass, outcome(fn () => $subclass::validate([...$queries(), Query::groupBy([1, 'zz']), Query::equal('*', [1]), Query::equal('', [1])], ['a', 'b', 't', 'p'])));
    emit('validate bad', $subclass, outcome(fn () => $subclass::validate(['x'], [])));
    foreach ([[1], [2, 10], [0], [1, 0], [PHP_INT_MAX, 3]] as $list) {
        emit('page', [$subclass, $list], outcome(fn () => $subclass::page(...$list)));
    }
    emit('fingerprint', $subclass, outcome(fn () => $subclass::fingerprint([...$queries(), '{"method":"isNull","attribute":"q"}'])));
    emit('fingerprint order', $subclass, outcome(fn () => $subclass::fingerprint([Query::equal('b', [1]), Query::equal('a', [2])]) === $subclass::fingerprint([Query::equal('a', [3]), Query::equal('b', [4])])));
    foreach ([[1], [null], ['nope'], [new stdClass()], [new Overriding(Method::Equal, 'a', [])]] as $list) {
        emit('fingerprint element', [$subclass, $list], outcome(fn () => $subclass::fingerprint($list)));
    }
}
emit('validate recursion', 'Recording', outcome(function () {
    Recording::$calls = [];
    $errors = Recording::validate([Query::or([Query::equal('x', [1]), Query::and([Query::equal('y', [1])])])], []);

    return [$errors, Recording::$calls];
}));

// utopia-php/database's Query: what a list request does -------------------------------------------

if ($database) {
    $db = \Utopia\Database\Query::class;
    $requests = [
        [
            '{"method":"equal","attribute":"status","values":["active","pending"]}',
            '{"method":"greaterThan","attribute":"$createdAt","values":["2024-01-01T00:00:00.000+00:00"]}',
            '{"method":"orderDesc","attribute":"$createdAt"}',
            '{"method":"limit","values":[25]}',
            '{"method":"offset","values":[50]}',
        ],
        [
            '{"method":"or","values":[{"method":"equal","attribute":"a","values":[1]},{"method":"and","values":[{"method":"lessThan","attribute":"b","values":[2.5]},"{\"method\":\"isNotNull\",\"attribute\":\"c\"}"]}]}',
            '{"method":"select","values":["name","$id","author.*"]}',
            '{"method":"cursorAfter","values":["doc1"]}',
            '{"method":"orderAsc"}',
        ],
        ['{"method":"search","attribute":"title","values":["hello world"]}', '{"method":"limit","values":[0]}'],
        ['{"method":"raw","attribute":"1=1"}'],
        ['{"method":"or","values":[7]}'],
        ['{"method":"or","values":["{oops"]}'],
        ['{"method":"nope"}'],
        ['{"method":"equal","attribute":1}'],
        ['{oops'],
        ['{"method":"join","attribute":"orders","values":["$id","=","customerId"]}'],
        ['{"method":"elemMatch","attribute":"tags","values":[{"method":"equal","attribute":"n","values":["x"]}]}'],
    ];
    foreach ($requests as $request) {
        emit('database parseQueries', $request, outcome(function () use ($db, $request) {
            $parsed = $db::parseQueries($request);
            $grouped = $db::groupByType($parsed);

            return [array_map(described(...), $parsed), exported($grouped), array_map(fn ($q) => $q->toString(), $db::getCursorQueries($parsed)), $db::fingerprint($parsed)];
        }));
    }
    emit('database cursor document', 'cursorAfter', outcome(function () use ($db, $document) {
        $cursor = $db::cursorAfter($document);
        $grouped = $db::groupByType([$cursor, $db::limit(5)]);

        return [$cursor->toString(), $grouped->cursor === $document, exported($cursor), $db::getCursorQueries([$cursor])[0]->getValue() === $document];
    }));
    emit('database cursor scalar', 'groupByType', outcome(fn () => $db::groupByType([$db::parse('{"method":"cursorAfter","values":["doc1"]}')])));
    emit('database order default', 'orderAsc', outcome(fn () => described($db::orderAsc())));
    emit('database join alias', 'join', outcome(fn () => described($db::join('orders', '', [$db::on('$id', 'customerId')]))));
    emit('database toArray invalid child', 'or', outcome(fn () => (new $db(Method::Or, '', ['x']))->toArray()));
    emit('database shape cycle', 'and', outcome(function () use ($db) {
        $a = $db::and([]);
        $a->setValues([$a]);

        return $a->shape();
    }));
    if (class_exists(\Appwrite\Utopia\Database\RuntimeQuery::class)) {
        $runtime = \Appwrite\Utopia\Database\RuntimeQuery::class;
        emit('runtime parse', 'equal', outcome(fn () => described($runtime::parse('{"method":"and","values":[{"method":"equal","attribute":"a","values":[1]}]}'))));
    }
}
if (class_exists(\Utopia\Audit\Query::class)) {
    $audit = \Utopia\Audit\Query::class;
    emit('audit', 'equal', outcome(fn () => described($audit::equal('a', 'scalar'))));
    emit('audit', 'createdBefore', outcome(fn () => described($audit::createdBefore('2020'))));
    emit('audit', 'parseQueries', outcome(fn () => array_map(described(...), $audit::parseQueries(['{"method":"lessThan","attribute":"time","values":["x"]}']))));
}
if (class_exists(\Utopia\Usage\UsageQuery::class)) {
    $usage = \Utopia\Usage\UsageQuery::class;
    emit('usage', 'groupBy', outcome(fn () => described($usage::groupBy('metric'))));
    emit('usage', 'parse', outcome(fn () => described($usage::parse('{"method":"groupBy","values":["a"]}'))));
}
