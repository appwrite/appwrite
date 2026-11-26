<?php

// What has hand-written handlers or shares its state in Utopia\Query (src/query/value.rs,
// src/query/classifier.rs, src/classes/stack.rs): the getters and classify() through PHP
// subclasses, with the wrong arguments and through reflection; clones and serialized copies
// that must not share what they change; trees deep enough to be read on a kept stack, and
// calls back into PHP from one. The output must match query-handlers.expected, which is what
// the same calls gave through ext-php-rs's handlers.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Query\AST\Parser;
use Utopia\Query\AST\Serializer;
use Utopia\Query\Classifier\MongoDB;
use Utopia\Query\Classifier\MySQL;
use Utopia\Query\Classifier\PostgreSQL;
use Utopia\Query\Method;
use Utopia\Query\Query;
use Utopia\Query\Tokenizer\Tokenizer;

class SubQuery extends Query
{
}
class OverQuery extends Query
{
    public function getAttribute(): string
    {
        return 'over:' . parent::getAttribute();
    }
}
class OwnConstructor extends Query
{
    public string $extra;

    public function __construct(string $attribute)
    {
        $this->extra = 'set first';
        parent::__construct(Method::Equal, $attribute, [1, 2], 'own');
    }
}
class SubMySQL extends MySQL
{
}
class OverPostgreSQL extends PostgreSQL
{
    public function classify(string $data): Utopia\Query\Type
    {
        return parent::classify(strtoupper($data));
    }
}
class Text implements Stringable
{
    public function __toString(): string
    {
        return 'SELECT 1';
    }
}

$out = function (string $case, callable $f): void {
    try {
        $r = $f();
        if ($r instanceof UnitEnum) {
            $r = $r::class . '::' . $r->name;
        } elseif ($r instanceof Query) {
            $r = [$r::class, $r->toArray()];
        }
        echo $case, ' => ', json_encode($r), "\n";
    } catch (\Throwable $e) {
        // Without the checkout's path, which a userland method's errors name.
        echo $case, ' !! ', get_class($e), ': ', str_replace(__FILE__, basename(__FILE__), $e->getMessage()), "\n";
    }
};
$shape = fn (Query $q): array => [$q->getMethod()->name, $q->getAttribute(), $q->getValues(), $q->getAlias(), $q->isAliasDefaulted()];

// The getters, on each class and every way of building a query.
foreach ([Query::class, SubQuery::class, OverQuery::class] as $class) {
    $made = [
        'new' => new $class(Method::Equal, 'status', ['active', 'pending']),
        'new by value' => new $class('greaterThan', 'age', [18]),
        'new defaults' => new $class(Method::Limit),
        'new int attribute' => new $class(Method::Equal, 42, [1]),
        'new join alias' => new $class(Method::Join, 'users', [], 'users'),
        'new empty alias' => new $class(Method::Join, '', [], ''),
        'factory' => $class::equal('name', ['x']),
        'parsed' => $class::parse('{"method":"lessThan","attribute":"time","values":[5]}'),
        'unserialized' => unserialize(serialize(new $class(Method::Equal, 'a', ['b'], 'c'))),
        'nested' => $class::and([$class::equal('a', [1]), $class::or([$class::isNull('b'), $class::lessThan('c', 2)])]),
    ];
    foreach ($made as $label => $q) {
        $out("$class $label", fn () => [$q->getMethod()->name, $q->getAttribute(), $q->getValues(), $q->getAlias()]);
    }
}
$q = new Query(Method::Equal, 'status', ['active']);
$out('getAttribute extra arg', fn () => $q->getAttribute(1));
$out('getMethod extra args', fn () => $q->getMethod(1, 2));
$out('getValues named arg', fn () => $q->getValues(x: 1));
$out('getAlias by reflection', fn () => (new ReflectionMethod(Query::class, 'getAlias'))->invoke(new Query(Method::Join, 't', [], 'alias')));
$out('getValues first-class callable', fn () => ($q->getValues(...))());
$out('getMethod on uninitialized', fn () => (new ReflectionClass(Query::class))->newInstanceWithoutConstructor()->getMethod());
$out('getAttribute through call_user_func', fn () => call_user_func([$q, 'getAttribute']));
$out('constructor bad method', fn () => new Query('nope'));
$out('constructor array attribute', fn () => new Query(Method::Equal, ['x']));
$out('constructor null values', fn () => new Query(Method::Equal, 'x', null));
$out('constructor too many', fn () => new Query(Method::Equal, 'x', [], 'a', 'b'));
$out('constructor none', fn () => new Query());

$out('own constructor', fn () => [($o = new OwnConstructor('mine'))->getAttribute(), $o->getValues(), $o->getAlias(), $o->extra]);
$out('constructor again', function () {
    $q = new Query(Method::Equal, 'first', [1]);
    $q->__construct(Method::Limit, 'second', [2], 'al');

    return [$q->getMethod()->name, $q->getAttribute(), $q->getValues(), $q->getAlias()];
});
$out('constructor again by value', function () {
    $q = new Query(Method::Equal, 'first', [1]);
    $q->__construct('lessThan', 'second');

    return [$q->getMethod()->name, $q->getAttribute(), $q->getValues(), $q->getAlias()];
});
$out('constructor named', fn () => (new Query(method: Method::Equal, values: [3], alias: 'x'))->toArray());
$out('constructor named skipping', fn () => [($q = new Query(Method::Join, alias: 'users'))->getAttribute(), $q->getValues(), $q->getAlias(), $q->isAliasDefaulted()]);
$out('constructor spread', fn () => (new Query(...[Method::Equal, 'a', ['b']]))->toArray());
$out('case object is the case', fn () => [(new Query(Method::Equal))->getMethod() === Method::Equal, (new Query('equal'))->getMethod() === Method::Equal, Query::limit(1)->getMethod() === Method::Limit]);
$out('case after setMethod', function () {
    $q = new Query(Method::Equal, 'a');
    $q->getMethod();
    $q->setMethod(Method::NotEqual);
    $c = clone $q;
    $c->setMethod('lessThan');

    return [$q->getMethod()->name, $c->getMethod()->name, unserialize(serialize($c))->getMethod()->name];
});
$out('constructor in a loop keeps each', function () {
    $made = [];
    foreach (['a', 'b', 'c'] as $i => $name) {
        $made[] = new Query(Method::Equal, $name . $i, [$i], $name);
    }

    return array_map(fn (Query $q) => [$q->getAttribute(), $q->getValues(), $q->getAlias()], $made);
});

// What a getter gave stays as it was when the query changes, and the other way round.
$q = new Query(Method::Equal, 'status', ['active']);
$attribute = $q->getAttribute();
$values = $q->getValues();
$values[] = 'changed';
$q->setAttribute('renamed');
$q->setValues(['other']);
$out('kept getters', fn () => [$attribute, $values, $q->getAttribute(), $q->getValues()]);
$name = 'built';
$name .= 'name';
$q = new Query(Method::Equal, $name, ['x'], $name);
$name .= 'changed';
$out('constructor keeps its strings', fn () => [$q->getAttribute(), $q->getAlias(), $name]);
$attr = str_repeat('a', 3);
$vals = ['x', 'y'];
$q = new Query(Method::Equal, $attr, $vals);
$vals[] = 'z';
$out('constructor keeps its array', fn () => $q->getValues());

// Clones: flat ones share their values until changed, nested ones are cloned through.
$flat = new Query(Method::Equal, 'a', ['x', 'y'], 'al');
$copy = clone $flat;
$copy->setValues(['changed']);
$copy->setAttribute('b');
$out('flat clone', fn () => [$shape($flat), $shape($copy)]);
$inner = Query::equal('c', [1]);
$nested = Query::and([$inner, Query::or([Query::isNull('d')])]);
$copy = clone $nested;
$copy->getValues()[0]->setAttribute('changed');
$out('nested clone', fn () => [$nested->toArray(), $copy->toArray(), $inner->getAttribute()]);
$mixed = new Query(Method::And, '', [Query::equal('e', [1]), 'plain', 7]);
$copy = clone $mixed;
$copy->getValues()[0]->setValues([2]);
$out('mixed clone', fn () => [$mixed->toArray(), $copy->toArray()]);
$deep = Query::equal('leaf', [1]);
for ($i = 0; $i < 300; $i++) {
    $deep = Query::and([$deep]);
}
$out('deep clone', fn () => (clone $deep)->toString() === $deep->toString());
$picked = Query::getByType([Query::limit(5), Query::offset(1), Query::equal('f', [1])], [Method::Limit, Method::Offset]);
$picked[0]->setValues([9]);
$out('getByType clones', fn () => array_map(fn (Query $q) => $q->toArray(), $picked));
$out('serialized clone', fn () => unserialize(serialize(clone $mixed))->toArray());

// The classifiers, through subclasses and with what PHP converts or refuses.
$packets = [
    'mysql select' => "\x00\x00\x00\x00\x03SELECT 1",
    'mysql execute' => "\x05\x00\x00\x00\x17\x01\x00\x00\x00",
    'postgres select' => 'Q' . "\x00\x00\x00\x00" . "select 1\x00",
    'empty' => '',
];
foreach ([MySQL::class, SubMySQL::class, PostgreSQL::class, OverPostgreSQL::class, MongoDB::class] as $class) {
    $c = new $class();
    foreach ($packets as $label => $packet) {
        $out("$class $label", fn () => $c->classify($packet));
    }
    $out("$class int", fn () => $c->classify(42));
    $out("$class float", fn () => $c->classify(1.5));
    $out("$class bool", fn () => $c->classify(true));
    $out("$class Stringable", fn () => $c->classify(new Text()));
    $out("$class null", fn () => $c->classify(null));
    $out("$class array", fn () => $c->classify([]));
    $out("$class none", fn () => $c->classify());
    $out("$class two", fn () => $c->classify('a', 'b'));
    $out("$class named", fn () => $c->classify(data: 'x'));
    $out("$class wrong name", fn () => $c->classify(other: 'x'));
    $out("$class by reflection", fn () => (new ReflectionMethod($class, 'classify'))->invoke($c, "\x00\x00\x00\x00\x03BEGIN"));
}
$out('same case object', fn () => (new MySQL())->classify("\x00\x00\x00\x00\x03SELECT 1") === Utopia\Query\Type::Read);

// Trees past the depth read on the caller's stack, kept and reused, and through a PHP subclass.
$tokenizer = new Tokenizer();
$parser = new Parser();
$serializer = new Serializer();
foreach ([20, 40, 150, 400] as $n) {
    $ast = $parser->parse(Tokenizer::filter($tokenizer->tokenize('SELECT a FROM t WHERE a = 0' . str_repeat(' OR a = 1', $n))));
    $sql = $serializer->serialize($ast);
    $out("serialize $n ORs", fn () => [strlen($sql), md5($sql), $serializer->serialize($ast) === $sql]);
}
$out('serialize too deep', fn () => strlen($serializer->serialize($parser->parse(Tokenizer::filter($tokenizer->tokenize('SELECT a FROM t WHERE ' . str_repeat('(', 3000) . 'a = 1' . str_repeat(')', 3000)))))));
class Reentrant extends Serializer
{
    public int $calls = 0;
    public ?object $inner = null;

    protected function quoteIdentifier(string $name): string
    {
        $this->calls++;
        if ($this->inner !== null && $this->calls < 4) {
            // Another deep tree serialized while this one is on the kept stack, if the
            // serializer ever calls this override (it does not: its quoting is its own).
            (new Serializer())->serialize($this->inner);
        }

        return parent::quoteIdentifier($name);
    }
}
$reentrant = new Reentrant();
$reentrant->inner = $parser->parse(Tokenizer::filter($tokenizer->tokenize('SELECT a FROM t WHERE a = 0' . str_repeat(' OR a = 1', 150))));
$outer = $parser->parse(Tokenizer::filter($tokenizer->tokenize('SELECT b FROM u WHERE b = 0' . str_repeat(' OR b = 1', 150))));
$out('serialize while serializing', fn () => [md5($reentrant->serialize($outer)), $reentrant->calls > 0]);
