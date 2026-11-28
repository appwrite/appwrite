<?php

// Parity corpus for utopia-php/database's Key, Label and UID validators: diff its stdout on the
// extension (`database-validators` switched on) and on the PHP classes in vendor/ (off, the default).

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\Label;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\Roles;
use Utopia\Database\Validator\UID;
use Utopia\Validator\Validator;

const CLASSES = [Key::class, Label::class, UID::class];

$internal = (new ReflectionClass(Key::class))->isInternal();
fwrite(STDERR, 'Utopia\\Database\\Validator: ' . ($internal ? 'utopia extension' : 'PHP') . PHP_EOL);
// A run meant for the extension that gets the PHP classes would compare PHP with PHP and pass.
$libraries = array_filter(array_map(trim(...), explode(',', (string) ini_get('utopia.libraries'))), fn (string $name): bool => $name !== '');
$switched = array_map(trim(...), explode(',', (string) getenv('_APP_EXTENSION_LIBRARIES')));
$requested = $libraries === [] ? in_array('database-validators', $switched, true) : in_array('database-validators', $libraries, true);
if ($internal !== $requested) {
    fwrite(STDERR, 'database-validators is ' . ($requested ? '' : 'not ') . 'switched on, but the other implementation loaded' . PHP_EOL);
    exit(1);
}
foreach (CLASSES as $class) {
    if ((new ReflectionClass($class))->isInternal() !== $internal) {
        fwrite(STDERR, $class . ' is not from the same implementation as Key' . PHP_EOL);
        exit(1);
    }
}
if ((new ReflectionClass(Roles::class))->isInternal() || (new ReflectionClass(Permissions::class))->isInternal()) {
    fwrite(STDERR, 'Roles and Permissions stay PHP' . PHP_EOL);
    exit(1);
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
    if ($value instanceof UnitEnum) {
        return ['enum' => $value::class . '::' . $value->name];
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
    if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
        return ['bytes' => bin2hex($value)];
    }

    return $value;
}

/** An exception's message. */
function message(Throwable $e): string
{
    return $e->getMessage();
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
        $message = message($e);
        $result = ['throws' => $e::class, 'message' => mb_check_encoding($message, 'UTF-8') ? $message : bin2hex($message), 'code' => $e->getCode()];
        if ($e->getPrevious() !== null) {
            $result['previous'] = [$e->getPrevious()::class, $e->getPrevious()->getMessage(), $e->getPrevious()->getCode()];
        }
    }
    if ($warnings !== []) {
        $result['warnings'] = $warnings;
    }

    return $result;
}

function emit(string $case, array $result): void
{
    echo json_encode([$case, $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), PHP_EOL;
}

function check(string $case, callable $run): void
{
    emit($case, outcome($run));
}

/** $dump with each object handle ("#12 ") dropped: handles differ between runs. */
function withoutHandles(string $dump): string
{
    $parts = explode('#', $dump);
    foreach ($parts as $i => $part) {
        $digits = strspn($part, '0123456789');
        if ($i > 0 && $digits > 0 && ($part[$digits] ?? '') === ' ') {
            $parts[$i] = substr($part, $digits);
        }
    }

    return implode('#', $parts);
}

/** What `php -r $code` prints and exits with, in a child process loading the same autoloader. */
function child(string $code, bool $strict = false): array
{
    $prelude = ($strict ? 'declare(strict_types=1); ' : '') . 'require ' . var_export(__DIR__ . '/../../../vendor/autoload.php', true) . '; use Utopia\Database\Validator\{Key, Label, UID};';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-r', $prelude . $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    // Exceptions name the file and line, which differ: only the first line is compared.
    $stderr = trim($stderr);
    foreach ([' in /', ' in Command line code'] as $marker) {
        $at = strpos($stderr, $marker);
        if ($at !== false) {
            $stderr = substr($stderr, 0, $at);
        }
    }

    return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => proc_close($process)];
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

// PHP subclasses, as Appwrite's, declared before the cases use them ---------------------------

/** Appwrite's CustomId: a key, or `unique()`. */
class CustomKey extends Key
{
    public const UNIQUE = 'unique()';

    public function isValid($value): bool
    {
        return $value == self::UNIQUE || parent::isValid($value);
    }

    public function state(): array
    {
        return [$this->message, $this->allowInternal, $this->maxLength];
    }
}

/** Appwrite's KeywordId: any ID, plus one keyword. */
class KeywordUid extends UID
{
    public function __construct(
        private readonly string $keyword,
        int $maxLength = 36,
    ) {
        parent::__construct($maxLength);
    }

    public function isValid($value): bool
    {
        return $value === $this->keyword || parent::isValid($value);
    }

    public function getDescription(): string
    {
        return parent::getDescription() . '. Can also be the keyword ' . $this->keyword;
    }
}

/** A key with its own message, set after the parent's constructor, as Label does. */
class MessageKey extends Key
{
    public function __construct(int $maxLength)
    {
        parent::__construct(true, $maxLength);
        $this->message = 'own message ' . $this->maxLength;
    }
}

/** A key that never calls the parent's constructor. */
class UnconstructedKey extends Key
{
    public function __construct()
    {
    }
}

/** A key that initializes the readonly properties itself (allowed from a subclass since PHP 8.4). */
class SelfInitializedKey extends Key
{
    public function __construct()
    {
        $this->allowInternal = true;
        $this->maxLength = 2;
        $this->message = 'self';
    }
}

/** A label that redeclares the message public, with a default. */
class PublicMessageLabel extends Label
{
    public string $message = 'default';
}

/** A UID whose isValid() is Label's rules through its own code. */
class StrictUid extends UID
{
    public function isValid(mixed $value): bool
    {
        return parent::isValid($value) && ctype_alnum((string) $value);
    }
}

/** A label that overrides the description and keeps isValid(). */
class DescribedLabel extends Label
{
    public function getDescription(): string
    {
        return 'label: ' . parent::getDescription() . ' (' . $this->maxLength . ')';
    }
}

// 1. The classes, as reflection shows them. `isInternal()`, the file, the doc comments and the
// `#[\Override]` attributes differ by construction.
foreach (CLASSES as $name) {
    $class = new ReflectionClass($name);
    check('class ' . $name, fn () => [
        'abstract' => $class->isAbstract(), 'final' => $class->isFinal(), 'parent' => ($class->getParentClass() ?: null)?->getName(),
        'interfaces' => $class->getInterfaceNames(), 'constructor' => $class->getConstructor()?->getName(),
        'instantiable' => $class->isInstantiable(), 'cloneable' => $class->isCloneable(), 'modifiers' => Reflection::getModifierNames($class->getModifiers()),
        'constants' => $class->getConstants(), 'static' => $class->getStaticProperties(), 'defaults' => $class->getDefaultProperties(),
        'methods' => get_class_methods($name), 'short' => $class->getShortName(), 'namespace' => $class->getNamespaceName(),
        'subclass of Validator' => $class->isSubclassOf(Validator::class), 'readonly' => $class->isReadOnly(),
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
            $method->isConstructor(), $method->isPublic(), $method->isPrivate(),
        ]);
    }
}

// 2. isValid() over every validator and value ------------------------------------------------

/** The validators, as the package and Appwrite build them. */
$validators = [
    'Key()' => fn () => new Key(),
    'Key(true)' => fn () => new Key(true),
    'Key(false, 5)' => fn () => new Key(false, 5),
    'Key(true, 3)' => fn () => new Key(true, 3),
    'Key(maxLength: 81)' => fn () => new Key(maxLength: 81),
    'Key(true, 255)' => fn () => new Key(true, 255),
    'Key(false, 0)' => fn () => new Key(false, 0),
    'Key(true, -1)' => fn () => new Key(true, -1),
    'Key(true, PHP_INT_MAX)' => fn () => new Key(true, PHP_INT_MAX),
    'Label()' => fn () => new Label(),
    'Label(true)' => fn () => new Label(true),
    'Label(false, 3)' => fn () => new Label(false, 3),
    'Label(maxLength: 0)' => fn () => new Label(maxLength: 0),
    'UID()' => fn () => new UID(),
    'UID(5)' => fn () => new UID(5),
    'UID(255)' => fn () => new UID(255),
    'UID(maxLength: -3)' => fn () => new UID(maxLength: -3),
];

/** Strings, valid and not, for each rule and boundary. */
$strings = [
    '', 'a', 'A', '0', '00', '123', '1e3', ' 1', '-1', '1.5', 'abc-DEF_09.x', 'movie_1', 'a.b-c_d', 'a_', 'a.', 'a-', 'x-y.z',
    '_', '.', '-', '_a', '.a', '-a', '$', '$id', '$createdAt', '$updatedAt', '$sequence', '$permissions', '$collection', '$tenant',
    '$ID', '$Id', '$id ', ' $id', '$id$', '$$id', 'id', 'a$', 'a$b', '$a', '$0', '$-', '$_',
    'a b', ' a', 'a ', "a\t", "a\n", "\na", "a\0", "\0a", "a\0b", "\0", '@', '#', 'a/b', 'a:b', 'a"b', "a'b", 'a\\b', 'a+b', 'a,b', 'a=b', 'a*b',
    'héllo', 'é', 'éa', 'aé', 'ü_1', '日本', '😀', "\xff", "\xfe\xff", "a\xff", "\xc3", "\xc3_", "_\xc3", "\xe2\x82",
    'vip', 'vip2', 'VIP', 'v.i.p', 'v-i-p', 'v_i_p', 'abc', 'abcd', 'abcde', 'abcdef', 'xyz12',
    str_repeat('x', 35), str_repeat('x', 36), str_repeat('x', 37), str_repeat('a', 80), str_repeat('a', 81), str_repeat('a', 82),
    str_repeat('z', 254), str_repeat('z', 255), str_repeat('z', 256), str_repeat('a.', 18), str_repeat('é', 36), str_repeat('é', 10),
    'unique()', 'current()', 'recent()', 'ID.unique()',
    '6564a1b2c3d4e5f6a7b8', '6564a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', '6564a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e',
];

/** Values that are not strings: isValid() refuses them before anything else. */
$others = [
    'int' => 123, 'zero' => 0, 'negative' => -5, 'float' => 1.5, 'true' => true, 'false' => false, 'null' => null,
    'array' => ['a'], 'empty array' => [], 'object' => new stdClass(), 'stringable' => new class () {
        public function __toString(): string
        {
            return 'abc';
        }
    },
    'enum' => \Utopia\Database\PermissionType::Read, 'closure' => fn () => 'a',
];

foreach ($validators as $label => $make) {
    check("$label built", function () use ($make) {
        $validator = $make();

        return [$validator, $validator->getDescription(), $validator->isArray(), $validator->getType()];
    });
    $validator = $make();
    $results = [];
    foreach ($strings as $s) {
        $results[bin2hex($s)] = $validator->isValid($s);
    }
    check("$label isValid strings", fn () => $results);
    foreach ($others as $name => $value) {
        check("$label isValid $name", fn () => $validator->isValid($value));
    }
}

// Seeded random strings over the bytes the rules look at, against every validator.
mt_srand(2026);
$alphabet = ['a', 'Z', '0', '9', '_', '-', '.', '$', ' ', "\0", "\xff", 'é', 'id', 'createdAt', 'updatedAt'];
$generated = [];
for ($i = 0; $i < 3000; $i++) {
    $s = '';
    $length = mt_rand(0, 6);
    for ($j = 0; $j < $length; $j++) {
        $s .= $alphabet[mt_rand(0, count($alphabet) - 1)];
    }
    if (mt_rand(0, 4) === 0) {
        $s = str_repeat('k', mt_rand(30, 40)) . $s;
    }
    $generated[] = $s;
}
foreach ($validators as $label => $make) {
    $validator = $make();
    $accepted = [];
    foreach ($generated as $i => $s) {
        if ($validator->isValid($s)) {
            $accepted[] = $i;
        }
    }
    check("$label generated", fn () => [count($accepted), md5(implode(',', $accepted))]);
}

// 3. Constructors: coercion, names, errors -------------------------------------------------

$arguments = [
    'none' => [], 'true' => [true], '"1"' => ['1'], '"0"' => ['0'], '1' => [1], '0' => [0], '1.0' => [1.0], '0.5' => [0.5], '"yes"' => ['yes'],
    'null' => [null], 'array' => [[]], 'object' => [new stdClass()],
    'false, "5"' => [false, '5'], 'false, 5.0' => [false, 5.0], 'false, 5.5' => [false, 5.5], 'false, "5.5"' => [false, '5.5'],
    'false, "5x"' => [false, '5x'], 'false, " 5"' => [false, ' 5'], 'false, "5 "' => [false, '5 '], 'false, true' => [false, true],
    'false, null' => [false, null], 'false, []' => [false, []], 'false, 1e30' => [false, 1e30], 'false, NAN' => [false, NAN],
    'false, INF' => [false, INF], 'false, "abc"' => [false, 'abc'], 'three' => [true, 5, 'extra'], 'four' => [true, 5, 'extra', 'more'],
];
foreach ($arguments as $label => $args) {
    check("new Key $label", function () use ($args) {
        $key = new Key(...$args);

        return [$key, $key->getDescription(), $key->isValid('$id'), $key->isValid('abcde'), $key->isValid('abcdef')];
    });
    check("new Label $label", function () use ($args) {
        $label = new Label(...$args);

        return [$label, $label->getDescription(), $label->isValid('$id'), $label->isValid('abcde'), $label->isValid('abcdef')];
    });
}
foreach (['none' => [], '5' => [5], '"5"' => ['5'], '5.0' => [5.0], '5.5' => [5.5], 'true' => [true], 'null' => [null], '"x"' => ['x'], 'array' => [[]], 'two' => [5, 6]] as $label => $args) {
    check("new UID $label", function () use ($args) {
        $uid = new UID(...$args);

        return [$uid, $uid->getDescription(), $uid->isValid('abcde'), $uid->isValid('abcdef'), $uid->isValid('$id')];
    });
}
check('Key named', fn () => [new Key(maxLength: 9), new Key(allowInternal: true), new Key(maxLength: 2, allowInternal: true)]);
check('Label named', fn () => [new Label(maxLength: 9), new Label(allowInternal: true)]);
check('UID named', fn () => new UID(maxLength: 9));
check('Key unknown named', fn () => new Key(other: 1));
check('UID unknown named', fn () => new UID(allowInternal: true));
check('Key spread', fn () => new Key(...['allowInternal' => true, 'maxLength' => 4]));
check('Key strict bool', fn () => child('var_dump(new Key(1));', true));
check('Key strict int', fn () => child('var_dump(new Key(false, "5"));', true));
check('Key strict float', fn () => child('var_dump(new Key(false, 5.0));', true));
check('Label strict', fn () => child('var_dump(new Label("1"));', true));
check('UID strict', fn () => child('var_dump(new UID("5"));', true));
check('Key strict valid', fn () => child('var_dump((new Key(true, 4))->isValid("\$id"), (new UID())->getDescription());', true));
check('isValid no argument', fn () => (new Key())->isValid());
check('Label isValid no argument', fn () => (new Label())->isValid());
check('isValid extra arguments', fn () => [(new Key())->isValid('a', 'b'), (new Label())->isValid('a', 'b'), (new UID())->isValid('a', 'b')]);
check('isValid named', fn () => [(new Key())->isValid(value: 'a'), (new Label())->isValid(value: 'b')]);
check('isValid unknown named', fn () => (new Key())->isValid(other: 'a'));
check('getDescription extra arguments', fn () => [(new Key())->getDescription(1), (new UID())->getDescription(1), (new Key())->isArray(1), (new Key())->getType(1)]);
check('isValid reference', function () {
    $value = 'abc';
    $ref = &$value;
    $array = ['$id'];

    return [(new Key())->isValid($ref), (new Key(true))->isValid($array[0]), (new Label())->isValid($ref)];
});
check('Key construct twice', function () {
    $key = new Key(true, 5);
    try {
        $key->__construct(false, 9);
    } finally {
        $state = [$key, $key->getDescription(), $key->isValid('$id')];
    }

    return $state;
});
check('Label construct twice', function () {
    $label = new Label(false, 5);
    $label->__construct(false, 9);

    return $label;
});
check('UID construct twice', function () {
    $uid = new UID(5);
    $uid->__construct(9);

    return $uid;
});
check('Key construct twice caught', function () {
    $key = new Key(true, 5);
    $outcome = outcome(fn () => $key->__construct(false, 9));

    return [$outcome, $key, $key->getDescription()];
});
check('Label construct twice caught', function () {
    $label = new Label(false, 5);
    $outcome = outcome(fn () => $label->__construct(true, 9));

    return [$outcome, $label, $label->getDescription()];
});

// 4. Objects: without a constructor, dumps, copies, reflection ------------------------------

foreach (CLASSES as $name) {
    $short = (new ReflectionClass($name))->getShortName();
    check("$short without constructor", function () use ($name) {
        $object = (new ReflectionClass($name))->newInstanceWithoutConstructor();

        return [
            $object, outcome(fn () => $object->getDescription()), outcome(fn () => $object->isValid('abc')), outcome(fn () => $object->isValid('$id')),
            outcome(fn () => $object->isValid('_a')), outcome(fn () => $object->isValid('a b')), outcome(fn () => $object->isValid(5)),
            $object->isArray(), $object->getType(),
        ];
    });
    check("$short var_dump", function () use ($name) {
        ob_start();
        var_dump(new $name());

        return withoutHandles(ob_get_clean());
    });
    check("$short print_r var_export json", fn () => [print_r(new $name(), true), var_export(new $name(), true), json_encode(new $name()), get_object_vars(new $name())]);
    check("$short serialize", function () use ($name) {
        $serialized = serialize(new $name());
        $back = unserialize($serialized);

        return [$serialized, $back, $back->getDescription(), $back->isValid('abc')];
    });
    check("$short comparison and clone", function () use ($name) {
        $a = new $name();
        $b = clone $a;

        return [$a == $b, $a == new $name(), $b, $b->getDescription(), $b->isValid('abc')];
    });
    check("$short array cast", fn () => array_map(fn ($key) => bin2hex($key), array_keys((array) new $name())));
    check("$short dynamic property", function () use ($name) {
        $object = new $name();
        $object->extra = 1;

        return $object;
    });
    check("$short protected property from outside", fn () => (new $name())->maxLength);
    check("$short protected write from outside", function () use ($name) {
        $object = new $name();
        $object->message = 'x';

        return $object;
    });
    check("$short properties through reflection", function () use ($name) {
        $object = new $name();
        $message = new ReflectionProperty($name, 'message');
        $max = new ReflectionProperty($name, 'maxLength');
        $before = [$message->getValue($object), $max->getValue($object), (new ReflectionProperty($name, 'allowInternal'))->getValue($object)];
        $message->setValue($object, 'changed');
        $write = outcome(fn () => $max->setValue($object, 3));

        return [$before, $write, $object->getDescription(), $object->isValid('abcd'), $object];
    });
    check("$short uninitialized through reflection", function () use ($name) {
        $object = (new ReflectionClass($name))->newInstanceWithoutConstructor();
        $max = new ReflectionProperty($name, 'maxLength');
        $initialized = [$max->isInitialized($object), (new ReflectionProperty($name, 'message'))->isInitialized($object)];
        $max->setValue($object, 4);
        (new ReflectionProperty($name, 'allowInternal'))->setValue($object, true);

        return [$initialized, $object->isValid('abcd'), $object->isValid('abcde'), $object->isValid('$id'), outcome(fn () => $object->getDescription()), $object];
    });
    check("$short unset property through reflection", function () use ($name) {
        $object = new $name();
        $unset = Closure::bind(function () {
            unset($this->message);
        }, $object, CustomKey::class);

        return [outcome(fn () => $unset()), outcome(fn () => $object->getDescription()), $object];
    });
}

// 5. PHP subclasses, as Appwrite's (declared above) -----------------------------------

$subclasses = [
    'CustomKey' => fn () => new CustomKey(),
    'CustomKey(true, 4)' => fn () => new CustomKey(true, 4),
    'KeywordUid' => fn () => new KeywordUid('current()'),
    'KeywordUid(5)' => fn () => new KeywordUid('recent()', 5),
    'MessageKey' => fn () => new MessageKey(4),
    'SelfInitializedKey' => fn () => new SelfInitializedKey(),
    'PublicMessageLabel' => fn () => new PublicMessageLabel(false, 5),
    'StrictUid' => fn () => new StrictUid(6),
    'DescribedLabel' => fn () => new DescribedLabel(true, 4),
];
foreach ($subclasses as $label => $make) {
    check("subclass $label", function () use ($make, $strings) {
        $validator = $make();
        $results = [];
        foreach ($strings as $s) {
            $results[bin2hex($s)] = $validator->isValid($s);
        }

        return [$validator, $validator->getDescription(), $validator->isArray(), $validator->getType(), $results, $validator->isValid(0), $validator->isValid(null)];
    });
}
check('CustomKey state', fn () => (new CustomKey(true, 7))->state());
check('CustomKey loose unique', fn () => [(new CustomKey())->isValid(0), (new CustomKey())->isValid('unique()'), (new CustomKey())->isValid(true)]);
check('UnconstructedKey', function () {
    $key = new UnconstructedKey();

    return [$key, outcome(fn () => $key->getDescription()), outcome(fn () => $key->isValid('abc')), outcome(fn () => $key->isValid('$id')), $key->isValid('.a'), outcome(fn () => $key->__construct())];
});
check('UnconstructedKey parent constructor', function () {
    $key = new UnconstructedKey();
    $construct = Closure::bind(fn () => Key::__construct(true, 3), $key, UnconstructedKey::class);
    $construct();

    return [$key, $key->getDescription(), $key->isValid('$id'), $key->isValid('abcd')];
});
check('subclass without constructor', function () {
    $key = (new ReflectionClass(CustomKey::class))->newInstanceWithoutConstructor();

    return [$key, outcome(fn () => $key->isValid('abc')), $key->isValid('unique()')];
});
check('subclass reads parent properties', fn () => Closure::bind(fn () => [$this->message, $this->allowInternal, $this->maxLength], new DescribedLabel(true, 4), DescribedLabel::class)());
check('subclass instanceof', fn () => [new KeywordUid('x') instanceof Key, new KeywordUid('x') instanceof Validator, new DescribedLabel() instanceof Key, is_subclass_of(UID::class, Key::class)]);
check('subclass serialize', fn () => unserialize(serialize(new KeywordUid('current()', 9))));
check('subclass clone', function () {
    $a = new MessageKey(3);
    $b = clone $a;

    return [$a == $b, $b->getDescription(), $b->isValid('abc')];
});

// 6. The package's validators that build these ----------------------------------------------

$permissions = [
    'read("any")', 'read("users")', 'read("guests")', 'read("user:abc")', 'read("user:abc/verified")', 'read("user:_abc")', 'read("user:$id")',
    'update("team:team1/owner")', 'update("team:team1/' . str_repeat('d', 81) . '")', 'update("team:team1/' . str_repeat('d', 82) . '")',
    'delete("label:vip")', 'delete("label:v.i.p")', 'delete("label:' . str_repeat('l', 36) . '")', 'delete("label:' . str_repeat('l', 37) . '")',
    'write("member:m1")', 'write("member:-m1")', 'create("team:a.b-c_d")', 'create("team:a b")', 'read("user:' . str_repeat('u', 37) . '")',
    'read("team:t/a.b")', 'read("team:t/_a")', 'read("label:$id")', 'read("user:é")', 'read("user:' . "\xff" . '")',
];
foreach ($permissions as $permission) {
    check('Permissions ' . bin2hex($permission), function () use ($permission) {
        $validator = new Permissions();

        return [$validator->isValid([$permission]), $validator->getDescription()];
    });
}
check('Permissions many', function () use ($permissions) {
    $validator = new Permissions(100);

    return [$validator->isValid(array_slice($permissions, 0, 5)), $validator->isValid($permissions), $validator->getDescription()];
});
foreach (['any', 'users', 'user:abc', 'user:-x', 'team:t/owner', 'team:t/-o', 'label:vip', 'label:v_ip', 'member:' . str_repeat('m', 37)] as $role) {
    check('Roles ' . $role, function () use ($role) {
        $validator = new Roles();

        return [$validator->isValid([$role]), $validator->getDescription()];
    });
}
check('Query Cursor', function () {
    $cursor = new \Utopia\Database\Validator\Query\Cursor();

    return array_map(fn ($value) => [$cursor->isValid(new \Utopia\Database\Query(\Utopia\Query\Method::CursorAfter, values: [$value])), $cursor->getDescription()], ['abc', '_abc', str_repeat('c', 37), 'a b']);
});

if ($internal) {
    $differences = [];
    // A closure cannot be bound to the scope of an internal class.
    $differences['bind'] = @Closure::bind(fn () => 1, new Key(), Key::class) === null;
    // An internal class has no file, and its methods no `#[\Override]` attribute.
    $differences['file'] = (new ReflectionClass(Key::class))->getFileName() === false;
    $differences['attributes'] = (new ReflectionMethod(Key::class, 'isValid'))->getAttributes() === [];
    if (in_array(false, $differences, true)) {
        fwrite(STDERR, 'a known difference changed: ' . json_encode($differences) . PHP_EOL);
        exit(1);
    }
}
