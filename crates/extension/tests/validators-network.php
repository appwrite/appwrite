<?php

// Parity corpus for the network leaf validators: Hostname, Domain, Host, IP, URL and Phone.
// Run it on the PHP classes and on the utopia extension's
// (_APP_EXTENSION_LIBRARIES=validators,validators-network), and diff stdout: every line must
// match. Stderr names the implementation that ran.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Validator\Domain;
use Utopia\Validator\Host;
use Utopia\Validator\Hostname;
use Utopia\Validator\IP;
use Utopia\Validator\Phone;
use Utopia\Validator\URL;
use Utopia\Validator\Validator;

const CLASSES = [Hostname::class, Domain::class, Host::class, IP::class, URL::class, Phone::class];

$internal = (new ReflectionClass(URL::class))->isInternal();
fwrite(STDERR, 'Utopia\\Validator\\URL: ' . ($internal ? 'utopia extension' : 'PHP') . PHP_EOL);
// A run meant for the extension that gets the PHP classes would compare PHP with PHP and pass.
$switches = array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: ''));
$requested = in_array('validators', $switches, true) && in_array('validators-network', $switches, true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' validators and validators-network, but the other implementation loaded' . PHP_EOL);
    exit(1);
}
foreach (CLASSES as $class) {
    if ((new ReflectionClass($class))->isInternal() !== $internal) {
        fwrite(STDERR, $class . ' is not from the same implementation as URL' . PHP_EOL);
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
emit('constants as values', outcome(fn () => [IP::ALL, IP::V4, IP::V6, IP::TYPE_STRING, URL::TYPE_STRING, constant(IP::class . '::V6')]));

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

/** A backed enum case, which no string conversion accepts. */
enum Suit: string
{
    case Hearts = 'H';
}

/** An object with public and private properties, which foreach walks from a class's scope. */
final class Props
{
    public $first = 'api.';
    protected $second = 'x';
    private $third = 'y';
    public $last = 'console.';
}

// The values isValid() checks: the generic ones, then hostnames, IPs, URLs and phone numbers.
$values = [
    'null' => null, 'true' => true, 'false' => false, '0' => 0, '1' => 1, '-1' => -1, '1.5' => 1.5, 'NAN' => NAN, 'INF' => INF, '1e20' => 1e20,
    "''" => '', "' '" => ' ', "'0'" => '0', "'a'" => 'a', '[]' => [], "['a.com']" => ['a.com'], 'object' => new stdClass(),
    'throwing stringable' => throwing(), 'enum' => Suit::Hearts, 'closure' => fn () => 1,
    "stringable 'https://a.com'" => stringable('https://a.com'), "stringable 'a.com'" => stringable('a.com'),
    "stringable '127.0.0.1'" => stringable('127.0.0.1'), "stringable '+1234567'" => stringable('+1234567'),
    'not utf-8' => "\xff", 'not utf-8 host' => "\xff.com", 'nul byte' => "a\x00b.com",
    // Hostnames and domains.
    'localhost' => 'localhost', 'example.com' => 'example.com', 'EXAMPLE.COM' => 'EXAMPLE.COM', 'a.com' => 'a.com', 'x.a.com' => 'x.a.com',
    'y.x.a.com' => 'y.x.a.com', 'b.com' => 'b.com', '.a.com' => '.a.com', 'a.com.' => 'a.com.', 'a..b' => 'a..b', '-a.com' => '-a.com',
    'a-.com' => 'a-.com', 'a-' => 'a-', 'a.' => 'a.', 'a_b.com' => 'a_b.com', '_dmarc.a.com' => '_dmarc.a.com', 'a b.com' => 'a b.com',
    '*.a.com' => '*.a.com', 'label 63' => str_repeat('a', 63) . '.com', 'label 64' => str_repeat('a', 64) . '.com',
    'host 253' => implode('.', array_fill(0, 63, 'abc')) . '.a', 'host 254' => implode('.', array_fill(0, 63, 'abc')) . '.ab',
    'host 253 multibyte' => str_repeat('é', 253), 'host 254 multibyte' => str_repeat('é', 254), 'münchen.de' => 'münchen.de',
    'punycode' => 'xn--mnchen-3ya.de', 'xn--' => 'xn--', '例子.中国' => '例子.中国', 'emoji host' => '😀.com', 'a/b' => 'a/b', 'a:1' => 'a:1',
    "'a.com\\n'" => "a.com\n", "' a.com'" => ' a.com', 'appwrite.network' => 'appwrite.network', 'x.appwrite.network' => 'x.appwrite.network',
    'api.x.appwrite.network' => 'api.x.appwrite.network', 'console.appwrite.network' => 'console.appwrite.network', 'a.b.c.d.e' => 'a.b.c.d.e',
    // IP addresses.
    '127.0.0.1' => '127.0.0.1', '0.0.0.0' => '0.0.0.0', '255.255.255.255' => '255.255.255.255', '256.0.0.1' => '256.0.0.1', '1.2.3' => '1.2.3',
    '01.2.3.4' => '01.2.3.4', '1.2.3.04' => '1.2.3.04', '192.168.1.1' => '192.168.1.1', '10.0.0.1' => '10.0.0.1', '169.254.1.1' => '169.254.1.1',
    '127.1' => '127.1', '0x7f.0.0.1' => '0x7f.0.0.1', '1.2.3.4/24' => '1.2.3.4/24', "'1.2.3.4 '" => '1.2.3.4 ', "' 1.2.3.4'" => ' 1.2.3.4',
    "'1.2.3.4\\n'" => "1.2.3.4\n", '::1' => '::1', '::' => '::', '2001:db8::1' => '2001:db8::1', 'zone id' => 'fe80::1%eth0',
    'v4-mapped' => '::ffff:1.2.3.4', 'v4-mapped bad' => '::ffff:256.1.1.1', 'v4-compat' => '::1.2.3.4', 'full v6' => '1:2:3:4:5:6:7:8',
    'v6 nine groups' => '1:2:3:4:5:6:7:8:9', 'two ::' => '1::2::3', 'v6 hex g' => '2001:db8::g', 'v6 leading zeros' => '2001:0db8:0000::0001',
    'v6 5 digits' => '2001:00db8::1', 'v6 bracketed' => '[::1]', 'v6 upper' => 'FE80::ABCD', 'v6 trailing v4' => '1:2:3:4:5:6:1.2.3.4',
    // URLs.
    'http://a.com' => 'http://a.com', 'https full' => 'https://user:pass@a.com:8443/p/a?q=1&r[]=2#frag', 'ftp' => 'ftp://user:pass@host:21/x',
    'http://localhost' => 'http://localhost', 'http://LOCALHOST' => 'http://LOCALHOST', 'http://localhost.' => 'http://localhost.',
    'http://127.0.0.1:8080' => 'http://127.0.0.1:8080', 'http://127.0.0.2' => 'http://127.0.0.2', 'http://[::1]/' => 'http://[::1]/',
    'http://[::1]:80' => 'http://[::1]:80', 'https://[2001:db8::1]' => 'https://[2001:db8::1]', 'http://[zone]' => 'http://[fe80::1%25eth0]/',
    'port 65535' => 'http://a.com:65535', 'port 65536' => 'http://a.com:65536', 'port 99999' => 'http://a.com:99999', 'port -1' => 'http://a.com:-1',
    'port empty' => 'http://a.com:', 'http://' => 'http://', 'http:///a' => 'http:///a', '//a.com' => '//a.com', 'mailto' => 'mailto:a@b.c',
    'javascript' => 'javascript:alert(1)', 'file' => 'file:///etc/passwd', 'data' => 'data:text/plain,hi', 'urn' => 'urn:isbn:123',
    'private-use' => 'com.example.app:/oauth', 'private-use authority' => 'com.example.app://host/x', 'private-use upper' => 'Com.Example.App:/cb',
    'private-use no dot' => 'myapp:/x', 'private-use empty path' => 'com.x:', 'private-use //' => 'com.x://', 'private-use fragment' => 'com.x:/cb#f',
    'iri path' => 'http://a.com/čšž', 'iri host' => 'http://ü.com', 'url space host' => 'http://a b.com', 'url space path' => 'http://a.com/a b',
    'empty fragment' => 'http://a.com#', 'empty query' => 'http://a.com?', 'userinfo' => 'http://user@a.com', 'pass only' => 'http://:pass@a.com',
    'HTTP://A.COM' => 'HTTP://A.COM', 'Https://a.com' => 'Https://a.com', 'hex ip url' => 'http://0x7f000001', "'http://a.com\\n'" => "http://a.com\n",
    'url nul' => "http://a.com/\x00", 'percent bad' => 'http://a.com/%zz', 'percent good' => 'http://a.com/%41', 'https 443' => 'https://a.com:443',
    'url underscore' => 'http://a_b.com', 'url dash' => 'http://-a.com', 'x:' => 'x:', 'scheme only http:' => 'http:', 'url not utf-8' => "http://a.com/\xff",
    'no scheme path' => 'a.com/p', 'backslashes' => 'http:\\\\a.com', 'tab in url' => "http://a.com/\t", 'at in path' => 'http://a.com/@b',
    'question in host' => 'http://a.com?.b.com', 'fragment only' => '#frag', 'query only' => '?q', 'long url' => 'https://a.com/' . str_repeat('p', 3000),
    // Phone numbers.
    '+1234567' => '+1234567', '+123456789012345' => '+123456789012345', '16 digits' => '+1234567890123456', '+0234567' => '+0234567',
    '1234567' => '1234567', "' 1234567'" => ' 1234567', '%2B1234567' => '%2B1234567', '%201234567' => '%201234567', '+1 234 567' => '+1 234 567',
    '+1-234-567' => '+1-234-567', "'+1234567\\n'" => "+1234567\n", "'+1234567\\n\\n'" => "+1234567\n\n", "' 1234567\\n'" => " 1234567\n",
    '++1234567' => '++1234567', 'arabic digits' => '+١٢٣٤٥٦٧', "'+'" => '+', '+123456' => '+123456', '%2b1234567' => '%2b1234567', '%2' => '%2',
    '%25' => '%25', 'phone not utf-8' => "+1234567\xff",
];

// The constructors, over arguments of every kind PHP converts or rejects.
$restriction = static fn (...$args) => Domain::createRestriction(...$args);
$constructions = [
    'Hostname' => [
        [], [[]], [['a.com']], [['*.a.com']], [['*']], [['*.a.com', 'b.com']], [['A.COM']], [['']], [['*', 'a.com']], [['*.']],
        [[1, 2.5, true, false]], [[null, '*.a.com']], [[stringable('*.a.com')]], [[stringable('a.com')]], [[['x'], 'a.com']], [[new stdClass()]],
        [[throwing()]], [[Suit::Hearts]], [['k' => '*.a.com', 3 => 'b.com']], ['abc'], [null], [[], 'extra'],
    ],
    'Domain' => [
        [], [[], false], [[], true, true], [[], false, true], [[$restriction('a.com', 2)]], [[$restriction('appwrite.network', 3, ['api.', 'console.'])]],
        [[$restriction('appwrite.network'), $restriction('com', 2)]], [[$restriction('a.com', 3), $restriction('a.com', 2)]], [[['hostname' => 'a.com']]],
        [[[]]], [['string']], [[null]], [[5]], [[['hostname' => null, 'levels' => null, 'prefixDenyList' => null]]],
        [[['hostname' => 'com', 'levels' => '2', 'prefixDenyList' => []]]], [[['hostname' => 'com', 'levels' => 2.0, 'prefixDenyList' => []]]],
        [[['hostname' => 'com', 'levels' => '2abc', 'prefixDenyList' => []]]], [[['hostname' => 'com', 'levels' => [], 'prefixDenyList' => []]]],
        [[['hostname' => 'com', 'levels' => PHP_INT_MAX, 'prefixDenyList' => []]]], [[['hostname' => 'com', 'levels' => 0, 'prefixDenyList' => []]]],
        [[['hostname' => 'com', 'levels' => -1, 'prefixDenyList' => []]]], [[['hostname' => 'com', 'levels' => true, 'prefixDenyList' => []]]],
        [[['hostname' => '', 'levels' => null, 'prefixDenyList' => 'x']]], [[['hostname' => '', 'levels' => null, 'prefixDenyList' => 5]]],
        [[['hostname' => '', 'levels' => null, 'prefixDenyList' => new Props()]]], [[['hostname' => '', 'levels' => null, 'prefixDenyList' => new ArrayIterator(['x.', 'api.'])]]],
        [[['hostname' => '', 'levels' => null, 'prefixDenyList' => [1, stringable('api.'), ['a'], null]]]],
        [[['hostname' => '', 'levels' => null, 'prefixDenyList' => [throwing()]]]], [[['hostname' => stringable('a.com'), 'levels' => null, 'prefixDenyList' => []]]],
        [[['hostname' => [], 'levels' => null, 'prefixDenyList' => []]]], [[['hostname' => new stdClass(), 'levels' => null, 'prefixDenyList' => []]]],
        [[new ArrayObject(['hostname' => 'a.com', 'levels' => 2, 'prefixDenyList' => []])]], [[new ArrayObject([])]], [[new stdClass()]],
        [['k' => ['levels' => 2, 'hostname' => 'a.com', 'prefixDenyList' => []], 'j' => $restriction('com', 2)]], ['x'], [[], 'yes'], [[], null],
    ],
    'Host' => [
        [[]], [['a.com']], [['*.a.com']], [['*']], [['localhost', '127.0.0.1']], [[1, null]], [[stringable('*.a.com')]], [[['x']]], [], ['x'],
        [null], [['a.com'], 'extra'],
    ],
    'IP' => [
        [], [IP::ALL], [IP::V4], [IP::V6], ['IPV4'], [''], [null], [5], [stringable('ipv4')], [[]], [true], [IP::V4, 'extra'],
    ],
    'URL' => [
        [], [['http', 'https']], [['http'], true], [[], false, false], [[], false, true, true], [['https'], false, true, false, true],
        [[], false, true, false, true], [['com.example.app', 'https'], false, true, true, true], [['HTTP']], [[1, null, true, '']],
        [[stringable('http')]], [['http'], false, false], [[], true], [['ftp', 'mailto']], [['k' => 'https']], [[['x']]], [[throwing()]],
        ['x'], [[], 'yes'], [[], null], [[], false, true, false, 'yes'], [[], false, true, false, false, 'extra'],
    ],
    'Phone' => [[], [true], [false, true], [true, true], ['yes'], [null], [1, 0], [[]]],
];

$instances = [];
foreach ($constructions as $short => $variants) {
    $class = 'Utopia\\Validator\\' . $short;
    foreach ($variants as $arguments) {
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
        foreach (['getDescription', 'getType', 'isArray'] as $getter) {
            emit($case . ' ' . $getter, outcome(fn () => $made->$getter()));
        }
        foreach ($values as $label => $value) {
            emit($case . ' isValid ' . $label, outcome(fn () => $made->isValid($value)));
        }
    }
}
fwrite(STDERR, count($instances) . ' validators, ' . count($values) . ' values' . PHP_EOL);

// The same constructions from a file with strict types, where each argument must already be
// of its parameter's type.
foreach (['Domain' => '[[], "yes"]', 'URL' => '[[], 1]', 'IP' => '[5]', 'Phone' => '[1]', 'Hostname' => '[[1]]', 'Host' => '[[]]'] as $short => $arguments) {
    emit('strict new ' . $short . ' ' . $arguments, child('try { $v = new Utopia\\Validator\\' . $short . '(...' . $arguments . '); echo json_encode([$v->getDescription(), $v->isValid("a.com")]); } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(); }', true));
}

// The static methods, over every value and the arguments PHP converts or rejects.
foreach ($values as $label => $value) {
    emit('Phone::normalize ' . $label, outcome(fn () => Phone::normalize($value)));
}
foreach ([[], ['a', 'b'], [null]] as $arguments) {
    emit('Phone::normalize args ' . json_encode($arguments), outcome(fn () => Phone::normalize(...$arguments)));
}
emit('Phone::normalize strict int', child('try { echo Utopia\\Validator\\Phone::normalize(1234567); } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(); }', true));
$restrictions = [
    ['a.com'], ['a.com', 2], ['a.com', null], ['a.com', 2, ['api.']], ['a.com', '2'], ['a.com', 2.0], ['a.com', 2.5], ['a.com', '2x'], ['a.com', true],
    [1], [1.5], [true], [null], [stringable('a.com')], [[]], ['a.com', []], ['a.com', 2, 'x'], ['a.com', 2, null], [], ['a', 1, [], 'extra'],
    ['a.com', 'levels' => 3], ['hostname' => 'b.com', 'prefixDenyList' => ['x.']],
];
foreach ($restrictions as $arguments) {
    emit('Domain::createRestriction ' . json_encode(exported($arguments), JSON_UNESCAPED_SLASHES), outcome(fn () => Domain::createRestriction(...$arguments)));
}
emit('Domain::createRestriction strict', child('try { echo json_encode(Utopia\\Validator\\Domain::createRestriction("a.com", "2")); } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(); }', true));
emit('Domain::createRestriction static call on instance', outcome(fn () => (new Domain())::createRestriction('a.com', 2)));

// URL's private helper, through reflection.
$private = new ReflectionMethod(URL::class, 'isPrivateUseSchemeURI');
foreach ($values as $label => $value) {
    emit('URL::isPrivateUseSchemeURI ' . $label, outcome(fn () => $private->invoke(new URL(), $value)));
}
emit('URL::isPrivateUseSchemeURI from outside', outcome(fn () => (new URL())->isPrivateUseSchemeURI('com.x:/y')));

// How often each validator converts a Stringable value: PHP casts it again for every
// parse_url() it runs.
$counted = [
    'URL default' => [new URL(), 'https://a.com'], 'URL schemes' => [new URL(['https']), 'https://a.com'],
    'URL schemes no fragments' => [new URL(['https'], false, false), 'https://a.com'],
    'URL https or loopback http' => [new URL([], false, true, false, true), 'http://localhost'],
    'URL https or loopback https' => [new URL([], false, true, false, true), 'https://a.com'],
    'URL all' => [new URL(['http'], false, false, true, true), 'http://127.0.0.1'], 'URL invalid' => [new URL(['https']), 'not a url'],
    'URL private-use' => [new URL([], false, true, true), 'com.example.app:/cb'], 'Host' => [new Host(['a.com']), 'https://a.com'],
    'Host invalid' => [new Host(['a.com']), 'nope'], 'IP' => [new IP(), '127.0.0.1'], 'IP v6 type' => [new IP(IP::V6), '::1'],
    'Hostname' => [new Hostname(), 'a.com'], 'Domain' => [new Domain(), 'a.com'], 'Phone' => [new Phone(), '+1234567'],
];
foreach ($counted as $label => [$validator, $string]) {
    $value = stringable($string, $calls);
    emit('stringable calls ' . $label, outcome(function () use ($validator, $value, &$calls) {
        return [$validator->isValid($value), $calls];
    }));
}

// Each object itself.
foreach ($instances as $case => $made) {
    emit($case . ' object', outcome(fn () => [
        'var_dump' => (function () use ($made) {
            ob_start();
            var_dump($made);

            return withoutHandles(ob_get_clean());
        })(),
        'clone' => properties(clone $made), 'equal clone' => $made == clone $made, 'serialize' => serialize($made),
        'unserialize' => properties(unserialize(serialize($made))), 'json' => json_encode($made), 'export' => var_export($made, true),
    ]));
}
emit('equality across instances', outcome(fn () => [new URL() == new URL(), new URL() == new URL(['http']), new IP() == new IP(IP::V4), new Domain() == new Domain()]));

// Appwrite's subclass: PublicURL, with a PublicHostname that answers without DNS.
$publicHostname = new class () extends Appwrite\Network\Validator\PublicHostname {
    public function __construct()
    {
    }

    public function isValid(mixed $value): bool
    {
        return is_string($value) && !str_contains($value, 'private');
    }

    public function getDescription(): string
    {
        return 'stub hostname';
    }
};
$publicURL = new Appwrite\Network\Validator\PublicURL($publicHostname);
emit('PublicURL construct', outcome(fn () => [properties($publicURL), $publicURL->getDescription(), $publicURL->getType(), $publicURL->isArray()]));
foreach ($values as $label => $value) {
    emit('PublicURL isValid ' . $label, outcome(fn () => [$publicURL->isValid($value), $publicURL->getDescription()]));
}
foreach (['https://appwrite.io', 'http://private.appwrite.io', 'https://1.2.3.4', 'https://[::1]', 'https://example.invalid', 'ftp://appwrite.io', 'https://appwrite.io#f'] as $url) {
    emit('PublicURL ' . $url, outcome(fn () => [$publicURL->isValid($url), $publicURL->getDescription()]));
}

// Anonymous subclasses: protected state read and written, parent:: calls, constructors skipped.
$schemes = new class () extends URL {
    public function __construct()
    {
        parent::__construct(['https'], false, false);
        $this->allowedSchemes[] = 'ftp';
    }

    public function getDescription(): string
    {
        return '[' . parent::getDescription() . ']';
    }

    public function state(): array
    {
        return [$this->allowedSchemes, $this->allowEmpty, $this->allowFragments, $this->allowPrivateUseSchemes, $this->httpsOrLoopback];
    }
};
emit('subclass URL', outcome(fn () => [$schemes->state(), $schemes->getDescription(), $schemes->isValid('ftp://a.com'), $schemes->isValid('http://a.com'), $schemes->isValid('https://a.com#f'), properties($schemes)]));
$bare = new class () extends URL {
    public function __construct()
    {
    }
};
foreach (['isValid' => fn () => $bare->isValid('https://a.com'), 'getDescription' => fn () => $bare->getDescription(), 'properties' => fn () => properties($bare)] as $name => $run) {
    emit('subclass URL without parent constructor ' . $name, outcome($run));
}
$partial = new class () extends URL {
    public function __construct()
    {
        $this->allowEmpty = true;
        $this->allowPrivateUseSchemes = true;
    }
};
emit('subclass URL partly initialized', outcome(fn () => [outcome(fn () => $partial->isValid('')), outcome(fn () => $partial->isValid('com.x:/y')), outcome(fn () => $partial->isValid('https://a.com'))]));
$ownHelper = new class () extends URL {
    private function isPrivateUseSchemeURI($value): bool
    {
        return true;
    }

    public function __construct()
    {
        parent::__construct([], false, true, true);
    }
};
emit('subclass URL private helper not overridden', outcome(fn () => [$ownHelper->isValid('nonsense'), $ownHelper->isValid('com.x:/y')]));
$ip = new class () extends IP {
    public function use(string $type): bool
    {
        $this->type = $type;

        return $this->isValid('::1');
    }
};
emit('subclass IP', outcome(fn () => [$ip->use(IP::V6), $ip->use(IP::V4), $ip->use('nonsense'), $ip->use('IPV6'), properties($ip), outcome(fn () => $ip->use(...[[]]))]));
$bareIp = new class () extends IP {
    public function __construct()
    {
    }
};
emit('subclass IP without parent constructor', outcome(fn () => $bareIp->isValid('::1')));
$hostname = new class (['*.a.com']) extends Hostname {
    public function isValid(mixed $value): bool
    {
        $this->allowList[] = 'b.com';

        return parent::isValid($value);
    }

    public function list(): array
    {
        return $this->allowList;
    }
};
emit('subclass Hostname', outcome(fn () => [$hostname->isValid('b.com'), $hostname->isValid('x.a.com'), $hostname->list(), properties($hostname)]));
$bareHostname = new class () extends Hostname {
    public function __construct()
    {
    }
};
emit('subclass Hostname without parent constructor', outcome(fn () => [outcome(fn () => $bareHostname->isValid('a/b')), outcome(fn () => $bareHostname->isValid('a.com'))]));
$domain = new class () extends Domain {
    public function __construct()
    {
        parent::__construct([self::createRestriction('a.com', 2)]);
        $this->restrictions[] = static::createRestriction('b.com', null, ['api.']);
        $this->hostnames = false;
    }
};
emit('subclass Domain', outcome(fn () => [$domain->isValid('x.a.com'), $domain->isValid('a.com'), $domain->isValid('api.b.com'), $domain->isValid('x_y.b.com'), properties($domain)]));
$bareDomain = new class () extends Domain {
    public function __construct()
    {
    }
};
emit('subclass Domain without parent constructor', outcome(fn () => [outcome(fn () => $bareDomain->isValid('')), outcome(fn () => $bareDomain->isValid('a.com'))]));
$phone = new class (false, true) extends Phone {
    public static function normalize(string $value): string
    {
        return '+19999999';
    }
};
emit('subclass Phone normalize not used by isValid', outcome(fn () => [$phone->isValid('1234567'), $phone->isValid('nope'), $phone::normalize('x'), Phone::normalize('1234567')]));
$host = new class (['a.com']) extends Host {
    public function isValid($value): bool
    {
        $this->whitelist = ['*'];

        return parent::isValid($value);
    }
};
emit('subclass Host', outcome(fn () => [$host->isValid('https://anything.com'), $host->getDescription(), properties($host)]));
$bareHost = new class () extends Host {
    public function __construct()
    {
    }
};
emit('subclass Host without parent constructor', outcome(fn () => [outcome(fn () => $bareHost->isValid('nope')), outcome(fn () => $bareHost->isValid('https://a.com')), outcome(fn () => $bareHost->getDescription())]));

// Declarations the engine checks against the native classes, from a child process.
$declarations = [
    'narrower URL isValid' => 'new class () extends Utopia\\Validator\\URL { public function isValid(string $value): bool { return true; } };',
    'typed URL isValid' => 'echo (new class () extends Utopia\\Validator\\URL { public function isValid(mixed $value): bool { return true; } })->isValid(1) ? "ok" : "no";',
    'narrower Hostname isValid' => 'new class () extends Utopia\\Validator\\Hostname { public function isValid(string $value): bool { return true; } };',
    'untyped Hostname isValid' => 'echo (new class () extends Utopia\\Validator\\Hostname { public function isValid($value): bool { return true; } })->isValid(1) ? "ok" : "no";',
    'createRestriction narrower' => 'new class () extends Utopia\\Validator\\Domain { public static function createRestriction(int $hostname): array { return []; } };',
    'createRestriction non-static' => 'new class () extends Utopia\\Validator\\Domain { public function createRestriction(string $hostname, ?int $levels = null, array $prefixDenyList = []): array { return []; } };',
    'normalize non-static' => 'new class () extends Utopia\\Validator\\Phone { public function normalize(string $value): string { return $value; } };',
    'normalize wider' => 'echo (new class () extends Utopia\\Validator\\Phone { public static function normalize(mixed $value): string { return "+1234567"; } })::normalize(1);',
    'redeclare IP type' => 'echo json_encode((array) new class () extends Utopia\\Validator\\IP { protected string $type = "ipv6"; });',
    'redeclare IP type narrower' => 'new class () extends Utopia\\Validator\\IP { protected int $type = 1; };',
    'override IP constant' => 'echo json_encode((new ReflectionClass(new class () extends Utopia\\Validator\\IP { const V4 = "x"; }))->getConstants());',
    'URL private helper public in subclass' => 'echo (new class () extends Utopia\\Validator\\URL { public function isPrivateUseSchemeURI($value): bool { return false; } })->isPrivateUseSchemeURI("x") ? "t" : "f";',
    'final class' => 'final class F extends Utopia\\Validator\\Phone {} echo (new F())->isValid("+1234567") ? "ok" : "no";',
    'abstract leaf' => 'abstract class A extends Utopia\\Validator\\Host {} echo "ok";',
    'static isValid' => 'new class () extends Utopia\\Validator\\IP { public static function isValid($value): bool { return true; } };',
];
foreach ($declarations as $name => $code) {
    emit('declaration ' . $name, child($code));
}

// Known differences of the extension, each a waiver in crates/extension/README.md: Phone runs
// its regular expressions without PHP's preg functions, so it leaves preg_last_error() as it was.
$waivers = [
    'preg_last_error after Phone' => outcome(function () {
        preg_match('/a/u', "\xff");
        (new Phone())->isValid('+1234567');

        return preg_last_error();
    }),
    'preg_last_error after Phone::normalize' => outcome(function () {
        preg_match('/a/u', "\xff");
        Phone::normalize('1234567');

        return preg_last_error();
    }),
];
$expected = $internal ? [
    'preg_last_error after Phone' => ['value' => PREG_BAD_UTF8_ERROR],
    'preg_last_error after Phone::normalize' => ['value' => PREG_BAD_UTF8_ERROR],
] : [
    'preg_last_error after Phone' => ['value' => PREG_NO_ERROR],
    'preg_last_error after Phone::normalize' => ['value' => PREG_NO_ERROR],
];
foreach ($expected as $name => $outcome) {
    if ($waivers[$name] !== $outcome) {
        fwrite(STDERR, "Waiver changed: {$name}: " . json_encode(exported($waivers[$name])) . PHP_EOL);
        exit(1);
    }
}
fwrite(STDERR, count($expected) . ' waivers hold' . PHP_EOL);
