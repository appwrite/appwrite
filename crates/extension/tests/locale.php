<?php

// Parity corpus for Utopia\Locale\Locale. Run it on the PHP class and on the utopia
// extension's (_APP_EXTENSION_LIBRARIES=locale), and diff stdout: every line must match.
// Stderr names the implementation that ran. Languages are static state, so cases run in
// order on one set of languages, as they would in a worker.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Locale\Locale;

$internal = (new ReflectionClass(Locale::class))->isInternal();
fwrite(STDERR, 'Utopia\\Locale\\Locale: ' . ($internal ? 'utopia extension' : 'PHP') . PHP_EOL);
// A run meant for the extension that gets the PHP class would compare PHP with PHP and pass.
$requested = in_array('locale', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' locale, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

/** Warnings raised by the current case, without the file and line they point at. */
$warnings = [];
set_error_handler(function (int $level, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});

/** The observable outcome of $run: its value or exception, and the warnings it raised. */
function outcome(callable $run): array
{
    global $warnings;
    $warnings = [];
    try {
        $result = ['value' => $run()];
    } catch (Throwable $e) {
        $result = ['throws' => $e::class, 'message' => $e->getMessage()];
    }
    if ($warnings !== []) {
        $result['warnings'] = $warnings;
    }

    return $result;
}

function emit(string $case, array $result): void
{
    echo json_encode([$case, $result], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
}

$files = sys_get_temp_dir() . '/utopia-locale-corpus';
is_dir($files) || mkdir($files);
$file = function (string $name, string $content) use ($files): string {
    file_put_contents("{$files}/{$name}", $content);

    return "{$files}/{$name}";
};

// Every Appwrite translation, the way app/init/locales.php loads them.
$translations = __DIR__ . '/../../../app/config/locale/translations';
$codes = array_map(fn (string $path) => basename($path, '.json'), glob("{$translations}/*.json"));
emit('exceptions default', outcome(fn () => Locale::$exceptions));
Locale::$exceptions = false;
foreach ($codes as $code) {
    emit('load ' . $code, outcome(fn () => Locale::setLanguageFromJSON($code, "{$translations}/{$code}.json")));
}
emit('languages', outcome(fn () => Locale::getLanguages()));
foreach ($codes as $code) {
    $locale = (new Locale($code))->setFallback('en');
    $texts = [];
    foreach (array_keys((new Locale('en'))->getTranslations()) as $key) {
        $texts[$key] = $locale->getText($key, placeholders: ['project' => 'Demo', 'user' => 'Ana', 'redirect' => 'https://x.y/?a=1&b=2']);
    }
    emit('texts ' . $code, ['value' => $texts]);
    emit('translations ' . $code, outcome(fn () => $locale->getTranslations()));
}

// Languages from arrays and from JSON of every shape.
Locale::$exceptions = true;
$languages = [
    'en-US' => ['hello' => 'Hello', 'world' => 'World', 'empty' => '', 'helloPlaceholder' => 'Hello {{name}} {{surname}}!', 'braces' => '{{a}}{{{a}}}{{ a }}{{a}}', '5' => 'five', 'repeat' => '{{x}}{{x}}'],
    'he-IL' => ['hello' => 'שלום', 'empty' => 'ריק'],
    '' => ['hello' => 'from the unnamed language', 'onlyUnnamed' => 'unnamed'],
    'list' => ['zero', 'one'],
    'numeric' => [10 => 'ten', '011' => 'leading zero', '-1' => 'minus one'],
];
foreach ($languages as $name => $texts) {
    emit('array ' . $name, outcome(fn () => Locale::setLanguageFromArray($name, $texts)));
}
$json = [
    'object' => '{"a": "A", "b": "B", "7": "seven", "": "blank key"}',
    'list json' => '["x", "y"]',
    'empty object' => '{}',
    'empty array' => '[]',
    'invalid' => '{',
    'empty file' => '',
    'scalar int' => '5',
    'scalar float' => '1.5',
    'scalar string' => '"text"',
    'scalar true' => 'true',
    'scalar false' => 'false',
    'null' => 'null',
    'unicode' => '{"k": "é中😀"}',
    'duplicate keys' => '{"a": "first", "a": "second"}',
];
foreach ($json as $name => $content) {
    emit('json ' . $name, outcome(fn () => Locale::setLanguageFromJSON($name, $file(md5($name) . '.json', $content))));
}
emit('json missing file, exceptions on', outcome(fn () => Locale::setLanguageFromJSON('missing', $files . '/missing.json')));
emit('json directory, exceptions on', outcome(fn () => Locale::setLanguageFromJSON('directory', $files)));
Locale::$exceptions = false;
emit('json missing file, exceptions off', outcome(fn () => Locale::setLanguageFromJSON('missing', $files . '/missing.json')));
Locale::$exceptions = true;
emit('languages after loading', outcome(fn () => Locale::getLanguages()));
emit('replace keeps position', outcome(function () {
    Locale::setLanguageFromArray('he-IL', ['hello' => 'שלום', 'empty' => 'ריק', 'extra' => 'נוסף']);

    return Locale::getLanguages();
}));

// Lookups with every combination of default, fallback and missing text.
$keys = ['hello', 'world', 'empty', 'helloPlaceholder', 'braces', '5', 'repeat', 'onlyUnnamed', 'a', '7', '', '0', 'missing', 'k', 'extra'];
$defaults = [
    'omitted' => [],
    'null' => [null],
    'text' => ['fallback text'],
    'empty' => [''],
    'dynamic key' => [Locale::DEFAULT_DYNAMIC_KEY],
    'braces' => ['{{name}}'],
];
$pairs = [
    ['en-US', null], ['he-IL', null], ['he-IL', 'en-US'], ['en-US', 'he-IL'], ['object', 'en-US'], ['list', null], ['numeric', null],
    ['list json', 'object'], ['empty object', 'en-US'], ['unicode', ''], ['duplicate keys', null], ['', null],
];
foreach ([true, false] as $exceptions) {
    Locale::$exceptions = $exceptions;
    foreach ($pairs as [$default, $fallback]) {
        $locale = new Locale($default);
        if ($fallback !== null) {
            $locale->setFallback($fallback);
        }
        foreach ($keys as $key) {
            foreach ($defaults as $name => $args) {
                emit("text {$default}/{$fallback} {$key} {$name} exceptions=" . (int) $exceptions, outcome(fn () => $locale->getText($key, ...$args)));
            }
        }
        emit("translations {$default}/{$fallback} exceptions=" . (int) $exceptions, outcome(fn () => $locale->getTranslations()));
    }
}

// Languages that hold no translations, and unknown ones while exceptions are off.
Locale::$exceptions = false;
foreach (['invalid', 'empty file', 'scalar int', 'scalar float', 'scalar string', 'scalar true', 'scalar false', 'null', 'missing'] as $name) {
    emit("unusable new {$name}", outcome(fn () => (new Locale($name))->default));
    emit("unusable text {$name}", outcome(fn () => (new Locale($name))->getText('a')));
    emit("unusable translations {$name}", outcome(fn () => (new Locale($name))->getTranslations()));
    emit("unusable fallback {$name}", outcome(fn () => (new Locale('en-US'))->setFallback($name)->getText('missing')));
    emit("unusable fallback hit {$name}", outcome(fn () => (new Locale('en-US'))->setFallback($name)->getText('hello')));
}
Locale::$exceptions = true;
foreach (['unknown', 'missing', '', 'EN-US'] as $name) {
    emit("unknown new {$name}", outcome(fn () => new Locale($name)));
    emit("unknown setDefault {$name}", outcome(fn () => (new Locale('en-US'))->setDefault($name)->default));
    emit("unknown setFallback {$name}", outcome(fn () => (new Locale('en-US'))->setFallback($name)->fallback));
}

// What $exceptions takes: PHP reads it for truthiness.
foreach (['zero' => 0, 'one' => 1, 'empty string' => '', 'string zero' => '0', 'string' => 'yes', 'null' => null, 'array' => [], 'non-empty array' => [1]] as $name => $value) {
    Locale::$exceptions = $value;
    emit("exceptions {$name}", outcome(fn () => [(new Locale('en-US'))->getText('missing'), Locale::$exceptions]));
    emit("exceptions {$name} new", outcome(fn () => (new Locale('nope'))->default));
}
Locale::$exceptions = true;

// Placeholders, cast as (string) is.
$stringable = new class () {
    public function __toString(): string
    {
        return 'stringable';
    }
};
$placeholders = [
    'strings' => ['name' => 'Ana', 'surname' => 'Lima'],
    'chained' => ['name' => '{{surname}}', 'surname' => 'B'],
    'reverse chain' => ['surname' => 'B', 'name' => '{{surname}}'],
    'int' => ['name' => 5, 'surname' => -0],
    'float' => ['name' => 1.5, 'surname' => 1e20],
    'float precision' => ['name' => 0.1 + 0.2, 'surname' => -0.0],
    'bool' => ['name' => true, 'surname' => false],
    'null' => ['name' => null],
    'stringable' => ['name' => $stringable],
    'int keys' => [0 => 'zero', 5 => 'five'],
    'numeric key' => ['5' => 'five'],
    'empty name' => ['' => 'blank'],
    'spaced name' => [' a ' => 'spaced'],
    'brace name' => ['{a}' => 'braced'],
    'binary' => ['name' => "\xff\xfe", 'surname' => "a\0b"],
    'repeat' => ['x' => 'y'],
    'braces' => ['a' => 'A'],
    'none' => [],
    'array value' => ['name' => ['x']],
    'object value' => ['name' => new stdClass()],
    'throwing value' => ['name' => new class () {
        public function __toString(): string
        {
            throw new LogicException('unprintable');
        }
    }],
];
$locale = new Locale('en-US');
foreach ($placeholders as $name => $values) {
    foreach (['helloPlaceholder', 'repeat', 'braces', 'missing'] as $key) {
        emit("placeholders {$name} {$key}", outcome(fn () => bin2hex($locale->getText($key, 'default {{name}}', $values) ?? 'NULL')));
    }
}
emit('placeholders on null', outcome(fn () => $locale->getText('missing', null, ['name' => 'x'])));
emit('placeholders on dynamic key', outcome(fn () => (new Locale('en-US'))->setFallback('')->getText('{{name}}', Locale::DEFAULT_DYNAMIC_KEY, ['name' => 'x']))); // exceptions are on: throws

// Defaults and keys PHP converts, since Locale.php has no strict_types.
$weak = [
    'int key' => fn () => (new Locale('en-US'))->getText(5),
    'float key' => fn () => (new Locale('en-US'))->getText(5.0),
    'bool key' => fn () => (new Locale('en-US'))->getText(true, 'd'),
    'stringable key' => fn () => (new Locale('en-US'))->getText($stringable, 'd'),
    'null key' => fn () => (new Locale('en-US'))->getText(null),
    'array key' => fn () => (new Locale('en-US'))->getText([]),
    'int default' => fn () => (new Locale('en-US'))->getText('missing', 5),
    'float default' => fn () => (new Locale('en-US'))->getText('missing', 0.1),
    'bool default' => fn () => (new Locale('en-US'))->getText('missing', false),
    'stringable default' => fn () => (new Locale('en-US'))->getText('missing', $stringable),
    'binary default' => fn () => bin2hex((new Locale('en-US'))->getText('missing', "\xff")),
    'array default' => fn () => (new Locale('en-US'))->getText('missing', []),
    'string placeholders' => fn () => (new Locale('en-US'))->getText('hello', 'd', 'x'),
    'null placeholders' => fn () => (new Locale('en-US'))->getText('hello', 'd', null),
    'int locale' => function () {
        Locale::setLanguageFromArray(7, ['k' => 'seven']);

        return [(new Locale(7))->getText('k'), (new Locale('en-US'))->setDefault(7.0)->default, Locale::getLanguages()];
    },
    'null locale' => fn () => new Locale(null),
    'null fallback' => fn () => (new Locale('en-US'))->setFallback(null),
    'array name' => fn () => Locale::setLanguageFromArray([], []),
    'string translations' => fn () => Locale::setLanguageFromArray('x', 'y'),
    'stringable path' => fn () => Locale::setLanguageFromJSON('s', new class () {
        public function __toString(): string
        {
            return '/missing-locale-file.json';
        }
    }),
    'no arguments' => fn () => new Locale(),
    'named arguments' => fn () => (new Locale(default: 'en-US'))->getText(key: 'helloPlaceholder', placeholders: ['name' => 'N']),
];
foreach ($weak as $name => $run) {
    // An argument error's message names the caller's file and line in PHP, so only its class is compared.
    $result = outcome($run);
    if (in_array($result['throws'] ?? null, [TypeError::class, ArgumentCountError::class], true)) {
        unset($result['message']);
    }
    emit('weak ' . $name, $result);
}

// The object: chaining, properties, copies.
emit('chaining returns the same object', outcome(function () {
    $locale = new Locale('en-US');

    return [$locale->setDefault('he-IL') === $locale, $locale->setFallback('en-US') === $locale];
}));
emit('properties', outcome(function () {
    $locale = (new Locale('he-IL'))->setFallback('en-US');

    return [$locale->default, $locale->fallback, (new Locale('en-US'))->fallback, isset($locale->fallback), isset((new Locale('en-US'))->fallback)];
}));
emit('clone', outcome(function () {
    $locale = new Locale('en-US');
    $copy = clone $locale;
    $copy->setDefault('he-IL')->setFallback('en-US');

    return [$locale->default, $locale->fallback, $copy->default, $copy->fallback, $copy->getText('world')];
}));
Locale::$exceptions = false;
emit('assigned properties', outcome(function () {
    $locale = new Locale('en-US');
    $read = [];
    foreach ([7, '7', 7.0, true, null, 'he-IL', ['x']] as $value) {
        $locale->default = $value;
        $locale->fallback = $value;
        $read[] = [$locale->default, $locale->fallback];
    }
    $locale->default = 7;
    $locale->fallback = 'en-US';
    $read[] = [$locale->getText('k'), $locale->getText('hello')];
    $locale->default = 'he-IL';
    $locale->fallback = null;
    $read[] = [$locale->getText('hello'), $locale->getText('world', 'd')];

    return $read;
}));
Locale::$exceptions = true;
emit('constant', outcome(fn () => [Locale::DEFAULT_DYNAMIC_KEY, (new ReflectionClassConstant(Locale::class, 'DEFAULT_DYNAMIC_KEY'))->getValue()]));
$methods = (new ReflectionClass(Locale::class))->getMethods(ReflectionMethod::IS_PUBLIC);
usort($methods, fn (ReflectionMethod $a, ReflectionMethod $b) => strcmp($a->getName(), $b->getName()));
emit('methods', outcome(fn () => array_map(fn (ReflectionMethod $method) => [$method->getName(), $method->isStatic(), $method->getNumberOfParameters(), $method->getNumberOfRequiredParameters()], $methods)));
emit('static exceptions', outcome(fn () => [(new ReflectionProperty(Locale::class, 'exceptions'))->isStatic(), (new ReflectionProperty(Locale::class, 'exceptions'))->isPublic()]));

// Known differences of the extension, each a waiver in crates/extension/README.md.
Locale::$exceptions = false;
$waivers = [
    'array values' => outcome(fn () => Locale::setLanguageFromArray('ints', ['n' => 5])),
    'non-UTF-8 key' => outcome(fn () => (new Locale('en-US'))->getText("\xff", 'd') === 'd'),
    'unknown default language' => outcome(fn () => (new Locale('unknown'))->getText('a')),
    'unknown default translations' => outcome(fn () => (new Locale('unknown'))->getTranslations()),
    'serialize' => outcome(fn () => strlen(serialize(new Locale('en-US')))),
];
Locale::$exceptions = true;
$waivers['non-UTF-8 key message'] = outcome(fn () => (new Locale('en-US'))->getText("\xff"));
$waivers['non-UTF-8 text'] = outcome(fn () => Locale::setLanguageFromArray('binary', ['k' => "a\xffb"]));
$waivers['non-UTF-8 translation key'] = outcome(fn () => Locale::setLanguageFromArray('binary', ["\xff" => 'a', "\xfe" => 'b']));
$waivers['non-UTF-8 language name'] = outcome(fn () => Locale::setLanguageFromArray("\xff", ['k' => 'a']));
$waivers['non-UTF-8 JSON language name'] = outcome(fn () => Locale::setLanguageFromJSON("\xff", $file('binary-name.json', '{"k": "a"}')));
$expected = $internal ? [
    'array values' => ['throws' => 'Exception', 'message' => 'Translation "n" is not a string.'],
    'non-UTF-8 key' => ['value' => true],
    'unknown default language' => ['throws' => 'TypeError', 'message' => 'array_key_exists(): Argument #2 ($array) must be of type array, null given'],
    'unknown default translations' => ['throws' => 'TypeError', 'message' => 'Utopia\\Locale\\Locale::getTranslations(): Return value must be of type array, null returned'],
    'serialize' => ['throws' => 'Exception', 'message' => "Serialization of 'Utopia\\Locale\\Locale' is not allowed"],
    'non-UTF-8 key message' => ['throws' => 'Exception', 'message' => "Key named \"\u{FFFD}\" not found"],
    'non-UTF-8 text' => ['throws' => 'Exception', 'message' => 'Translation "k" is not UTF-8.'],
    'non-UTF-8 translation key' => ['throws' => 'Exception', 'message' => 'Translation key is not UTF-8.'],
    'non-UTF-8 language name' => ['throws' => 'Exception', 'message' => 'Language name is not UTF-8.'],
    'non-UTF-8 JSON language name' => ['throws' => 'Exception', 'message' => 'Language name is not UTF-8.'],
] : $waivers;
foreach ($expected as $name => $outcome) {
    if ($waivers[$name] !== $outcome) {
        fwrite(STDERR, "Waiver changed: {$name}: " . json_encode($waivers[$name], JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL);
        exit(1);
    }
}
fwrite(STDERR, count($expected) . ' waivers hold' . PHP_EOL);

array_map(unlink(...), glob("{$files}/*"));
rmdir($files);
