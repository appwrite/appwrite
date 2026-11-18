<?php

// Parity corpus for the password hashes of utopia-php/auth: Hash, Argon2, Bcrypt, MD5, PHPass,
// Plaintext, Scrypt, ScryptModified and Sha. Run it on the PHP classes and on the utopia
// extension's (_APP_EXTENSION_LIBRARIES=auth), and diff stdout: every line must match. Stderr
// names the implementation that ran.
//
// Hashes with a random salt cannot match line for line: each run's are checked with PHP's own
// password_verify() and crypt() (the extension's hashes accepted by PHP), the class's verify()
// is run over hashes PHP made (PHP's hashes accepted by the extension), and a subclass whose
// getRandomBytes() is fixed makes PHPass deterministic.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Auth\Hash;
use Utopia\Auth\Hashes\Argon2;
use Utopia\Auth\Hashes\Bcrypt;
use Utopia\Auth\Hashes\MD5;
use Utopia\Auth\Hashes\PHPass;
use Utopia\Auth\Hashes\Plaintext;
use Utopia\Auth\Hashes\Scrypt;
use Utopia\Auth\Hashes\ScryptModified;
use Utopia\Auth\Hashes\Sha;

const CLASSES = [Hash::class, Argon2::class, Bcrypt::class, MD5::class, PHPass::class, Plaintext::class, Scrypt::class, ScryptModified::class, Sha::class];

$internal = (new ReflectionClass(Hash::class))->isInternal();
fwrite(STDERR, 'Utopia\\Auth\\Hash: ' . ($internal ? 'utopia extension' : 'PHP') . PHP_EOL);
// A run meant for the extension that gets the PHP classes would compare PHP with PHP and pass.
$requested = in_array('auth', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' auth, but the other implementation loaded' . PHP_EOL);
    exit(1);
}
foreach (CLASSES as $class) {
    if ((new ReflectionClass($class))->isInternal() !== $internal) {
        fwrite(STDERR, $class . ' is not from the same implementation as Hash' . PHP_EOL);
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
// methods' attributes differ by construction.
foreach (CLASSES as $name) {
    $class = new ReflectionClass($name);
    $constants = [];
    foreach ($class->getReflectionConstants() as $constant) {
        $constants[$constant->getName()] = [exported($constant->getValue()), Reflection::getModifierNames($constant->getModifiers()), (string) $constant->getType(), $constant->getDeclaringClass()->getName()];
    }
    emit('class ' . $name, outcome(fn () => [
        'abstract' => $class->isAbstract(), 'final' => $class->isFinal(), 'parent' => ($class->getParentClass() ?: null)?->getName(),
        'interfaces' => $class->getInterfaceNames(), 'constructor' => $class->getConstructor()?->getName(),
        'instantiable' => $class->isInstantiable(), 'cloneable' => $class->isCloneable(), 'modifiers' => Reflection::getModifierNames($class->getModifiers()),
        'constants' => $constants, 'static' => $class->getStaticProperties(), 'defaults' => exported($class->getDefaultProperties()),
        'subclass of base' => $class->isSubclassOf(Hash::class),
    ]));
    $properties = $class->getProperties();
    usort($properties, fn ($a, $b) => strcmp($a->getName(), $b->getName()));
    foreach ($properties as $property) {
        emit('property ' . $name . '::$' . $property->getName(), outcome(fn () => [
            $property->getDeclaringClass()->getName(), Reflection::getModifierNames($property->getModifiers()), (string) $property->getType(),
            $property->hasDefaultValue(), exported($property->getDefaultValue()), $property->isPromoted(), $property->isReadOnly(), $property->isStatic(),
        ]));
    }
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

/** The passwords every hash is run over: empty, ASCII, UTF-8, not UTF-8, NUL bytes, past bcrypt's 72 bytes, long. */
const PASSWORDS = ['', 'password', 'pässwörd 🔑', "p\xffw\xc3", "p\0w", "\0", '0', ' padded ', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaTAIL'];

/** Random hashes as their shape: what precedes the salt, and the length. */
function shape(mixed $hash): mixed
{
    if (!is_string($hash)) {
        return exported($hash);
    }
    $parts = explode('$', $hash);
    // `$argon2id$v=19$m=..,t=..,p=..$salt$hash`, `$2y$cost$salthash`, `$P$Csalthash`.
    $prefix = match (true) {
        count($parts) >= 6 => implode('$', array_slice($parts, 0, 4)),
        count($parts) === 4 => implode('$', array_slice($parts, 0, 3)),
        default => substr($hash, 0, 4),
    };

    return ['prefix' => $prefix, 'length' => strlen($hash)];
}

/** Hashes $password with $hash and checks it both ways: the class's verify(), and PHP's own functions. */
function crossed(Hash $hash, string $password): array
{
    $hashed = $hash->hash($password);

    return [
        'shape' => shape($hashed),
        'verify' => $hash->verify($password, $hashed),
        'verify other' => $hash->verify($password . 'x', $hashed),
        'password_verify' => password_verify($password, $hashed),
        'crypt' => hash_equals($hashed, crypt($password, $hashed)),
    ];
}

// Options: the base's API, the values the constructors set, and the keys PHP arrays convert.
$keys = ['type', 'cost', '1', '01', '-1', '-0', '1.5', '', ' 1', '9223372036854775807', '9223372036854775808', "nul\0key"];
foreach ([Argon2::class, Bcrypt::class, MD5::class, Plaintext::class, Sha::class] as $class) {
    emit('constructed options ' . $class, outcome(fn () => (new $class())->getOptions()));
    foreach ($keys as $key) {
        emit('option ' . $class . ' ' . bin2hex($key), outcome(function () use ($class, $key) {
            $hash = new $class();
            $same = $hash->setOption($key, 'v') === $hash;

            return [$same, $hash->getOption($key), $hash->getOption($key, 'd'), $hash->getOptions()];
        }));
    }
}
emit('constructed options PHPass', outcome(fn () => array_keys((new PHPass())->getOptions())));
emit('random_state PHPass', outcome(fn () => is_string((new PHPass())->getOption('random_state'))));
emit('constructed options Scrypt', outcome(function () {
    $options = (new Scrypt())->getOptions();

    return [array_keys($options), $options['costCpu'], $options['costMemory'], $options['costParallel'], $options['length'], strlen($options['salt']), ctype_xdigit($options['salt'])];
}));
emit('constructed options ScryptModified', outcome(function () {
    $options = (new ScryptModified())->getOptions();

    return [array_keys($options), $options['type'], strlen(base64_decode($options['salt'], true)), strlen(base64_decode($options['saltSeparator'], true)), strlen(base64_decode($options['signerKey'], true))];
}));
$values = [null, true, false, 0, 1, -1, 1.5, '', 'x', [], [1, 2], ['a' => 1], new stdClass()];
foreach ($values as $i => $value) {
    emit('option value ' . $i, outcome(function () use ($value) {
        $hash = new MD5();
        $hash->setOption('k', $value);

        return [exported($hash->getOption('k')), exported($hash->getOption('k', 'default')), exported($hash->getOption('missing', $value))];
    }));
}
$lists = [[], ['a' => 1, 'b' => 2], ['type' => 'changed'], ['1' => 'one'], [5 => 'five'], ['a' => 1, 0 => 'zero', 'b' => 2], ['x' => null]];
foreach ($lists as $i => $list) {
    emit('setOptions ' . $i, outcome(function () use ($list) {
        $hash = new MD5();
        try {
            $same = $hash->setOptions($list) === $hash;
        } catch (TypeError $e) {
            // PHP adds where Hash.php called setOption(); the rest of the message is the same.
            $message = $e->getMessage();
            $at = strpos($message, ', called in');
            $same = [$e::class, $at === false ? $message : substr($message, 0, $at)];
        }

        return [$same, $hash->getOptions()];
    }));
}
// Arguments of the wrong kind, coerced (the corpus is not strict) or rejected as PHP's methods do.
$calls = [
    'setOption()' => fn () => (new MD5())->setOption(),
    'setOption(k)' => fn () => (new MD5())->setOption('k'),
    'setOption(1, v)' => fn () => (new MD5())->setOption(1, 'v')->getOptions(),
    'setOption(1.5, v)' => fn () => (new MD5())->setOption(1.5, 'v')->getOptions(),
    'setOption(null, v)' => fn () => (new MD5())->setOption(null, 'v'),
    'setOption([], v)' => fn () => (new MD5())->setOption([], 'v'),
    'setOption(true, v)' => fn () => (new MD5())->setOption(true, 'v')->getOptions(),
    'setOption(stringable, v)' => fn () => (new MD5())->setOption(stringable('s'), 'v')->getOptions(),
    'setOptions(string)' => fn () => (new MD5())->setOptions('x'),
    'setOptions(null)' => fn () => (new MD5())->setOptions(null),
    'setOptions()' => fn () => (new MD5())->setOptions(),
    'getOption()' => fn () => (new MD5())->getOption(),
    'getOption(1)' => fn () => (new MD5())->setOption('1', 'v')->getOption(1),
    'getOption(null)' => fn () => (new MD5())->getOption(null),
    'hash()' => fn () => (new MD5())->hash(),
    'hash(1)' => fn () => (new MD5())->hash(1),
    'hash(1.5)' => fn () => (new Sha())->hash(1.5),
    'hash(true)' => fn () => (new Plaintext())->hash(true),
    'hash(null)' => fn () => (new MD5())->hash(null),
    'hash([])' => fn () => (new Sha())->hash([]),
    'hash(stringable)' => fn () => (new Sha())->hash(stringable('s')),
    'verify(x)' => fn () => (new MD5())->verify('x'),
    'verify(1, 1)' => fn () => (new Plaintext())->verify(1, '1'),
    'verify(null, x)' => fn () => (new Argon2())->verify(null, 'x'),
    'verify(x, null)' => fn () => (new Bcrypt())->verify('x', null),
    'verify(x, [])' => fn () => (new PHPass())->verify('x', []),
    'Bcrypt::setCost(10)' => fn () => (new Bcrypt())->setCost(10)->getOptions(),
    'Bcrypt::setCost("10")' => fn () => (new Bcrypt())->setCost('10')->getOptions(),
    'Bcrypt::setCost(10.0)' => fn () => (new Bcrypt())->setCost(10.0)->getOptions(),
    'Bcrypt::setCost(10.5)' => fn () => (new Bcrypt())->setCost(10.5),
    'Bcrypt::setCost("x")' => fn () => (new Bcrypt())->setCost('x'),
    'Bcrypt::setCost(true)' => fn () => (new Bcrypt())->setCost(true),
    'Bcrypt::setCost()' => fn () => (new Bcrypt())->setCost(),
    'Bcrypt::setCost(3)' => fn () => (new Bcrypt())->setCost(3),
    'Bcrypt::setCost(4)' => fn () => (new Bcrypt())->setCost(4)->getOptions(),
    'Bcrypt::setCost(31)' => fn () => (new Bcrypt())->setCost(31)->getOptions(),
    'Bcrypt::setCost(32)' => fn () => (new Bcrypt())->setCost(32),
    'Argon2 setters' => fn () => (new Argon2())->setMemoryCost(2048)->setTimeCost(2)->setThreads(1)->getOptions(),
    'Argon2 setters, any int' => fn () => (new Argon2())->setMemoryCost(-1)->setTimeCost(0)->setThreads(PHP_INT_MAX)->getOptions(),
    'Argon2::setThreads("2")' => fn () => (new Argon2())->setThreads('2')->getOptions(),
    'Argon2::setTimeCost(null)' => fn () => (new Argon2())->setTimeCost(null),
    'Sha::setVersion(sha512)' => fn () => (new Sha())->setVersion('sha512')->getOptions(),
    'Sha::setVersion(SHA512)' => fn () => (new Sha())->setVersion('SHA512'),
    'Sha::setVersion(md5)' => fn () => (new Sha())->setVersion('md5'),
    'Sha::setVersion("")' => fn () => (new Sha())->setVersion(''),
    'Sha::setVersion(1)' => fn () => (new Sha())->setVersion(1),
    'Sha constants' => fn () => [Sha::SHA1, Sha::SHA224, Sha::SHA256, Sha::SHA384, Sha::SHA512, Sha::SHA3_224, Sha::SHA3_256, Sha::SHA3_384, Sha::SHA3_512],
    'Sha::VALID_VERSIONS' => fn () => constant(Sha::class . '::VALID_VERSIONS'),
    'Scrypt::setCpuCost(16)' => fn () => (new Scrypt())->setCpuCost(16)->getOption('costCpu'),
    'Scrypt::setCpuCost(2)' => fn () => (new Scrypt())->setCpuCost(2)->getOption('costCpu'),
    'Scrypt::setCpuCost(1)' => fn () => (new Scrypt())->setCpuCost(1),
    'Scrypt::setCpuCost(12)' => fn () => (new Scrypt())->setCpuCost(12),
    'Scrypt::setCpuCost(0)' => fn () => (new Scrypt())->setCpuCost(0),
    'Scrypt::setCpuCost(-8)' => fn () => (new Scrypt())->setCpuCost(-8),
    'Scrypt::setCpuCost(PHP_INT_MIN)' => fn () => (new Scrypt())->setCpuCost(PHP_INT_MIN),
    'Scrypt::setCpuCost(2^62)' => fn () => (new Scrypt())->setCpuCost(1 << 62)->getOption('costCpu'),
    'Scrypt::setMemoryCost(0)' => fn () => (new Scrypt())->setMemoryCost(0),
    'Scrypt::setMemoryCost(1)' => fn () => (new Scrypt())->setMemoryCost(1)->getOption('costMemory'),
    'Scrypt::setParallelCost(0)' => fn () => (new Scrypt())->setParallelCost(0),
    'Scrypt::setParallelCost(3)' => fn () => (new Scrypt())->setParallelCost(3)->getOption('costParallel'),
    'Scrypt::setLength(15)' => fn () => (new Scrypt())->setLength(15),
    'Scrypt::setLength(16)' => fn () => (new Scrypt())->setLength(16)->getOption('length'),
    'Scrypt::setSalt("")' => fn () => (new Scrypt())->setSalt(''),
    'Scrypt::setSalt("0")' => fn () => (new Scrypt())->setSalt('0'),
    'Scrypt::setSalt("00")' => fn () => (new Scrypt())->setSalt('00')->getOption('salt'),
    'Scrypt::setSalt(0)' => fn () => (new Scrypt())->setSalt(0),
    'Scrypt::setSalt(bytes)' => fn () => (new Scrypt())->setSalt("\0\xff")->getOption('salt'),
    'PHPass::setIterationCount(3)' => fn () => (new PHPass())->setIterationCount(3),
    'PHPass::setIterationCount(4)' => fn () => (new PHPass())->setIterationCount(4)->getOption('iteration_count_log2'),
    'PHPass::setIterationCount(31)' => fn () => (new PHPass())->setIterationCount(31)->getOption('iteration_count_log2'),
    'PHPass::setIterationCount(32)' => fn () => (new PHPass())->setIterationCount(32),
    'PHPass::setPortableHashes(1)' => fn () => (new PHPass())->setPortableHashes(1)->getOption('portable_hashes'),
    'PHPass::setPortableHashes("")' => fn () => (new PHPass())->setPortableHashes('')->getOption('portable_hashes'),
    'PHPass::setPortableHashes([])' => fn () => (new PHPass())->setPortableHashes([]),
    'new MD5(extra)' => fn () => (new MD5('extra'))->getOptions(),
    'new Hash' => fn () => new Hash(),
];
foreach (['setSalt', 'setSaltSeparator', 'setSignerKey'] as $setter) {
    foreach (['', '0', 'AA==', 'AAA=', 'AAAA', 'A', 'A===', '=', '==', 'AA==\n', "AA==\n", "AA==\n\n", "\nAA==", 'AA AA', 'AA==AA', 'a+/9', '-_', "AA\0"] as $value) {
        $calls['ScryptModified::' . $setter . '(' . bin2hex($value) . ')'] = fn () => (new ScryptModified())->{$setter}($value)->getOptions()[['setSalt' => 'salt', 'setSaltSeparator' => 'saltSeparator', 'setSignerKey' => 'signerKey'][$setter]];
    }
}
foreach ($calls as $case => $call) {
    emit($case, outcome($call));
}

// The hashes with no random salt: every output must match.
foreach (PASSWORDS as $password) {
    emit('MD5 ' . bin2hex($password), outcome(fn () => [(new MD5())->hash($password), (new MD5())->verify($password, md5($password)), (new MD5())->verify($password, strtoupper(md5($password)))]));
    emit('Plaintext ' . bin2hex($password), outcome(fn () => [(new Plaintext())->hash($password), (new Plaintext())->verify($password, $password), (new Plaintext())->verify($password, $password . ' ')]));
    foreach (['sha1', 'sha224', 'sha256', 'sha384', 'sha512', 'sha3-224', 'sha3-256', 'sha3-384', 'sha3-512'] as $version) {
        emit('Sha ' . $version . ' ' . bin2hex($password), outcome(function () use ($version, $password) {
            $sha = (new Sha())->setVersion($version);
            $hashed = $sha->hash($password);

            return [$hashed, $sha->verify($password, $hashed), $sha->verify($password, ''), $sha->verify($password . 'x', $hashed)];
        }));
    }
}
// Sha with versions setVersion() refuses but setOption() stores.
foreach (['md5', 'SHA256', 'crc32b', 'whirlpool', 'nope', '', 256, null, 1.5, ['sha1']] as $version) {
    emit('Sha option version ' . json_encode($version), outcome(fn () => [(new Sha())->setOption('version', $version)->hash('pw'), (new Sha())->setOption('version', $version)->verify('pw', hash('sha256', 'pw'))]));
}
emit('Sha without version', outcome(fn () => (new Sha())->setOptions(['version' => null])->hash('pw')));

// Scrypt (PECL scrypt), over its parameters and the options it checks.
$scrypts = [
    'default' => [],
    'small' => ['costCpu' => 16, 'costMemory' => 8, 'costParallel' => 1, 'length' => 16],
    'r1 p2' => ['costCpu' => 32, 'costMemory' => 1, 'costParallel' => 2, 'length' => 32],
    'length 100' => ['costCpu' => 16, 'costMemory' => 4, 'costParallel' => 1, 'length' => 100],
    'binary salt' => ['salt' => "\0\xff salt"],
    'empty salt' => ['salt' => ''],
    'N 2' => ['costCpu' => 2],
    'N 1' => ['costCpu' => 1],
    'N 0' => ['costCpu' => 0],
    'N -2' => ['costCpu' => -2],
    'N 12' => ['costCpu' => 12],
    'r 0' => ['costMemory' => 0],
    'r -1' => ['costMemory' => -1],
    'p 0' => ['costParallel' => 0],
    'p -1' => ['costParallel' => -1],
    'length 15' => ['length' => 15],
    'length 0' => ['length' => 0],
    'length 2^38' => ['length' => 1 << 38],
    'r*p 2^30' => ['costCpu' => 2, 'costMemory' => 1 << 15, 'costParallel' => 1 << 15],
    'r huge' => ['costCpu' => 2, 'costMemory' => 1 << 40, 'costParallel' => 1],
    'N huge' => ['costCpu' => 1 << 62, 'costMemory' => 1, 'costParallel' => 1],
    'salt int' => ['salt' => 5],
    'salt null' => ['salt' => null],
    'cpu string' => ['costCpu' => '16'],
    'cpu float' => ['costCpu' => 16.0],
    'length null' => ['length' => null],
    'checked salt first' => ['salt' => 5, 'costCpu' => 'x'],
];
foreach ($scrypts as $case => $options) {
    foreach (['', 'password', "p\xff\0w"] as $password) {
        emit('Scrypt ' . $case . ' ' . bin2hex($password), outcome(function () use ($options, $password) {
            $scrypt = (new Scrypt())->setOptions(['salt' => 'fixed salt'] + $options);
            $hashed = $scrypt->hash($password);

            return [$hashed, $scrypt->verify($password, $hashed), $scrypt->verify($password . 'x', $hashed), $scrypt->verify($password, strtoupper($hashed))];
        }));
    }
}
emit('Scrypt unset salt', outcome(function () {
    $scrypt = new class () extends Scrypt {
        public function drop(string $key): static
        {
            unset($this->options[$key]);

            return $this;
        }
    };

    return [$scrypt->drop('salt')->hash('pw')];
}));

// ScryptModified (Firebase): its published test vector, and other options.
$firebase = ['salt' => '42xEC+ixf3L2lw==', 'saltSeparator' => 'Bw==', 'signerKey' => 'jxspr8Ki0RYycVU8zykbdLGjFQ3McFUH0uiiTvC8pVMXAn210wjLNmdZJzxUECKbm0QsEmYUSDzZvpjeJ9WmXA=='];
$modified = [
    'firebase' => $firebase,
    'short signer' => ['signerKey' => 'YWJj'] + $firebase,
    'empty separator' => ['saltSeparator' => ''] + $firebase,
    'empty signer' => ['signerKey' => ''] + $firebase,
    'not base64' => ['salt' => '!!not base64!!', 'saltSeparator' => '*', 'signerKey' => '@@'] + $firebase,
    'salt int' => ['salt' => 1] + $firebase,
    'separator null' => ['saltSeparator' => null] + $firebase,
    'signer int' => ['signerKey' => 1] + $firebase,
    'signer and salt int' => ['signerKey' => 1, 'salt' => 1] + $firebase,
];
foreach ($modified as $case => $options) {
    foreach (['user1password', '', 'pässwörd', "p\xffw\xc3", "p\0w"] as $password) {
        emit('ScryptModified ' . $case . ' ' . bin2hex($password), outcome(function () use ($options, $password) {
            $hash = (new ScryptModified())->setOptions($options);
            $hashed = $hash->hash($password);

            return [$hashed, $hash->verify($password, $hashed), $hash->verify($password, 'lSrfV15cpx95/sZS2W9c9Kp6i/LVgQNDNC/qzrCnh1SAyZvqmZqAjTdn3aoItz+VHjoZilo78198JAdRuid5lQ==')];
        }));
    }
}
foreach (['salt', 'saltSeparator', 'signerKey'] as $key) {
    emit('ScryptModified missing ' . $key, outcome(function () use ($key, $firebase) {
        $hash = new class () extends ScryptModified {
            public function drop(string $key): static
            {
                unset($this->options[$key]);

                return $this;
            }
        };

        return $hash->setOptions($firebase)->drop($key)->hash('pw');
    }));
}
emit('ScryptModified private methods', outcome(function () use ($firebase) {
    $hash = (new ScryptModified())->setOptions($firebase);
    $derive = new ReflectionMethod(ScryptModified::class, 'generateDerivedKey');
    $keys = new ReflectionMethod(ScryptModified::class, 'hashKeys');
    $derived = $derive->invoke($hash, 'user1password');

    return [bin2hex($derived), bin2hex($keys->invoke($hash, 'signer', $derived)), bin2hex($keys->invoke($hash, 'signer', 'short')), bin2hex($keys->invoke($hash, str_repeat('s', 40), str_repeat('k', 64)))];
}));

// Argon2, Bcrypt and PHPass draw a random salt: each hash is checked by its class and by PHP's
// own functions, so the extension's hashes must be ones PHP accepts.
$argon2s = [
    'default' => [],
    'small' => ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1],
    'threads 4' => ['memory_cost' => 2048, 'time_cost' => 2, 'threads' => 4],
    'memory 8' => ['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1],
    'memory 7' => ['memory_cost' => 7, 'time_cost' => 1, 'threads' => 1],
    'memory 32 threads 8' => ['memory_cost' => 32, 'time_cost' => 1, 'threads' => 8],
    'memory 0' => ['memory_cost' => 0],
    'memory -1' => ['memory_cost' => -1],
    'memory 2^32' => ['memory_cost' => 1 << 32],
    'time 0' => ['memory_cost' => 1024, 'time_cost' => 0],
    'time -1' => ['memory_cost' => 1024, 'time_cost' => -1],
    'time 2^32' => ['memory_cost' => 1024, 'time_cost' => 1 << 32],
    'threads 0' => ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 0],
    'threads 2^24' => ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1 << 24],
    'strings' => ['memory_cost' => '1024', 'time_cost' => '1', 'threads' => '1'],
    'words' => ['memory_cost' => '1024 KiB', 'time_cost' => 'one', 'threads' => '1'],
    'floats' => ['memory_cost' => 1024.9, 'time_cost' => 1.9, 'threads' => 1.2],
    'bools' => ['memory_cost' => 1024, 'time_cost' => true, 'threads' => true],
    'nulls' => ['memory_cost' => null, 'time_cost' => null, 'threads' => null],
    'arrays' => ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => [1]],
    'all bad, memory first' => ['memory_cost' => 1, 'time_cost' => 0, 'threads' => 0],
    'salt' => ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1, 'salt' => 'ignoredignoredig'],
    'salt and bad threads' => ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 0, 'salt' => 'x'],
];
foreach ($argon2s as $case => $options) {
    foreach ($case === 'small' ? PASSWORDS : ['password'] as $password) {
        emit('Argon2 ' . $case . ' ' . bin2hex($password), outcome(fn () => crossed((new Argon2())->setOptions($options), $password)));
    }
}
emit('Argon2 unset options', outcome(function () {
    $hash = new class () extends Argon2 {
        public function clear(): static
        {
            $this->options = ['memory_cost' => 1024];

            return $this;
        }
    };

    return crossed($hash->clear(), 'pw');
}));
$bcrypts = [
    'default' => [], 'cost 4' => ['cost' => 4], 'cost 5' => ['cost' => 5], 'cost 10' => ['cost' => 10], 'cost 3' => ['cost' => 3], 'cost 32' => ['cost' => 32],
    'cost 0' => ['cost' => 0], 'cost -1' => ['cost' => -1], 'cost "5"' => ['cost' => '5'], 'cost "5x"' => ['cost' => '5x'], 'cost 5.9' => ['cost' => 5.9],
    'cost true' => ['cost' => true], 'cost null' => ['cost' => null], 'cost []' => ['cost' => []], 'salt' => ['cost' => 4, 'salt' => 'ignoredignoredignored22'],
    'salt and bad cost' => ['cost' => 3, 'salt' => 'x'],
];
foreach ($bcrypts as $case => $options) {
    foreach ($case === 'cost 4' ? PASSWORDS : ['password'] as $password) {
        emit('Bcrypt ' . $case . ' ' . bin2hex($password), outcome(fn () => crossed((new Bcrypt())->setOptions($options), $password)));
    }
}
emit('Bcrypt unset cost', outcome(function () {
    $hash = new class () extends Bcrypt {
        public function clear(): static
        {
            $this->options = [];

            return $this;
        }
    };

    return crossed($hash->clear(), 'pw');
}));
// Bcrypt reads 72 bytes: a longer password verifies against any with the same first 72.
emit('Bcrypt 72 bytes', outcome(function () {
    $hash = (new Bcrypt())->setCost(4);
    $hashed = $hash->hash(str_repeat('a', 72) . 'one');

    return [$hash->verify(str_repeat('a', 72) . 'two', $hashed), $hash->verify(str_repeat('a', 72), $hashed), $hash->verify(str_repeat('a', 71), $hashed)];
}));
foreach ([false, true] as $portable) {
    foreach ([4, 7, 8, 12] as $count) {
        foreach ($count === 8 ? PASSWORDS : ['password'] as $password) {
            emit('PHPass ' . ($portable ? 'portable ' : '') . $count . ' ' . bin2hex($password), outcome(fn () => crossed((new PHPass())->setPortableHashes($portable)->setIterationCount($count), $password)));
        }
    }
}

// Hashes PHP made (password_hash(), crypt()) and known vectors: the class's verify() must
// accept them as PHP does. Passwords are hex.
const VECTORS = [
    ['', '$2y$04$63mTYEEFfy9blioboHoS1uQ8j9r1vtMR8sxR44mURnz1TeN4nm4xG'],
    ['', '$argon2id$v=19$m=1024,t=1,p=1$dmZtQTZ1NS43WnRzUzhaQg$pYN/ku8Us3R/PVAIVIyO2oOlExnfAZ3xG9M6AUjL1GM'],
    ['', '$argon2i$v=19$m=1024,t=2,p=2$V1ZTTjhwVFd1NmdBVEtaZw$pMzMh/mMXofJXzUo/eRyKHVvYGJ4gJUi2/NPm3wyPmg'],
    ['', '$2a$05$abcdefghijklmnopqrstuu0oImNDIy4flhldV9YqunRgBAePKmw7m'],
    ['', '$2b$05$abcdefghijklmnopqrstuu0oImNDIy4flhldV9YqunRgBAePKmw7m'],
    ['', '$2x$05$abcdefghijklmnopqrstuu0oImNDIy4flhldV9YqunRgBAePKmw7m'],
    ['', '$1$saltsalt$5Jhcit4zN9UlGiA0txPkO0'],
    ['', '$5$rounds=1000$saltsalt$Naw4j7Jsi9DJmo461n8iSYTXTcY98Ufoq.Xsn7KkFN7'],
    ['', '$6$saltsalt$qkTgsCrWMTAS9gBGcf9W60sFfH.hU0oTCAOJjhbz5tSp/sU3/xXZK4OFwCtq8lIIdpJ6CatVdOTSHKp97TPkt/'],
    ['', 'abmF1QH4PEr.E'],
    ['', '_J9..salt7oN3Rp4tKOo'],
    ['70617373776f7264', '$2y$04$8n6PNKo/cRpZauwhCOHruOE8GgxYfp835LikPEPH1in38SLbi/8xm'],
    ['70617373776f7264', '$argon2id$v=19$m=1024,t=1,p=1$dk10UjluNVZtdjdzNU8yRw$Xo04kO+emLi04h+cTOcpybaS96X9VynadDs3VfB1gYg'],
    ['70617373776f7264', '$argon2i$v=19$m=1024,t=2,p=2$MldtVVZ6SUhKVnF4SFhmZw$yLgAZV73jDTFpZSHeR/NOwRknV3yACmPNd8npJCDO/c'],
    ['70617373776f7264', '$2a$05$abcdefghijklmnopqrstuuWG29KuyeAicPCJODk1zjyGvyQUU2awu'],
    ['70617373776f7264', '$2b$05$abcdefghijklmnopqrstuuWG29KuyeAicPCJODk1zjyGvyQUU2awu'],
    ['70617373776f7264', '$2x$05$abcdefghijklmnopqrstuuWG29KuyeAicPCJODk1zjyGvyQUU2awu'],
    ['70617373776f7264', '$1$saltsalt$qjXMvbEw8oaL.CzflDtaK/'],
    ['70617373776f7264', '$5$rounds=1000$saltsalt$azOwbpkvuuBKkE82dQPwTsQE8JyT9Fflpr9aKid3aT9'],
    ['70617373776f7264', '$6$saltsalt$qFmFH.bQmmtXzyBY0s9v7Oicd2z4XSIecDzlB5KiA2/jctKu9YterLp8wwnSq.qc.eoxqOmSuNp2xS0ktL3nh/'],
    ['70617373776f7264', 'abJnggxhB/yWI'],
    ['70617373776f7264', '_J9..saltJW8FtKdEkNM'],
    ['70c3a47373776f7264', '$2y$04$sMqoj0yWwyziTDKRJYtga.rJ3Q4/4o/mv/Pco1IDIemolhdxRHwxO'],
    ['70c3a47373776f7264', '$argon2id$v=19$m=1024,t=1,p=1$dzZiWkMzckZURGxBLzkvWQ$EpkBEd9MEBvi87jBX7KvG9y4IzWKc574TJnnFwPrlO0'],
    ['70c3a47373776f7264', '$argon2i$v=19$m=1024,t=2,p=2$TmhiUnk3S3JyQXhaa2JDQw$fDUhytbvIwF7+FiktQZi5pVS20z7k+9ihBSX+rRi2Yg'],
    ['70c3a47373776f7264', '$2a$05$abcdefghijklmnopqrstuucZGbUxuMlEqps0qcu2pjhO7vaatQZIC'],
    ['70c3a47373776f7264', '$2b$05$abcdefghijklmnopqrstuucZGbUxuMlEqps0qcu2pjhO7vaatQZIC'],
    ['70c3a47373776f7264', '$2x$05$abcdefghijklmnopqrstuuYbTll9sWB7zBWIyoa9YxAN.UZJjFTnG'],
    ['70c3a47373776f7264', '$1$saltsalt$QgbQ7EUpcKNQTLgSVL4qr/'],
    ['70c3a47373776f7264', '$5$rounds=1000$saltsalt$k0ylRWLHVlBxnL.QHEV.f8gDch7ScqF5//h.mx1WAa9'],
    ['70c3a47373776f7264', '$6$saltsalt$rVIyJtXnL0mXUCLepstXWqeOv9JVyd7nOH8wn0BvOU5lCiAviUJLxXZ5CkPgq6XNTHV22yG4mV0IO9gqPL5WQ.'],
    ['70c3a47373776f7264', 'abRy4/6THFs9U'],
    ['70c3a47373776f7264', '_J9..salt5AVBg4gtFEI'],
    ['70ff77', '$2y$04$adL0CTWe7fGotFnInfsHyOtb8QTq7PzeIlP.c7wgT2mvJLVr0Rh7i'],
    ['70ff77', '$argon2id$v=19$m=1024,t=1,p=1$ejFzRkRFU2JDVHZnZ0ptMA$/O1FIX6jGc4DyA237P3S+RcRznpgl3hWtMM/ASgXScg'],
    ['70ff77', '$argon2i$v=19$m=1024,t=2,p=2$WHJXOFJsalhEYTJROXk2bQ$7w9ZQiGSotDJhAWnR03p91hWMBIrE97SkYn5531D4Sw'],
    ['70ff77', '$2a$05$abcdefghijklmnopqrstuutu0N1y7EVSN3iwsxHGwiCbgKI1Vdk3i'],
    ['70ff77', '$2b$05$abcdefghijklmnopqrstuutu0N1y7EVSN3iwsxHGwiCbgKI1Vdk3i'],
    ['70ff77', '$2x$05$abcdefghijklmnopqrstuuO8txpzPVRmSa4t5kuawDr4Myn5FogaS'],
    ['70ff77', '$1$saltsalt$EWuQn8bZP9dBaWYQMl.6k0'],
    ['70ff77', '$5$rounds=1000$saltsalt$f23V5UaD8jDvq9lotwfMCKZfQSuKaJ4jKvC/8A8U296'],
    ['70ff77', '$6$saltsalt$NS3J1boCXxjgAKIoQzzunHjEGo8D.7svyTI91ZJHNOBGfQk6.8yVVSDzRHcCv3.TCxbaCVYWtiH5I7bIIcMu00'],
    ['70ff77', 'abbS1ni/PFtNA'],
    ['70ff77', '_J9..salt0u2nr9G8ndA'],
    ['700077', '$1$saltsalt$oVkV3EDq8KTkLj17vu4OM0'],
    ['700077', '$5$rounds=1000$saltsalt$hkwX4/ga0nwRGtM7xNjSsKshHJPH92T4Bm5LKu0W1g1'],
    ['700077', '$6$saltsalt$T8zzampJcN172f1r0kNFFiQGdtFquc5jnzebqomQxxdUNGG69JTP0XFkH2sFbHn4FG4SGRFsmj2vnmTApt5fI/'],
    ['700077', 'ab8Smhzf5D4wA'],
    ['700077', '_J9..saltAfC675zkBro'],
    ['6161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161615441494c', '$2y$04$OZEvycRpxbF9CmAc5IbGuOiM5d5K/64Tl9x7HfbOpRbpBGAZrgVcK'],
    ['6161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161615441494c', '$argon2id$v=19$m=1024,t=1,p=1$c0ZVSFpKUjBaUFBGSzBUOA$as9yJYYcncOG/cBD/jLdeeJ55Ktwuyzw0El40q6vMC8'],
    ['6161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161615441494c', '$argon2i$v=19$m=1024,t=2,p=2$S2tGNm1yYUV2dExNeUN0Qg$eTuvPM5hWMTiwboJujf17vSte9DQmig8blePq8WUsZY'],
    ['6161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161615441494c', '$2a$05$abcdefghijklmnopqrstuuGUnCqbfgs3htOkLrFduUjAyLBw1Rq/u'],
    ['6161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161615441494c', '$2b$05$abcdefghijklmnopqrstuuGUnCqbfgs3htOkLrFduUjAyLBw1Rq/u'],
    ['6161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161615441494c', '$2x$05$abcdefghijklmnopqrstuuGUnCqbfgs3htOkLrFduUjAyLBw1Rq/u'],
    ['6161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161615441494c', '$1$saltsalt$BATs1EH6mHTILXEgmcLD.0'],
    ['6161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161615441494c', '$5$rounds=1000$saltsalt$Ia1QxzcBJ6ApNsRJIh03G6zE967ULp7FjDUOFnRO1G3'],
    ['6161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161615441494c', '$6$saltsalt$Ru2nqA7aIIVk.ep0KUqIv66d7tTUFc2ks4Q/e41/9kh5fH4sc0r410ntoSHZmX5JTB0pIFpP4mATYSA0XuxI2.'],
    ['6161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161615441494c', 'abBUNZY4cR2mg'],
    ['6161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161615441494c', '_J9..salt3KVO1wWCjGs'],
    // phpass portable hashes (WordPress's $P$, phpBB's $H$), made by the PHP class.
    ['', '$P$Ao9cFdrGT8N1nZ56e.ymJNEYsj83BZ.'],
    ['', '$P$BqCQGgXW/.ZeM07qWeMoQFF097U2dQ/'],
    ['', '$P$D0Zy7kFGIi.N2x5mooBJRQO/3TDA/g/'],
    ['', '$H$G7RLh4hmKLuMEp44O8Zl2/HVLMfHDW.'],
    ['70617373776f7264', '$P$ANTwH2umW0cAk/5h/118Jweplyfswv.'],
    ['70617373776f7264', '$P$B4vpF7o426TU/vW7o0aep4f0jS2pVP/'],
    ['70617373776f7264', '$P$DZv4TvX/8nqj/aeAQ/qDOdyhVLgBmo1'],
    ['70617373776f7264', '$H$G2.s4MhBPqC.Vv9W8NEDwRTOZPJ.zb1'],
    ['70c3a4737377c3b67264', '$P$A9A75JcjsnkQzoQjirJzTqi0qZtG.B/'],
    ['70c3a4737377c3b67264', '$P$BOVAYNoI3TkkITc4zpqXmfBGJdi1NQ/'],
    ['70c3a4737377c3b67264', '$P$D7cOry/CspBDPohP6kUJENGvQs9C9x/'],
    ['70c3a4737377c3b67264', '$H$GwjZ1Ylt6RfhTurL..hagQdwi9Tw1D.'],
    ['70ff77', '$P$A7GJP73TyKqZfzT3lrNwXGqF1XZS3C0'],
    ['70ff77', '$P$B4rFXr/PV119F05CxYOyAjOFdMSl9S/'],
    ['70ff77', '$P$Dbu9iYyvxD.j4fB/izgzutLAk1qi70.'],
    ['70ff77', '$H$Gv1ZlxFA4ItKlP52krk7ovk7KYITiS.'],
    ['700077', '$P$AesEVI7pzuz.UYxn7bs/o87Cpz1X8N.'],
    ['700077', '$P$BUbTS9em5HK9lksweKyiFqtFn8Mpm20'],
    ['700077', '$P$DdXPAfmeQE8uzjm/I4l9dMckIjpw/U/'],
    ['700077', '$H$GlRiB382trwzzhtGQSaZbRrlshflaa0'],
    ['6161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161', '$P$AX3xiP7BzrVkFZiPluODhA8AgwUUgD/'],
    ['6161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161', '$P$B8IbDMcrrmA0tZY/zUQlIj3oZHIMn0/'],
    ['6161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161', '$P$DJgkwrKa0E67ADbngsUm5krh8hY8qB/'],
    ['6161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161', '$H$GHvPDiRBlCNOFIeroVyW8uDp/q.rF..'],
];
foreach (VECTORS as [$password, $hashed]) {
    $password = hex2bin($password);
    foreach ([new Argon2(), new Bcrypt(), new PHPass()] as $hash) {
        emit('vector ' . $hash::class . ' ' . bin2hex($password) . ' ' . $hashed, outcome(fn () => [
            $hash->verify($password, $hashed), $hash->verify($password . 'x', $hashed), $hash->verify($password, substr($hashed, 0, -1)), $hash->verify($password, $hashed . 'x'),
        ]));
    }
}
// Malformed hashes and prefixes of other algorithms.
$malformed = [
    '', '*', '*0', '*1', '$', '$2y$', '$2y$04$', '$2y$04$short', '$2y$03$63mTYEEFfy9blioboHoS1uQ8j9r1vtMR8sxR44mURnz1TeN4nm4xG', '$2y$32$63mTYEEFfy9blioboHoS1uQ8j9r1vtMR8sxR44mURnz1TeN4nm4xG',
    '$2c$04$63mTYEEFfy9blioboHoS1uQ8j9r1vtMR8sxR44mURnz1TeN4nm4xG', '$2y$04$63mTYEEFfy9blioboHoS1uQ8j9r1vtMR8sxR44mURnz1TeN4nm4x!',
    '$argon2id$', '$argon2id$v=19$m=1024,t=1,p=1$', '$argon2id$v=19$m=1024,t=1,p=1$c2FsdHNhbHQ$', '$argon2id$v=18$m=1024,t=1,p=1$RVZBOTVMb25RLjlQcjNmcw$3x7h2cLvzGX7qvkKSfy4mAof+FjXZthHQv60sOarGsM',
    '$argon2d$v=19$m=1024,t=1,p=1$RVZBOTVMb25RLjlQcjNmcw$3x7h2cLvzGX7qvkKSfy4mAof+FjXZthHQv60sOarGsM', '$argon2id$v=19$m=7,t=1,p=1$RVZBOTVMb25RLjlQcjNmcw$3x7h2cLvzGX7qvkKSfy4mAof+FjXZthHQv60sOarGsM',
    '$P$', '$P$B', '$P$Bsaltsal', '$P$Bsaltsalt', '$P$Bsaltsalt.', '$P$6saltsaltxxxxxxxxxxxxxxxxxxxxxx', '$P$Usaltsaltxxxxxxxxxxxxxxxxxxxxxx', '$P$!saltsaltxxxxxxxxxxxxxxxxxxxxxx',
    '$H$Bsaltsalt', '$Q$Bsaltsalt', '$1$', '$1$saltsalt', '$5$', '$6$rounds=999$saltsalt$', 'a', 'ab', '_J9..', '_', "\0", "\xff\xfe", 'password',
];
foreach ($malformed as $hashed) {
    foreach ([new Argon2(), new Bcrypt(), new PHPass(), new MD5(), new Plaintext(), new Sha()] as $hash) {
        emit('malformed ' . $hash::class . ' ' . bin2hex($hashed), outcome(fn () => [$hash->verify('password', $hashed), $hash->verify('', $hashed)]));
    }
}

// PHPass made deterministic: getRandomBytes() fixed, so the salts and hashes must match.
class FixedPHPass extends PHPass
{
    public string $bytes = "\x01\x23\x45\x67\x89\xab\xcd\xef\xfe\xdc\xba\x98\x76\x54\x32\x10";

    public array $asked = [];

    protected function getRandomBytes(int $count): string
    {
        $this->asked[] = $count;

        return substr($this->bytes, 0, $count);
    }

    public function random(int $count): string
    {
        return parent::getRandomBytes($count);
    }

    public function encode(string $input, int $count): string
    {
        return $this->encode64($input, $count);
    }

    public function alphabet(string $itoa64): static
    {
        $this->itoa64 = $itoa64;

        return $this;
    }
}
$fixed = [
    'blowfish' => fn () => new FixedPHPass(),
    'portable' => fn () => (new FixedPHPass())->setPortableHashes(true),
    'portable 4' => fn () => (new FixedPHPass())->setPortableHashes(true)->setIterationCount(4),
    'portable 23' => fn () => (new FixedPHPass())->setPortableHashes(true)->setOption('iteration_count_log2', 18),
    'blowfish 4' => fn () => (new FixedPHPass())->setIterationCount(4),
    'blowfish 12' => fn () => (new FixedPHPass())->setIterationCount(12),
    'blowfish 3 (option)' => fn () => (new FixedPHPass())->setOption('iteration_count_log2', 3),
    'blowfish 0 (option)' => fn () => (new FixedPHPass())->setOption('iteration_count_log2', 0),
    'blowfish -5 (option)' => fn () => (new FixedPHPass())->setOption('iteration_count_log2', -5),
    'blowfish "9" (option)' => fn () => (new FixedPHPass())->setOption('iteration_count_log2', '9'),
    'blowfish 9.5 (option)' => fn () => (new FixedPHPass())->setOption('iteration_count_log2', 9.5),
    'blowfish null (option)' => fn () => (new FixedPHPass())->setOption('iteration_count_log2', null),
    'blowfish "x" (option)' => fn () => (new FixedPHPass())->setOption('iteration_count_log2', 'x'),
    'blowfish [] (option)' => fn () => (new FixedPHPass())->setOption('iteration_count_log2', []),
    'portable "9" (option)' => fn () => (new FixedPHPass())->setPortableHashes(true)->setOption('iteration_count_log2', '9'),
    'portable 9.5 (option)' => fn () => (new FixedPHPass())->setPortableHashes(true)->setOption('iteration_count_log2', 9.5),
    'portable null (option)' => fn () => (new FixedPHPass())->setPortableHashes(true)->setOption('iteration_count_log2', null),
    'portable "x" (option)' => fn () => (new FixedPHPass())->setPortableHashes(true)->setOption('iteration_count_log2', 'x'),
    'portable 1 (option)' => fn () => (new FixedPHPass())->setPortableHashes(true)->setOption('iteration_count_log2', 1),
    'portable "1" (option)' => fn () => (new FixedPHPass())->setOption('portable_hashes', '1'),
    'portable "0" (option)' => fn () => (new FixedPHPass())->setOption('portable_hashes', '0'),
    'portable [] (option)' => fn () => (new FixedPHPass())->setOption('portable_hashes', []),
    'no portable option' => function () {
        $hash = new FixedPHPass();
        $hash->setOptions(['portable_hashes' => null]);

        return $hash;
    },
    'short bytes' => function () {
        $hash = new FixedPHPass();
        $hash->bytes = "\x01\x02\x03";

        return $hash;
    },
    'short bytes portable' => function () {
        $hash = (new FixedPHPass())->setPortableHashes(true);
        $hash->bytes = "\x01\x02\x03";

        return $hash;
    },
    'no bytes' => function () {
        $hash = new FixedPHPass();
        $hash->bytes = '';

        return $hash;
    },
    'other alphabet' => fn () => (new FixedPHPass())->setPortableHashes(true)->alphabet('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'),
    'short alphabet' => fn () => (new FixedPHPass())->setPortableHashes(true)->alphabet('./0123456789AB'),
    'empty alphabet' => fn () => (new FixedPHPass())->setPortableHashes(true)->alphabet(''),
];
foreach ($fixed as $case => $make) {
    foreach (['password', '', "p\xff\0w"] as $password) {
        emit('fixed PHPass ' . $case . ' ' . bin2hex($password), outcome(function () use ($make, $password) {
            $hash = $make();
            $hashed = $hash->hash($password);

            return [$hashed, $hash->asked, $hash->verify($password, $hashed), $hash->verify($password . 'x', $hashed), password_verify($password, $hashed)];
        }));
    }
}
foreach ([0, -1, 1, 6, 16, 17, 40] as $count) {
    emit('PHPass getRandomBytes ' . $count, outcome(fn () => strlen((new FixedPHPass())->random($count))));
    foreach (['', 'a', 'ab', 'abc', 'abcd', "\xff\xfe\xfd\xfc\xfb\xfa", str_repeat("\x80", 20)] as $input) {
        emit('PHPass encode64 ' . bin2hex($input) . ' ' . $count, outcome(fn () => (new FixedPHPass())->encode($input, $count)));
    }
}
// Every count character of a portable setting, and settings around the salt.
foreach (str_split('./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz!') as $c) {
    $setting = '$P$' . $c . 'saltsalt';
    emit('PHPass setting ' . $setting, outcome(function () use ($setting) {
        $method = new ReflectionMethod(PHPass::class, 'cryptPrivate');

        return [$method->invoke(new PHPass(), 'password', $setting)];
    }));
    if ($c === 'D') {
        break;
    }
}
foreach (['', '$P$', '$P$B', '$P$Bsalt', '$P$Bsaltsalt', '$P$Bsaltsaltextra', '$H$9saltsalt', '*0', '*0x', '$2a$', "\$P\$B\0altsalt"] as $setting) {
    emit('PHPass cryptPrivate ' . bin2hex($setting), outcome(function () use ($setting) {
        $method = new ReflectionMethod(PHPass::class, 'cryptPrivate');

        return [$method->invoke(new PHPass(), 'password', $setting)];
    }));
}
foreach (['', 'abc', "\x01\x23\x45\x67\x89\xab\xcd\xef\xfe\xdc\xba\x98\x76\x54\x32\x10", str_repeat("\xff", 20)] as $input) {
    foreach (['gensaltPrivate', 'gensaltBlowfish'] as $name) {
        emit('PHPass ' . $name . ' ' . bin2hex($input), outcome(function () use ($name, $input) {
            $method = new ReflectionMethod(PHPass::class, $name);

            return [$method->invoke(new PHPass(), $input), $method->invoke((new PHPass())->setIterationCount(17), $input)];
        }));
    }
}

// Subclasses: PHP's methods call each other through $this, so overrides must run.
class LoggedMD5 extends MD5
{
    public array $log = [];

    public function setOption(string $key, mixed $value): static
    {
        $this->log[] = [$key, $value];

        return parent::setOption($key . '!', $value);
    }

    public function getName(): string
    {
        return 'logged';
    }

    public function hash(string $value): string
    {
        return 'h:' . $value;
    }
}
class OptionsSha extends Sha
{
    public function getOption(string $key, mixed $default = null): mixed
    {
        return $key === 'version' ? 'sha1' : parent::getOption($key, $default);
    }
}
class OptionsArgon2 extends Argon2
{
    public function getOptions(): array
    {
        return ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1];
    }
}
class NamedBcrypt extends Bcrypt
{
    public function getName(): string
    {
        return 'named';
    }
}
class EncodedPHPass extends FixedPHPass
{
    protected function encode64(string $input, int $count): string
    {
        return str_repeat('e', $count);
    }
}
class ThrowingScrypt extends Scrypt
{
    public function setOption(string $key, mixed $value): static
    {
        if ($key === 'length') {
            throw new LogicException('no length');
        }

        return parent::setOption($key, $value);
    }
}
class Custom extends Hash
{
    public function hash(string $value): string
    {
        return strrev($value);
    }

    public function verify(string $value, string $hash): bool
    {
        return $this->hash($value) === $hash;
    }

    public function getName(): string
    {
        return 'custom';
    }
}
$subclasses = [
    'LoggedMD5' => function () {
        $hash = new LoggedMD5();
        $hash->setOptions(['a' => 1, 'b' => [2]]);

        return [$hash->log, $hash->getOptions(), $hash->hash('pw'), $hash->verify('pw', 'h:pw'), $hash->verify('pw', md5('pw')), $hash instanceof Hash];
    },
    'LoggedMD5 int key' => function () {
        $hash = new LoggedMD5();
        try {
            $hash->setOptions([3 => 'three']);
        } catch (TypeError $e) {
            // PHP adds where Hash.php called setOption().
            return [$e::class, explode(', called in', $e->getMessage())[0], $hash->log];
        }

        return [$hash->log, $hash->getOptions()];
    },
    'OptionsSha' => fn () => [(new OptionsSha())->hash('pw'), (new OptionsSha())->verify('pw', sha1('pw'))],
    'OptionsArgon2' => fn () => [crossed(new OptionsArgon2(), 'pw'), (new OptionsArgon2())->setThreads(9)->getOptions()],
    'NamedBcrypt' => fn () => (new NamedBcrypt())->getOptions(),
    'EncodedPHPass' => fn () => [(new EncodedPHPass())->hash('pw'), (new EncodedPHPass())->setPortableHashes(true)->hash('pw')],
    'ThrowingScrypt' => fn () => (new ThrowingScrypt())->getOptions(),
    'Custom' => function () {
        $hash = (new Custom())->setOption('k', 'v')->setOptions(['l' => 'w']);

        return [$hash->getOptions(), $hash->getOption('k'), $hash->hash('abc'), $hash->verify('abc', 'cba'), $hash->getName(), $hash instanceof Hash];
    },
    'anonymous' => function () {
        $hash = new class () extends Hash {
            public function hash(string $value): string
            {
                return $value;
            }

            public function verify(string $value, string $hash): bool
            {
                return $value === $hash;
            }

            public function getName(): string
            {
                return 'anonymous';
            }
        };

        return [$hash->setOption('x', 1)->getOptions(), $hash->getName()];
    },
    'options replaced' => function () {
        $hash = new class () extends MD5 {
            public function replace(mixed $options): static
            {
                $this->options = $options;

                return $this;
            }
        };

        return [$hash->replace(['a' => 1])->setOption('b', 2)->getOptions()];
    },
    'options unset' => function () {
        $hash = new class () extends MD5 {
            public function drop(): static
            {
                unset($this->options);

                return $this;
            }
        };

        return [$hash->drop()->getOptions()];
    },
    'options typed' => function () {
        $hash = new class () extends MD5 {
            public function replace(mixed $options): static
            {
                $this->options = $options;

                return $this;
            }
        };

        return [$hash->replace('string')];
    },
];
foreach ($subclasses as $case => $run) {
    emit('subclass ' . $case, outcome($run));
}

// The objects themselves: clone, serialize(), var_dump(), comparison, conversion to array.
$objects = [new MD5(), (new Sha())->setVersion('sha1'), (new Bcrypt())->setCost(5), (new Argon2())->setThreads(1), (new Scrypt())->setSalt('fixed'), (new ScryptModified())->setOptions($firebase), (new PHPass())->setOption('random_state', 'fixed'), new Plaintext()];
foreach ($objects as $object) {
    emit('object ' . $object::class, outcome(function () use ($object) {
        $clone = clone $object;
        $clone->setOption('cloned', true);
        $copy = unserialize(serialize($object));
        ob_start();
        var_dump($object);
        $dump = withoutHandles(ob_get_clean());

        return [
            'serialized' => serialize($object), 'copy' => $copy->getOptions(), 'copy class' => $copy::class, 'original' => $object->getOptions(), 'clone' => $clone->getOptions(),
            'equal' => $object == $copy, 'equal clone' => $object == $clone, 'dump' => $dump, 'export' => var_export($object, true), 'print' => print_r($object, true),
            'array' => properties($object), 'json' => json_encode($object), 'name' => $object->getName(), 'copy name' => $copy->getName(),
        ];
    }));
}
emit('unserialize with options', outcome(fn () => unserialize('O:22:"Utopia\Auth\Hashes\MD5":1:{s:10:"' . "\0*\0" . 'options";a:1:{s:1:"x";i:1;}}')->getOptions()));
emit('unserialize with bad options', outcome(fn () => unserialize('O:22:"Utopia\Auth\Hashes\MD5":1:{s:10:"' . "\0*\0" . 'options";s:1:"x";}')));
emit('dynamic property', outcome(function () {
    $hash = new MD5();
    $hash->extra = 1;

    return properties($hash);
}));
emit('options property access', outcome(fn () => (new MD5())->options));
emit('itoa64 property access', outcome(fn () => (new PHPass())->itoa64));
class LooseKeyMD5 extends MD5
{
    public function setOption($key, mixed $value): static
    {
        return parent::setOption((string) $key, [get_debug_type($key), $value]);
    }
}
class IntKeyMD5 extends MD5
{
    public function setOption(int|string $key, mixed $value): static
    {
        return parent::setOption((string) $key, [get_debug_type($key), $value]);
    }
}
foreach ([LooseKeyMD5::class, IntKeyMD5::class] as $class) {
    emit('subclass ' . $class . ' int key', outcome(fn () => (new $class())->setOptions([3 => 'three', '4' => 'four', 'k' => 'v'])->getOptions()));
}
