<?php

// Parity corpus for utopia-php/database's Id, Role, Permission and DateTime: diff its stdout on PHP
// and on the extension (_APP_EXTENSION_LIBRARIES=database).

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Database\DateTime;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\PermissionType;
use Utopia\Database\Role;

const CLASSES = [Id::class, Role::class, Permission::class, DateTime::class];

$internal = (new ReflectionClass(Role::class))->isInternal();
fwrite(STDERR, 'Utopia\\Database: ' . ($internal ? 'utopia extension' : 'PHP') . PHP_EOL);
// A run meant for the extension that gets the PHP classes would compare PHP with PHP and pass.
$requested = in_array('database', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' database, but the other implementation loaded' . PHP_EOL);
    exit(1);
}
foreach (CLASSES as $class) {
    if ((new ReflectionClass($class))->isInternal() !== $internal) {
        fwrite(STDERR, $class . ' is not from the same implementation as Role' . PHP_EOL);
        exit(1);
    }
}
if ((new ReflectionClass(PermissionType::class))->isInternal() || (new ReflectionClass(DatabaseException::class))->isInternal()) {
    fwrite(STDERR, 'PermissionType and Utopia\\Database\\Exception stay PHP' . PHP_EOL);
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
    if ($value instanceof DateTimeInterface) {
        return ['date' => $value::class, 'value' => $value->format('Y-m-d H:i:s.u e P')];
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

/** An exception's message, without the file and line of a call the package made itself (README). */
function message(Throwable $e): string
{
    return preg_replace('/, called in \S+\/Database\/Permission\.php on line \d+$/', '', $e->getMessage());
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
        if ($e instanceof DatabaseException) {
            $result['state'] = $e->state;
        }
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
    return preg_replace('/#\d+ /', '# ', $dump);
}

/** What `php -r $code` prints and exits with, in a child process loading the same autoloader. */
function child(string $code, bool $strict = false): array
{
    $prelude = ($strict ? 'declare(strict_types=1); ' : '') . 'require ' . var_export(__DIR__ . '/../../../vendor/autoload.php', true) . '; use Utopia\Database\{DateTime, Id, Permission, PermissionType, Role};';
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

// 1. The classes, as reflection shows them. `isInternal()`, the file and the doc comments differ
// by construction.
foreach (CLASSES as $name) {
    $class = new ReflectionClass($name);
    check('class ' . $name, fn () => [
        'abstract' => $class->isAbstract(), 'final' => $class->isFinal(), 'parent' => ($class->getParentClass() ?: null)?->getName(),
        'interfaces' => $class->getInterfaceNames(), 'constructor' => $class->getConstructor()?->getName(),
        'instantiable' => $class->isInstantiable(), 'cloneable' => $class->isCloneable(), 'modifiers' => Reflection::getModifierNames($class->getModifiers()),
        'constants' => $class->getConstants(), 'static' => $class->getStaticProperties(), 'defaults' => $class->getDefaultProperties(),
        'methods' => get_class_methods($name), 'short' => $class->getShortName(), 'namespace' => $class->getNamespaceName(),
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

/** Strings every string parameter is tried with. */
$strings = ['', '0', '00', ' ', 'a', '123', 'user', 'any', 'abc-DEF_09.x', "\0", "a\0b", 'héllo', "\xff\xfe", str_repeat('x', 300), '"', '(")', ':', '/', 'a:b', 'a/b', 'a:b/c'];
/** Values that are not strings, coerced (or refused) as a `string` parameter takes them. */
$scalars = [
    'int' => 123, 'zero' => 0, 'negative' => -5, 'float' => 1.5, 'float int' => 2.0, 'float exp' => 1.0E+25, 'true' => true, 'false' => false,
    'null' => null, 'array' => ['a'], 'object' => new stdClass(), 'stringable' => new class () {
        public function __toString(): string
        {
            return 'team:5/admin';
        }
    },
];

// 2. Id -----------------------------------------------------------------------------------------

foreach ($strings as $s) {
    check('Id::custom ' . bin2hex($s), fn () => Id::custom($s));
}
foreach ($scalars as $label => $value) {
    check("Id::custom $label", fn () => Id::custom($value));
}
check('Id::custom named', fn () => Id::custom(id: 'named'));
check('Id::custom no argument', fn () => Id::custom());
check('Id::custom extra arguments', fn () => Id::custom('a', 'b', 'c'));
check('Id::custom strict int', fn () => child('echo Id::custom(5);', true));
check('Id::custom strict string', fn () => child('echo Id::custom("5");', true));

/** What can be said of an ID: its length, whether it is hex, and its time prefix's shape. */
function described(string $id): array
{
    return [strlen($id), ctype_xdigit($id), strlen($id) >= 13 && ctype_xdigit(substr($id, 0, 13))];
}

foreach ([-100, -1, 0, 1, 2, 3, 6, 7, 8, 13, 20, 64, 101, 1000] as $padding) {
    check("Id::unique $padding", fn () => described(Id::unique($padding)));
}
check('Id::unique default', fn () => described(Id::unique()));
check('Id::unique named', fn () => described(Id::unique(padding: 3)));
foreach (['1.0' => 1.0, '"5"' => '5', 'true' => true, 'null' => null, '"x"' => 'x', '1.5' => 1.5, 'array' => []] as $label => $value) {
    check("Id::unique $label", fn () => described(Id::unique($value)));
}
check('Id::unique strict string', fn () => child('echo Id::unique("5");', true));
check('Id::unique time order and uniqueness', function () {
    $ids = [];
    $start = substr(uniqid(), 0, 13);
    for ($i = 0; $i < 2000; $i++) {
        $ids[] = Id::unique();
    }
    $end = uniqid();
    $prefixes = array_map(fn ($id) => substr($id, 0, 13), $ids);
    $sorted = $prefixes;
    sort($sorted, SORT_STRING);

    return [
        'distinct' => count(array_unique($ids)) === count($ids),
        'distinct prefixes' => count(array_unique($prefixes)) === count($prefixes),
        'ordered' => $sorted === $prefixes,
        'after start' => $prefixes[0] >= $start,
        'before end' => end($prefixes) <= $end,
        'padding spread' => count(array_unique(array_map(fn ($id) => substr($id, 13), $ids))) > 1900,
    ];
});
check('Id::unique padding is random hex', function () {
    $seen = [];
    for ($i = 0; $i < 4000; $i++) {
        foreach (str_split(substr(Id::unique(1), 13)) as $c) {
            $seen[$c] = true;
        }
    }
    ksort($seen);

    return implode('', array_keys($seen));
});

// 3. Role ---------------------------------------------------------------------------------------

/** Role strings, valid and not. */
$roles = [
    'any', 'guests', 'users', 'user', 'team', 'member', 'label', 'other', '', ':', '/', ':/', '/:', '::', '//',
    'user:123', 'user:', 'user:0', 'user:00', 'user:abc:def', 'user:abc:def/x', 'user:123/verified', 'user:123/unverified', 'user:123/0', 'user:123/',
    'user:/x', 'user:0/x', 'user:0/0', 'users/verified', 'users/', 'users/0', 'users/a/b', 'team/a/b', 'team/', 'team:abc/x/y', 'team:abc/',
    'team:123/owner', 'team:123/project-456-owner', 'team:123/456', 'member:abc', 'label:vip', 'label:vip/x', 'a:b:c', 'a/b:c', 'a/b:c/d',
    '/x', ':x', 'x/', 'x:', 'Ü:ß/é', "user:\xff/\xfe", "us\0er:1/2", 'read("any")', '"', 'team:1/2/3', 'team:1//', 'team://x', 'a:/',
];
foreach ($roles as $role) {
    check('Role::parse ' . bin2hex($role), function () use ($role) {
        $parsed = Role::parse($role);

        return [$parsed, $parsed->getRole(), $parsed->getIdentifier(), $parsed->getDimension(), $parsed->toString()];
    });
}
foreach ($scalars as $label => $value) {
    check("Role::parse $label", fn () => Role::parse($value));
}
check('Role::parse no argument', fn () => Role::parse());
check('Role::parse strict', fn () => child('var_dump(Role::parse(5));', true));

$factories = [
    'user' => fn (...$a) => Role::user(...$a), 'users' => fn (...$a) => Role::users(...$a), 'team' => fn (...$a) => Role::team(...$a),
    'label' => fn (...$a) => Role::label(...$a), 'member' => fn (...$a) => Role::member(...$a), 'any' => fn (...$a) => Role::any(...$a),
    'guests' => fn (...$a) => Role::guests(...$a),
];
$arguments = [[], ['123'], ['0'], [''], ['123', 'verified'], ['123', '0'], ['0', '0'], ['', 'x'], ['a:b', 'c/d'], [5, 6], [null], [[]], ['x', null], ["\xff", "\xfe"], ['a', 'b', 'c']];
foreach ($factories as $name => $factory) {
    foreach ($arguments as $args) {
        check("Role::$name " . json_encode(array_map(fn ($a) => is_string($a) ? bin2hex($a) : $a, $args)), function () use ($factory, $args) {
            $role = $factory(...$args);

            return [$role, $role->toString(), $role->getRole(), $role->getIdentifier(), $role->getDimension()];
        });
    }
}
check('Role named', fn () => [Role::user(status: 'v', identifier: 'i'), Role::team(identifier: 't'), Role::users(status: 's')]);
check('Role unknown named', fn () => Role::user('a', other: 1));
check('Role strict int', fn () => child('var_dump(Role::user(1));', true));
check('Role static on instance', fn () => (new Role('x'))->any());
foreach ([[], ['any'], ['user', '1'], ['user', '1', 'v'], ['user', '0', '0'], ['', '', ''], [1, 2, 3], [null], ['a', []], ['a', 'b', 'c', 'd']] as $args) {
    check('new Role ' . json_encode($args), function () use ($args) {
        $role = new Role(...$args);

        return [$role, $role->toString(), $role->getRole(), $role->getIdentifier(), $role->getDimension()];
    });
}
check('new Role named', fn () => new Role(dimension: 'd', role: 'r'));
check('Role construct twice', function () {
    $role = new Role('a', 'b', 'c');
    $role->__construct('x');

    return [$role, $role->toString()];
});
check('Role var_dump', function () {
    ob_start();
    var_dump(Role::team('t', 'owner'), Role::any());

    return withoutHandles(ob_get_clean());
});
check('Role print_r var_export json', fn () => [print_r(Role::user('1', 'v'), true), var_export(Role::user('1'), true), json_encode(Role::user('1')), (array) Role::team('x')]);
check('Role serialize', fn () => [serialize(Role::team('t', 'd')), unserialize(serialize(Role::team('t', 'd')))->toString(), unserialize('O:21:"Utopia\Database\Role":1:{s:27:"' . "\0Utopia\\Database\\Role\0role" . '";s:3:"abc";}')]);
check('Role comparison', fn () => [Role::any() == Role::any(), Role::any() == Role::guests(), Role::user('1') == new Role('user', '1'), Role::user('1') === Role::user('1'), Role::user('1') == Role::user('1', '')]);
check('Role clone', function () {
    $role = Role::team('t', 'd');
    $clone = clone $role;

    return [$clone, $clone == $role, $clone->toString()];
});
check('Role without constructor', function () {
    $role = (new ReflectionClass(Role::class))->newInstanceWithoutConstructor();

    return array_map(fn ($m) => outcome(fn () => $role->$m()), ['toString', 'getRole', 'getIdentifier', 'getDimension']);
});
check('Role properties through reflection', function () {
    $role = Role::user('1');
    $property = new ReflectionProperty(Role::class, 'identifier');
    $property->setValue($role, '0');
    $dimension = new ReflectionProperty(Role::class, 'dimension');

    return [$role->toString(), $property->getValue($role), outcome(fn () => $dimension->setValue($role, 5)), $role->getDimension(), Permission::read($role)];
});
check('Role property access', fn () => [outcome(fn () => Role::any()->role), outcome(fn () => Role::any()->identifier = 'x')]);
check('Role dynamic property', function () {
    $role = Role::any();
    $role->extra = 1;

    return [$role, $role->toString()];
});

class RenamedRole extends Role
{
    public function __construct(public string $tag = 'tag')
    {
        parent::__construct('team', 'renamed', 'admin');
    }

    public function getRole(): string
    {
        return 'custom';
    }

    public function toString(): string
    {
        return 'custom:' . $this->getIdentifier();
    }
}

class NumericRole extends Role
{
    public function getIdentifier(): string
    {
        return '0';
    }

    #[\ReturnTypeWillChange]
    public function getDimension(): string
    {
        return '7';
    }
}

class UnconstructedRole extends Role
{
    public function __construct()
    {
    }
}

check('Role subclass', function () {
    $role = new RenamedRole();

    return [$role, $role->toString(), $role->getRole(), $role->getIdentifier(), $role->getDimension(), $role instanceof Role, RenamedRole::any(), RenamedRole::parse('a:b')];
});
check('Role subclass in permissions', fn () => array_map(fn ($kind) => Permission::$kind(new RenamedRole()), ['read', 'create', 'update', 'delete', 'write']));
check('Role numeric subclass in permissions', fn () => [Permission::read(new NumericRole('user', '5', '1')), (new NumericRole('a', 'b', 'c'))->toString()]);
check('Role unconstructed subclass', fn () => [outcome(fn () => (new UnconstructedRole())->toString()), outcome(fn () => Permission::read(new UnconstructedRole()))]);

// 4. Permission ---------------------------------------------------------------------------------

$kinds = ['read', 'create', 'update', 'delete', 'write'];
$roleObjects = [
    'any' => Role::any(), 'guests' => Role::guests(), 'users' => Role::users(), 'users verified' => Role::users('verified'),
    'users 0' => Role::users('0'), 'user' => Role::user('123'), 'user 0' => Role::user('0'), 'user verified' => Role::user('123', 'verified'),
    'user 0 0' => Role::user('0', '0'), 'team' => Role::team('t1'), 'team owner' => Role::team('t1', 'owner'), 'team empty owner' => Role::team('', 'owner'),
    'member' => Role::member('m1'), 'label' => Role::label('vip'), 'other' => new Role('other', 'x', 'y'), 'empty' => new Role(''),
    'quote' => new Role('a"b', ')', '("'), 'bytes' => new Role("\xff", "\x00", "\xfe"), 'long' => Role::user(str_repeat('a', 1000)),
];
foreach ($kinds as $kind) {
    foreach ($roleObjects as $label => $role) {
        check("Permission::$kind $label", fn () => Permission::$kind($role));
    }
    check("Permission::$kind no argument", fn () => Permission::$kind());
    check("Permission::$kind string", fn () => Permission::$kind('any'));
    check("Permission::$kind null", fn () => Permission::$kind(null));
    check("Permission::$kind object", fn () => Permission::$kind(new stdClass()));
    check("Permission::$kind named", fn () => Permission::$kind(role: Role::any()));
}

$permissionStrings = [
    'read("any")', 'read("users")', 'read("user:123")', 'read("team:123/admin")', 'read("guests")', 'create("any")', 'update("user:1")',
    'delete("team:t/owner")', 'write("any")', 'write("user:1/verified")', 'read("users/verified")', 'read("users/unverified")', 'read', 'read(',
    'read(("any")', 'read("users/un/verified")', 'read("users/")', 'execute("any")', 'READ("any")', ' read("any")', 'read("any"', 'read(any)',
    'read("any")")', 'read("a")b")', 'read("")', 'read("user:")', 'read("user:0")', 'read("user:0/0")', 'read("user:1/0")', 'read("users/0")',
    'read("user:a:b")', 'read("user:a:b/c")', 'read("user:a/b/c")', 'read("a/b:c")', 'read("("x")', 'read("x")("y")', '', '("', 'read("é")',
    "read(\"\xff:\xfe/\xfd\")", "read(\"a\0b\")", 'read("any") ', 'read("x")suffix', 'read(" any ")', 'read(":")', 'read("/")', 'read(":/")',
    'read("/:")', 'read("team:1/2/3")', 'write', 'create("team:1//")', '0("any")', 'read("label:vip")', 'read("member:m")',
];
foreach ($permissionStrings as $string) {
    check('Permission::parse ' . bin2hex($string), function () use ($string) {
        $parsed = Permission::parse($string);

        return [$parsed, $parsed->getPermission(), $parsed->getRole(), $parsed->getIdentifier(), $parsed->getDimension(), $parsed->toString()];
    });
}
foreach ($scalars as $label => $value) {
    check("Permission::parse $label", fn () => Permission::parse($value));
}
check('Permission::parse no argument', fn () => Permission::parse());

// Every role string of 0 to 5 characters from a small alphabet, under every type (seeded).
mt_srand(15);
$alphabet = ['a', 'u', ':', '/', '0', '"', ')', '(', 'x'];
for ($i = 0; $i < 3000; $i++) {
    $length = mt_rand(0, 7);
    $role = '';
    for ($j = 0; $j < $length; $j++) {
        $role .= $alphabet[mt_rand(0, count($alphabet) - 1)];
    }
    $kind = $kinds[mt_rand(0, 4)];
    $string = mt_rand(0, 9) === 0 ? $role : "$kind(\"$role\")";
    check('generated Role::parse ' . bin2hex($role), fn () => Role::parse($role)->toString());
    check('generated Permission::parse ' . bin2hex($string), function () use ($string) {
        $parsed = Permission::parse($string);

        return [$parsed->getPermission(), $parsed->getRole(), $parsed->getIdentifier(), $parsed->getDimension(), $parsed->toString()];
    });
}

foreach ([['read', 'any'], ['read', 'user', '1'], ['write', 'team', 't', 'owner'], ['custom', 'x'], ['', ''], ['read', 'user', '0', '0'], [1, 2, 3, 4], ['read'], [], ['read', null], ['a', 'b', 'c', 'd', 'e']] as $args) {
    check('new Permission ' . json_encode($args), function () use ($args) {
        $permission = new Permission(...$args);

        return [$permission, $permission->toString(), $permission->getPermission(), $permission->getRole(), $permission->getIdentifier(), $permission->getDimension()];
    });
}
check('new Permission named', fn () => new Permission(role: 'r', permission: 'p', dimension: 'd'));
check('Permission var_dump', function () {
    ob_start();
    var_dump(Permission::parse('update("team:t/owner")'));

    return withoutHandles(ob_get_clean());
});
check('Permission serialize', fn () => [serialize(Permission::parse('read("user:1")')), unserialize(serialize(Permission::parse('read("user:1")')))->toString()]);
check('Permission comparison and clone', function () {
    $a = Permission::parse('read("any")');
    $b = clone $a;

    return [$a == $b, $a == Permission::parse('read("users")'), $a == new Permission('read', 'any'), $b->toString()];
});
check('Permission without constructor', function () {
    $permission = (new ReflectionClass(Permission::class))->newInstanceWithoutConstructor();

    return array_map(fn ($m) => outcome(fn () => $permission->$m()), ['toString', 'getPermission', 'getRole', 'getIdentifier', 'getDimension']);
});
check('Permission role replaced through reflection', function () {
    $permission = Permission::parse('read("any")');
    (new ReflectionProperty(Permission::class, 'role'))->setValue($permission, new RenamedRole());

    return [$permission->toString(), $permission->getRole(), $permission->getIdentifier(), $permission->getDimension()];
});
check('Permission static aggregates', fn () => (new ReflectionProperty(Permission::class, 'aggregates'))->getValue());

class TaggedPermission extends Permission
{
    public function __construct(public string $tag = 'tag')
    {
        parent::__construct('read', 'team', 'x', 'y');
    }
}

check('Permission subclass', fn () => [$p = new TaggedPermission(), $p->toString(), $p->getRole(), TaggedPermission::parse('read("any")'), TaggedPermission::read(Role::any())]);

$aggregates = [
    'null' => [null], 'empty' => [[]], 'write' => [['write("any")']], 'write allowed update delete' => [['write("any")'], [PermissionType::Update, PermissionType::Delete]],
    'write allowed none' => [['write("any")'], []], 'write allowed write' => [['write("any")'], [PermissionType::Write]],
    'write allowed read' => [['write("any")', 'read("any")'], [PermissionType::Read]],
    'mixed' => [['read("any")', 'read("user:123")', 'read("user:123")', 'write("user:123")', 'update("user:123")', 'delete("user:123")']],
    'mixed allowed' => [['read("any")', 'write("user:1/v")', 'create("user:1/v")'], [PermissionType::Create, PermissionType::Read, PermissionType::Update, PermissionType::Delete]],
    'duplicates allowed' => [['write("any")'], [PermissionType::Create, PermissionType::Create, PermissionType::Delete]],
    'keyed' => [['x' => 'read("any")', 5 => 'write("users")', 'y' => 'read("any")']], 'normalized' => [['read("user:0")', 'read("user")', 'read("user:0/0")']],
    'invalid' => [['read("any")', 'nope']], 'invalid type' => [['read("any")', 'execute("any")']], 'invalid dimension' => [['write("a/b/c")']],
    'int' => [[123]], 'invalid then array' => [['nope', []]], 'array then invalid' => [[[], 'nope']], 'null element' => [['read("any")', null]],
    'stringable' => [[new class () {
        public function __toString(): string
        {
            return 'write("team:1/owner")';
        }
    }]],
    'float' => [[1.5]], 'bool' => [[true]], 'allowed strings' => [['read("any")'], ['create']], 'allowed strings with null' => [null, ['create']],
    'allowed object' => [['write("any")'], [new stdClass()]], 'allowed int' => [[], [1]], 'allowed null' => [[], [null]],
    'not an array' => ['read("any")'], 'allowed not an array' => [[], 'create'], 'allowed null arg' => [[], null],
    'bytes' => [["write(\"\xff:\xfe\")", "read(\"\xff\")"]], 'reference' => (function () {
        $list = ['write("any")'];
        $ref = &$list[0];

        return [$list];
    })(),
    'long' => [array_map(fn ($i) => ($i % 2 ? 'write' : 'read') . '("user:' . ($i % 50) . '")', range(1, 300))],
];
foreach ($aggregates as $label => $args) {
    check("Permission::aggregate $label", fn () => Permission::aggregate(...$args));
}
check('Permission::aggregate no argument', fn () => Permission::aggregate());
check('Permission::aggregate named', fn () => Permission::aggregate(allowed: [PermissionType::Read], permissions: ['write("any")', 'read("x")']));
check('Permission::aggregate default from reflection', fn () => (new ReflectionMethod(Permission::class, 'aggregate'))->getParameters()[1]->getDefaultValue());

// 5. DateTime -----------------------------------------------------------------------------------

check('DateTime new', fn () => new DateTime());
check('DateTime static formats', fn () => (new ReflectionClass(DateTime::class))->getStaticProperties());
check('DateTime::now shape', fn () => [preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/', DateTime::now()), abs(strtotime(DateTime::now()) - time()) <= 1]);
check('DateTime::now order', function () {
    $previous = DateTime::now();
    $ordered = true;
    for ($i = 0; $i < 1000; $i++) {
        $now = DateTime::now();
        $ordered = $ordered && $now >= $previous;
        $previous = $now;
    }

    return $ordered;
});
check('DateTime::now matches the clock', function () {
    for ($i = 0; $i < 20; $i++) {
        $before = (new \DateTime())->format('Y-m-d H:i:s.v');
        $now = DateTime::now();
        $after = (new \DateTime())->format('Y-m-d H:i:s.v');
        if ($now < $before || $now > $after) {
            return [$before, $now, $after];
        }
    }

    return true;
});

$dates = [
    'utc' => new \DateTime('2024-01-02 03:04:05.678901', new DateTimeZone('UTC')),
    'offset' => new \DateTime('2024-01-02 03:04:05.6+05:30'),
    'zone' => new \DateTime('2024-03-10 01:59:59.999', new DateTimeZone('America/New_York')),
    'abbr' => new \DateTime('2024-07-01 12:00:00 EST'),
    'epoch' => new \DateTime('@0'),
    'negative' => new \DateTime('-0044-03-15 12:00:00'),
    'year 5 digits' => new \DateTime('+12345-01-01 00:00:00'),
    'year 0' => new \DateTime('0000-01-01 00:00:00'),
    'microseconds' => new \DateTime('2024-01-01 00:00:00.999999'),
    'leap' => new \DateTime('2024-02-29 23:59:59.5'),
    'modified' => (new \DateTime('2024-01-31 10:00:00'))->modify('+1 month'),
    'timestamp' => (new \DateTime())->setTimestamp(1700000000),
    'timezone set' => (new \DateTime('2024-06-01 12:00:00', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Kolkata')),
];
foreach ($dates as $label => $date) {
    check("DateTime::format $label", fn () => DateTime::format($date));
}

class FrozenDateTime extends \DateTime
{
    public function format(string $format): string
    {
        return 'frozen ' . $format;
    }

    public function add(DateInterval $interval): static
    {
        parent::add($interval);
        parent::add($interval);

        return $this;
    }
}

class PlainDateTime extends \DateTime
{
}

class UnconstructedDateTime extends \DateTime
{
    public function __construct()
    {
    }
}

check('DateTime::format subclass', fn () => [DateTime::format(new FrozenDateTime('2024-01-01')), DateTime::format(new PlainDateTime('2024-01-01 01:02:03.4'))]);
check('DateTime::format unconstructed', fn () => DateTime::format(new UnconstructedDateTime()));
check('DateTime::format without constructor', fn () => DateTime::format((new ReflectionClass(\DateTime::class))->newInstanceWithoutConstructor()));
check('DateTime::format immutable', fn () => DateTime::format(new DateTimeImmutable('2024-01-01')));
check('DateTime::format string', fn () => DateTime::format('2024-01-01'));
check('DateTime::format no argument', fn () => DateTime::format());

foreach ([0, 1, -1, 59, 60, 3600, 86400, -86400, 86400 * 400, 1000000000, -1000000000, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN + 1] as $seconds) {
    foreach (['utc' => 'UTC', 'zone' => 'America/New_York', 'offset' => '+05:30'] as $label => $zone) {
        check("DateTime::addSeconds $label $seconds", function () use ($seconds, $zone) {
            $date = new \DateTime('2024-03-10 01:59:59.250', new DateTimeZone($zone));
            $result = DateTime::addSeconds($date, $seconds);

            return [$result, $date->format('Y-m-d H:i:s.u e')];
        });
    }
}
check('DateTime::addSeconds subclass', function () {
    $date = new FrozenDateTime('2024-01-01 00:00:00');
    $result = DateTime::addSeconds($date, 10);

    return [$result, $date->getTimestamp()];
});
check('DateTime::addSeconds plain subclass', fn () => DateTime::addSeconds(new PlainDateTime('2024-01-01 00:00:00'), 10));
check('DateTime::addSeconds coerced', fn () => [DateTime::addSeconds(new \DateTime('2024-01-01'), '5'), DateTime::addSeconds(new \DateTime('2024-01-01'), 2.0), DateTime::addSeconds(new \DateTime('2024-01-01'), true)]);
check('DateTime::addSeconds float', fn () => DateTime::addSeconds(new \DateTime('2024-01-01'), 2.5));
check('DateTime::addSeconds string', fn () => DateTime::addSeconds(new \DateTime('2024-01-01'), 'x'));
check('DateTime::addSeconds one argument', fn () => DateTime::addSeconds(new \DateTime('2024-01-01')));
check('DateTime::addSeconds immutable', fn () => DateTime::addSeconds(new DateTimeImmutable('2024-01-01'), 1));
check('DateTime::addSeconds unconstructed', fn () => DateTime::addSeconds(new UnconstructedDateTime(), 1));
check('DateTime::addSeconds strict', fn () => child('echo DateTime::addSeconds(new \DateTime("2024-01-01"), "5");', true));

$values = [
    '2024-01-02 03:04:05.678', '2024-01-02 03:04:05', '2024-01-02 03:04:05.6', '2024-01-02 03:04:05.123456', '2024-01-02T03:04:05.678+02:00',
    '2024-01-02 03:04:05.678+02:00', '2024-01-02 03:04:05.678 Europe/Paris', '2024-01-02 03:04:05 EST', '2024-01-02', 'tomorrow', '@1700000000',
    '@1700000000.5', '1 January 2024', 'next monday', 'not a date', ' ', '0', '2024-13-01 00:00:00', '2024-02-30 00:00:00', '2023-02-29 00:00:00',
    '2024-01-01 24:00:00', '2024-01-01 23:59:60', '0000-00-00 00:00:00', '9999-12-31 23:59:59.999', '-0001-01-01', '+12345-01-01', '2024-01-01 00:00:00.',
    "2024-01-01\x00", "\xff", '2024-01-01T00:00:00Z', '2024-01-01T00:00:00.000Z', '2024-06-15 12:00:00 America/New_York', 'Sat, 01 Jun 2024 10:00:00 GMT',
    '2024-01-01 00:00:00 +14:00', '2024-01-01 00:00:00 -12:00', 'yesterday noon', 'last day of february', '10/11/12', '2024-W01-1', '1700000000',
];
foreach ($values as $value) {
    check('DateTime::setTimezone ' . bin2hex($value), fn () => DateTime::setTimezone($value));
    check('DateTime::formatTz ' . bin2hex($value), fn () => preg_replace('/^' . date('Y-m-d') . '/', 'TODAY', DateTime::formatTz($value) ?? 'null'));
}
foreach (['now', ''] as $value) {
    check('DateTime::setTimezone current ' . bin2hex($value), fn () => abs(strtotime(DateTime::setTimezone($value)) - time()) <= 1);
    check('DateTime::formatTz current ' . bin2hex($value), fn () => abs(strtotime(DateTime::formatTz($value)) - time()) <= 1);
}
check('DateTime::formatTz null', fn () => DateTime::formatTz(null));
check('DateTime::formatTz no argument', fn () => DateTime::formatTz());
check('DateTime::formatTz int', fn () => DateTime::formatTz(5));
check('DateTime::formatTz array', fn () => DateTime::formatTz([]));
check('DateTime::setTimezone null', fn () => DateTime::setTimezone(null));
check('DateTime::setTimezone no argument', fn () => DateTime::setTimezone());
check('DateTime::formatTz stored dates', function () {
    $out = [];
    foreach ([1970, 1999, 2000, 2024, 2100, 9999] as $year) {
        foreach (range(1, 12) as $month) {
            foreach ([1, 28, 29, 30, 31] as $day) {
                $value = sprintf('%04d-%02d-%02d 13:07:59.042', $year, $month, $day);
                $out[] = DateTime::formatTz($value);
            }
        }
    }

    return md5(implode(',', $out));
});

check('DateTime::nowAfter null', fn () => preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/', DateTime::nowAfter(null)));
check('DateTime::nowAfter future', fn () => DateTime::nowAfter('2999-01-01 00:00:00.123'));
check('DateTime::nowAfter future end of second', fn () => DateTime::nowAfter('2999-12-31 23:59:59.999'));
check('DateTime::nowAfter future with offset', fn () => DateTime::nowAfter('2999-01-01 00:00:00.123+02:00'));
check('DateTime::nowAfter past', fn () => DateTime::nowAfter('2000-01-01 00:00:00.000') > '2000-01-01 00:00:00.000');
check('DateTime::nowAfter invalid', fn () => DateTime::nowAfter('not a date'));
check('DateTime::nowAfter empty', fn () => DateTime::nowAfter('') > '2000');
check('DateTime::nowAfter no argument', fn () => DateTime::nowAfter());
check('DateTime::nowAfter equal', function () {
    $now = DateTime::now();
    $after = DateTime::nowAfter($now);

    return $after > $now;
});

// With another default timezone and with the formats changed, the classes go PHP's way.
foreach (['America/New_York', 'Asia/Kathmandu', 'UTC'] as $zone) {
    date_default_timezone_set($zone);
    check("timezone $zone now", fn () => [preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/', DateTime::now()), substr(DateTime::now(), 0, 13) === (new \DateTime())->format('Y-m-d H')]);
    foreach (['2024-01-02 03:04:05.678', '2024-01-02T03:04:05.678+02:00', 'not a date', '2024-07-01 12:00:00 EST', '@0'] as $value) {
        check("timezone $zone setTimezone $value", fn () => DateTime::setTimezone($value));
        check("timezone $zone formatTz $value", fn () => DateTime::formatTz($value));
    }
    check("timezone $zone nowAfter", fn () => DateTime::nowAfter('2999-01-01 00:00:00.123'));
    check("timezone $zone nowAfter invalid", fn () => preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/', DateTime::nowAfter('x')));
}

class ReformattingDateTime extends DateTime
{
    public static function use(string $db, string $tz): void
    {
        self::$formatDb = $db;
        self::$formatTz = $tz;
    }
}

ReformattingDateTime::use('Y/m/d', 'c');
check('formats changed', fn () => [
    DateTime::format(new \DateTime('2024-01-02 03:04:05')), DateTime::addSeconds(new \DateTime('2024-01-02 03:04:05'), 5),
    DateTime::setTimezone('2024-01-02 03:04:05+01:00'), DateTime::formatTz('2024-01-02 03:04:05'), DateTime::nowAfter('2999-01-01 00:00:00.123'),
    strlen(DateTime::now()), (new ReflectionClass(DateTime::class))->getStaticProperties(),
]);
ReformattingDateTime::use('Y-m-d H:i:s.v', 'Y-m-d\TH:i:s.vP');
check('formats restored', fn () => [DateTime::format(new \DateTime('2024-01-02 03:04:05')), DateTime::formatTz('2024-01-02 03:04:05')]);

// 6. Known differences --------------------------------------------------------------------------
// Asserted on the extension only, so a fix shows up here (and the README must change with it).

if ($internal) {
    $differences = [];
    // A TypeError for an element of aggregate()'s $permissions does not name Permission.php.
    try {
        Permission::aggregate([[]]);
    } catch (TypeError $e) {
        $differences['aggregate TypeError'] = !str_contains($e->getMessage(), 'called in');
    }
    // An exception names the caller's file and line, since the classes have no PHP source.
    try {
        Permission::parse('x');
    } catch (DatabaseException $e) {
        $differences['file'] = $e->getFile() === __FILE__;
    }
    // A closure cannot be bound to the scope of an internal class.
    $differences['bind'] = @Closure::bind(fn () => 1, Role::any(), Role::class) === null;
    // Permission::$aggregates is read only by the class: aggregate() expands `write` as declared.
    $differences['aggregates'] = (function () {
        $property = new ReflectionProperty(Permission::class, 'aggregates');
        $property->setValue(null, []);
        $result = Permission::aggregate(['write("any")']);
        $property->setValue(null, ['write' => ['create', 'update', 'delete']]);

        return $result === ['create("any")', 'update("any")', 'delete("any")'];
    })();
    if (in_array(false, $differences, true)) {
        fwrite(STDERR, 'a known difference changed: ' . json_encode($differences) . PHP_EOL);
        exit(1);
    }
}
