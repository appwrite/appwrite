<?php

// Parity corpus for the composing validators (Nullable, ArrayList, AllOf, AnyOf, NoneOf,
// Multiple, Assoc), the JSON ones (JSON, JSON\ArrayValidator, JSON\ObjectValidator, JSON\FCM)
// and Globstar. Run it on the PHP classes and on the utopia extension's
// (_APP_EXTENSION_LIBRARIES=validators,validators-compose), and diff stdout: every line must
// match. Stderr names the implementation that ran.
//
// The validators these wrap are PHP probes that log every call made to them, so the order and
// number of calls, the arguments and the short-circuits are compared, not only the results.
// The leaves they also wrap (Text, Integer, URL, ...) are PHP or the extension's, as the other
// batches' switches say: run.sh runs this corpus with only this batch on and with every batch on.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Validator\AllOf;
use Utopia\Validator\AnyOf;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Boolean;
use Utopia\Validator\Globstar;
use Utopia\Validator\Hostname;
use Utopia\Validator\Integer;
use Utopia\Validator\JSON;
use Utopia\Validator\JSON\ArrayValidator;
use Utopia\Validator\JSON\FCM;
use Utopia\Validator\JSON\ObjectValidator;
use Utopia\Validator\Multiple;
use Utopia\Validator\NoneOf;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\URL;
use Utopia\Validator\Validator;
use Utopia\Validator\WhiteList;

const CLASSES = [
    Nullable::class, ArrayList::class, AllOf::class, AnyOf::class, NoneOf::class, Multiple::class, Assoc::class, JSON::class,
    ArrayValidator::class, ObjectValidator::class, FCM::class, Globstar::class,
];

$internal = (new ReflectionClass(Nullable::class))->isInternal();
fwrite(STDERR, 'Utopia\\Validator\\Nullable: ' . ($internal ? 'utopia extension' : 'PHP') . PHP_EOL);
// A run meant for the extension that gets the PHP classes would compare PHP with PHP and pass.
$switches = array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: ''));
$requested = in_array('validators', $switches, true) && in_array('validators-compose', $switches, true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' validators and validators-compose, but the other implementation loaded' . PHP_EOL);
    exit(1);
}
foreach (CLASSES as $class) {
    if ((new ReflectionClass($class))->isInternal() !== $internal) {
        fwrite(STDERR, $class . ' is not from the same implementation as Nullable' . PHP_EOL);
        exit(1);
    }
}

/** Warnings raised by the current case. */
$warnings = [];
set_error_handler(function (int $level, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});

/** $value with every string that is not UTF-8 as its hex, floats as text, and every object as its class. */
function exported(mixed $value): mixed
{
    if (is_string($value)) {
        return mb_check_encoding($value, 'UTF-8') ? $value : ['hex' => bin2hex($value)];
    }
    if (is_float($value) && (is_nan($value) || is_infinite($value))) {
        return ['float' => (string) $value];
    }
    if (is_float($value)) {
        return ['float' => var_export($value, true)];
    }
    if (is_array($value)) {
        return array_map(exported(...), $value);
    }
    if (is_object($value)) {
        // An anonymous class's name ends with a counter that depends on what was compiled before.
        return ['class' => str_contains($value::class, '@anonymous') ? 'class@anonymous' : $value::class];
    }

    return $value;
}

/**
 * $message without the ", called in …/packages/validators/src/X.php on line N" PHP adds to a
 * TypeError for an argument one of its classes passed; the extension's ends at "given" (waived
 * at the end of this file).
 */
function uncalled(string $message): string
{
    $at = strpos($message, ', called in ' . realpath(__DIR__ . '/../../../packages/validators/src') . '/');

    return $at === false ? $message : substr($message, 0, $at);
}

/** The observable outcome of $run: its value or exception, and the warnings it raised. */
function outcome(callable $run): array
{
    global $warnings;
    $warnings = [];
    try {
        $result = ['value' => exported($run())];
    } catch (Throwable $e) {
        $result = ['throws' => $e::class, 'message' => exported(uncalled($e->getMessage()))];
    }
    if ($warnings !== []) {
        $result['warnings'] = $warnings;
    }

    return $result;
}

function emit(string $case, array $result): void
{
    echo json_encode([exported($case), $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
}

/** The properties as (array) shows them, keyed by name. */
function properties(object $object): array
{
    $properties = [];
    foreach ((array) $object as $key => $value) {
        $at = strrpos($key, "\0");
        $properties[$at === false ? $key : substr($key, $at + 1)] = exported($value);
    }

    return $properties;
}

/** $dump with each object handle ("#12 ") dropped: handles differ between runs. */
function withoutHandles(string $dump): string
{
    $parts = explode('#', $dump);
    $result = array_shift($parts);
    foreach ($parts as $part) {
        $digits = strspn($part, '0123456789');
        $result .= '#' . ($digits > 0 && ($part[$digits] ?? '') === ' ' ? substr($part, $digits) : $part);
    }

    return $result;
}

/** What `php -r $code` prints and exits with, in a child process loading the same autoloader. */
function child(string $code, bool $strict = false): array
{
    $prelude = ($strict ? 'declare(strict_types=1); ' : '') . 'require ' . var_export(__DIR__ . '/../../../vendor/autoload.php', true) . ';';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-r', $prelude . $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['stdout' => $stdout, 'stderr' => trim($stderr), 'exit' => proc_close($process)];
}

/** A parameter as reflection shows it. */
function parameter(ReflectionParameter $p): array
{
    $default = null;
    if ($p->isDefaultValueAvailable()) {
        $default = [exported($p->getDefaultValue()), $p->isDefaultValueConstant() ? $p->getDefaultValueConstantName() : null];
    }

    return [
        $p->getName(), $p->getPosition(), (string) $p->getType(), $p->hasType(), $p->allowsNull(), $p->isOptional(), $p->isVariadic(),
        $p->isPassedByReference(), $p->isPromoted(), $p->isDefaultValueAvailable(), $default,
    ];
}

// Each class, as reflection shows it. `isInternal()`, the file, the doc comments and the
// methods' attributes (`#[\Override]`) differ by construction.
foreach (CLASSES as $name) {
    $class = new ReflectionClass($name);
    emit('class ' . $name, outcome(fn () => [
        'abstract' => $class->isAbstract(), 'final' => $class->isFinal(), 'parent' => $class->getParentClass()?->getName(),
        'interfaces' => $class->getInterfaceNames(), 'constructor' => $class->getConstructor()?->getName(),
        'instantiable' => $class->isInstantiable(), 'cloneable' => $class->isCloneable(), 'modifiers' => Reflection::getModifierNames($class->getModifiers()),
        'constants' => $class->getConstants(), 'static' => $class->getStaticProperties(), 'defaults' => exported($class->getDefaultProperties()),
        'subclass of base' => $class->isSubclassOf(Validator::class),
    ]));
    foreach ($class->getReflectionConstants() as $constant) {
        emit('constant ' . $name . '::' . $constant->getName(), outcome(fn () => [
            $constant->getDeclaringClass()->getName(), Reflection::getModifierNames($constant->getModifiers()), $constant->hasType(), (string) $constant->getType(),
            $constant->isFinal(), $constant->isEnumCase(), exported($constant->getValue()),
        ]));
    }
    $properties = $class->getProperties();
    usort($properties, fn ($a, $b) => strcmp($a->getName(), $b->getName()));
    foreach ($properties as $property) {
        emit('property ' . $name . '::$' . $property->getName(), outcome(fn () => [
            $property->getDeclaringClass()->getName(), Reflection::getModifierNames($property->getModifiers()), (string) $property->getType(),
            $property->hasDefaultValue(), exported($property->getDefaultValue()), $property->isPromoted(), $property->isReadOnly(), $property->isStatic(),
        ]));
    }
    // The order (array) and var_dump() list the properties in.
    emit('property order ' . $name, outcome(fn () => array_map(fn ($p) => $p->getName(), $class->getProperties())));
    $methods = $class->getMethods();
    usort($methods, fn ($a, $b) => strcmp($a->getName(), $b->getName()));
    foreach ($methods as $method) {
        emit('method ' . $name . '::' . $method->getName(), outcome(fn () => [
            $method->getDeclaringClass()->getName(), Reflection::getModifierNames($method->getModifiers()), $method->isStatic(), $method->isAbstract(),
            (string) $method->getReturnType(), $method->getReturnType()?->allowsNull(), $method->hasTentativeReturnType(), $method->returnsReference(),
            $method->getNumberOfParameters(), $method->getNumberOfRequiredParameters(), array_map(parameter(...), $method->getParameters()),
        ]));
    }
    // The order getMethods() lists them in.
    emit('method order ' . $name, outcome(fn () => array_map(fn ($m) => $m->getName(), $class->getMethods())));
}
emit('constants as values', outcome(fn () => [Nullable::TYPE_STRING, AllOf::TYPE_MIXED, Multiple::TYPE_ARRAY, FCM::TYPE_OBJECT, constant(Globstar::class . '::TYPE_BOOLEAN')]));
emit('private constants', outcome(function () {
    $class = new ReflectionClass(FCM::class);

    return array_map(fn ($c) => [$c->getName(), Reflection::getModifierNames($c->getModifiers()), exported($c->getValue())], $class->getReflectionConstants(ReflectionClassConstant::IS_PRIVATE));
}));
emit('private constant from outside', outcome(fn () => constant(FCM::class . '::URL_FIELDS')));

/** Every call a probe or a loose validator answered, in order. */
$log = [];

/** A validator that logs each call and answers as configured. */
class Probe extends Validator
{
    public function __construct(public string $name, public mixed $result = true, public string $description = 'probe', public string $type = 'string')
    {
    }

    public function getDescription(): string
    {
        global $log;
        $log[] = "{$this->name}:getDescription";

        return $this->description;
    }

    public function isArray(): bool
    {
        global $log;
        $log[] = "{$this->name}:isArray";

        return false;
    }

    public function getType(): string
    {
        global $log;
        $log[] = "{$this->name}:getType";

        return $this->type;
    }

    public function isValid($value): bool
    {
        global $log;
        $log[] = "{$this->name}:isValid(" . json_encode(exported($value)) . ')';
        if ($this->result instanceof Closure) {
            return ($this->result)($value);
        }

        return $this->result;
    }
}

/** A validator whose methods throw. */
class Thrower extends Validator
{
    public function getDescription(): string
    {
        throw new RuntimeException('getDescription failed');
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        throw new LogicException('getType failed');
    }

    public function isValid($value): bool
    {
        throw new RuntimeException('isValid failed');
    }
}

/** Not a Validator: answers whatever it holds, which AllOf, AnyOf and NoneOf accept in their arrays. */
class Loose
{
    public function __construct(public mixed $result)
    {
    }

    public function isValid($value)
    {
        global $log;
        $log[] = 'loose:isValid';

        return $this->result;
    }

    public function getDescription()
    {
        global $log;
        $log[] = 'loose:getDescription';

        return $this->result;
    }
}

/** Not a Validator: every method through __call(). */
class Magic
{
    public function __call(string $name, array $arguments)
    {
        global $log;
        $log[] = "magic:{$name}(" . count($arguments) . ')';

        return $name === 'isValid' ? 0 : 'magic description';
    }
}

/** Not a Validator: its isValid() is private. */
class Hidden
{
    private function isValid($value): bool
    {
        return true;
    }
}

/** $run's outcome and the calls the probes logged while it ran, outside any logged() it nests. */
function logged(callable $run): array
{
    global $log;
    $outer = $log;
    $log = [];
    $outcome = outcome($run);
    if ($log !== []) {
        $outcome['log'] = $log;
    }
    $log = $outer;

    return $outcome;
}

/** A validator's answers: isValid() for $value, then the description, type and properties. */
function check(Validator $validator, mixed $value): array
{
    return [
        'valid' => logged(fn () => $validator->isValid($value)),
        'description' => logged(fn () => $validator->getDescription()),
        'type' => logged(fn () => $validator->getType()),
        'array' => $validator->isArray(),
        'properties' => properties($validator),
    ];
}

/** A Stringable object of $string, counting its __toString() calls in $calls. */
function stringable(string $string, ?int &$calls = null): object
{
    $calls = 0;

    return new class ($string, $calls) {
        public function __construct(private string $string, private ?int &$calls)
        {
        }

        public function __toString(): string
        {
            $this->calls++;

            return $this->string;
        }
    };
}

/** An object whose __toString() throws. */
function throwing(): object
{
    return new class () {
        public function __toString(): string
        {
            throw new RuntimeException('__toString failed');
        }
    };
}

/** A backed enum case. */
enum Suit: string
{
    case Hearts = 'H';
}

/** An ArrayAccess object, which `$this->rules[] = $rule` appends to through offsetSet(). */
class Bag implements ArrayAccess
{
    public array $items = [];

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        global $log;
        $log[] = 'bag:offsetSet(' . var_export($offset, true) . ')';
        $this->items[] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
    }
}

// The values the validators check.
$values = [
    'null' => null, 'true' => true, 'false' => false, '0' => 0, '1' => 1, '-1' => -1, '1.5' => 1.5, 'NAN' => NAN, 'INF' => INF,
    "''" => '', "' '" => ' ', "'0'" => '0', "'1'" => '1', "'a'" => 'a', "'abc'" => 'abc', 'not utf-8' => "\xff",
    '[]' => [], '[1, 2]' => [1, 2], "['a']" => ['a'], "['a' => 1]" => ['a' => 1], '[1 => 1]' => [1 => 1], '[0 => 1, 2 => 2]' => [0 => 1, 2 => 2],
    "['0' => 'a', '1' => 'b']" => ['0' => 'a', '1' => 'b'], '[[1], [2]]' => [[1], [2]], 'mixed list' => [1, 'a', null, true, 1.5, []],
    'list of strings' => ['a', 'bb', 'ccc'], 'list with object' => [new stdClass()], 'list with INF' => [INF], 'list with not utf-8' => ["\xff"],
    'deep array' => array_reduce(range(1, 600), fn ($carry) => [$carry], []), 'large list' => range(1, 1000),
    'large assoc' => array_combine(array_map(fn ($i) => "k{$i}", range(1, 500)), range(1, 500)),
    'object' => new stdClass(), 'stdClass a' => (object) ['a' => 1], 'stdClass list' => (object) [1, 2], 'enum' => Suit::Hearts,
    'stringable' => stringable('abc'), 'throwing stringable' => throwing(), 'closure' => fn () => 1,
    // JSON text.
    'json {}' => '{}', 'json []' => '[]', 'json object' => '{"a":1}', 'json list' => '[1,2]', 'json nested' => '{"a":[1,{"b":null}]}',
    'json scalar 1' => '1', 'json scalar string' => '"x"', 'json null' => 'null', 'json true' => 'true', 'json spaced' => " \n{\"a\" : 1}\t",
    'json invalid' => '{a}', 'json trailing comma' => '[1,]', 'json single quotes' => "{'a':1}", 'json duplicate keys' => '{"a":1,"a":2}',
    'json big int' => '[123456789012345678901234567890]', 'json empty key' => '{"":1}', 'json bom' => "\xEF\xBB\xBF{}",
    'json not utf-8' => "[\"\xff\"]", 'json lone surrogate' => '["\ud800"]', 'json escaped nul' => '{"a\u0000b":1}', 'json depth 512' => str_repeat('[', 512) . str_repeat(']', 512),
    'json depth 513' => str_repeat('[', 513) . str_repeat(']', 513), 'json long' => '[' . implode(',', range(1, 200)) . ']',
];

// The validators wrapped: probes, then the leaves of the other batches.
$probeTrue = fn (string $name = 'p') => new Probe($name, true, "{$name} description");
$probeFalse = fn (string $name = 'q') => new Probe($name, false, "{$name} description");
$factories = [
    'Nullable(probe true)' => fn () => new Nullable($probeTrue()),
    'Nullable(probe false)' => fn () => new Nullable($probeFalse()),
    'Nullable(Text(3))' => fn () => new Nullable(new Text(3)),
    'Nullable(Integer)' => fn () => new Nullable(new Integer()),
    'Nullable(ArrayList(Boolean))' => fn () => new Nullable(new ArrayList(new Boolean())),
    'ArrayList(probe true)' => fn () => new ArrayList($probeTrue()),
    'ArrayList(probe false)' => fn () => new ArrayList($probeFalse()),
    'ArrayList(probe true, 2)' => fn () => new ArrayList($probeTrue(), 2),
    'ArrayList(probe true, -1)' => fn () => new ArrayList($probeTrue(), -1),
    'ArrayList(Text(2))' => fn () => new ArrayList(new Text(2)),
    'ArrayList(Integer, 3)' => fn () => new ArrayList(new Integer(), 3),
    'ArrayList(probe description empty)' => fn () => new ArrayList(new Probe('e', true, '')),
    "ArrayList(probe description '0')" => fn () => new ArrayList(new Probe('z', true, '0')),
    'ArrayList(Nullable(Integer(true)))' => fn () => new ArrayList(new Nullable(new Integer(true))),
    'AllOf(true, false, true)' => fn () => new AllOf([$probeTrue('a'), $probeFalse('b'), $probeTrue('c')]),
    'AllOf(true, true)' => fn () => new AllOf([$probeTrue('a'), $probeTrue('b')], Validator::TYPE_STRING),
    'AllOf()' => fn () => new AllOf([]),
    'AllOf(Text(5), WhiteList)' => fn () => new AllOf([new Text(5), new WhiteList(['a', 'abc', '1'])]),
    'AnyOf(false, true, false)' => fn () => new AnyOf([$probeFalse('a'), $probeTrue('b'), $probeFalse('c')]),
    'AnyOf(false, false)' => fn () => new AnyOf([$probeFalse('a'), $probeFalse('b')], 'custom'),
    'AnyOf()' => fn () => new AnyOf([]),
    'AnyOf(Integer, Text(1))' => fn () => new AnyOf([new Integer(), new Text(1)]),
    'AnyOf(URL, Hostname)' => fn () => new AnyOf([new URL(), new Hostname()]),
    'NoneOf(false, true, false)' => fn () => new NoneOf([$probeFalse('a'), $probeTrue('b'), $probeFalse('c')]),
    'NoneOf(false, false)' => fn () => new NoneOf([$probeFalse('a'), $probeFalse('b')]),
    'NoneOf()' => fn () => new NoneOf([]),
    'NoneOf(Range(1, 5))' => fn () => new NoneOf([new Range(1, 5)]),
    'Multiple(true, false)' => fn () => new Multiple([$probeTrue('a'), $probeFalse('b')]),
    'Multiple(true, true)' => fn () => new Multiple([$probeTrue('a'), $probeTrue('b')], 'list'),
    'Multiple()' => fn () => new Multiple([]),
    'Multiple(null type)' => fn () => new Multiple([$probeTrue('a')], null),
    'Multiple(Text(3), Integer)' => fn () => new Multiple([new Text(3), new Integer()]),
    'Assoc' => fn () => new Assoc(),
    'Assoc(10)' => fn () => new Assoc(10),
    'Assoc(0)' => fn () => new Assoc(0),
    'JSON' => fn () => new JSON(),
    'ArrayValidator' => fn () => new ArrayValidator(),
    'ArrayValidator(5)' => fn () => new ArrayValidator(5),
    'ArrayValidator(100)' => fn () => new ArrayValidator(100),
    'ObjectValidator' => fn () => new ObjectValidator(),
    'ObjectValidator(7)' => fn () => new ObjectValidator(7),
    'ObjectValidator(100)' => fn () => new ObjectValidator(100),
    'FCM' => fn () => new FCM(),
    'Globstar([])' => fn () => new Globstar([]),
    "Globstar(['*'])" => fn () => new Globstar(['*']),
    "Globstar(['a*', '!ab*'])" => fn () => new Globstar(['a*', '!ab*']),
    'nested' => fn () => new Nullable(new ArrayList(new AnyOf([new Integer(), new AllOf([new Text(3), $probeTrue('n')])]))),
];
foreach ($factories as $name => $factory) {
    foreach ($values as $label => $value) {
        // A fresh validator for each value: AllOf, AnyOf, NoneOf and FCM keep state between calls.
        emit($name . ' ' . $label, logged(fn () => check($factory(), $value)));
    }
}

// The same validator over a sequence of values: what failedRule and FCM's error keep.
foreach (['AllOf(true, false, true)', 'AnyOf(false, true, false)', 'NoneOf(false, true, false)', 'FCM'] as $name) {
    $validator = $factories[$name]();
    foreach (['1', '[]', 'json object', 'null'] as $label) {
        emit('sequence ' . $name . ' ' . $label, logged(fn () => check($validator, $values[$label])));
    }
}

// Probes answering by value, so the short-circuit point moves.
$evens = new Probe('even', fn ($v) => is_int($v) && $v % 2 === 0, 'even');
$positives = new Probe('positive', fn ($v) => is_int($v) && $v > 0, 'positive');
foreach ([[1, 2, 3], [2, 4, 6], [-2, 4], [2, -4], [], ['a' => 2, 'b' => 3], [1 => 2, 0 => 4]] as $i => $list) {
    emit("ArrayList(even) list {$i}", logged(fn () => check(new ArrayList($evens), $list)));
    foreach ([2, 3, -2, -3, 'x'] as $value) {
        emit("AllOf(even, positive) {$i} {$value}", logged(fn () => check(new AllOf([$evens, $positives]), $value)));
        emit("AnyOf(even, positive) {$i} {$value}", logged(fn () => check(new AnyOf([$evens, $positives]), $value)));
        emit("NoneOf(even, positive) {$i} {$value}", logged(fn () => check(new NoneOf([$evens, $positives]), $value)));
        emit("Multiple(even, positive) {$i} {$value}", logged(fn () => check(new Multiple([$evens, $positives]), $value)));
    }
}

// What AllOf, AnyOf and NoneOf ask of anything their arrays hold.
$members = [
    'thrower' => fn () => new Thrower(), 'loose 1' => fn () => new Loose(1), 'loose 0' => fn () => new Loose(0),
    "loose ''" => fn () => new Loose(''), "loose '0'" => fn () => new Loose('0'), 'loose []' => fn () => new Loose([]),
    'loose [1]' => fn () => new Loose([1]), 'loose null' => fn () => new Loose(null), 'loose NAN' => fn () => new Loose(NAN),
    'loose object' => fn () => new Loose(new stdClass()), 'loose stringable' => fn () => new Loose(stringable('d')), 'loose 1.5' => fn () => new Loose(1.5),
    'magic' => fn () => new Magic(), 'hidden' => fn () => new Hidden(), 'string' => fn () => 'Utopia\\Validator\\Text', 'null' => fn () => null,
    'int' => fn () => 1, 'array' => fn () => [new Probe('x')], 'stdClass' => fn () => new stdClass(), 'closure' => fn () => fn () => true,
];
foreach ([AllOf::class, AnyOf::class, NoneOf::class] as $class) {
    foreach ($members as $label => $member) {
        emit("{$class} [{$label}]", logged(fn () => check(new $class([$member()]), 'v')));
        emit("{$class} [probe, {$label}]", logged(fn () => check(new $class([new Probe('first', $class === AllOf::class), $member()]), 'v')));
        emit("{$class} keyed [{$label}]", logged(fn () => check(new $class(['k' => $member()]), 'v')));
        emit("{$class} description only [{$label}]", logged(fn () => (new $class([$member()]))->getDescription()));
    }
}
foreach (['thrower' => new Thrower(), 'loose 5' => new Loose(5), 'loose array' => new Loose(['a'])] as $label => $member) {
    emit("Multiple via subclass [{$label}]", logged(function () use ($member) {
        $multiple = new class ([]) extends Multiple {
            public function inject(mixed $rule): void
            {
                $this->rules[] = $rule;
            }
        };
        $multiple->inject($member);

        return check($multiple, 'v');
    }));
}

// Descriptions and types the wrapped validators decide.
emit('Nullable description of Thrower', logged(fn () => (new Nullable(new Thrower()))->getDescription()));
emit('Nullable type of Thrower', logged(fn () => (new Nullable(new Thrower()))->getType()));
emit('Nullable isValid of Thrower', logged(fn () => (new Nullable(new Thrower()))->isValid('x')));
emit('Nullable isValid null skips Thrower', logged(fn () => (new Nullable(new Thrower()))->isValid(null)));
emit('ArrayList description of Thrower', logged(fn () => (new ArrayList(new Thrower()))->getDescription()));
emit('ArrayList isValid of Thrower', logged(fn () => (new ArrayList(new Thrower()))->isValid([1])));
emit('ArrayList isValid of Thrower empty', logged(fn () => (new ArrayList(new Thrower()))->isValid([])));
emit('Multiple description of Thrower', logged(fn () => (new Multiple([new Probe('a'), new Thrower()]))->getDescription()));
emit('Multiple description keys', logged(fn () => (new Multiple([new Probe('a'), new Probe('b'), new Probe('c')]))->getDescription()));
emit('Nullable getValidator', logged(fn () => (new Nullable($v = new Probe('w')))->getValidator() === $v));
emit('ArrayList getValidator', logged(fn () => (new ArrayList($v = new Probe('w')))->getValidator() === $v));
emit('AnyOf getValidators', logged(fn () => exported((new AnyOf([new Probe('a'), 'k' => new Probe('b')]))->getValidators())));

// Multiple: addRule(), its return and its argument.
emit('Multiple addRule chain', logged(function () {
    $multiple = new Multiple([]);
    $same = $multiple->addRule(new Probe('a'))->addRule(new Probe('b', false)) === $multiple;

    return [$same, check($multiple, 1)];
}));
foreach (['string' => 'x', 'null' => null, 'stdClass' => new stdClass(), 'Loose' => new Loose(true), 'int' => 5] as $label => $rule) {
    emit("Multiple addRule {$label}", logged(fn () => (new Multiple([]))->addRule($rule)));
}
emit('Multiple addRule missing', logged(fn () => (new Multiple([]))->addRule()));
emit('Multiple type null getType', logged(fn () => (new Multiple([], null))->getType()));
foreach (['int type' => 5, 'float type' => 1.5, 'bool type' => true, 'stringable type' => stringable('t'), 'array type' => [], 'object type' => new stdClass()] as $label => $type) {
    emit("Multiple {$label}", logged(fn () => (new Multiple([], $type))->getType()));
}
// What `$this->rules[] = $rule` does with whatever a subclass left in $rules.
foreach (['null' => null, 'false' => false, 'true' => true, "''" => '', "'x'" => 'x', '1' => 1, 'stdClass' => new stdClass(), 'Bag' => new Bag(), 'keyed' => ['a' => new Probe('k')]] as $label => $rules) {
    emit("Multiple rules {$label} addRule", logged(function () use ($rules) {
        $multiple = new class ([]) extends Multiple {
            public function setRules(mixed $rules): void
            {
                $this->rules = $rules;
            }
        };
        $multiple->setRules($rules);
        $result = $multiple->addRule(new Probe('added'));

        return [$result === $multiple, properties($multiple)];
    }));
    emit("Multiple rules {$label} check", logged(function () use ($rules) {
        $multiple = new class ([]) extends Multiple {
            public function setRules(mixed $rules): void
            {
                $this->rules = $rules;
            }
        };
        $multiple->setRules($rules);

        return check($multiple, 'v');
    }));
}
emit('Multiple rules string keys description', logged(function () {
    $multiple = new class ([]) extends Multiple {
        public function setRules(mixed $rules): void
        {
            $this->rules = $rules;
        }
    };
    $multiple->setRules(['a' => new Probe('a'), 'z' => new Probe('z'), 'Az' => new Probe('az'), '9' => new Probe('nine'), PHP_INT_MAX => new Probe('max')]);

    return $multiple->getDescription();
}));
emit('Multiple rules object description', logged(function () {
    $multiple = new class ([]) extends Multiple {
        public function setRules(mixed $rules): void
        {
            $this->rules = $rules;
        }
    };
    $rules = new stdClass();
    $rules->first = new Probe('first');
    $rules->second = new Probe('second');
    $multiple->setRules($rules);

    return [$multiple->getDescription(), $multiple->isValid(1)];
}));
emit('Multiple rules iterator', logged(function () {
    $multiple = new class ([]) extends Multiple {
        public function setRules(mixed $rules): void
        {
            $this->rules = $rules;
        }
    };
    $multiple->setRules(new ArrayIterator(['x' => new Probe('x'), 'y' => new Probe('y', false)]));

    return [$multiple->getDescription(), $multiple->isValid(1)];
}));

// The constructors' arguments.
$constructors = [
    'Nullable()' => fn () => new Nullable(),
    "Nullable('x')" => fn () => new Nullable('x'),
    'Nullable(null)' => fn () => new Nullable(null),
    'Nullable(stdClass)' => fn () => new Nullable(new stdClass()),
    'ArrayList()' => fn () => new ArrayList(),
    "ArrayList(Text, '3')" => fn () => new ArrayList(new Text(1), '3'),
    "ArrayList(Text, 'x')" => fn () => new ArrayList(new Text(1), 'x'),
    'ArrayList(Text, 1.5)' => fn () => new ArrayList(new Text(1), 1.5),
    'ArrayList(Text, null)' => fn () => new ArrayList(new Text(1), null),
    'ArrayList(Text, true)' => fn () => new ArrayList(new Text(1), true),
    'ArrayList named' => fn () => new ArrayList(length: 2, validator: new Text(1)),
    'AllOf()' => fn () => new AllOf(),
    "AllOf('x')" => fn () => new AllOf('x'),
    'AllOf([], 5)' => fn () => new AllOf([], 5),
    'AllOf([], null)' => fn () => new AllOf([], null),
    'AnyOf([], stringable)' => fn () => new AnyOf([], stringable('s')),
    'NoneOf([], [])' => fn () => new NoneOf([], []),
    'Multiple()' => fn () => new Multiple(),
    "Multiple(['x'])" => fn () => new Multiple(['x']),
    'Multiple([Probe, null])' => fn () => new Multiple([new Probe('a'), null]),
    'Multiple([], 5)' => fn () => new Multiple([], 5),
    "Assoc('5')" => fn () => new Assoc('5'),
    "Assoc('x')" => fn () => new Assoc('x'),
    'Assoc(null)' => fn () => new Assoc(null),
    'ArrayValidator(1.0)' => fn () => new ArrayValidator(1.0),
    'ArrayValidator(1.5)' => fn () => new ArrayValidator(1.5),
    "ObjectValidator('')" => fn () => new ObjectValidator(''),
    'Globstar()' => fn () => new Globstar(),
    "Globstar('x')" => fn () => new Globstar('x'),
    'JSON(1)' => fn () => new JSON(1),
    'FCM(1)' => fn () => new FCM(1),
];
foreach ($constructors as $label => $make) {
    emit('construct ' . $label, logged(function () use ($make) {
        $made = $make();

        return ['class' => $made::class, 'properties' => properties($made)];
    }));
}
// Strict types: the coercions above become TypeErrors.
foreach (['new Utopia\\Validator\\ArrayList(new Utopia\\Validator\\Text(1), "3");', 'new Utopia\\Validator\\AllOf([], 5);', 'new Utopia\\Validator\\Multiple([], 5);', 'new Utopia\\Validator\\Assoc("5");', 'new Utopia\\Validator\\JSON\\ArrayValidator(1.0);', 'echo (new Utopia\\Validator\\AnyOf([new Utopia\\Validator\\Wildcard()]))->isValid(1) ? "t" : "f";'] as $code) {
    // Caught, as the uncaught error names the file the class is defined in.
    emit('strict ' . $code, child('try { ' . $code . ' } catch (Throwable $e) { echo $e::class, ": ", $e->getMessage(); }', true));
}
foreach (CLASSES as $class) {
    emit('isValid without argument ' . $class, logged(function () use ($class) {
        $made = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        return $made->isValid();
    }));
    emit('without constructor ' . $class, logged(function () use ($class) {
        $made = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        return [
            'properties' => properties($made),
            'valid' => outcome(fn () => $made->isValid(['a' => 1])),
            'description' => outcome(fn () => $made->getDescription()),
            'type' => outcome(fn () => $made->getType()),
        ];
    }));
}

// Assoc, JSON and the JSON validators, with json_last_error() as they leave it.
$jsons = [
    'Assoc' => new Assoc(), 'Assoc(10)' => new Assoc(10), 'JSON' => new JSON(), 'ArrayValidator' => new ArrayValidator(), 'ArrayValidator(5)' => new ArrayValidator(5),
    'ObjectValidator' => new ObjectValidator(), 'ObjectValidator(7)' => new ObjectValidator(7), 'FCM' => new FCM(),
];
foreach ($jsons as $name => $validator) {
    foreach ($values as $label => $value) {
        emit("json state {$name} {$label}", logged(function () use ($validator, $value) {
            json_decode('{');
            $valid = outcome(fn () => $validator->isValid($value));

            return [$valid, json_last_error(), json_last_error_msg()];
        }));
    }
}
foreach (['INF' => [INF], 'NAN' => [NAN], 'not utf-8' => ["\xff"], 'recursive' => (function () {
    $a = ['x' => 1];
    $a['self'] = &$a;

    return $a;
})(), 'stdClass not utf-8' => (object) ['a' => "\xff"], 'resource' => [STDOUT]] as $label => $value) {
    foreach ([new Assoc(), new Assoc(0), new ArrayValidator(), new ArrayValidator(100), new ObjectValidator(), new ObjectValidator(100)] as $validator) {
        emit("json encode {$label} " . $validator::class . ' ' . json_encode(properties($validator)), logged(function () use ($validator, $value) {
            json_decode('{');

            return [outcome(fn () => $validator->isValid($value)), json_last_error()];
        }));
    }
}
foreach ([ArrayValidator::class, ObjectValidator::class] as $class) {
    foreach ([0, 2, 10] as $length) {
        foreach (["'[1]'" => '[1]', "'{\"a\":1}'" => '{"a":1}', 'false' => false, 'true' => true, '5' => 5, 'null' => null, '[]' => [], 'stringable' => stringable('[1]')] as $label => $encoded) {
            emit("{$class}({$length}) hasValidLength {$label}", logged(function () use ($class, $length, $encoded) {
                $method = new ReflectionMethod($class, 'hasValidLength');

                return $method->invoke(new $class($length), $encoded);
            }));
        }
    }
}

// FCM: service account JSON.
$account = [
    'type' => 'service_account', 'project_id' => 'project', 'private_key_id' => 'key-id',
    'private_key' => "-----BEGIN PRIVATE KEY-----\nMIIE\n-----END PRIVATE KEY-----\n", 'client_email' => 'firebase@project.iam.gserviceaccount.com',
    'client_id' => '123', 'auth_uri' => 'https://accounts.google.com/o/oauth2/auth', 'token_uri' => 'https://oauth2.googleapis.com/token',
    'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
    'client_x509_cert_url' => 'https://www.googleapis.com/robot/v1/metadata/x509/firebase', 'universe_domain' => 'googleapis.com',
];
$accounts = ['complete' => $account, 'required only' => array_intersect_key($account, array_flip(['type', 'project_id', 'private_key', 'client_email', 'token_uri']))];
foreach (array_keys($account) as $field) {
    $without = $account;
    unset($without[$field]);
    $accounts["without {$field}"] = $without;
    foreach (['empty' => '', 'blank' => " \n\t", 'null' => null, 'int' => 5, 'array' => ['x'], 'http' => 'http://example.com', 'upper https' => 'HTTPS://example.com', 'not a url' => 'https//x', 'ftp' => 'ftp://example.com', 'nul' => "a\0b", 'not utf-8' => "\xff"] as $label => $bad) {
        $accounts["{$field} {$label}"] = [$field => $bad] + $account;
    }
}
$accounts['type other'] = ['type' => 'authorized_user'] + $account;
$accounts['email invalid'] = ['client_email' => 'not an email'] + $account;
$accounts['email unicode'] = ['client_email' => 'ü@example.com'] + $account;
$accounts['key no begin'] = ['private_key' => "MIIE\n-----END PRIVATE KEY-----"] + $account;
$accounts['key no end'] = ['private_key' => "-----BEGIN PRIVATE KEY-----\nMIIE"] + $account;
$accounts['key spaced end'] = ['private_key' => "-----BEGIN PRIVATE KEY-----\nMIIE\n-----END PRIVATE KEY-----  \n\0"] + $account;
$accounts['key leading space'] = ['private_key' => " -----BEGIN PRIVATE KEY-----\n-----END PRIVATE KEY-----"] + $account;
$accounts['extra field'] = $account + ['extra' => ['nested' => true]];
$accounts['numeric keys'] = $account + [0 => 'zero', 1 => 'one'];
foreach ($accounts as $label => $fields) {
    foreach (['array' => $fields, 'json' => json_encode($fields), 'stdClass' => (object) $fields, 'json pretty' => json_encode($fields, JSON_PRETTY_PRINT)] as $form => $value) {
        emit("FCM {$label} as {$form}", logged(fn () => check(new FCM(), $value)));
    }
}
foreach (['list' => ['type', 'project_id'], 'empty list' => [], 'json list' => '["a"]', 'json scalar' => '"x"', 'json invalid' => '{', 'stdClass subclass' => new class () extends stdClass {
    public $type = 'service_account';

    protected $hidden = 'x';
}] as $label => $value) {
    emit("FCM shape {$label}", logged(fn () => check(new FCM(), $value)));
}
foreach (['isNonEmptyString' => ['x', ' ', '', 5, null, [], stringable('s')], 'isHttpsUrl' => ['https://a.com', 'http://a.com', 'HTTPS://A.COM', 'nope', 5, true, null, [], stringable('https://b.com')]] as $method => $arguments) {
    foreach ($arguments as $i => $argument) {
        emit("FCM {$method} {$i}", logged(fn () => (new ReflectionMethod(FCM::class, $method))->invoke(new FCM(), $argument)));
    }
}

// Globstar: patterns and subjects.
$patterns = [
    'all' => ['*'], 'star star' => ['**'], 'extension' => ['*.md'], 'deep' => ['docs/**/*.md'], 'dir star star' => ['src/**'], 'question' => ['a?c'],
    'class' => ['[abc]x'], 'negated class' => ['[!abc]x'], 'caret class' => ['[^abc]x'], 'literal bang class' => ['[!]x'], 'literal caret class' => ['[^]x'],
    'bracket first' => ['[]a]'], 'bracket after bang' => ['[!]a]'], 'range' => ['[a-c]*'], 'reversed range' => ['[z-a]'], 'posix class' => ['[[:alpha:]]*'],
    'unclosed' => ['[abc'], 'escaped star' => ['a\\*b'], 'trailing backslash' => ['a\\'], 'regex chars' => ['a.b+c(d)'], 'tilde' => ['a~b'], 'nul' => ["a\0b"],
    'hash' => ['a#b'], 'literal' => ['README.md'], 'exclusion only' => ['!*.md'], 'exclusions only' => ['!a*', '!*b'], 'include exclude' => ['src/**', '!src/test/**'],
    'exclude then include' => ['!*.md', '*.md'], 'literal beats exclusion' => ['README.md', '!*.md'], 'later include' => ['*.md', '!README*', 'READ*'],
    'bang alone' => ['!'], 'empty pattern' => [''], 'empty and bang' => ['', '!'], 'multibyte' => ['ü*'], 'not utf-8' => ["\xff*"],
    'int' => [5], 'float' => [1.5], 'true' => [true], 'false' => [false], 'stringable' => [stringable('*.md')], 'stringable exclusion' => [stringable('!*.md'), '*'],
    'int and exclusion' => [5, '!5'], 'null' => [null], 'array' => [['*']], 'object' => [new stdClass()], 'enum' => [Suit::Hearts], 'keyed' => ['x' => '*.md', 'y' => '!b*'],
];
$subjects = ['README.md', 'readme.md', 'docs/a.md', 'docs/x/y/a.md', 'docs/a.txt', 'src/a.php', 'src/test/a.php', 'src', 'abc', 'abx', 'bx', 'ax', 'dx', ']', ']a', '!x', '^x',
    'a*b', 'ab', 'a\\', 'a.b+c(d)', 'a~b', "a\0b", 'a#b', 'üx', "\xffx", '', '5', '1.5', '1', 'b', 'x/y', "a\nb", 'zzz'];
foreach ($patterns as $label => $list) {
    foreach ($subjects as $subject) {
        emit("Globstar {$label} " . json_encode(exported($subject)), logged(function () use ($list, $subject) {
            preg_match('/a/u', "\xff");
            $valid = outcome(fn () => (new Globstar($list))->isValid($subject));

            return [$valid, preg_last_error()];
        }));
    }
}
foreach (['null' => null, 'int' => 5, 'array' => [], 'stringable' => stringable('x')] as $label => $subject) {
    emit("Globstar non-string subject {$label}", logged(fn () => (new Globstar(['*']))->isValid($subject)));
}
emit('Globstar cast counts', logged(function () {
    $cast = 0;
    $pattern = stringable('a?c', $cast);
    $valid = (new Globstar([$pattern, '!x']))->isValid('abc');

    return [$valid, $cast];
}));
emit('Globstar cast counts exclusions', logged(function () {
    $cast = 0;
    $pattern = stringable('!x*', $cast);
    $valid = (new Globstar([$pattern, '!y*']))->isValid('abc');

    return [$valid, $cast];
}));
emit('Globstar readonly', logged(function () {
    $globstar = new Globstar(['*']);
    $globstar->__construct(['x']);
}));
foreach (['match', 'matchGlobstar'] as $method) {
    foreach ([['abc', 'a*'], ['abc', 'b*'], [5, '5'], ['x', null], ['x', []], ['x', stringable('x')]] as $i => $arguments) {
        emit("Globstar {$method} {$i}", logged(fn () => (new ReflectionMethod(Globstar::class, $method))->invoke(new Globstar([]), ...$arguments)));
    }
}

// Subclasses of the extension's classes, as PHP code extends them.
$subclasses = [
    'AnyOf reading state' => function () {
        $any = new class ([new Probe('a', false), new Probe('b')]) extends AnyOf {
            public function isValid(mixed $value): bool
            {
                global $log;
                $log[] = 'subclass:before ' . count($this->validators) . ' ' . $this->type . ' ' . var_export($this->failedRule, true);
                $valid = parent::isValid($value);
                $log[] = 'subclass:after ' . $this->failedRule::class;

                return !$valid;
            }
        };

        return check($any, 1);
    },
    'AllOf overriding getDescription' => function () {
        $all = new class ([new Probe('a', false, 'A')]) extends AllOf {
            public function getDescription(): string
            {
                return '[' . parent::getDescription() . ']';
            }
        };

        return check($all, 1);
    },
    'NoneOf writing failedRule' => function () {
        $none = new class ([new Probe('a', false)]) extends NoneOf {
            public function setFailed(?Validator $failed): void
            {
                $this->failedRule = $failed;
            }
        };
        $none->setFailed(new Probe('preset', true, 'preset description'));

        return check($none, 1);
    },
    'NoneOf failedRule wrong type' => function () {
        $none = new class ([]) extends NoneOf {
            public function setFailed(mixed $failed): void
            {
                $this->failedRule = $failed;
            }
        };
        $none->setFailed('x');
    },
    'Nullable reading validator' => function () {
        $nullable = new class (new Probe('a')) extends Nullable {
            public function inner(): string
            {
                return $this->validator::class;
            }
        };

        return [$nullable->inner(), check($nullable, 1)];
    },
    'Nullable replacing validator' => function () {
        $nullable = new class (new Probe('a')) extends Nullable {
            public function replace(Validator $validator): void
            {
                $this->validator = $validator;
            }
        };
        $nullable->replace(new Probe('b', false));

        return check($nullable, 1);
    },
    'ArrayList length change' => function () {
        $list = new class (new Probe('a'), 2) extends ArrayList {
            public function grow(): void
            {
                $this->length = 5;
            }
        };
        $before = $list->isValid([1, 2, 3]);
        $list->grow();

        return [$before, check($list, [1, 2, 3])];
    },
    'ArrayList validator changing mid-loop' => function () {
        $list = null;
        $changer = new Probe('changer', function ($v) use (&$list) {
            $list->swap(new Probe('swapped', false));

            return true;
        });
        $list = new class ($changer) extends ArrayList {
            public function swap(Validator $validator): void
            {
                $this->validator = $validator;
            }
        };

        return check($list, [1, 2, 3]);
    },
    'AnyOf validators changing mid-loop' => function () {
        $any = null;
        $changer = new Probe('changer', function ($v) use (&$any) {
            $any->replaceAll([new Probe('late')]);

            return false;
        });
        $any = new class ([$changer, new Probe('second', false)]) extends AnyOf {
            public function replaceAll(array $validators): void
            {
                $this->validators = $validators;
            }
        };

        return [check($any, 1), check($any, 2)];
    },
    'Multiple overriding addRule' => function () {
        $multiple = new class ([new Probe('a'), new Probe('b')]) extends Multiple {
            public function addRule(Validator $rule): static
            {
                global $log;
                $log[] = 'subclass:addRule ' . $rule->getType();

                return parent::addRule(new Probe('wrapped-' . count($this->rules)));
            }
        };

        return check($multiple, 1);
    },
    'Multiple addRule returning other' => function () {
        $multiple = new class ([new Probe('a')]) extends Multiple {
            public function addRule(Validator $rule): static
            {
                return new self([]);
            }
        };

        return properties($multiple);
    },
    'Multiple without parent constructor' => function () {
        $multiple = new class () extends Multiple {
            public function __construct()
            {
            }
        };

        return check($multiple, 1);
    },
    'AllOf without parent constructor' => function () {
        $all = new class () extends AllOf {
            public function __construct()
            {
            }
        };

        return check($all, 1);
    },
    'Globstar subclass' => function () {
        $globstar = new class (['*.md', '!x*']) extends Globstar {
            public function getType(): string
            {
                return 'subclass';
            }

            public function patterns(): mixed
            {
                return $this->patterns ?? 'inaccessible';
            }
        };

        return [check($globstar, 'a.md'), outcome(fn () => $globstar->patterns())];
    },
    'Globstar subclass own match' => function () {
        $globstar = new class (['a*']) extends Globstar {
            private function match(string $subject, string $pattern): bool
            {
                return false;
            }
        };

        return check($globstar, 'abc');
    },
    'FCM subclass' => function () {
        $fcm = new class () extends FCM {
            public function getDescription(): string
            {
                return 'subclass: ' . parent::getDescription();
            }
        };

        return [check($fcm, ['type' => 'x']), check($fcm, '{}')];
    },
    'ArrayValidator subclass' => function () {
        $validator = new class (3) extends ArrayValidator {
            public function isValid(mixed $value): bool
            {
                return parent::isValid($value) && $this->length === 3;
            }
        };

        return [check($validator, '[1]'), check($validator, '[1,2]')];
    },
    'Assoc subclass length' => function () {
        $assoc = new class () extends Assoc {
            public function length(): int
            {
                return $this->length;
            }
        };

        return [$assoc->length(), check($assoc, ['a' => 1])];
    },
    'JSON subclass' => function () {
        $json = new class () extends JSON {
            public function isArray(): bool
            {
                return true;
            }
        };

        return check($json, '{}');
    },
];
foreach ($subclasses as $label => $run) {
    emit('subclass ' . $label, logged($run));
}

// The objects themselves: dumps, clones, serialization, comparison.
foreach ($factories as $name => $factory) {
    emit('object ' . $name, logged(function () use ($factory) {
        $made = $factory();
        $made->isValid(['x' => 1]);
        ob_start();
        var_dump($made);
        $dump = withoutHandles(ob_get_clean());

        return [
            'var_dump' => $dump, 'clone' => properties(clone $made), 'equal clone' => $made == clone $made,
            'serialize' => outcome(fn () => serialize($made)), 'unserialize' => outcome(fn () => properties(unserialize(serialize($made)))),
            'json' => json_encode($made), 'export' => outcome(fn () => var_export($made, true)),
        ];
    }));
}
emit('equality', logged(fn () => [
    new Assoc() == new Assoc(), new Assoc() == new Assoc(5), new JSON() == new JSON(), new Globstar(['a']) == new Globstar(['a']),
    new AnyOf([]) == new AnyOf([]), new Nullable(new Text(1)) == new Nullable(new Text(1)), new FCM() == new FCM(),
]));

// Declarations PHP checks when a class extends one of these, in a child process.
$declarations = [
    'Nullable isValid string' => 'class X extends Utopia\\Validator\\Nullable { public function isValid(string $value): bool { return true; } }',
    'Assoc isValid int' => 'class X extends Utopia\\Validator\\Assoc { public function isValid(int $value): bool { return true; } }',
    'AnyOf getValidators string' => 'class X extends Utopia\\Validator\\AnyOf { public function getValidators(): string { return ""; } }',
    'Nullable getValidator array' => 'class X extends Utopia\\Validator\\Nullable { public function getValidator(): array { return []; } }',
    'Multiple addRule self' => 'class X extends Utopia\\Validator\\Multiple { public function addRule(Utopia\\Validator\\Validator $rule): self { return $this; } }',
    'Multiple addRule narrower' => 'class X extends Utopia\\Validator\\Multiple { public function addRule(Utopia\\Validator\\Text $rule): static { return $this; } }',
    'ArrayValidator hasValidLength public' => 'class X extends Utopia\\Validator\\JSON\\ArrayValidator { public function hasValidLength(int $x): int { return 1; } } echo (new X())->hasValidLength(5);',
    'FCM isHttpsUrl own' => 'class X extends Utopia\\Validator\\JSON\\FCM { public function isHttpsUrl($x) { return "own"; } } echo (new X())->isHttpsUrl(1);',
    'Globstar patterns redeclared' => 'class X extends Utopia\\Validator\\Globstar { public array $patterns = ["x"]; } $x = new X(["*"]); echo json_encode($x->patterns), (new X(["*"]))->isValid("a") ? "t" : "f";',
    'AllOf failedRule redeclared public' => 'class X extends Utopia\\Validator\\AllOf { public ?Utopia\\Validator\\Validator $failedRule = null; } $x = new X([new Utopia\\Validator\\Wildcard()]); var_dump($x->failedRule);',
    'AllOf failedRule int' => 'class X extends Utopia\\Validator\\AllOf { protected ?int $failedRule = null; }',
    'final subclass' => 'final class F extends Utopia\\Validator\\JSON { } echo (new F())->isValid("{}") ? "ok" : "no";',
    'readonly subclass' => 'readonly class R extends Utopia\\Validator\\JSON { }',
    'abstract subclass' => 'abstract class A extends Utopia\\Validator\\Multiple {} echo "ok";',
];
foreach ($declarations as $name => $code) {
    emit('declaration ' . $name, child($code));
}

// Known differences of the extension, each a waiver in crates/extension/README.md. A TypeError
// PHP raises for an argument one of these classes passes to its own method names the file and
// line in packages/validators/src that made the call; the extension's has no PHP source, so its
// message ends at "given". Every other line above compares these errors with that suffix cut.
$raw = function (callable $run): array {
    global $warnings;
    $warnings = [];
    try {
        $run();
        $result = ['throws' => null];
    } catch (Throwable $e) {
        $result = ['throws' => $e::class, 'message' => $e->getMessage()];
    }
    if ($warnings !== []) {
        $result['warnings'] = $warnings;
    }

    return $result;
};
$waivers = [
    'Multiple constructor with a rule that is not a Validator' => $raw(fn () => new Multiple(['x'])),
    'Globstar with a null pattern' => $raw(fn () => (new Globstar([null]))->isValid('x')),
    'Globstar with an array pattern' => $raw(fn () => (new Globstar([['*']]))->isValid('x')),
];
// The extension's outcomes; PHP's are the same with ", called in" the file below and a line.
$expected = [
    'Multiple constructor with a rule that is not a Validator' => ['Multiple.php', ['throws' => TypeError::class, 'message' => 'Utopia\\Validator\\Multiple::addRule(): Argument #1 ($rule) must be of type Utopia\\Validator\\Validator, string given']],
    'Globstar with a null pattern' => ['Globstar.php', ['throws' => TypeError::class, 'message' => 'Utopia\\Validator\\Globstar::match(): Argument #2 ($pattern) must be of type string, null given']],
    'Globstar with an array pattern' => ['Globstar.php', ['throws' => TypeError::class, 'message' => 'Utopia\\Validator\\Globstar::match(): Argument #2 ($pattern) must be of type string, array given', 'warnings' => array_fill(0, 5, 'Array to string conversion')]],
];
foreach ($expected as $name => [$file, $outcome]) {
    if (!$internal) {
        $called = ', called in ' . realpath(__DIR__ . '/../../../packages/validators/src/' . $file) . ' on line ';
        $message = $waivers[$name]['message'] ?? '';
        $at = strpos($message, $called);
        $line = $at === false ? '' : substr($message, $at + strlen($called));
        if ($line === '' || !ctype_digit($line)) {
            fwrite(STDERR, "Waiver changed on PHP: {$name}: " . json_encode(exported($waivers[$name])) . PHP_EOL);
            exit(1);
        }
        $outcome['message'] .= $called . $line;
    }
    if ($waivers[$name] !== $outcome) {
        fwrite(STDERR, "Waiver changed: {$name}: " . json_encode(exported($waivers[$name])) . PHP_EOL);
        exit(1);
    }
}
fwrite(STDERR, count($expected) . ' waivers hold' . PHP_EOL);
