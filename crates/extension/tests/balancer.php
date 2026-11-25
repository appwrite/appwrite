<?php

// Parity corpus for utopia-php/balancer: diff its stdout on PHP and on the extension (_APP_EXTENSION_LIBRARIES=balancer).

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Balancer\Algorithm;
use Utopia\Balancer\Algorithm\First;
use Utopia\Balancer\Algorithm\Last;
use Utopia\Balancer\Algorithm\Random;
use Utopia\Balancer\Algorithm\RoundRobin;
use Utopia\Balancer\Balancer;
use Utopia\Balancer\Group;
use Utopia\Balancer\Option;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Gauge;
use Utopia\Telemetry\Histogram;
use Utopia\Telemetry\ObservableGauge;
use Utopia\Telemetry\UpDownCounter;

const CLASSES = [Option::class, Algorithm::class, First::class, Last::class, Random::class, RoundRobin::class, Balancer::class, Group::class];
const ALGORITHMS = [First::class, Last::class, Random::class, RoundRobin::class];

$internal = (new ReflectionClass(Balancer::class))->isInternal();
fwrite(STDERR, 'Utopia\\Balancer: ' . ($internal ? 'utopia extension' : 'PHP') . PHP_EOL);
// A run meant for the extension that gets the PHP classes would compare PHP with PHP and pass.
$requested = in_array('balancer', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' balancer, but the other implementation loaded' . PHP_EOL);
    exit(1);
}
foreach (CLASSES as $class) {
    if ((new ReflectionClass($class))->isInternal() !== $internal) {
        fwrite(STDERR, $class . ' is not from the same implementation as Balancer' . PHP_EOL);
        exit(1);
    }
}

/** Warnings, notices and deprecations raised by the current case, and not silenced. */
$warnings = [];
set_error_handler(function (int $level, string $message) use (&$warnings): bool {
    if (!(error_reporting() & $level)) {
        return true;
    }
    $warnings[] = $message;

    return true;
});

/** Object ids, numbered in the order a case first shows them: handles differ between runs. */
$ids = null;

/** $value with every object as its class, a stable id and its properties. */
function exported(mixed $value, int $depth = 0): mixed
{
    global $ids;
    if (is_array($value)) {
        $out = [];
        foreach ($value as $key => $item) {
            $out[] = [$key, exported($item, $depth)];
        }

        return ['array' => $out];
    }
    if ($value instanceof Closure) {
        return ['closure' => (new ReflectionFunction($value))->getName()];
    }
    if (is_object($value)) {
        $ids ??= new SplObjectStorage();
        if (!isset($ids[$value])) {
            $ids[$value] = count($ids) + 1;
        }
        $shown = ['class' => get_debug_type($value), 'id' => $ids[$value]];
        if ($depth < 3) {
            $properties = [];
            foreach ((array) $value as $key => $item) {
                $at = strrpos($key, "\0");
                $properties[] = [$at === false ? $key : substr($key, $at + 1), exported($item, $depth + 1)];
            }
            $shown['properties'] = $properties;
        }

        return $shown;
    }
    if (is_float($value)) {
        return ['float' => var_export($value, true)];
    }

    return $value;
}

/** The observable outcome of $run: its value or exception, and the warnings it raised. */
function outcome(callable $run): array
{
    global $warnings, $ids;
    $warnings = [];
    $ids = null;
    try {
        $result = ['value' => exported($run())];
    } catch (Throwable $e) {
        $result = ['throws' => $e::class, 'message' => $e->getMessage()];
        if ($e->getPrevious() !== null) {
            $result['previous'] = [$e->getPrevious()::class, $e->getPrevious()->getMessage()];
        }
    }
    if ($warnings !== []) {
        $result['warnings'] = $warnings;
    }

    return $result;
}

function emit(string $case, array $result): void
{
    echo json_encode([$case, $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), PHP_EOL;
}

function check(string $case, callable $run): void
{
    emit($case, outcome($run));
}

/** $dump with each object handle ("#12 ") dropped: handles differ between runs. */
function withoutHandles(string $dump): string
{
    return preg_replace('/#\d+ /', '# ', $dump);
}

/** What `php -r $code` prints and exits with, in a child process loading the same autoloader. */
function child(string $code, bool $strict = false): array
{
    $prelude = ($strict ? 'declare(strict_types=1); ' : '') . 'require ' . var_export(__DIR__ . '/../../../vendor/autoload.php', true) . '; use Utopia\Balancer\{Balancer, Group, Option}; use Utopia\Balancer\Algorithm\{First, Last, Random, RoundRobin};';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-r', $prelude . $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    // Exceptions name the file and line, which differ (README): only the first line is compared.
    return ['stdout' => $stdout, 'stderr' => preg_replace('/ in (\/|Command line code).*$/s', '', trim($stderr)), 'exit' => proc_close($process)];
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

/** Options with the given hostnames, each with `online` and `cpu`. */
function workers(array $specs): array
{
    $options = [];
    foreach ($specs as $name => [$online, $cpu]) {
        $options[] = new Option(['hostname' => $name, 'online' => $online, 'cpu' => $cpu]);
    }

    return $options;
}

function hostname(?Option $option): ?string
{
    return $option?->getState('hostname');
}

// 1. The classes, as reflection shows them. `isInternal()`, the file and the doc comments differ
// by construction.
foreach (CLASSES as $name) {
    $class = new ReflectionClass($name);
    check('class ' . $name, fn () => [
        'abstract' => $class->isAbstract(), 'final' => $class->isFinal(), 'parent' => ($class->getParentClass() ?: null)?->getName(),
        'interfaces' => $class->getInterfaceNames(), 'constructor' => $class->getConstructor()?->getName(),
        'instantiable' => $class->isInstantiable(), 'cloneable' => $class->isCloneable(), 'modifiers' => Reflection::getModifierNames($class->getModifiers()),
        'constants' => $class->getConstants(), 'static' => $class->getStaticProperties(), 'defaults' => $class->getDefaultProperties(),
        'parents' => class_parents($name), 'methods' => get_class_methods($name), 'short' => $class->getShortName(),
        'namespace' => $class->getNamespaceName(), 'iterable' => $class->isIterable(), 'enum' => $class->isEnum(),
    ]);
    foreach ($class->getProperties() as $property) {
        check('property ' . $name . '::$' . $property->getName(), fn () => [
            $property->getDeclaringClass()->getName(), Reflection::getModifierNames($property->getModifiers()), (string) $property->getType(),
            $property->hasType(), $property->getType()?->allowsNull(), $property->hasDefaultValue(), $property->getDefaultValue(),
            $property->isPromoted(), $property->isReadOnly(), $property->isStatic(), $property->isPrivate(), $property->isProtected(),
        ]);
    }
    check('property order ' . $name, fn () => array_map(fn ($p) => $p->getName(), $class->getProperties()));
    foreach ($class->getMethods() as $method) {
        check('method ' . $name . '::' . $method->getName(), fn () => [
            $method->getDeclaringClass()->getName(), Reflection::getModifierNames($method->getModifiers()), $method->isStatic(), $method->isAbstract(),
            (string) $method->getReturnType(), $method->getReturnType()?->allowsNull(), $method->hasTentativeReturnType(), $method->returnsReference(),
            $method->getNumberOfParameters(), $method->getNumberOfRequiredParameters(), array_map(parameter(...), $method->getParameters()),
            $method->isConstructor(), $method->isPublic(),
        ]);
    }
}

// 2. Option -------------------------------------------------------------------------------------

/** States an option can start with. */
$states = [
    'empty' => [],
    'list' => ['a', 'b', 'c'],
    'assoc' => ['hostname' => 'worker-1', 'online' => true, 'cpu' => 80, 'load' => 0.5, 'none' => null],
    'numeric keys' => [5 => 'five', '6' => 'six', '07' => 'zero seven', -1 => 'minus one', '-2' => 'minus two', '1.5' => 'one and a half', '' => 'empty'],
    'nested' => ['tags' => ['a' => 1, 'b' => [2, 3]], 'object' => new ArrayObject([1, 2])],
    'falsy' => ['zero' => 0, 'empty' => '', 'false' => false, 'null' => null, 'array' => [], 'zero string' => '0'],
    'closure' => ['run' => fn () => 1],
];
$keys = ['hostname', 'online', 'cpu', 'load', 'none', 'missing', 'a', '0', '1', '2', '5', '6', '06', '07', '7', '-1', '-2', '-0', '1.5', '', 'tags', 'object', 'zero', 'empty', 'false', 'null', 'array', 'zero string', 'run', ' 5', '5 ', '0x1', '1e3', 'HOSTNAME'];
foreach ($states as $label => $state) {
    check("option new $label", fn () => new Option($state));
    check("option getStates $label", fn () => (new Option($state))->getStates());
    foreach ($keys as $key) {
        check("option getState $label " . json_encode($key), fn () => [
            (new Option($state))->getState($key),
            (new Option($state))->getState($key, 'default'),
            (new Option($state))->getState($key, null),
            (new Option($state))->getState($key, false),
        ]);
    }
    foreach (['new', 'hostname', '5', '9', '-1', '07', '', '1.5'] as $key) {
        check("option setState $label " . json_encode($key), function () use ($state, $key) {
            $option = new Option($state);
            $same = $option->setState($key, 'set') === $option;

            return [$same, $option->getStates(), $option->getState($key)];
        });
        check("option deleteState $label " . json_encode($key), function () use ($state, $key) {
            $option = new Option($state);
            $same = $option->deleteState($key) === $option;

            return [$same, $option->getStates(), $option->getState($key, 'gone')];
        });
    }
}

check('option keys of other types (weak)', function () {
    $option = new Option([5 => 'five', '1' => 'one', 'true' => 't']);
    $out = [];
    foreach ([5, 1, 1.0, 5.0, true, false, 1.5, -0.0, new class () {
        public function __toString(): string
        {
            return '5';
        }
    }] as $key) {
        $out[] = outcome(fn () => [$option->getState($key), $option->getState($key, 'd')]);
        $out[] = outcome(fn () => $option->setState($key, 'x')->getStates());
        $out[] = outcome(fn () => $option->deleteState($key)->getStates());
    }

    return $out;
});
foreach (['null' => 'null', 'array' => '[]', 'object' => 'new stdClass()', 'float' => '1.5', 'int' => '5', 'bool' => 'true'] as $label => $key) {
    foreach (['getState', 'deleteState'] as $method) {
        check("option $method key $label", fn () => [child("\$o = new Option(['5' => 1, '1' => 2]); try { var_dump(\$o->$method($key)); } catch (Throwable \$e) { echo get_class(\$e), ': ', \$e->getMessage(); }"), child("\$o = new Option(['5' => 1]); try { var_dump(\$o->$method($key)); } catch (Throwable \$e) { echo get_class(\$e), ': ', \$e->getMessage(); }", true)]);
    }
    check("option setState key $label", fn () => [child("\$o = new Option([]); try { var_dump(\$o->setState($key, 1)->getStates()); } catch (Throwable \$e) { echo get_class(\$e), ': ', \$e->getMessage(); }"), child("\$o = new Option([]); try { var_dump(\$o->setState($key, 1)->getStates()); } catch (Throwable \$e) { echo get_class(\$e), ': ', \$e->getMessage(); }", true)]);
}
foreach (['string' => "'a'", 'null' => 'null', 'int' => '5', 'object' => 'new stdClass()', 'iterator' => 'new ArrayIterator([1])'] as $label => $state) {
    check("option new $label", fn () => [child("try { var_dump(new Option($state)); } catch (Throwable \$e) { echo get_class(\$e), ': ', \$e->getMessage(); }"), child("try { var_dump(new Option($state)); } catch (Throwable \$e) { echo get_class(\$e), ': ', \$e->getMessage(); }", true)]);
}
check('option arity', fn () => [
    outcome(fn () => new Option()),
    outcome(fn () => (new Option([]))->getState()),
    outcome(fn () => (new Option([]))->setState('a')),
    outcome(fn () => (new Option([]))->deleteState()),
    outcome(fn () => (new Option(['a' => 1], 'extra'))->getState('a', 2, 3)),
    outcome(fn () => (new Option([]))->setState('a', 1, 2)->getStates()),
]);
check('option values pass through', function () {
    $object = new stdClass();
    $closure = fn () => 'called';
    $resource = fopen('php://memory', 'r');
    $option = new Option(['object' => $object, 'closure' => $closure]);
    $option->setState('resource', $resource)->setState('array', ['x' => [1]])->setState('float', 0.1 + 0.2)->setState('int', PHP_INT_MAX);

    return [
        $option->getState('object') === $object, $option->getState('closure') === $closure, ($option->getState('closure'))(),
        $option->getState('resource') === $resource, get_resource_type($option->getState('resource')), $option->getState('array'),
        $option->getState('float'), $option->getState('int'), $option->getStates()['object'] === $object,
    ];
});
check('option state is a value', function () {
    $state = ['a' => 1];
    $option = new Option($state);
    $option->setState('a', 2)->setState('b', 3);
    $copy = $option->getStates();
    $option->setState('c', 4)->deleteState('a');
    $copy['d'] = 5;
    $second = new Option($state);

    return [$state, $copy, $option->getStates(), $second->getStates()];
});
check('option state shared by objects', function () {
    $shared = new ArrayObject(['n' => 1]);
    $a = new Option(['shared' => $shared]);
    $b = new Option(['shared' => $shared]);
    $a->getState('shared')['n'] = 2;

    return [$b->getState('shared')['n'], $a->getState('shared') === $b->getState('shared')];
});
check('option references in state', function () {
    $x = 1;
    $state = ['ref' => &$x, 'plain' => 2];
    $option = new Option($state);
    $before = $option->getState('ref');
    $option->setState('ref', 10);
    $afterSet = $x;
    $x = 20;
    $seen = $option->getState('ref');
    $option->deleteState('ref');
    $x = 30;

    return [$before, $afterSet, $seen, $option->getStates(), $x, $state];
});
check('option typed reference in state', function () {
    $holder = new class () {
        public int $count = 1;
        public ?string $label = null;
    };
    $option = new Option(['count' => &$holder->count, 'label' => &$holder->label]);
    $out = [];
    $out[] = outcome(fn () => $option->setState('count', '5')->getState('count'));
    $out[] = outcome(fn () => $holder->count);
    $out[] = outcome(fn () => $option->setState('count', 'many'));
    $out[] = outcome(fn () => $holder->count);
    $out[] = outcome(fn () => $option->setState('count', 2.0)->getState('count'));
    $out[] = outcome(fn () => $option->setState('count', 2.5)->getState('count'));
    $out[] = outcome(fn () => $option->setState('label', 7)->getState('label'));
    $out[] = outcome(fn () => $option->setState('label', null)->getState('label', 'default'));
    $out[] = outcome(fn () => [$holder->count, $holder->label]);

    return $out;
});
check('option destructor order', function () {
    $log = [];
    $make = fn (string $name) => new class ($name, $log) {
        public function __construct(public string $name, private array &$log)
        {
        }

        public function __destruct()
        {
            $this->log[] = 'destruct ' . $this->name;
        }
    };
    $option = new Option(['a' => $make('first')]);
    $option->setState('a', $make('second'));
    $log[] = 'after set';
    $option->deleteState('a');
    $log[] = 'after delete';

    return $log;
});
check('option uninitialized', function () {
    $out = [];
    foreach (['getState' => ['a'], 'setState' => ['a', 1], 'deleteState' => ['a'], 'getStates' => []] as $method => $args) {
        $option = (new ReflectionClass(Option::class))->newInstanceWithoutConstructor();
        $out[$method] = outcome(fn () => $option->$method(...$args));
        $out[$method . ' after'] = outcome(fn () => (new ReflectionProperty(Option::class, 'state'))->isInitialized($option));
    }

    return $out;
});
check('option clone, compare, serialize', function () {
    $a = new Option(['a' => 1, 'b' => [1, 2]]);
    $b = clone $a;
    $b->setState('a', 2);
    $c = new Option(['a' => 1, 'b' => [1, 2]]);
    $d = unserialize(serialize($a));

    return [
        $a->getStates(), $b->getStates(), $a == $c, $a == $b, $a === $c, $a <=> $b, serialize($a), $d == $a, $d->getStates(),
        var_export($a, true), json_encode($a), (array) $a === ["\0Utopia\\Balancer\\Option\0state" => ['a' => 1, 'b' => [1, 2]]],
        withoutHandles(print_r($a, true)), get_object_vars($a),
    ];
});
check('option var_dump', function () {
    ob_start();
    var_dump(new Option(['a' => 1, 'n' => null, 'o' => new Option([])]));

    return withoutHandles(ob_get_clean());
});
check('option debug_zval_refcount-free state', function () {
    $option = new Option(['a' => 1]);
    $states = $option->getStates();
    $option->setState('b', 2);

    return [$states, $option->getStates()];
});

// 3. Option subclasses ----------------------------------------------------------------------

class TypedOption extends Option
{
    public const string HOST = 'host';

    public function __construct(string $host, private bool $edge = false)
    {
        parent::__construct([self::HOST => $host, 'edge' => $edge]);
    }

    public function getHost(): string
    {
        return $this->getState(self::HOST);
    }

    public function isEdge(): bool
    {
        return $this->getState('edge', false) === true;
    }
}

class ShadowOption extends Option
{
    public array $state = ['shadow' => true];

    public function __construct()
    {
    }
}

class OverridingOption extends Option
{
    public array $calls = [];

    public function getState(string $key, mixed $default = null): mixed
    {
        $this->calls[] = $key;

        return parent::getState(strtolower($key), $default);
    }

    public function setState(string $key, mixed $value): self
    {
        return parent::setState(strtolower($key), $value);
    }
}

check('option subclass', function () {
    $option = new TypedOption('edge-1', true);
    $option->setState('weight', 3);

    return [$option->getHost(), $option->isEdge(), $option->getStates(), $option instanceof Option, $option, serialize($option)];
});
check('option subclass with its own $state', function () {
    $option = new ShadowOption();
    $out = [outcome(fn () => $option->getState('shadow')), outcome(fn () => $option->getStates()), outcome(fn () => $option->state)];
    $out[] = outcome(fn () => $option->setState('x', 1)->getStates());
    (function () {
        $this->__construct(['real' => 1]);
    })->call($option);

    return [...$out, outcome(fn () => $option->getStates()), outcome(fn () => $option->state), outcome(fn () => $option->getState('shadow', 'no'))];
});
check('option subclass overriding', function () {
    $option = new OverridingOption(['host' => 'a']);
    $option->setState('CPU', 5);
    $balancer = (new Balancer(new First()))->addOption($option)->addFilter(fn (Option $o) => $o->getState('CPU') === 5);

    return [$option->getState('HOST'), $option->getStates(), hostname($balancer->run()), $option->calls];
});
// 4. Algorithms ---------------------------------------------------------------------------

$o = fn (string $name) => new Option(['name' => $name]);
$arrays = [
    'empty' => [],
    'one' => [$o('a')],
    'three' => [$o('a'), $o('b'), $o('c')],
    'seven' => array_map($o, ['a', 'b', 'c', 'd', 'e', 'f', 'g']),
    'keyed' => ['x' => $o('a'), 'y' => $o('b'), 'z' => $o('c')],
    'sparse' => [3 => $o('a'), 1 => $o('b'), 7 => $o('c')],
    'negative' => [-1 => $o('a'), 0 => $o('b'), 1 => $o('c')],
    'holes' => (function () use ($o) {
        $a = array_map($o, ['a', 'b', 'c', 'd', 'e']);
        unset($a[1], $a[3]);

        return $a;
    })(),
    'mostly holes' => (function () use ($o) {
        $a = array_map($o, range('a', 'p'));
        foreach (range(0, 15) as $i) {
            if ($i % 5 !== 2) {
                unset($a[$i]);
            }
        }

        return $a;
    })(),
    'null element' => [null, $o('b')],
    'string element' => ['x', $o('b')],
    'object element' => [new stdClass(), $o('b')],
    'subclass element' => [new TypedOption('typed'), $o('b')],
    'reference element' => (function () use ($o) {
        $x = $o('ref');
        $y = 'not an option';

        return [&$x, $o('b'), &$y];
    })(),
];
foreach (ALGORITHMS as $algorithm) {
    check("$algorithm getName", fn () => [($algorithm === RoundRobin::class ? new RoundRobin(-1) : new $algorithm())->getName()]);
    foreach ($arrays as $label => $options) {
        if ($algorithm === RoundRobin::class) {
            foreach ([-2, -1, 0, 1, 2, 5, 6, 7, PHP_INT_MIN] as $start) {
                check("$algorithm run $label from $start", function () use ($options, $start) {
                    $algo = new RoundRobin($start);
                    $picks = [];
                    for ($i = 0; $i < 9; $i++) {
                        $picks[] = outcome(fn () => [$algo->run($options)?->getState('name'), $algo->getIndex()]);
                    }

                    return $picks;
                });
            }
            continue;
        }
        if ($algorithm === Random::class) {
            foreach ([0, 1, 7, 42, 2147483647, -5] as $seed) {
                check("$algorithm run $label seed $seed", function () use ($options, $seed) {
                    mt_srand($seed);
                    $algo = new Random();
                    $picks = [];
                    for ($i = 0; $i < 12; $i++) {
                        $picks[] = outcome(fn () => $algo->run($options)?->getState('name'));
                    }

                    return [$picks, mt_rand()];
                });
            }
            check("$algorithm run $label legacy seed", function () use ($options) {
                mt_srand(3, MT_RAND_PHP);
                $picks = [];
                for ($i = 0; $i < 6; $i++) {
                    $picks[] = outcome(fn () => (new Random())->run($options)?->getState('name'));
                }

                return [$picks, mt_rand()];
            });
            continue;
        }
        check("$algorithm run $label", fn () => (new $algorithm())->run($options));
    }
    check("$algorithm run arguments", function () use ($algorithm) {
        $algo = $algorithm === RoundRobin::class ? new RoundRobin(-1) : new $algorithm();

        return [outcome(fn () => $algo->run()), outcome(fn () => $algo->run(null)), outcome(fn () => $algo->run(new ArrayIterator([]))), outcome(fn () => $algo->run('a')), outcome(fn () => $algo->run([new Option(['k' => 1])], 'extra')?->getStates())];
    });
}
check('random distribution', function () {
    mt_srand(2024);
    $options = array_map(fn ($i) => new Option(['i' => $i]), range(0, 4));
    $balancer = new Balancer(new Random());
    foreach ($options as $option) {
        $balancer->addOption($option);
    }
    $counts = array_fill(0, 5, 0);
    for ($i = 0; $i < 5000; $i++) {
        $counts[$balancer->run()->getState('i')]++;
    }

    return [$counts, mt_rand()];
});
check('random unseeded stays in range', function () {
    $options = [new Option(['i' => 0]), new Option(['i' => 1])];
    $seen = [];
    for ($i = 0; $i < 200; $i++) {
        $seen[(new Random())->run($options)->getState('i')] = true;
    }
    ksort($seen);

    return array_keys($seen);
});
check('random and mt_rand share the engine', function () {
    mt_srand(99);
    $options = array_map(fn ($i) => new Option(['i' => $i]), range(0, 9));
    $a = [(new Random())->run($options)->getState('i'), mt_rand(1, 100), (new Random())->run($options)->getState('i'), random_int(1, 1), array_rand([1, 2, 3])];
    mt_srand(99);
    $b = [mt_rand(0, 9), mt_rand(1, 100), mt_rand(0, 9)];

    return [$a, $b];
});
foreach (['-1' => -1, '0' => 0, 'max' => PHP_INT_MAX, 'min' => PHP_INT_MIN] as $label => $start) {
    check("roundrobin index $label", function () use ($start) {
        $rr = new RoundRobin($start);
        $before = $rr->getIndex();
        $same = $rr->setIndex($start) === $rr;

        return [$before, $same, outcome(fn () => $rr->run([new Option([])])), outcome(fn () => $rr->getIndex()), outcome(fn () => $rr->run(['k' => new Option([])])), $rr->getIndex()];
    });
}
foreach (['string int' => "'3'", 'float' => '2.0', 'fraction' => '1.5', 'string' => "'x'", 'null' => 'null', 'bool' => 'true', 'array' => '[]', 'leading' => "'7 '", 'big float' => '1e30'] as $label => $value) {
    check("roundrobin argument $label", fn () => [
        child("try { \$r = new RoundRobin($value); var_dump(\$r->getIndex()); var_dump(\$r->setIndex($value)->getIndex()); } catch (Throwable \$e) { echo get_class(\$e), ': ', \$e->getMessage(); }"),
        child("try { \$r = new RoundRobin($value); var_dump(\$r->getIndex()); } catch (Throwable \$e) { echo get_class(\$e), ': ', \$e->getMessage(); }", true),
    ]);
}
check('roundrobin arity', fn () => [outcome(fn () => new RoundRobin()), outcome(fn () => (new RoundRobin(1))->setIndex()), outcome(fn () => (new RoundRobin(1, 2))->getIndex())]);
check('roundrobin uninitialized', function () {
    $rr = (new ReflectionClass(RoundRobin::class))->newInstanceWithoutConstructor();
    $balancer = (new Balancer($rr))->addOption(new Option([]));

    return [outcome(fn () => $rr->getIndex()), outcome(fn () => $rr->run([new Option([])])), outcome(fn () => $balancer->run()), outcome(fn () => $rr->setIndex(4)->run([new Option([])])), $rr->getIndex()];
});
check('algorithm abstract', fn () => [outcome(fn () => new Algorithm()), child('new class () extends Utopia\\Balancer\\Algorithm {};'), child('class Half extends Utopia\\Balancer\\Algorithm { public function getName(): string { return "h"; } }')]);
check('algorithm getName arity', fn () => [(new First())->getName('extra'), (new RoundRobin(0))->getName(1, 2)]);

// 5. Balancer -----------------------------------------------------------------------------

check('balancer constructor', fn () => [
    outcome(fn () => new Balancer()),
    outcome(fn () => new Balancer(new stdClass())),
    outcome(fn () => new Balancer(null)),
    outcome(fn () => new Balancer('First')),
    outcome(fn () => (new Balancer(new First(), 'extra'))->getAlgo()),
]);
check('balancer getAlgo', function () {
    $algo = new RoundRobin(3);
    $balancer = new Balancer($algo);

    return [$balancer->getAlgo() === $algo, $balancer->getAlgo()];
});
check('balancer addOption', function () {
    $balancer = new Balancer(new First());
    $a = new Option(['a' => 1]);

    return [
        $balancer->addOption($a) === $balancer, $balancer->addOption($a)->getOptions()[1] === $a,
        outcome(fn () => $balancer->addOption(new stdClass())), outcome(fn () => $balancer->addOption(null)), outcome(fn () => $balancer->addOption(['a' => 1])),
        outcome(fn () => $balancer->addOption()), outcome(fn () => $balancer->addOption(new TypedOption('t'))->getOptions()),
    ];
});

class Filters
{
    public static array $log = [];

    public static function online(Option $option): bool
    {
        self::$log[] = 'static online ' . $option->getState('hostname');

        return $option->getState('online') === true;
    }

    public function idle(Option $option): bool
    {
        self::$log[] = 'idle ' . $option->getState('hostname');

        return $option->getState('cpu') < 50;
    }

    public function __invoke(Option $option): bool
    {
        self::$log[] = 'invoke ' . $option->getState('hostname');

        return true;
    }

    public function __call(string $name, array $arguments): mixed
    {
        self::$log[] = "__call $name " . $arguments[0]->getState('hostname');

        return $name !== 'none';
    }

    public static function __callStatic(string $name, array $arguments): mixed
    {
        self::$log[] = "__callStatic $name " . $arguments[0]->getState('hostname');

        return true;
    }

    private function hidden(Option $option): bool
    {
        return true;
    }

    public function hiddenFilter(): array
    {
        return [$this, 'hidden'];
    }

    public function addHidden(Balancer $balancer): Balancer
    {
        return $balancer->addFilter([$this, 'hidden']);
    }

    public function hiddenClosure(): Closure
    {
        return $this->hidden(...);
    }
}

function online_filter(Option $option): bool
{
    Filters::$log[] = 'function ' . $option->getState('hostname');

    return $option->getState('online') === true;
}

$filters = [
    'closure' => fn (Option $o) => $o->getState('online') === true,
    'function name' => 'online_filter',
    'static string' => 'Filters::online',
    'static array' => [Filters::class, 'online'],
    'method array' => [new Filters(), 'idle'],
    'invokable' => new Filters(),
    'first-class' => online_filter(...),
    'fromCallable' => Closure::fromCallable([new Filters(), 'idle']),
    '__call' => [new Filters(), 'magic'],
    '__call none' => [new Filters(), 'none'],
    '__callStatic' => [Filters::class, 'magicStatic'],
    'builtin' => 'is_object',
    'private closure' => (new Filters())->hiddenClosure(),
    'returns int' => fn (Option $o) => $o->getState('cpu'),
    'returns string' => fn (Option $o) => $o->getState('online') ? 'yes' : '',
    'returns zero string' => fn (Option $o) => '0',
    'returns array' => fn (Option $o) => $o->getState('online') ? [1] : [],
    'returns null' => fn (Option $o) => null,
    'returns float' => fn (Option $o) => $o->getState('cpu') / 100 - 0.5,
    'returns object' => fn (Option $o) => new stdClass(),
    'by reference' => function (Option &$o) {
        return true;
    },
    'two parameters' => fn (Option $o, $second = 'default') => $second === 'default',
    'variadic' => fn (Option ...$o) => count($o) === 1,
    'no parameters' => fn () => true,
    'untyped' => fn ($o) => $o instanceof Option,
    'wrong type' => fn (string $o) => true,
];
foreach ($filters as $label => $filter) {
    foreach ([First::class, Last::class, RoundRobin::class] as $algorithm) {
        check("balancer filter $label with $algorithm", function () use ($filter, $algorithm) {
            Filters::$log = [];
            $balancer = new Balancer($algorithm === RoundRobin::class ? new RoundRobin(-1) : new $algorithm());
            foreach (workers(['w1' => [true, 80], 'w2' => [false, 20], 'w3' => [true, 35], 'w4' => [true, 5]]) as $option) {
                $balancer->addOption($option);
            }
            $out = [outcome(fn () => $balancer->addFilter($filter) === $balancer)];
            for ($i = 0; $i < 3; $i++) {
                $out[] = outcome(fn () => hostname($balancer->run()));
            }
            $out[] = outcome(fn () => array_map(hostname(...), $balancer->getFilteredOptions()));
            $out[] = Filters::$log;

            return $out;
        });
    }
}
foreach (['string' => "'nope'", 'array' => '[1, 2]', 'int' => '5', 'null' => 'null', 'missing method' => "[new stdClass(), 'x']", 'abstract' => "['Utopia\\\\Balancer\\\\Algorithm', 'run']", 'non-static' => "['Utopia\\\\Balancer\\\\Option', 'getState']", 'self' => "'self::getOptions'", 'parent' => "'parent::getOptions'", 'static' => "'static::getOptions'", 'self array' => "['self', 'getOptions']", 'option method' => "[new Option(['x' => 1]), 'getStates']", 'balancer private' => "[new Balancer(new First()), 'getAlgo']"] as $label => $filter) {
    check("balancer addFilter $label", fn () => child("\$b = new Balancer(new First()); \$b->addOption(new Option(['a' => 1])); try { var_dump(\$b->addFilter($filter) === \$b); var_dump(\$b->run()?->getStates()); var_dump(count(\$b->getFilteredOptions())); } catch (Throwable \$e) { echo get_class(\$e), ': ', \$e->getMessage(); }"));
}
check('balancer private method filter', function () {
    $filters = new Filters();
    $balancer = (new Balancer(new First()))->addOption(new Option(['hostname' => 'a']));

    return [outcome(fn () => $balancer->addFilter($filters->hiddenFilter())), outcome(fn () => hostname($filters->addHidden($balancer)->run()))];
});
check('balancer filter order and count', function () {
    $log = [];
    $balancer = new Balancer(new First());
    foreach (workers(['w1' => [true, 80], 'w2' => [false, 20], 'w3' => [true, 35], 'w4' => [false, 5], 'w5' => [true, 10]]) as $option) {
        $balancer->addOption($option);
    }
    $balancer
        ->addFilter(function (Option $o) use (&$log) {
            $log[] = 'online ' . hostname($o);

            return $o->getState('online');
        })
        ->addFilter(function (Option $o) use (&$log) {
            $log[] = 'idle ' . hostname($o);

            return $o->getState('cpu') < 50;
        })
        ->addFilter(function (Option $o) use (&$log) {
            $log[] = 'last ' . hostname($o);

            return true;
        });
    $picked = hostname($balancer->run());
    $log[] = '--';
    $filtered = array_map(hostname(...), $balancer->getFilteredOptions());

    return [$picked, $filtered, $log];
});
check('balancer filter throws', function () {
    $log = [];
    $balancer = new Balancer(new First());
    foreach (workers(['w1' => [true, 80], 'w2' => [false, 20], 'w3' => [true, 35]]) as $option) {
        $balancer->addOption($option);
    }
    $balancer
        ->addFilter(function (Option $o) use (&$log) {
            $log[] = 'first ' . hostname($o);
            if (hostname($o) === 'w2') {
                throw new RuntimeException('w2 is broken', 7, new LogicException('cause'));
            }

            return true;
        })
        ->addFilter(function (Option $o) use (&$log) {
            $log[] = 'second ' . hostname($o);

            return true;
        });

    return [outcome(fn () => $balancer->run()), outcome(fn () => $balancer->getFilteredOptions()), $log];
});
check('balancer filter errors', function () {
    $balancer = (new Balancer(new First()))->addOption(new Option([]));
    $out = [outcome(fn () => (clone $balancer)->addFilter(fn (Option $o) => $o->missing())->run())];
    $out[] = outcome(fn () => (clone $balancer)->addFilter(fn (Option $o) => intdiv(1, 0))->getFilteredOptions());
    $out[] = outcome(fn () => (clone $balancer)->addFilter(fn (Option $o) => trigger_error('careful', E_USER_WARNING) || true)->run()?->getStates());
    $out[] = outcome(fn () => (clone $balancer)->addFilter(fn (Option $o) => $undefined ?? 1)->run()?->getStates());
    $out[] = outcome(fn () => (clone $balancer)->addFilter(fn (Option $o): int => 'x')->run());

    return $out;
});
check('balancer filters see a snapshot', function () {
    $log = [];
    $balancer = new Balancer(new First());
    $balancer->addOption(new Option(['hostname' => 'w1']));
    $balancer->addFilter(function (Option $o) use (&$log, &$balancer) {
        $log[] = 'first ' . hostname($o);
        if (count($log) === 1) {
            $balancer->addOption(new Option(['hostname' => 'added']));
            $balancer->addFilter(function (Option $o) use (&$log) {
                $log[] = 'late ' . hostname($o);

                return false;
            });
        }

        return true;
    });
    $first = hostname($balancer->run());
    $log[] = '--';
    $second = hostname($balancer->run());

    return [$first, $second, $log, count($balancer->getOptions())];
});
check('balancer filters change state', function () {
    $balancer = new Balancer(new RoundRobin(-1));
    foreach (workers(['w1' => [true, 1], 'w2' => [true, 2], 'w3' => [true, 3]]) as $option) {
        $balancer->addOption($option);
    }
    $balancer->addFilter(fn (Option $o) => !$o->getState('attempted', false));
    $picks = [];
    while (($option = $balancer->run()) !== null) {
        $picks[] = hostname($option);
        $option->setState('attempted', true);
    }

    return [$picks, array_map(fn (Option $o) => $o->getStates(), $balancer->getOptions()), $balancer->getAlgo()->getIndex()];
});
check('balancer no options', function () {
    $calls = [];
    $algo = new class ($calls) extends Algorithm {
        public function __construct(private array &$calls)
        {
        }

        public function getName(): string
        {
            return 'Logged';
        }

        public function run(array $options): ?Option
        {
            $this->calls[] = count($options);

            return $options[0] ?? null;
        }
    };
    $balancer = new Balancer($algo);
    $empty = [$balancer->run(), $balancer->getFilteredOptions(), $balancer->getOptions()];
    $balancer->addOption(new Option(['hostname' => 'w1']))->addFilter(fn () => false);
    $filteredOut = [$balancer->run(), $balancer->getFilteredOptions()];

    return [$empty, $filteredOut, $calls];
});
check('balancer getFilteredOptions keys', function () {
    $balancer = new Balancer(new First());
    foreach (workers(['w1' => [true, 80], 'w2' => [false, 20], 'w3' => [true, 35]]) as $option) {
        $balancer->addOption($option);
    }
    $all = $balancer->getFilteredOptions();
    $all[] = 'appended';
    $balancer->addFilter(fn (Option $o) => $o->getState('online'));
    $some = $balancer->getFilteredOptions();

    return [array_keys($all), count($balancer->getOptions()), array_keys($some), array_map(hostname(...), $some), array_is_list($some)];
});
check('balancer getOptions is a value', function () {
    $balancer = new Balancer(new First());
    $balancer->addOption(new Option(['a' => 1]));
    $options = $balancer->getOptions();
    $balancer->addOption(new Option(['b' => 2]));
    $options[] = 'mine';

    return [count($options), count($balancer->getOptions())];
});

/** A PHP algorithm: the option with the lowest `cpu`. */
class Idlest extends Algorithm
{
    public array $seen = [];

    public function getName(): string
    {
        return 'Idlest';
    }

    public function run(array $options): ?Option
    {
        $this->seen[] = array_keys($options);
        usort($options, fn (Option $a, Option $b) => $a->getState('cpu') <=> $b->getState('cpu'));

        return $options[0] ?? null;
    }
}

class Nothing extends Algorithm
{
    public function getName(): string
    {
        return 'Nothing';
    }

    public function run(array $options): ?Option
    {
        return null;
    }
}

class LoggedFirst extends First
{
    public array $log = [];

    public function run(array $options): ?Option
    {
        $this->log[] = count($options);

        return parent::run(array_reverse($options));
    }
}

class PlainLast extends Last
{
    public function getName(): string
    {
        return 'PlainLast ' . parent::getName();
    }
}

class SubRoundRobin extends RoundRobin
{
}

class SubRandom extends Random
{
}

foreach (['Idlest' => fn () => new Idlest(), 'Nothing' => fn () => new Nothing(), 'LoggedFirst' => fn () => new LoggedFirst(), 'PlainLast' => fn () => new PlainLast(), 'SubRoundRobin' => fn () => new SubRoundRobin(0), 'SubRandom' => fn () => new SubRandom(), 'anonymous' => fn () => new class () extends First {
}] as $label => $make) {
    check("balancer with algorithm $label", function () use ($make) {
        mt_srand(5);
        $algo = $make();
        $balancer = new Balancer($algo);
        foreach (workers(['w1' => [true, 80], 'w2' => [false, 20], 'w3' => [true, 35], 'w4' => [true, 5]]) as $option) {
            $balancer->addOption($option);
        }
        $picks = [];
        for ($i = 0; $i < 4; $i++) {
            $picks[] = hostname($balancer->run());
        }
        $balancer->addFilter(fn (Option $o) => $o->getState('online'));
        for ($i = 0; $i < 4; $i++) {
            $picks[] = hostname($balancer->run());
        }

        return [$picks, $algo->getName(), $algo, mt_rand()];
    });
}
check('balancer shares an algorithm', function () {
    $rr = new RoundRobin(-1);
    $a = new Balancer($rr);
    $b = new Balancer($rr);
    foreach (workers(['w1' => [true, 1], 'w2' => [true, 2], 'w3' => [true, 3]]) as $option) {
        $a->addOption($option);
        $b->addOption($option);
    }
    $b->addFilter(fn (Option $o) => $o->getState('cpu') > 1);

    return [hostname($a->run()), hostname($b->run()), hostname($a->run()), hostname($b->run()), $rr->getIndex()];
});

class SortedBalancer extends Balancer
{
    public array $log = [];

    public function getFilteredOptions(): array
    {
        $this->log[] = 'getFilteredOptions';
        $options = parent::getFilteredOptions();
        usort($options, fn (Option $a, Option $b) => strcmp($b->getState('hostname'), $a->getState('hostname')));

        return $options;
    }
}

class EmptyBalancer extends Balancer
{
    public function getFilteredOptions(): array
    {
        return [];
    }
}

class PlainBalancer extends Balancer
{
    public string $extra = 'kept';
}

class LoggedBalancer extends Balancer
{
    public array $log = [];

    public function run(): ?Option
    {
        $this->log[] = 'run';

        return parent::run();
    }

    public function getOptions(): array
    {
        $this->log[] = 'getOptions';

        return array_reverse(parent::getOptions());
    }
}

foreach (['Sorted' => SortedBalancer::class, 'Empty' => EmptyBalancer::class, 'Plain' => PlainBalancer::class, 'Logged' => LoggedBalancer::class] as $label => $class) {
    foreach ([First::class, Random::class, RoundRobin::class, Idlest::class] as $algorithm) {
        check("balancer subclass $label with $algorithm", function () use ($class, $algorithm) {
            mt_srand(11);
            $balancer = new $class($algorithm === RoundRobin::class ? new RoundRobin(-1) : new $algorithm());
            foreach (workers(['w1' => [true, 80], 'w2' => [false, 20], 'w3' => [true, 35]]) as $option) {
                $balancer->addOption($option);
            }
            $balancer->addFilter(fn (Option $o) => $o->getState('online'));
            $picks = [];
            for ($i = 0; $i < 3; $i++) {
                $picks[] = hostname($balancer->run());
            }

            return [$picks, array_map(hostname(...), $balancer->getFilteredOptions()), $balancer, mt_rand()];
        });
    }
}
check('balancer uninitialized', function () {
    $balancer = (new ReflectionClass(Balancer::class))->newInstanceWithoutConstructor();
    $out = [outcome(fn () => $balancer->run()), outcome(fn () => $balancer->getOptions()), outcome(fn () => $balancer->getFilteredOptions()), outcome(fn () => $balancer->getAlgo())];
    $balancer->addOption(new Option(['hostname' => 'w1']));
    $out[] = outcome(fn () => $balancer->run());
    $balancer->__construct(new Last());

    return [...$out, outcome(fn () => hostname($balancer->run()))];
});
check('balancer options replaced through reflection', function () {
    $out = [];
    foreach ([First::class, Last::class, Random::class, RoundRobin::class, Idlest::class] as $algorithm) {
        foreach (['string' => ['x'], 'object' => [new stdClass()], 'null' => [null], 'keyed' => ['k' => new Option(['hostname' => 'keyed'])], 'reference' => (function () {
            $x = new Option(['hostname' => 'ref']);

            return [&$x];
        })()] as $label => $options) {
            $balancer = new Balancer($algorithm === RoundRobin::class ? new RoundRobin(-1) : new $algorithm());
            (new ReflectionProperty(Balancer::class, 'options'))->setValue($balancer, $options);
            $out["$algorithm $label"] = [outcome(fn () => hostname($balancer->run())), outcome(fn () => count($balancer->getFilteredOptions()))];
        }
    }

    return $out;
});
check('balancer filters replaced through reflection', function () {
    $balancer = (new Balancer(new First()))->addOption(new Option(['hostname' => 'w1']));
    (new ReflectionProperty(Balancer::class, 'filters'))->setValue($balancer, ['k' => fn () => true, 5 => 'not callable']);

    return [outcome(fn () => $balancer->run()), outcome(fn () => $balancer->getFilteredOptions())];
});
check('balancer algorithm replaced through reflection', function () {
    $balancer = (new Balancer(new First()))->addOption(new Option(['hostname' => 'w1']));
    (new ReflectionProperty(Balancer::class, 'algo'))->setValue($balancer, new Nothing());

    return [outcome(fn () => $balancer->run()), outcome(fn () => (new ReflectionProperty(Balancer::class, 'algo'))->setValue($balancer, new stdClass()))];
});
check('balancer clone, compare, serialize', function () {
    $a = new Balancer(new RoundRobin(-1));
    $a->addOption(new Option(['hostname' => 'w1']))->addOption(new Option(['hostname' => 'w2']));
    $b = clone $a;
    $b->addOption(new Option(['hostname' => 'w3']));
    $runs = [hostname($a->run()), hostname($b->run()), hostname($b->run())];
    $c = unserialize(serialize($a));
    $withFilter = (clone $a)->addFilter(fn () => true);

    return [
        $runs, count($a->getOptions()), count($b->getOptions()), $a->getAlgo() === $b->getAlgo(), $a == $c, hostname($c->run()), serialize($a),
        outcome(fn () => serialize($withFilter)), var_export($a, true), withoutHandles(print_r($a, true)), $a == $b,
    ];
});
check('balancer var_dump', function () {
    $balancer = (new Balancer(new RoundRobin(-1)))->addOption(new Option(['a' => 1]))->addFilter('is_object');
    ob_start();
    var_dump($balancer);

    return withoutHandles(ob_get_clean());
});
check('balancer appwrite avatars', function () {
    $providers = ['gravatar' => ['initials' => false], 'github' => ['initials' => true], 'fallback' => ['initials' => true]];
    $balancer = new Balancer(new First());
    foreach ($providers as $name => $profile) {
        $balancer->addOption(new Option(['provider' => $name, 'supports' => $profile['initials']]));
    }
    $balancer->addFilter(fn (Option $option) => $option->getState('supports'));
    $balancer->addFilter(fn (Option $option) => !$option->getState('attempted', false));
    $attempts = [];
    while (($option = $balancer->run()) !== null) {
        $option->setState('attempted', true);
        $attempts[] = $option->getState('provider');
    }

    return $attempts;
});
check('balancer cloud cdn', function () {
    $balancer = new Balancer(new First());
    $balancer
        ->addOption(new TypedOption('fastly-edge', true))
        ->addOption(new TypedOption('fastly'))
        ->addOption(new TypedOption('cloudflare'));
    $edge = (clone $balancer)->addFilter(fn (TypedOption $option): bool => $option->isEdge() === true);
    $custom = (clone $balancer)->addFilter(fn (TypedOption $option): bool => $option->isEdge() === false);
    $none = (clone $balancer)->addFilter(fn (TypedOption $option): bool => false);

    return [array_map(fn (TypedOption $o) => $o->getHost(), $edge->getFilteredOptions()), array_map(fn (TypedOption $o) => $o->getHost(), $custom->getFilteredOptions()), $none->getFilteredOptions()];
});
check('balancer many options', function () {
    mt_srand(8);
    $out = [];
    foreach ([First::class, Last::class, Random::class, RoundRobin::class] as $algorithm) {
        $balancer = new Balancer($algorithm === RoundRobin::class ? new RoundRobin(-1) : new $algorithm());
        for ($i = 0; $i < 100; $i++) {
            $balancer->addOption(new Option(['i' => $i]));
        }
        $balancer->addFilter(fn (Option $o) => $o->getState('i') % 3 !== 0);
        $picks = [];
        for ($i = 0; $i < 40; $i++) {
            $picks[] = $balancer->run()->getState('i');
        }
        $out[$algorithm] = [$picks, count($balancer->getFilteredOptions())];
    }

    return [$out, mt_rand()];
});
check('balancer recursion', function () {
    $inner = (new Balancer(new First()))->addOption(new Option(['hostname' => 'inner']));
    $outer = new Balancer(new Last());
    $outer->addOption(new Option(['hostname' => 'outer-1']))->addOption(new Option(['hostname' => 'outer-2']));
    $outer->addFilter(fn (Option $o) => $inner->run() !== null && hostname($inner->run()) === 'inner');

    return [hostname($outer->run()), array_map(hostname(...), $outer->getFilteredOptions())];
});

// 6. Group --------------------------------------------------------------------------------

/** A telemetry adapter recording what Group asks of it. */
class Recording implements Telemetry
{
    public array $log = [];

    public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Counter
    {
        throw new LogicException('not used');
    }

    public function createHistogram(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Histogram
    {
        $this->log[] = ['createHistogram', $name, $unit, $description, var_export($advisory, true), func_num_args()];
        $log = &$this->log;

        return new class ($log) extends Histogram {
            public function __construct(private array &$log)
            {
            }

            public function record(float|int $amount, iterable $attributes = []): void
            {
                $this->log[] = ['record', get_debug_type($amount), $amount >= 0 && $amount < 5, $attributes, func_num_args()];
            }
        };
    }

    public function createGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Gauge
    {
        throw new LogicException('not used');
    }

    public function createUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): UpDownCounter
    {
        throw new LogicException('not used');
    }

    public function createObservableGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): ObservableGauge
    {
        throw new LogicException('not used');
    }

    public function collect(): bool
    {
        return true;
    }
}

check('group constructor', fn () => [
    outcome(fn () => new Group()), outcome(fn () => new Group('workers')), outcome(fn () => new Group(null)), outcome(fn () => new Group(5)),
    outcome(fn () => new Group(1.5)), outcome(fn () => new Group(true)), outcome(fn () => new Group([])), outcome(fn () => new Group(new stdClass())),
    outcome(fn () => new Group('a', 'extra')),
]);
check('group constructor strict', fn () => child("try { var_dump(new Group(5)); } catch (Throwable \$e) { echo get_class(\$e), ': ', \$e->getMessage(); }", true));
check('group add', function () {
    $group = new Group();
    $balancer = new Balancer(new First());

    return [
        $group->add($balancer) === $group, outcome(fn () => $group->add(new stdClass())), outcome(fn () => $group->add(null)), outcome(fn () => $group->add()),
        outcome(fn () => $group->add(new SortedBalancer(new First()))->add($balancer)), $group,
    ];
});
check('group run and options', function () {
    $options = workers(['fra-1' => [false, 91], 'fra-2' => [false, 95], 'lon-1' => [true, 87]]);
    $idle = new Balancer(new First());
    $idle->addFilter(fn (Option $o) => $o->getState('cpu') < 80)->addFilter(fn (Option $o) => $o->getState('online') === true);
    $online = (new Balancer(new First()))->addFilter(fn (Option $o) => $o->getState('online') === true);
    $any = new Balancer(new Last());
    foreach ($options as $option) {
        $idle->addOption($option);
        $online->addOption($option);
        $any->addOption($option);
    }
    $out = [];
    foreach ([[], [$idle], [$idle, $online], [$idle, $any], [$any, $online], [$idle, $online, $any]] as $balancers) {
        $group = new Group('workers');
        $telemetry = new Recording();
        $group->setTelemetry($telemetry);
        foreach ($balancers as $balancer) {
            $group->add($balancer);
        }
        $out[] = [hostname($group->run()), array_map(hostname(...), $group->getOptions()), $telemetry->log];
    }

    return $out;
});
check('group telemetry', function () {
    $out = [];
    foreach ([null, 'named', '', '0'] as $name) {
        $group = new Group($name);
        $telemetry = new Recording();
        $out[] = outcome(fn () => $group->setTelemetry($telemetry));
        $group->add((new Balancer(new First()))->addOption(new Option(['hostname' => 'w1'])));
        $group->run();
        $group->run();
        $out[] = $telemetry->log;
    }
    $group = new Group();
    $out[] = outcome(fn () => $group->setTelemetry(new stdClass()));
    $out[] = outcome(fn () => $group->setTelemetry());
    $out[] = outcome(fn () => $group->setTelemetry(new NoTelemetry()));
    $out[] = outcome(fn () => $group->setTelemetry(new Utopia\Telemetry\Adapter\Test()));

    return $out;
});
check('group throwing balancer', function () {
    $telemetry = new Recording();
    $group = new Group('g');
    $group->setTelemetry($telemetry);
    $group->add((new Balancer(new First()))->addOption(new Option([]))->addFilter(fn () => throw new DomainException('down')));
    $group->add((new Balancer(new First()))->addOption(new Option(['hostname' => 'never'])));

    return [outcome(fn () => $group->run()), $telemetry->log, array_map(hostname(...), $group->getOptions())];
});
check('group with subclasses', function () {
    $telemetry = new Recording();
    $group = new Group();
    $group->setTelemetry($telemetry);
    $logged = (new LoggedBalancer(new First()))->addOption(new Option(['hostname' => 'a']))->addOption(new Option(['hostname' => 'b']));
    $empty = (new EmptyBalancer(new First()))->addOption(new Option(['hostname' => 'e']));
    $group->add($empty)->add($logged);

    return [hostname($group->run()), array_map(hostname(...), $group->getOptions()), $logged->log, $telemetry->log];
});

class TelemetryGroup extends Group
{
    public array $log = [];

    public function setTelemetry(Telemetry $telemetry): void
    {
        $this->log[] = get_class($telemetry);
        parent::setTelemetry($telemetry);
    }
}

class NoParentGroup extends Group
{
    public function __construct()
    {
    }

    public function balancers(): array
    {
        return $this->balancers;
    }
}

class RedeclaredGroup extends Group
{
    protected array $balancers = ['not a balancer'];
}

check('group subclasses', function () {
    $out = [];
    $group = new TelemetryGroup('t');
    $out[] = $group->log;
    $out[] = outcome(fn () => $group->run());
    $bare = new NoParentGroup();
    $out[] = outcome(fn () => $bare->getOptions());
    $out[] = outcome(fn () => $bare->run());
    $out[] = outcome(fn () => $bare->add(new Balancer(new First()))->balancers());
    $out[] = outcome(fn () => $bare->getOptions());
    $out[] = outcome(fn () => $bare->run());
    $redeclared = new RedeclaredGroup();
    $out[] = outcome(fn () => $redeclared->getOptions());
    $out[] = outcome(fn () => $redeclared->run());
    $out[] = outcome(fn () => $redeclared->add(new Balancer(new First())));

    return $out;
});
check('group uninitialized', function () {
    $group = (new ReflectionClass(Group::class))->newInstanceWithoutConstructor();
    $out = [outcome(fn () => $group->run()), outcome(fn () => $group->getOptions())];
    $group->setTelemetry(new Recording());
    $out[] = outcome(fn () => $group->run());

    return $out;
});
check('group clone, compare, serialize', function () {
    $group = new Group('g');
    $group->add((new Balancer(new First()))->addOption(new Option(['hostname' => 'w1'])));
    $copy = clone $group;
    $copy->add(new Balancer(new Last()));

    return [count($group->getOptions()), count($copy->getOptions()), outcome(fn () => serialize($group)), withoutHandles(print_r($group, true)), $group == $copy];
});

// 7. Calls PHP makes through the classes ----------------------------------------------------

check('callables to the methods', function () {
    $option = new Option(['a' => 1]);
    $balancer = (new Balancer(new First()))->addOption($option);

    return [
        call_user_func([$option, 'getState'], 'a'), call_user_func_array([$option, 'getState'], ['key' => 'b', 'default' => 'named']),
        array_map([$option, 'getState'], ['a', 'b']), $option->getState(...)('a'), (new ReflectionMethod(Option::class, 'getState'))->invoke($option, 'a'),
        hostname(call_user_func([$balancer, 'run'])), $option->getState(default: 'named', key: 'z'), $option->setState(value: 2, key: 'n')->getStates(),
        outcome(fn () => $option->getState(key: 'a', other: 1)), outcome(fn () => $option->getState(default: 1)),
    ];
});
check('instanceof and class checks', fn () => [
    new First() instanceof Algorithm, new SubRoundRobin(1) instanceof RoundRobin, is_subclass_of(First::class, Algorithm::class), is_a(new TypedOption('x'), Option::class),
    class_implements(new Balancer(new First())), get_parent_class(new Random()), (new Balancer(new First())) instanceof Stringable,
]);

// 8. Known differences ----------------------------------------------------------------------
// Asserted on the extension only, so a fix shows up here (and the README must change with it).

if ($internal) {
    $differences = [];
    // A closure cannot be bound to the scope of an internal class.
    $differences['bind'] = @Closure::bind(fn () => 1, new Option([]), Option::class) === null;
    // An internal class, or a class extending one, cannot be made lazy.
    foreach ([Option::class, TypedOption::class, Balancer::class] as $class) {
        try {
            (new ReflectionClass($class))->newLazyGhost(fn () => null);
            $differences["lazy $class"] = false;
        } catch (Error $e) {
            $differences["lazy $class"] = str_starts_with($e->getMessage(), 'Cannot make instance of internal class lazy');
        }
    }
    // An exception thrown in a filter has no array_filter() or getFilteredOptions() frame in its
    // trace: run() calls the filters itself.
    $trace = [];
    try {
        (new Balancer(new First()))->addOption(new Option([]))->addFilter(fn () => throw new Exception('x'))->run();
    } catch (Exception $e) {
        $trace = array_map(fn ($frame) => ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'], $e->getTrace());
    }
    $differences['trace'] = !in_array('array_filter', $trace, true) && in_array('Utopia\\Balancer\\Balancer->run', $trace, true);
    if (in_array(false, $differences, true)) {
        fwrite(STDERR, 'a known difference changed: ' . json_encode($differences) . PHP_EOL);
        exit(1);
    }
}
