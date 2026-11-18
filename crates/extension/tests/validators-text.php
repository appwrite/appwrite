<?php

// Parity corpus for the text leaf validators: Text, Identifier, WhiteList, Contains and Label.
// Run it on the PHP classes and on the utopia extension's
// (_APP_EXTENSION_LIBRARIES=validators,validators-text), and diff stdout: every line must
// match. Stderr names the implementation that ran.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Validator\Contains;
use Utopia\Validator\Identifier;
use Utopia\Validator\Label;
use Utopia\Validator\Text;
use Utopia\Validator\Validator;
use Utopia\Validator\WhiteList;

const CLASSES = [Text::class, Identifier::class, WhiteList::class, Contains::class, Label::class];

$internal = (new ReflectionClass(Text::class))->isInternal();
fwrite(STDERR, 'Utopia\\Validator\\Text: ' . ($internal ? 'utopia extension' : 'PHP') . PHP_EOL);
// A run meant for the extension that gets the PHP classes would compare PHP with PHP and pass.
$switches = array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: ''));
$requested = in_array('validators', $switches, true) && in_array('validators-text', $switches, true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' validators and validators-text, but the other implementation loaded' . PHP_EOL);
    exit(1);
}
foreach (CLASSES as $class) {
    if ((new ReflectionClass($class))->isInternal() !== $internal) {
        fwrite(STDERR, $class . ' is not from the same implementation as Text' . PHP_EOL);
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
emit('constants as values', outcome(fn () => [Text::NUMBERS, Text::ALPHABET_UPPER, Text::ALPHABET_LOWER, Label::MAX_LENGTH, Identifier::NUMBERS, Text::TYPE_STRING]));
emit('constants spread', outcome(fn () => [...Text::NUMBERS, ...Text::ALPHABET_LOWER]));
emit('constants modified copy', outcome(function () {
    $numbers = Text::NUMBERS;
    $numbers[] = 'x';
    $numbers[0] = 'y';
    sort($numbers);

    return [$numbers, Text::NUMBERS, constant(Text::class . '::NUMBERS') === Text::NUMBERS];
}));

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

// The values isValid() checks.
$values = [
    'null' => null, 'true' => true, 'false' => false, '0' => 0, '1' => 1, '-1' => -1, '10' => 10, '1.0' => 1.0, '1.5' => 1.5, '0.0' => 0.0,
    '-0.0' => -0.0, 'NAN' => NAN, 'INF' => INF, '1e20' => 1e20, '0.1+0.2' => 0.1 + 0.2,
    "''" => '', "' '" => ' ', "'a'" => 'a', "'A'" => 'A', "'ab'" => 'ab', "'abc'" => 'abc', "'abcd'" => 'abcd', "'abcde'" => 'abcde', "'abcdef'" => 'abcdef',
    "'Abc'" => 'Abc', "'ABC'" => 'ABC', "'a b'" => 'a b', "'a_b'" => 'a_b', "'_a'" => '_a', "'a1'" => 'a1', "'1a'" => '1a', "'1'" => '1', "'0'" => '0',
    "'01'" => '01', "'1.0'" => '1.0', "'1e0'" => '1e0', "' 1'" => ' 1', "'1 '" => '1 ', "'10'" => '10', "'0x1'" => '0x1', "'a-b'" => 'a-b', "'-ab'" => '-ab',
    "'ab-'" => 'ab-', "'a--b'" => 'a--b', "'a.b'" => 'a.b', "'a\\n'" => "a\n", "'\\na'" => "\na", "'a\\t'" => "a\t", "'a\\x00b'" => "a\x00b",
    "'\\t\\n\\r'" => "\t\n\r", "'\\v\\f'" => "\v\f", 'nbsp' => "\u{00A0}", 'nel' => "\u{0085}", 'line sep' => "\u{2028}", 'para sep' => "\u{2029}",
    'zwsp' => "\u{200B}", 'zwj' => "\u{200D}", 'bom' => "\u{FEFF}", 'ideographic space' => "\u{3000}", 'ogham space' => "\u{1680}",
    'soft hyphen' => "\u{00AD}", 'nbsp a' => "\u{00A0}a", 'é' => 'é', 'É' => 'É', 'e + combining acute' => "e\u{0301}", 'ñ' => 'ñ', 'ß' => 'ß', 'ẞ' => 'ẞ',
    'İ' => 'İ', 'ı' => 'ı', 'Σ' => 'Σ', 'ΑΣ' => 'ΑΣ', 'ΑΣ Α' => 'ΑΣ Α', 'ΣΑ' => 'ΣΑ', 'Ǆ' => 'Ǆ', 'ﬃ' => 'ﬃ', 'Ω' => 'Ω', 'K kelvin' => "\u{212A}",
    '日本語' => '日本語', '한국어' => '한국어', 'عربي' => 'عربي', 'emoji' => '😀', 'emoji family' => '👨‍👩‍👧', 'flag' => '🇮🇳', 'mixed' => 'aé😀',
    'not utf-8' => "\xff", 'not utf-8 pair' => "\xff\xfe", 'overlong' => "\xc0\xaf", 'surrogate' => "\xed\xa0\x80", 'truncated' => "\xe2\x82",
    'lone continuation' => "\x80", 'too high' => "\xf4\x90\x80\x80", 'a + not utf-8' => "a\xff", 'Latin-1 é' => "\xe9",
    'long 64' => str_repeat('a', 64), 'long 63' => str_repeat('a', 63), 'long multibyte' => str_repeat('é', 6),
    '[]' => [], '[a]' => ['a'], 'object' => new stdClass(), "stringable 'a'" => stringable('a'), "stringable 'ABC'" => stringable('ABC'),
    'throwing stringable' => throwing(), 'enum' => Suit::Hearts, 'closure' => fn () => 1,
];

// The constructors, over arguments of every kind PHP converts or rejects.
$constructions = [
    'Text' => [
        [5], [0], [0, 0], [5, 5], [5, 0], [3, 1, ['a', 'b', 'c']], [10, 1, Text::NUMBERS], [10, 2, [...Text::ALPHABET_LOWER, ...Text::NUMBERS], true],
        [0, 1, [], true], [5, 1, [1, 2.0, true]], [5, 1, ['1', 'a' => 'b', 5 => 'c']], [5, 1, [null, false, 0]], [5, 1, [[1], 'a']],
        [5, 1, [stringable('a'), 'b']], [5, 1, ['é', 'ab', '']], [10, 3], [3, 10], [-1, -1], [PHP_INT_MAX], ['5'], [' 5'], ['5abc'], ['abc'], [5.0], [5.5],
        [true], [null], [[]], [5, '2'], [5, null], [5, 1, null], [5, 1, 'abc'], [5, 1, [], 'yes'], [5, 1, [], null], [], [stringable('5')],
        [5, 1, ['a'], false, 'extra'],
    ],
    'Identifier' => [[], [0], [3], [10], ['3'], [3.0], [3.5], [null], [[]], [true], [3, 'extra']],
    'WhiteList' => [
        [['a', 'B', 'c']], [['a', 'B'], true], [['a', 'B'], false, Validator::TYPE_MIXED], [[1, 2, 3]], [[1, 2, 3], true], [[1.5, 0.1 + 0.2, -0.0, 1e20, INF]],
        [[true, false, null]], [[true, false, null], true], [['x' => 'Y', 5 => 'Z', -3 => 'w', '7' => 'v']], [[]], [[], true], [['ÀÉ', 'ß', 'ΣΑ']],
        [[[1], 'a']], [[stringable('ABC'), 'd']], [[new stdClass(), 'a']], [[throwing()]], [[Suit::Hearts]], [[Suit::Hearts], true], [['a'], 'yes'],
        [['a'], null], [['a'], false, 5], [['a'], false, null], ['abc'], [null], [], [['1', '01', '1.0', '1e0', ' 1']], [['0', '', 'null', 'false']],
        [['a'], false, 'string', 'extra'],
    ],
    'Contains' => [
        [['a']], [['ab', 'cd']], [['AB'], true], [['AB'], false], [['']], [['é', 'Σ']], [['ΣΑ'], true], [[1, 2.5, true]], [[1, 2.5, true], true],
        [[null]], [[[1]]], [[[1]], true], [[stringable('X')]], [[new stdClass()]], [[new stdClass()], true], [[throwing()]], [[Suit::Hearts]],
        [['k' => 'a', 9 => 'b']], [[]], [[], true], ['abc'], [null], [], [['a'], 'yes'], [['a'], null], [["\xff"]], [["\xff"], true], [['a'], false, 'extra'],
    ],
    'Label' => [[], ['extra']],
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
        $getters = ['getDescription', 'getType', 'isArray'];
        if ($made instanceof WhiteList) {
            $getters[] = 'getList';
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

// Every code point, in ranges of the same outcome: what Text refuses as blank (\p{Z}, \p{Cf}
// and \s under /u), and what Contains folds (each code point against the lower case PHP gives
// it, so a code point lowered differently fails).
$blank = new Text(0, 0, [], true);
$folds = [];
$runs = ['blank' => [], 'fold' => []];
$last = ['blank' => null, 'fold' => null];
for ($code = 0; $code <= 0x10FFFF; $code++) {
    if ($code >= 0xD800 && $code <= 0xDFFF) {
        continue;
    }
    $char = mb_chr($code, 'UTF-8');
    $outcomes = [
        'blank' => $blank->isValid($char) ? 'kept' : 'blank',
        'fold' => (new Contains([mb_strtolower($char, 'UTF-8')]))->isValid($char) && (new Contains([$char]))->isValid(mb_strtoupper($char, 'UTF-8')) ? 'folds' : 'differs',
    ];
    foreach ($outcomes as $kind => $outcome) {
        if ($last[$kind] === null || $last[$kind][1] !== $outcome) {
            $runs[$kind][] = [sprintf('U+%04X', $code), $outcome];
        }
        $last[$kind] = [$code, $outcome];
    }
}
foreach ($runs as $kind => $list) {
    foreach (array_chunk($list, 50) as $i => $chunk) {
        emit('code points ' . $kind . ' ' . $i, ['value' => $chunk]);
    }
}

// Character counts (mb_strlen) of every two-byte string, through Text's bounds, and of
// seeded random byte strings.
$one = new Text(1, 1);
$two = new Text(2, 2);
$counts = [];
for ($a = 0; $a < 256; $a++) {
    $row = '';
    for ($b = 0; $b < 256; $b++) {
        $bytes = chr($a) . chr($b);
        $row .= $one->isValid($bytes) ? '1' : ($two->isValid($bytes) ? '2' : '0');
    }
    $counts[] = $row;
}
foreach (array_chunk($counts, 16) as $i => $chunk) {
    emit('two-byte counts ' . $i, ['value' => $chunk]);
}
mt_srand(7503);
$texts = array_map(fn ($n) => new Text($n, $n), range(1, 8));
$alphabet = "\x00\x41\x7f\x80\xbf\xc0\xc1\xc2\xdf\xe0\xe1\xed\xef\xf0\xf1\xf4\xf5\xf8\xfc\xff";
for ($i = 0; $i < 4000; $i++) {
    $length = mt_rand(1, 8);
    $bytes = '';
    for ($j = 0; $j < $length; $j++) {
        $bytes .= mt_rand(0, 2) === 0 ? chr(mt_rand(0, 255)) : $alphabet[mt_rand(0, strlen($alphabet) - 1)];
    }
    // The character count: the n of the one Text(n, n) that accepts it.
    $chars = null;
    foreach ($texts as $text) {
        if ($text->isValid($bytes)) {
            $chars = $text->getDescription();
            break;
        }
    }
    $lower = (new Contains(['?']))->isValid($bytes);
    emit('random bytes ' . $i, ['value' => [bin2hex($bytes), $chars, $lower]]);
}

// Loose comparisons: an allow list and a white list of every kind of value, against values
// of every kind.
$items = [
    'int 1' => 1, 'int 0' => 0, 'float 1.0' => 1.0, 'float 1.5' => 1.5, 'true' => true, 'false' => false, 'null' => null, "'1'" => '1', "'01'" => '01',
    "'1.0'" => '1.0', "'1e0'" => '1e0', "' 1'" => ' 1', "''" => '', "'a'" => 'a', "'A'" => 'A', "'abc'" => 'abc', 'array' => [1], 'stringable a' => stringable('a'),
    'object' => new stdClass(), 'enum' => Suit::Hearts, 'INF' => INF, 'NAN' => NAN,
];
foreach ($items as $label => $item) {
    $allow = new Text(0, 0, [$item]);
    emit('allow list ' . $label, outcome(fn () => array_map(fn ($v) => outcome(fn () => $allow->isValid($v)), ['1', '0', 'a', 'A', ' ', '', '11', '1a', '.', 'e', '1.0'])));
    foreach ([false, true] as $strict) {
        emit('white list ' . $label . ($strict ? ' strict' : ''), outcome(function () use ($item, $strict, $items) {
            $list = new WhiteList([$item], $strict);

            return [properties($list), array_map(fn ($v) => outcome(fn () => $list->isValid($v)), $items)];
        }));
    }
    emit('contains ' . $label, outcome(function () use ($item) {
        $contains = new Contains([$item]);
        $strict = new Contains([$item], true);

        return [outcome(fn () => $contains->getDescription()), array_map(fn ($v) => [outcome(fn () => $contains->isValid($v)), outcome(fn () => $strict->isValid($v))], ['1', 'a1', 'A', 'xay', '', '1.5', 'Array', 'H'])];
    }));
}

// Calls the methods' signatures reject.
foreach (CLASSES as $name) {
    $short = substr($name, strrpos($name, '\\') + 1);
    $validator = $instances[array_key_first(array_filter($instances, fn ($v) => $v::class === $name))];
    emit('isValid() no argument ' . $short, outcome(fn () => $validator->isValid()));
    emit('isValid() two arguments ' . $short, outcome(fn () => $validator->isValid('a', 2)));
    emit('isValid() named ' . $short, outcome(fn () => $validator->isValid(value: 'a')));
    emit('isValid() unknown named ' . $short, outcome(fn () => $validator->isValid(other: 'a')));
    emit('getDescription() with an argument ' . $short, outcome(fn () => $validator->getDescription(1)));
    emit('via reflection ' . $short, outcome(fn () => (new ReflectionMethod($name, 'isValid'))->invoke($validator, 'a')));
    emit('call_user_func ' . $short, outcome(fn () => call_user_func([$validator, 'isValid'], 'a')));
    emit('callable ' . $short, outcome(fn () => array_map($validator->isValid(...), ['a', 1, 'ab-c'])));
}
emit('new Text named', outcome(fn () => properties(new Text(requireNonBlank: true, length: 4))));
emit('new Text named allowList', outcome(fn () => properties(new Text(3, allowList: Text::NUMBERS))));
emit('new Text unknown named', outcome(fn () => new Text(1, other: 2)));
emit('new Text via reflection, too few', outcome(fn () => (new ReflectionClass(Text::class))->newInstance()));
emit('new WhiteList named', outcome(fn () => properties(new WhiteList(type: 'integer', list: ['A']))));
emit('new Contains named', outcome(fn () => properties(new Contains(strict: true, patterns: ['A']))));
emit('new Identifier named', outcome(fn () => properties(new Identifier(length: 4))));
foreach ([Text::class, Identifier::class, WhiteList::class, Contains::class] as $name) {
    emit('newInstanceWithoutConstructor ' . $name, outcome(function () use ($name) {
        $validator = (new ReflectionClass($name))->newInstanceWithoutConstructor();

        return [properties($validator), outcome(fn () => $validator->isValid('a')), outcome(fn () => $validator->isValid(1)), outcome(fn () => $validator->getDescription()),
            outcome(fn () => $validator->getType()), $validator instanceof WhiteList ? outcome(fn () => $validator->getList()) : null];
    }));
}

// Strict types: the caller's declaration decides the conversions, as for any call.
$strict = [
    'Text string length' => 'new Utopia\\Validator\\Text("5");',
    'Text float length' => 'new Utopia\\Validator\\Text(5.0);',
    'Text int non-blank' => 'new Utopia\\Validator\\Text(5, 1, [], 1);',
    'Identifier string length' => 'new Utopia\\Validator\\Identifier("5");',
    'WhiteList int type' => 'new Utopia\\Validator\\WhiteList(["a"], false, 5);',
    'WhiteList int strict' => 'new Utopia\\Validator\\WhiteList(["a"], 1);',
    'WhiteList stringable type' => 'new Utopia\\Validator\\WhiteList(["a"], false, new class { function __toString(): string { return "x"; } });',
    'Contains int strict' => 'new Utopia\\Validator\\Contains(["a"], 1);',
    'isValid strict' => 'var_dump((new Utopia\\Validator\\Text(5))->isValid(5), (new Utopia\\Validator\\WhiteList([1]))->isValid(1), (new Utopia\\Validator\\Contains(["1"]))->isValid(1));',
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

        return [$serialized, $back == $validator, $back::class, $back->getDescription(), $back->isValid('a')];
    }));
    emit($case . ' json', outcome(fn () => json_encode($validator)));
    emit($case . ' get_object_vars', outcome(fn () => exported(get_object_vars($validator))));
    emit($case . ' dynamic property', outcome(function () use ($validator) {
        $copy = clone $validator;
        $copy->extra = 1;

        return properties($copy);
    }));
    emit($case . ' read protected', outcome(fn () => $validator->length ?? $validator->list ?? 'unset'));
}
emit('compare', outcome(fn () => [new Text(5) == new Text(5), new Text(5) == new Text(6), new Text(5) == new Identifier(5), new Text(5, 1) < new Text(6, 1),
    new WhiteList(['A']) == new WhiteList(['a']), new WhiteList(['A'], true) == new WhiteList(['a'], true), new Contains(['a']) <=> new Contains(['b'])]));
emit('getList copy', outcome(function () {
    $list = new WhiteList(['A', 'B']);
    $copy = $list->getList();
    $copy[] = 'z';
    $copy[0] = 'q';

    return [$copy, $list->getList(), $list->isValid('q')];
}));
emit('unserialize crafted Text', outcome(function () {
    $text = unserialize('O:21:"Utopia\\Validator\\Text":2:{s:9:"' . "\0*\0" . 'length";i:2;s:6:"' . "\0*\0" . 'min";i:1;}');

    return [properties($text), outcome(fn () => $text->isValid('ab')), outcome(fn () => $text->isValid('abc'))];
}));
emit('unserialize crafted wrong type', outcome(fn () => unserialize('O:21:"Utopia\\Validator\\Text":1:{s:9:"' . "\0*\0" . 'length";s:3:"abc";}')));

// The two subclasses Appwrite has, when this tree has them.
$appwrite = [
    'Appwrite\\Platform\\Modules\\Compute\\Validator\\VariableKey' => [[], [5], [0]],
    'Appwrite\\Utopia\\Database\\Validator\\Filename' => [[255], [10, 2], [0, 0]],
];
foreach ($appwrite as $name => $variants) {
    if (!class_exists($name)) {
        emit('appwrite ' . $name, ['value' => 'missing']);
        continue;
    }
    foreach ($variants as $arguments) {
        emit('appwrite ' . $name . ' ' . json_encode($arguments), outcome(function () use ($name, $arguments, $values) {
            $validator = new $name(...$arguments);

            return [properties($validator), $validator->getDescription(), $validator->getType(), array_map(fn ($v) => outcome(fn () => $validator->isValid($v)), $values)];
        }));
    }
}

// PHP subclasses of the native leaves, reading and writing every protected property.
$text = new class (8) extends Text {
    public string $label = 'first';

    public function getDescription(): string
    {
        return parent::getDescription() . ' (' . $this->label . ')';
    }

    public function set(string $property, mixed $value): void
    {
        $this->$property = $value;
    }

    public function read(): array
    {
        return [$this->length, $this->min, $this->allowList, $this->requireNonBlank];
    }

    public function isValid(mixed $value): bool
    {
        return parent::isValid($value) && $value !== 'nope';
    }
};
emit('subclass Text', outcome(fn () => [$text instanceof Text, $text instanceof Validator, $text->getDescription(), $text->isValid('abc'), $text->isValid('nope'), $text->read(), properties($text)]));
foreach ([['length', 2], ['min', 4], ['allowList', ['a', 'b']], ['requireNonBlank', true], ['length', '3'], ['length', 'abc'], ['allowList', 'abc'], ['min', null]] as [$property, $value]) {
    emit('subclass Text set ' . $property . ' ' . json_encode($value), outcome(function () use ($text, $property, $value) {
        $copy = clone $text;
        $copy->set($property, $value);

        return [$copy->read(), $copy->getDescription(), array_map(fn ($v) => $copy->isValid($v), ['a', 'ab', 'abc', 'abcd', 'abcde', ' ', 'ba'])];
    }));
}
$bare = new class () extends Text {
    public function __construct()
    {
    }
};
emit('subclass Text without parent constructor', outcome(fn () => [properties($bare), outcome(fn () => $bare->isValid('a')), outcome(fn () => $bare->isValid(1)),
    outcome(fn () => $bare->getDescription()), outcome(fn () => $bare->getType())]));
$partial = new class () extends Text {
    public function __construct()
    {
        $this->requireNonBlank = false;
        $this->min = 1;
    }
};
emit('subclass Text partly set', outcome(fn () => [outcome(fn () => $partial->isValid('a')), outcome(fn () => $partial->isValid('')), outcome(fn () => $partial->getDescription())]));
$identifier = new class () extends Identifier {
    public function __construct()
    {
        parent::__construct(4);
        $this->allowList = ['a', 'b', '_'];
    }
};
emit('subclass Identifier', outcome(fn () => [properties($identifier), $identifier->getDescription(), array_map(fn ($v) => $identifier->isValid($v), ['ab', 'a_b', 'abc', '_', 'aaaaa'])]));
$whiteList = new class (['A', 'B']) extends WhiteList {
    public function add(mixed $item): void
    {
        $this->list[] = $item;
    }

    public function strict(bool $strict): void
    {
        $this->strict = $strict;
    }
};
emit('subclass WhiteList', outcome(function () use ($whiteList) {
    $copy = clone $whiteList;
    $copy->add('C');
    $copy->add(5);
    $result = [$copy->getList(), $copy->getDescription(), $copy->isValid('c'), $copy->isValid('C'), $copy->isValid(5)];
    $copy->strict(true);

    return [...$result, $copy->isValid('c'), $copy->isValid('C'), $copy->isValid(5), $copy->isValid('5')];
}));
$caught = new class () extends WhiteList {
    public ?string $failure = null;

    public function __construct()
    {
        try {
            parent::__construct(['A', 'B', new stdClass(), 'D']);
        } catch (Error $e) {
            $this->failure = $e->getMessage();
        }
    }
};
emit('subclass WhiteList constructor fails half way', outcome(fn () => [properties($caught), $caught->failure, outcome(fn () => $caught->isValid('a')), outcome(fn () => $caught->isValid('D'))]));
$contains = new class (['Ab']) extends Contains {
    public function patterns(array $patterns): void
    {
        $this->patterns = $patterns;
    }
};
emit('subclass Contains', outcome(function () use ($contains) {
    $copy = clone $contains;
    $result = [$copy->isValid('xaby'), $copy->getDescription()];
    $copy->patterns([]);

    return [...$result, $copy->isValid('xaby'), $copy->getDescription(), properties($copy)];
}));
$emptyContains = new class () extends Contains {
    public function __construct()
    {
        try {
            parent::__construct([], true);
        } catch (InvalidArgumentException) {
        }
    }
};
emit('subclass Contains empty patterns', outcome(fn () => [properties($emptyContains), outcome(fn () => $emptyContains->isValid('a')), outcome(fn () => $emptyContains->getDescription())]));
$label = new class () extends Label {
    public function isValid(mixed $value): bool
    {
        return parent::isValid($value) && strlen($value) > 2;
    }
};
emit('subclass Label', outcome(fn () => [$label->isValid('ab'), $label->isValid('abc'), $label::MAX_LENGTH, $label->getDescription()]));

// Declarations the engine checks against the native classes, from a child process.
$declarations = [
    'narrower isValid' => 'new class (1) extends Utopia\\Validator\\Text { public function isValid(string $value): bool { return true; } };',
    'untyped isValid' => 'echo (new class (1) extends Utopia\\Validator\\Text { public function isValid($value): bool { return true; } })->isValid(1) ? "ok" : "no";',
    'Contains typed isValid' => 'echo (new class (["a"]) extends Utopia\\Validator\\Contains { public function isValid(mixed $value): bool { return true; } })->isValid(1) ? "ok" : "no";',
    'Contains narrower isValid' => 'new class (["a"]) extends Utopia\\Validator\\Contains { public function isValid(string $value): bool { return true; } };',
    'getList narrower' => 'new class (["a"]) extends Utopia\\Validator\\WhiteList { public function getList(): string { return ""; } };',
    'Text bad constructor' => 'new class () extends Utopia\\Validator\\Text { public function __construct(string $x = "") { parent::__construct(1); } }; echo "ok";',
    'redeclare property' => 'echo json_encode((array) new class (1) extends Utopia\\Validator\\Text { protected int $length = 7; });',
    'redeclare property narrower' => 'new class (1) extends Utopia\\Validator\\Text { protected string $length = "7"; };',
    'redeclare property private' => 'new class (1) extends Utopia\\Validator\\Text { private int $length = 7; };',
    'override constant' => 'echo json_encode((new ReflectionClass(new class (1) extends Utopia\\Validator\\Text { const NUMBERS = ["x"]; }))->getConstants());',
    'override typed constant' => 'echo (new class () extends Utopia\\Validator\\Label { const MAX_LENGTH = 10; })::MAX_LENGTH;',
    'override typed constant wrong type' => 'new class () extends Utopia\\Validator\\Label { const MAX_LENGTH = "x"; };',
    'override typed constant narrower type' => 'new class () extends Utopia\\Validator\\Label { const string MAX_LENGTH = "x"; };',
    'final class' => 'final class F extends Utopia\\Validator\\Label {} echo (new F())->isValid("a") ? "ok" : "no";',
    'readonly class' => 'readonly class R extends Utopia\\Validator\\Label {}',
    'abstract leaf' => 'abstract class A extends Utopia\\Validator\\Text {} echo "ok";',
    'static isValid' => 'new class () extends Utopia\\Validator\\Label { public static function isValid($value): bool { return true; } };',
];
foreach ($declarations as $name => $code) {
    emit('declaration ' . $name, child($code));
}

// Known differences of the extension, each a waiver in crates/extension/README.md: the native
// classes run their regular expressions without PHP's preg functions, so they leave
// preg_last_error() as it was.
$waivers = [
    'preg_last_error after Text' => outcome(function () {
        preg_match('/a/', 'a');
        (new Text(0, 0, [], true))->isValid("\xff");

        return preg_last_error();
    }),
    'preg_last_error after Identifier' => outcome(function () {
        preg_match('/a/u', "\xff");
        (new Identifier())->isValid('abc');

        return preg_last_error();
    }),
];
$expected = $internal ? [
    'preg_last_error after Text' => ['value' => PREG_NO_ERROR],
    'preg_last_error after Identifier' => ['value' => PREG_BAD_UTF8_ERROR],
] : [
    'preg_last_error after Text' => ['value' => PREG_BAD_UTF8_ERROR],
    'preg_last_error after Identifier' => ['value' => PREG_NO_ERROR],
];
foreach ($expected as $name => $outcome) {
    if ($waivers[$name] !== $outcome) {
        fwrite(STDERR, "Waiver changed: {$name}: " . json_encode(exported($waivers[$name])) . PHP_EOL);
        exit(1);
    }
}
fwrite(STDERR, count($expected) . ' waivers hold' . PHP_EOL);
