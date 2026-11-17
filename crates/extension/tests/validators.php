<?php

// Parity corpus for Utopia\Validator\Validator, the base of every validator. Run it on the PHP
// class and on the utopia extension's (_APP_EXTENSION_LIBRARIES=validators), and diff stdout:
// every line must match. Stderr names the implementation that ran. The leaf validators stay
// PHP; on the extension they extend the internal base.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Validator\AllOf;
use Utopia\Validator\AnyOf;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Boolean;
use Utopia\Validator\Contains;
use Utopia\Validator\Domain;
use Utopia\Validator\FloatValidator;
use Utopia\Validator\Globstar;
use Utopia\Validator\HexColor;
use Utopia\Validator\Host;
use Utopia\Validator\Hostname;
use Utopia\Validator\Identifier;
use Utopia\Validator\Integer;
use Utopia\Validator\IP;
use Utopia\Validator\JSON;
use Utopia\Validator\JSON\ArrayValidator;
use Utopia\Validator\JSON\FCM;
use Utopia\Validator\JSON\ObjectValidator;
use Utopia\Validator\Label;
use Utopia\Validator\Multiple;
use Utopia\Validator\NoneOf;
use Utopia\Validator\Nullable;
use Utopia\Validator\Numeric;
use Utopia\Validator\Phone;
use Utopia\Validator\Port;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\URL;
use Utopia\Validator\Validator;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Wildcard;

$internal = (new ReflectionClass(Validator::class))->isInternal();
fwrite(STDERR, 'Utopia\\Validator\\Validator: ' . ($internal ? 'utopia extension' : 'PHP') . PHP_EOL);
// A run meant for the extension that gets the PHP class would compare PHP with PHP and pass.
$requested = in_array('validators', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' validators, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

/** Warnings raised by the current case. */
$warnings = [];
set_error_handler(function (int $level, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});

/** $value with every string that is not UTF-8 as its hex, and every object as its class. */
function exported(mixed $value): mixed
{
    if (is_string($value)) {
        return mb_check_encoding($value, 'UTF-8') ? $value : ['hex' => bin2hex($value)];
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
function child(string $code): array
{
    $prelude = 'require ' . var_export(__DIR__ . '/../../../vendor/autoload.php', true) . ';';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-r', $prelude . $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['stdout' => $stdout, 'stderr' => trim($stderr), 'exit' => proc_close($process)];
}

// The base class, as reflection shows it. `isInternal()`, the file and the doc comments differ
// by construction; `isValid()`'s parameter type is a waiver below.
$class = new ReflectionClass(Validator::class);
emit('class', outcome(fn () => [
    'name' => $class->getName(), 'abstract' => $class->isAbstract(), 'final' => $class->isFinal(), 'interface' => $class->isInterface(),
    'parent' => $class->getParentClass(), 'interfaces' => $class->getInterfaceNames(), 'constructor' => $class->getConstructor()?->getName(),
    'instantiable' => $class->isInstantiable(), 'cloneable' => $class->isCloneable(), 'modifiers' => Reflection::getModifierNames($class->getModifiers()),
    'properties' => $class->getProperties(), 'static' => $class->getStaticProperties(), 'constants' => $class->getConstants(),
]));
foreach ($class->getReflectionConstants() as $constant) {
    emit('constant ' . $constant->getName(), outcome(fn () => [Reflection::getModifierNames($constant->getModifiers()), $constant->isFinal(), $constant->hasType(), $constant->getValue()]));
}
$methods = $class->getMethods();
usort($methods, fn ($a, $b) => strcmp($a->getName(), $b->getName()));
foreach ($methods as $method) {
    emit('method ' . $method->getName(), outcome(fn () => [
        Reflection::getModifierNames($method->getModifiers()), $method->isStatic(), (string) $method->getReturnType(), $method->getReturnType()?->allowsNull(),
        $method->hasTentativeReturnType(), $method->returnsReference(), $method->getNumberOfParameters(), $method->getNumberOfRequiredParameters(),
        array_map(fn (ReflectionParameter $p) => [$p->getName(), $p->getPosition(), $p->allowsNull(), $p->isOptional(), $p->isVariadic(), $p->isPassedByReference()], $method->getParameters()),
    ]));
}

// Every validator of the package over values of every kind: each extends the base.
$inputs = [
    'null' => null, 'true' => true, 'false' => false, '0' => 0, '1' => 1, '-1' => -1, '65536' => 65536, '1.5' => 1.5, 'NAN' => NAN,
    "'0'" => '0', "''" => '', "' '" => ' ', "'a'" => 'a', "'abc'" => 'abc', "'8080'" => '8080', "'1.5'" => '1.5', "'1e3'" => '1e3',
    'domain' => 'example.com', 'wildcard domain' => '*.example.com', 'subdomain' => 'a.b.example.co.uk', 'url' => 'https://example.com/a?b=c#d',
    'url no scheme' => 'example.com/a', 'mailto' => 'mailto:a@b.c', 'ipv4' => '127.0.0.1', 'ipv6' => '::1', 'private ip' => '10.0.0.1',
    'hex short' => '#fff', 'hex' => 'ff00aa', 'hex bad' => '#zzzzzz', 'json object' => '{"a":1}', 'json array' => '[1,2]', 'json bad' => '{a',
    'phone' => '+14155552671', 'phone bad' => '14155552671', 'label' => 'my-label_1', 'not utf-8' => "\xff\xfe", 'long' => str_repeat('a', 300),
    'control' => "a\x00b", 'emoji' => "\u{1F600}", '[]' => [], "['a']" => ['a'], "['a','b','c','d']" => ['a', 'b', 'c', 'd'], "['k'=>'v']" => ['k' => 'v'],
    '[[1]]' => [[1]], '[1,2]' => [1, 2], 'fcm' => ['message' => ['token' => 'x']], 'object' => new stdClass(), 'stringable' => new class () {
        public function __toString(): string
        {
            return 'example.com';
        }
    },
];
$validators = [
    'AllOf' => fn () => new AllOf([new Text(10), new WhiteList(['a', 'abc'])]),
    'AllOf string' => fn () => new AllOf([new Text(10)], Validator::TYPE_STRING),
    'AnyOf' => fn () => new AnyOf([new Integer(), new Text(3)]),
    'ArrayList' => fn () => new ArrayList(new Text(5), 3),
    'ArrayList unbounded' => fn () => new ArrayList(new Integer(true)),
    'Assoc' => fn () => new Assoc(),
    'Assoc small' => fn () => new Assoc(10),
    'Boolean' => fn () => new Boolean(),
    'Boolean loose' => fn () => new Boolean(true),
    'Contains' => fn () => new Contains(['ab', 'cd']),
    'Contains strict' => fn () => new Contains(['ab', 1], true),
    'Domain' => fn () => new Domain(),
    'Domain restricted' => fn () => new Domain([], false, true),
    'FloatValidator' => fn () => new FloatValidator(),
    'FloatValidator loose' => fn () => new FloatValidator(true),
    'Globstar' => fn () => new Globstar(['*.example.com', 'a/**']),
    'HexColor' => fn () => new HexColor(),
    'Hostname' => fn () => new Hostname(),
    'Hostname allow list' => fn () => new Hostname(['example.com', '*.test.com']),
    'Host' => fn () => new Host(['example.com']),
    'Identifier' => fn () => new Identifier(),
    'Identifier 5' => fn () => new Identifier(5),
    'Integer' => fn () => new Integer(),
    'Integer loose' => fn () => new Integer(true),
    'Integer 64 unsigned' => fn () => new Integer(false, 64, true),
    'IP' => fn () => new IP(),
    'IP v4' => fn () => new IP(IP::V4),
    'IP v6' => fn () => new IP(IP::V6),
    'IP bad type' => fn () => new IP('bad'),
    'JSON' => fn () => new JSON(),
    'JSON ArrayValidator' => fn () => new ArrayValidator(),
    'JSON FCM' => fn () => new FCM(),
    'JSON ObjectValidator' => fn () => new ObjectValidator(),
    'Label' => fn () => new Label(),
    'Multiple' => fn () => new Multiple([new Text(5), new WhiteList(['a', 'abc'])]),
    'NoneOf' => fn () => new NoneOf([new Integer(), new WhiteList(['a'])]),
    'Nullable' => fn () => new Nullable(new Text(5)),
    'Numeric' => fn () => new Numeric(),
    'Phone' => fn () => new Phone(),
    'Phone empty normalize' => fn () => new Phone(true, true),
    'Port' => fn () => new Port(),
    'Range' => fn () => new Range(1, 10),
    'Range float' => fn () => new Range(0.5, 1.5, Validator::TYPE_FLOAT),
    'Text' => fn () => new Text(10),
    'Text unbounded' => fn () => new Text(0, 0),
    'Text allow list' => fn () => new Text(5, 1, ['a', 'b']),
    'Text non blank' => fn () => new Text(10, 1, [], true),
    'URL' => fn () => new URL(),
    'URL https' => fn () => new URL(['https'], true),
    'WhiteList' => fn () => new WhiteList(['a', 'b', 1]),
    'WhiteList strict' => fn () => new WhiteList(['a', '1'], true),
    'Wildcard' => fn () => new Wildcard(),
];
foreach ($validators as $name => $make) {
    emit($name, outcome(function () use ($make) {
        $validator = $make();

        return [$validator::class, $validator instanceof Validator, $validator->getDescription(), $validator->getType(), $validator->isArray()];
    }));
    $validator = null;
    try {
        $validator = $make();
    } catch (Throwable) {
    }
    if ($validator === null) {
        continue;
    }
    foreach ($inputs as $label => $input) {
        emit($name . ' isValid ' . $label, outcome(fn () => $validator->isValid($input)));
    }
}
fwrite(STDERR, count($validators) . ' validators, ' . count($inputs) . ' inputs' . PHP_EOL);

// PHP subclasses of the base.
$sub = new class () extends Validator {
    public string $label = 'first';

    public array $seen = [];

    public function getDescription(): string
    {
        return 'Value must be "' . $this->label . '"';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function isValid($value): bool
    {
        $this->seen[] = $value;

        return $value === $this->label;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }
};
emit('subclass', outcome(fn () => [$sub instanceof Validator, $sub->isValid('first'), $sub->isValid('x'), $sub->getDescription(), $sub->getType(), $sub::TYPE_FLOAT, get_parent_class($sub)]));
emit('subclass clone', outcome(function () use ($sub) {
    $copy = clone $sub;
    $copy->label = 'copy';
    $copy->isValid('copy');

    return [$copy::class === $sub::class, $sub->label, $copy->label, $sub->seen, $copy->seen, properties($copy), $copy == $sub];
}));
emit('subclass var_dump', outcome(function () use ($sub) {
    ob_start();
    var_dump($sub);

    return withoutHandles(ob_get_clean());
}));
emit('subclass serialize', outcome(fn () => unserialize(serialize(new Text(5))) == new Text(5)));
emit('subclass parent constructor', outcome(fn () => new class () extends Validator {
    public function __construct()
    {
        parent::__construct();
    }

    public function getDescription(): string
    {
        return '';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function isValid($value): bool
    {
        return true;
    }

    public function getType(): string
    {
        return self::TYPE_MIXED;
    }
}));
emit('subclass of a leaf', outcome(function () {
    $text = new class (4) extends Text {
        public function getDescription(): string
        {
            return parent::getDescription() . ' and be shouted';
        }

        public function isValid($value): bool
        {
            return parent::isValid($value) && strtoupper($value) === $value;
        }

        public function limit(): int
        {
            return $this->length;
        }
    };

    return [$text->isValid('ABC'), $text->isValid('abc'), $text->isValid('ABCDE'), $text->getDescription(), $text->limit(), $text instanceof Validator, Text::TYPE_STRING];
}));
emit('constants through a leaf', outcome(fn () => [Text::TYPE_STRING, Range::TYPE_FLOAT, Boolean::TYPE_BOOLEAN, (new ReflectionClass(Text::class))->getConstants()]));
emit('new base', outcome(fn () => new Validator()));
emit('instanceof', outcome(fn () => [new Text(1) instanceof Validator, is_subclass_of(Text::class, Validator::class), is_a(new Range(1, 2), Validator::class), in_array(Validator::class, class_parents(new Range(1, 2)), true)]));
emit('closure', outcome(fn () => (new ReflectionMethod(Validator::class, 'getType'))->isAbstract()));

// The pre-namespace name, aliased by packages/validators/src/compat.php.
emit('compat alias', outcome(fn () => [class_exists('Utopia\Validator'), new Text(1) instanceof \Utopia\Validator, (new ReflectionClass('Utopia\Validator'))->getName(), get_parent_class(new Text(1))]));

// What the engine refuses, from a child process: these are compile-time fatals.
$declarations = [
    'missing method' => 'new class () extends Utopia\Validator\Validator { public function getDescription(): string { return ""; } public function isArray(): bool { return false; } public function isValid($value): bool { return true; } };',
    'missing return type' => 'new class () extends Utopia\Validator\Validator { public function getDescription(): string { return ""; } public function isArray(): bool { return false; } public function isValid($value): bool { return true; } public function getType() { return ""; } };',
    'wrong return type' => 'new class () extends Utopia\Validator\Validator { public function getDescription(): string { return ""; } public function isArray(): bool { return false; } public function isValid($value): bool { return true; } public function getType(): int { return 1; } };',
    'mixed parameter' => 'echo (new class () extends Utopia\Validator\Validator { public function getDescription(): string { return ""; } public function isArray(): bool { return false; } public function isValid(mixed $value): bool { return true; } public function getType(): string { return ""; } })->isValid(1) ? "ok" : "no";',
    'extra optional parameter' => 'echo (new class () extends Utopia\Validator\Validator { public function getDescription(): string { return ""; } public function isArray(): bool { return false; } public function isValid($value, bool $strict = false): bool { return true; } public function getType(): string { return ""; } })->isValid(1) ? "ok" : "no";',
    'static method' => 'new class () extends Utopia\Validator\Validator { public function getDescription(): string { return ""; } public function isArray(): bool { return false; } public static function isValid($value): bool { return true; } public function getType(): string { return ""; } };',
    'abstract subclass' => 'abstract class Half extends Utopia\Validator\Validator { public function getType(): string { return self::TYPE_STRING; } } echo (new ReflectionClass("Half"))->isAbstract() ? "abstract" : "concrete";',
];
foreach ($declarations as $name => $code) {
    emit('declaration ' . $name, child($code));
}

// Known differences of the extension, each a waiver in crates/extension/README.md.
$waivers = [
    // An internal argument has a type; `mixed` is the nearest to PHP's untyped `$value`, and
    // the two accept and override alike (the declarations above).
    'isValid parameter type' => outcome(fn () => (string) (new ReflectionParameter([Validator::class, 'isValid'], 0))->getType()),
    // The engine names the parent's signature in its message, typed on the extension.
    'incompatible override message' => child('new class () extends Utopia\Validator\Validator { public function getDescription(): string { return ""; } public function isArray(): bool { return false; } public function isValid(string $value): bool { return true; } public function getType(): string { return ""; } };'),
];
$incompatible = "Fatal error: Declaration of Utopia\\Validator\\Validator@anonymous::isValid(string \$value): bool must be compatible with Utopia\\Validator\\Validator::isValid(%s\$value): bool in Command line code on line 1\nStack trace:\n#0 {main}";
$expected = $internal ? [
    'isValid parameter type' => ['value' => 'mixed'],
    'incompatible override message' => ['stdout' => '', 'stderr' => sprintf($incompatible, 'mixed '), 'exit' => 255],
] : [
    'isValid parameter type' => ['value' => ''],
    'incompatible override message' => ['stdout' => '', 'stderr' => sprintf($incompatible, ''), 'exit' => 255],
];
foreach ($expected as $name => $outcome) {
    if ($waivers[$name] !== $outcome) {
        fwrite(STDERR, "Waiver changed: {$name}: " . json_encode(exported($waivers[$name])) . PHP_EOL);
        exit(1);
    }
}
fwrite(STDERR, count($expected) . ' waivers hold' . PHP_EOL);
