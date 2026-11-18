<?php

// Parity corpus for the scalar leaf validators: Boolean, Integer, FloatValidator, Numeric,
// Range, Port, HexColor and Wildcard. Run it on the PHP classes and on the utopia extension's
// (_APP_EXTENSION_LIBRARIES=validators,validators-scalar), and diff stdout: every line must
// match. Stderr names the implementation that ran.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Validator\Boolean;
use Utopia\Validator\FloatValidator;
use Utopia\Validator\HexColor;
use Utopia\Validator\Integer;
use Utopia\Validator\Numeric;
use Utopia\Validator\Port;
use Utopia\Validator\Range;
use Utopia\Validator\Validator;
use Utopia\Validator\Wildcard;

const CLASSES = [Boolean::class, Integer::class, FloatValidator::class, Numeric::class, Range::class, Port::class, HexColor::class, Wildcard::class];

$internal = (new ReflectionClass(Range::class))->isInternal();
fwrite(STDERR, 'Utopia\\Validator\\Range: ' . ($internal ? 'utopia extension' : 'PHP') . PHP_EOL);
// A run meant for the extension that gets the PHP classes would compare PHP with PHP and pass.
$switches = array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: ''));
$requested = in_array('validators', $switches, true) && in_array('validators-scalar', $switches, true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' validators and validators-scalar, but the other implementation loaded' . PHP_EOL);
    exit(1);
}
foreach (CLASSES as $class) {
    if ((new ReflectionClass($class))->isInternal() !== $internal) {
        fwrite(STDERR, $class . ' is not from the same implementation as Range' . PHP_EOL);
        exit(1);
    }
}

/** Warnings raised by the current case. */
$warnings = [];
set_error_handler(function (int $level, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});

/** $value with every string that is not UTF-8 as its hex, floats JSON cannot hold as text, and every object as its class. */
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
        return ['class' => $value::class];
    }

    return $value;
}

/** The observable outcome of $run: its value or exception, and the warnings it raised. */
function outcome(callable $run): array
{
    global $warnings;
    $warnings = [];
    try {
        $result = ['value' => exported($run())];
    } catch (Throwable $e) {
        $result = ['throws' => $e::class, 'message' => exported($e->getMessage())];
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
}

/** A Stringable object of $string. */
function stringable(string $string): object
{
    return new class ($string) {
        public function __construct(private string $string)
        {
        }

        public function __toString(): string
        {
            return $this->string;
        }
    };
}

// The constructors, over arguments of every kind PHP converts or rejects.
$constructions = [
    'Boolean' => [[], [true], [false], [1], [0], [2], ['1'], ['0'], ['yes'], [''], [1.5], [0.0], [null], [[]], [new stdClass()], [stringable('1')], [true, 'extra']],
    'FloatValidator' => [[], [true], [false], [1], ['1'], [null], [[]], [1.5]],
    'Integer' => [
        [], [true], [false, 8], [false, 16], [false, 32], [false, 64], [true, 8, true], [false, 16, true], [false, 32, true], [false, 64, true],
        [false, 12], [false, 0], [false, -8], [false, '32'], [false, ' 32'], [false, '32 '], [false, '32abc'], [false, 'abc'], [false, 32.0], [false, 32.5],
        [false, 1e100], [false, NAN], [false, true], [false, null], [false, []], [false, 8, '1'], [false, 8, null], [null], ['1', '16', 'yes'],
        [false, 8, false, 'extra'], [stringable('1')],
    ],
    'Numeric' => [[], ['extra']],
    'Range' => [
        [1, 10], [0.5, 1.5, Validator::TYPE_FLOAT], [1, 10, Validator::TYPE_STRING], [1, 10, 'other'], ['1', '10'], [' 1', '10 '], ['1.5', 2], ['1e2', 1e3],
        [true, 2], [false, true], [1], [], [null, 1], [1, null], [[], 1], ['abc', 1], ['1abc', 1], [1, INF], [-INF, INF], [NAN, 1], [1, 10, 5], [1, 10, 1.5],
        [1, 10, true], [1, 10, null], [1, 10, []], [1, 10, stringable('double')], [PHP_INT_MAX, PHP_INT_MAX], [PHP_INT_MIN, PHP_INT_MAX], [1e308, -1e308],
        [-0.0, 0], [0.1, 0.9, Validator::TYPE_FLOAT], [-1.5, 1.5], [1000000, 9999999.5], ['9223372036854775808', 1], [1, 10, 'integer', 'extra'],
    ],
    'Port' => [[], ['extra']],
    'HexColor' => [[]],
    'Wildcard' => [[], [1, 2]],
];

// Values of every kind, for isValid().
$values = [
    'null' => null, 'true' => true, 'false' => false, '0' => 0, '1' => 1, '-1' => -1, '2' => 2, '80' => 80, '127' => 127, '128' => 128, '255' => 255,
    '256' => 256, '-128' => -128, '-129' => -129, '65535' => 65535, '65536' => 65536, '2147483647' => 2147483647, '2147483648' => 2147483648,
    '-2147483648' => -2147483648, '-2147483649' => -2147483649, '4294967295' => 4294967295, 'PHP_INT_MAX' => PHP_INT_MAX, 'PHP_INT_MIN' => PHP_INT_MIN,
    '1.0' => 1.0, '1.5' => 1.5, '0.0' => 0.0, '-0.0' => -0.0, '5.0' => 5.0, '9.99' => 9.99, '10.0' => 10.0, '10.5' => 10.5, 'NAN' => NAN, 'INF' => INF,
    '-INF' => -INF, '1e308' => 1e308, 'epsilon' => PHP_FLOAT_EPSILON, 'float int max' => (float) PHP_INT_MAX,
    "'1'" => '1', "' 1'" => ' 1', "'1 '" => '1 ', "' 1 '" => ' 1 ', "'\\t1\\n'" => "\t1\n", "'\\v1\\f'" => "\v1\f", "'1e3'" => '1e3', "'1E3'" => '1E3',
    "'1.'" => '1.', "'.5'" => '.5', "'0x1A'" => '0x1A', "'0b1'" => '0b1', "'+1'" => '+1', "'-1'" => '-1', "'01'" => '01', "'1.0'" => '1.0',
    "'5'" => '5', "'5.5'" => '5.5', "'10'" => '10', "'11'" => '11', "''" => '', "' '" => ' ', "'.'" => '.', "'-'" => '-', "'+'" => '+', "'e'" => 'e',
    "'1e'" => '1e', "'abc'" => 'abc', "'1abc'" => '1abc', "'true'" => 'true', "'false'" => 'false', "'0'" => '0', "'TRUE'" => 'TRUE', "'00'" => '00',
    "'9223372036854775807'" => '9223372036854775807', "'9223372036854775808'" => '9223372036854775808', "'-9223372036854775809'" => '-9223372036854775809',
    "'1e400'" => '1e400', "'INF'" => 'INF', "'NAN'" => 'NAN', "'1_000'" => '1_000', "'1,000'" => '1,000', "'٣'" => '٣',
    "'80'" => '80', "'8080'" => '8080', "'65535'" => '65535', "'65536'" => '65536', "'080'" => '080', "'99999999999999999999'" => '99999999999999999999',
    "'127.0.0.1:80'" => '127.0.0.1:80', "'127.0.0.1:0'" => '127.0.0.1:0', "'127.0.0.1:080'" => '127.0.0.1:080', "'[::1]:443'" => '[::1]:443',
    "'[::1]'" => '[::1]', "'::1:80'" => '::1:80', "'[::1]:'" => '[::1]:', "'[:80'" => '[:80', "'[]:80'" => '[]:80', "'[:'" => '[:', "':80'" => ':80',
    "'127.0.0.1:'" => '127.0.0.1:', "'256.0.0.1:80'" => '256.0.0.1:80', "'localhost:80'" => 'localhost:80', "'1.2.3.4:65535'" => '1.2.3.4:65535',
    "'[2001:db8::1]:8080'" => '[2001:db8::1]:8080', "'[127.0.0.1]:80'" => '[127.0.0.1]:80', "'01.2.3.4:80'" => '01.2.3.4:80', "'1.2.3.4:80:90'" => '1.2.3.4:80:90',
    "'fff'" => 'fff', "'FFF'" => 'FFF', "'abcd'" => 'abcd', "'a1b2c3'" => 'a1b2c3', "'A1B2C3'" => 'A1B2C3', "'#fff'" => '#fff', "'ggg'" => 'ggg',
    "'fff\\n'" => "fff\n", "'fff\\n\\n'" => "fff\n\n", "'\\nfff'" => "\nfff", "'ffffff\\n'" => "ffffff\n", "'fff\\r\\n'" => "fff\r\n", "'ff ff'" => 'ff ff',
    "'fffffff'" => 'fffffff', 'not utf-8' => "\xff\xfe", 'nul' => "1\x00", '[]' => [], '[1]' => [1], "['a'=>1]" => ['a' => 1], 'object' => new stdClass(),
    "stringable '1'" => stringable('1'), "stringable 'fff'" => stringable('fff'),
];

$instances = [];
foreach ($constructions as $short => $variants) {
    $class = 'Utopia\\Validator\\' . $short;
    foreach ($variants as $i => $arguments) {
        $case = $short . ' ' . json_encode(exported($arguments), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $made = null;
        emit('new ' . $case, outcome(function () use ($class, $arguments, &$made) {
            $made = new $class(...$arguments);

            return properties($made);
        }));
        if ($made === null) {
            continue;
        }
        $instances[$case] = $made;
        $getters = ['getDescription', 'getType', 'isArray'];
        if ($made instanceof Integer) {
            $getters = [...$getters, 'getBits', 'isUnsigned', 'getFormat'];
        }
        if ($made instanceof Range) {
            $getters = [...$getters, 'getMin', 'getMax', 'getFormat'];
        }
        foreach ($getters as $getter) {
            emit($case . ' ' . $getter, outcome(fn () => $made->$getter()));
        }
        foreach ($values as $label => $value) {
            emit($case . ' isValid ' . $label, outcome(fn () => $made->isValid($value)));
        }
    }
}
fwrite(STDERR, count($instances) . ' validators, ' . count($values) . ' values' . PHP_EOL);

// Calls the methods' signatures reject.
foreach (CLASSES as $name) {
    $short = substr($name, strrpos($name, '\\') + 1);
    $validator = $instances[array_key_first(array_filter($instances, fn ($v) => $v::class === $name))];
    emit('isValid() no argument ' . $short, outcome(fn () => $validator->isValid()));
    emit('isValid() two arguments ' . $short, outcome(fn () => $validator->isValid('1', 2)));
    emit('isValid() named ' . $short, outcome(fn () => $validator->isValid(value: '1')));
    emit('isValid() unknown named ' . $short, outcome(fn () => $validator->isValid(other: '1')));
    emit('getDescription() with an argument ' . $short, outcome(fn () => $validator->getDescription(1)));
    emit('via reflection ' . $short, outcome(fn () => (new ReflectionMethod($name, 'isValid'))->invoke($validator, '1')));
    emit('call_user_func ' . $short, outcome(fn () => call_user_func([$validator, 'isValid'], '1')));
    emit('callable ' . $short, outcome(fn () => array_map($validator->isValid(...), ['1', 1, 'fff'])));
}
emit('new Range via reflection, too few', outcome(fn () => (new ReflectionClass(Range::class))->newInstance(1)));
emit('new Range via reflection, bad type', outcome(fn () => (new ReflectionClass(Range::class))->newInstanceArgs([[], 1])));
emit('new Range named', outcome(fn () => properties(new Range(max: 5, min: 1))));
emit('new Range named format', outcome(fn () => properties(new Range(1, 2, format: 'double'))));
emit('new Range unknown named', outcome(fn () => new Range(1, 2, other: 'x')));
emit('new Integer named', outcome(fn () => properties(new Integer(unsigned: true, bits: 16))));
emit('newInstanceWithoutConstructor', outcome(function () {
    $range = (new ReflectionClass(Range::class))->newInstanceWithoutConstructor();

    return [properties($range), outcome(fn () => $range->isValid(1)), outcome(fn () => $range->getDescription()), outcome(fn () => $range->getMin())];
}));
emit('newInstanceWithoutConstructor Integer', outcome(function () {
    $integer = (new ReflectionClass(Integer::class))->newInstanceWithoutConstructor();

    return [properties($integer), outcome(fn () => $integer->isValid(1)), outcome(fn () => $integer->getDescription()), outcome(fn () => $integer->getBits())];
}));
emit('private Integer::getMin', outcome(fn () => (new Integer())->getMin()));
emit('private Integer::getMax via reflection', outcome(fn () => (new ReflectionMethod(Integer::class, 'getMax'))->invoke(new Integer(false, 16, true))));
emit('private Port::isValidPort via reflection', outcome(fn () => array_map(fn ($p) => (new ReflectionMethod(Port::class, 'isValidPort'))->invoke(new Port(), $p), ['80', '080', '70000', ''])));
emit('private Port::isValidHost via reflection', outcome(fn () => array_map(fn ($h) => (new ReflectionMethod(Port::class, 'isValidHost'))->invoke(new Port(), $h), ['127.0.0.1', '[::1]', '[', '::1', 'x'])));
emit('private Port::isValidPort int', outcome(fn () => (new ReflectionMethod(Port::class, 'isValidPort'))->invoke(new Port(), 80)));
emit('private Port::isValidPort array', outcome(fn () => (new ReflectionMethod(Port::class, 'isValidPort'))->invoke(new Port(), [])));

// Strict types: the caller's declaration decides the conversions, as for any call.
$strict = [
    'Boolean int' => 'new Utopia\\Validator\\Boolean(1);',
    'Integer string bits' => 'new Utopia\\Validator\\Integer(false, "32");',
    'Integer float bits' => 'new Utopia\\Validator\\Integer(false, 32.0);',
    'Range string min' => 'new Utopia\\Validator\\Range("1", 2);',
    'Range int format' => 'new Utopia\\Validator\\Range(1, 2, 5);',
    'Range int to float' => 'var_dump(new Utopia\\Validator\\Range(1, 2.5));',
    'isValid strict' => 'var_dump((new Utopia\\Validator\\Integer())->isValid("5"), (new Utopia\\Validator\\Port())->isValid(80));',
];
foreach ($strict as $name => $code) {
    emit('strict ' . $name, child('try { ' . $code . ' echo "ok\n"; } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }', true));
}

// Each class's objects: dumps, copies, comparisons and serialization.
foreach ($instances as $case => $validator) {
    emit($case . ' var_dump', outcome(function () use ($validator) {
        ob_start();
        var_dump($validator);

        return withoutHandles(ob_get_clean());
    }));
    emit($case . ' print_r var_export', outcome(fn () => [print_r($validator, true), var_export($validator, true)]));
    emit($case . ' clone', outcome(fn () => [properties(clone $validator), clone $validator == $validator]));
    emit($case . ' serialize', outcome(function () use ($validator) {
        $serialized = serialize($validator);
        $back = unserialize($serialized);

        return [$serialized, $back == $validator, $back::class, $back->getDescription(), $back->isValid('1')];
    }));
    emit($case . ' json', outcome(fn () => json_encode($validator)));
    emit($case . ' get_object_vars', outcome(fn () => get_object_vars($validator)));
    emit($case . ' iterate', outcome(fn () => iterator_to_array((function () use ($validator) {
        foreach ($validator as $key => $value) {
            yield $key => $value;
        }
    })())));
    emit($case . ' dynamic property', outcome(function () use ($validator) {
        $copy = clone $validator;
        $copy->extra = 1;

        return properties($copy);
    }));
    emit($case . ' read protected', outcome(fn () => $validator->loose ?? $validator->min ?? 'unset'));
}
emit('compare across classes', outcome(fn () => [new Numeric() == new Port(), new Boolean() == new Boolean(), new Boolean() == new Boolean(true), new Range(1, 2) == new Range(1, 2.0)]));
emit('compare order', outcome(fn () => [new Range(1, 2) < new Range(1, 3), new Range(2, 1) <=> new Range(1, 2), new Integer() <=> new Integer(true)]));
emit('unserialize crafted', outcome(fn () => properties(unserialize('O:22:"Utopia\\Validator\\Range":3:{s:6:"' . "\0*\0" . 'min";i:5;s:6:"' . "\0*\0" . 'max";i:1;s:9:"' . "\0*\0" . 'format";s:6:"double";}'))));
emit('unserialize crafted wrong type', outcome(fn () => unserialize('O:22:"Utopia\\Validator\\Range":1:{s:6:"' . "\0*\0" . 'min";s:3:"abc";}')));
emit('unserialize crafted missing', outcome(function () {
    $range = unserialize('O:22:"Utopia\\Validator\\Range":1:{s:6:"' . "\0*\0" . 'min";i:5;}');

    return [properties($range), outcome(fn () => $range->isValid(7)), outcome(fn () => $range->isValid(3))];
}));

// PHP subclasses of the native leaves.
$range = new class (1, 10) extends Range {
    public string $label = 'first';

    public array $seen = [];

    public function getDescription(): string
    {
        return parent::getDescription() . ' (' . $this->label . ')';
    }

    public function widen(int|float $by): void
    {
        $this->min -= $by;
        $this->max += $by;
    }

    public function retype(mixed $min): void
    {
        $this->min = $min;
    }

    public function format(string $format): void
    {
        $this->format = $format;
    }
};
emit('subclass Range', outcome(fn () => [$range instanceof Range, $range instanceof Numeric, $range instanceof Validator, $range->getDescription(), $range->isValid(0), $range->isValid(10), properties($range)]));
emit('subclass Range widen', outcome(function () use ($range) {
    $copy = clone $range;
    $copy->widen(1.5);
    $copy->seen[] = 'x';

    return [$copy->getDescription(), $copy->isValid(0), $copy->isValid(-1), $copy->getMin(), properties($copy), properties($range)];
}));
emit('subclass Range retype', outcome(function () use ($range) {
    $copy = clone $range;
    $copy->retype('3');
    $result = [$copy->getMin()];
    $copy->retype('abc');

    return $result;
}));
emit('subclass Range format', outcome(function () use ($range) {
    $copy = clone $range;
    $copy->format(Validator::TYPE_FLOAT);
    $result = [$copy->getType(), $copy->isValid(5.5)];
    $copy->format('other');

    return [...$result, $copy->isValid(5), $copy->getType()];
}));
emit('subclass Range serialize', outcome(function () use ($range) {
    $back = unserialize(serialize($range));

    return [$back::class === $range::class];
}));
emit('subclass Range var_dump', outcome(function () use ($range) {
    ob_start();
    var_dump($range);

    return str_replace($range::class, 'Range@anonymous', withoutHandles(ob_get_clean()));
}));
$integer = new class () extends Integer {
    public function isUnsigned(): bool
    {
        return true;
    }

    public function shrink(): void
    {
        $this->bits = 8;
    }
};
emit('subclass Integer isUnsigned override', outcome(fn () => [$integer->getFormat(), $integer->getDescription(), $integer->isUnsigned(), $integer->getBits()]));
emit('subclass Integer shrink', outcome(function () use ($integer) {
    $copy = clone $integer;
    $copy->shrink();

    return [$copy->getFormat(), $copy->getDescription(), $copy->isValid(127), $copy->isValid(128), $copy->getBits()];
}));
$truthy = new class () extends Integer {
    public function isUnsigned(): bool
    {
        throw new RuntimeException('isUnsigned failed');
    }
};
emit('subclass Integer isUnsigned throws', outcome(fn () => $truthy->getFormat()));
$lazy = new class () extends Range {
    public function __construct()
    {
    }
};
emit('subclass Range without parent constructor', outcome(fn () => [properties($lazy), outcome(fn () => $lazy->isValid(1)), outcome(fn () => $lazy->isValid('x')), outcome(fn () => $lazy->getDescription()), outcome(fn () => $lazy->getType())]));
$late = new class () extends Range {
    public function __construct()
    {
        parent::__construct('2', 4.5, Validator::TYPE_FLOAT);
    }
};
emit('subclass Range parent constructor', outcome(fn () => [properties($late), $late->isValid(3), $late->getDescription()]));
$bool = new class () extends Boolean {
    public function __construct()
    {
    }
};
emit('subclass Boolean without parent constructor', outcome(fn () => [properties($bool), outcome(fn () => $bool->isValid(true)), outcome(fn () => $bool->getDescription())]));
$port = new class () extends Port {
    public function isValid($value): bool
    {
        return parent::isValid($value) && $value !== '80';
    }
};
emit('subclass Port', outcome(fn () => [$port->isValid('80'), $port->isValid('8080'), $port->isValid(80)]));
$strictHex = new class () extends HexColor {
    public function isValid(mixed $value): bool
    {
        return parent::isValid($value) && strlen($value) === 6;
    }
};
emit('subclass HexColor', outcome(fn () => [$strictHex->isValid('fff'), $strictHex->isValid('ffffff'), $strictHex->isValid("fff\n")]));

// Declarations the engine checks against the native classes, from a child process.
$declarations = [
    'narrower isValid' => 'new class () extends Utopia\\Validator\\Numeric { public function isValid(string $value): bool { return true; } };',
    'wider return' => 'new class () extends Utopia\\Validator\\Numeric { public function isValid(mixed $value): mixed { return true; } };',
    'untyped isValid' => 'echo (new class () extends Utopia\\Validator\\Numeric { public function isValid($value): bool { return true; } })->isValid(1) ? "ok" : "no";',
    'private override' => 'new class () extends Utopia\\Validator\\Port { private function isValidPort(int $p): string { return ""; } };',
    'Range bad constructor' => 'new class () extends Utopia\\Validator\\Range { public function __construct(string $x) { parent::__construct(1, 2); } };',
    'Range getMin narrower' => 'new class (1, 2) extends Utopia\\Validator\\Range { public function getMin(): int { return 1; } };',
    'Range getMin wider' => 'new class (1, 2) extends Utopia\\Validator\\Range { public function getMin(): string { return "1"; } };',
    'Integer isUnsigned int' => 'new class () extends Utopia\\Validator\\Integer { public function isUnsigned(): int { return 1; } };',
    'redeclare property' => 'echo json_encode((array) new class (1, 2) extends Utopia\\Validator\\Range { protected int|float $min = 7; });',
    'redeclare property public' => 'echo json_encode(get_object_vars(new class (1, 2) extends Utopia\\Validator\\Range { public int|float $min = 7; }));',
    'redeclare property narrower' => 'new class (1, 2) extends Utopia\\Validator\\Range { protected int $min = 7; };',
    'redeclare property private' => 'new class (1, 2) extends Utopia\\Validator\\Range { private int|float $min = 7; };',
    'final class' => 'final class F extends Utopia\\Validator\\Wildcard {} echo (new F())->isValid(null) ? "ok" : "no";',
    'readonly class' => 'readonly class R extends Utopia\\Validator\\HexColor {}',
    'abstract leaf' => 'abstract class A extends Utopia\\Validator\\Boolean {} echo "ok";',
    'static isValid' => 'new class () extends Utopia\\Validator\\Wildcard { public static function isValid($value): bool { return true; } };',
];
foreach ($declarations as $name => $code) {
    emit('declaration ' . $name, child($code));
}
