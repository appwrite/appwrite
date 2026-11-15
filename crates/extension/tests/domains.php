<?php

// Parity corpus for Utopia\Domains\Domain. Run it on the PHP class and on the utopia
// extension's (_APP_EXTENSION_LIBRARIES=domains), and diff stdout: every line must match.
// Stderr names the implementation that ran.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Domains\Domain;

$internal = (new ReflectionClass(Domain::class))->isInternal();
fwrite(STDERR, 'Utopia\\Domains\\Domain: ' . ($internal ? 'utopia extension' : 'PHP') . PHP_EOL);
// A run meant for the extension that gets the PHP class would compare PHP with PHP and pass.
$requested = in_array('domains', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' domains, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

/** Warnings raised by the current case. */
$warnings = [];
set_error_handler(function (int $level, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});

/** $value with every string that is not UTF-8 as its hex, so json_encode() cannot hide a byte. */
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

/** The protected properties as (array) shows them, keyed by name. */
function properties(Domain $domain): array
{
    $properties = [];
    foreach ((array) $domain as $key => $value) {
        $properties[substr($key, strrpos($key, "\0") + 1)] = $value;
    }

    return $properties;
}

/** Every getter on a fresh object, then the properties they filled. */
function inspect(string $value): array
{
    $domain = new Domain($value);

    return [
        $domain->get(), $domain->getTLD(), $domain->getSuffix(), $domain->getRule(), $domain->getName(), $domain->getSub(),
        $domain->getApex(), $domain->getRegisterable(), $domain->isKnown(), $domain->isICANN(), $domain->isPrivate(), $domain->isTest(),
        properties($domain),
    ];
}

// Every rule of the list PHP ships, as a suffix, under a name, and under a subdomain.
$list = require __DIR__ . '/../../../packages/domains/data/data.php';
$rules = 0;
foreach (array_keys($list) as $rule) {
    $rule = (string) $rule;
    $base = match (true) {
        str_starts_with($rule, '!') => substr($rule, 1),
        str_starts_with($rule, '*.') => 'any.' . substr($rule, 2),
        default => $rule,
    };
    foreach ([$base, 'example.' . $base, 'www.example.' . $base, strtoupper('Shop.' . $base)] as $agent) {
        emit($agent, outcome(fn () => inspect($agent)));
    }
    $rules++;
}

// Hostnames Appwrite and Cloud parse, and edge cases.
$domains = [
    'cloud.appwrite.io', 'fra.cloud.appwrite.io', 'appwrite.io', 'localhost', 'appwrite', 'appwrite.test', 'sub.appwrite.test',
    'myapp.appwrite.network', 'abc123.fra.appwrite.run', 'project.appwrite.global', 'console.appwrite.io', 'api.example.com',
    'blog.example.co.uk', 'example.co.uk', 'co.uk', 'uk', 'a.b.c.d.e.f.example.com', 'www.ck', 'a.www.ck', 'b.c.ck', 'c.ck', 'ck',
    'example.github.io', 'user.github.io', 'github.io', 'example.blogspot.com', 'x.s3.amazonaws.com', 'x.compute.amazonaws.com',
    'example.com.', 'example.com..', '.example.com', '..', '.', '', ' ', '0', '0.0', '0.com', 'a.0', '0.0.0', 'com.0',
    'EXAMPLE.COM', 'ExAmPlE.Co.Uk', 'münchen.de', 'MÜNCHEN.DE', 'xn--mnchen-3ya.de', '例子.中国', 'пример.рф', 'İstanbul.com.tr',
    '127.0.0.1', '192.168.0.1.nip.io', '[::1]', '::1', 'example.com:8080', 'user@example.com', 'example.com/path', 'a_b.example.com',
    '-example.com', 'example-.com', 'example..com', 'a.' . str_repeat('b', 300) . '.com', str_repeat('a.', 200) . 'com',
    "exa\xffmple.com", "\xff\xfe.com", "ex\0ample.com", "tab\t.com", 'HTTP://example.com', 'Https://example.com', 'ftp://example.com',
    'http:/example.com', 'httpx://example.com',
];
foreach ($domains as $domain) {
    emit('edge ' . $domain, outcome(fn () => inspect($domain)));
}
foreach (['http://example.com', 'https://example.com', 'https://'] as $url) {
    emit('url ' . $url, outcome(fn () => new Domain($url)));
}
fwrite(STDERR, $rules . ' rules, ' . count($domains) . ' edge cases' . PHP_EOL);

// The properties PHP fills, after each single getter and after orders of getters.
$getters = ['get', 'getTLD', 'getSuffix', 'getRule', 'getName', 'getSub', 'getApex', 'getRegisterable', 'isKnown', 'isICANN', 'isPrivate', 'isTest'];
foreach (['blog.example.co.uk', 'localhost', 'a.unknowntld', 'www.ck', '0', 'x.0', 'example.com'] as $value) {
    emit('fresh ' . $value, outcome(fn () => properties(new Domain($value))));
    foreach ($getters as $getter) {
        emit($getter . ' ' . $value, outcome(function () use ($value, $getter) {
            $domain = new Domain($value);
            $domain->$getter();

            return properties($domain);
        }));
    }
    emit('all reversed ' . $value, outcome(function () use ($value, $getters) {
        $domain = new Domain($value);
        $results = array_map(fn ($getter) => $domain->$getter(), array_reverse($getters));

        return [$results, properties($domain)];
    }));
}

// What PHP converts a string parameter from outside strict_types.
$stringable = new class () {
    public function __toString(): string
    {
        return 'Blog.Example.CO.UK';
    }
};
foreach (['int' => 5, 'float' => 1.5, 'true' => true, 'false' => false, 'stringable' => $stringable] as $name => $argument) {
    emit('argument ' . $name, outcome(fn () => inspect($argument)));
}
foreach (['null' => null, 'array' => [], 'object' => new stdClass()] as $name => $argument) {
    emit('argument ' . $name, ['throws' => outcome(fn () => new Domain($argument))['throws'] ?? null]);
}

// The object itself.
emit('equal fresh', outcome(fn () => new Domain('example.com') == new Domain('example.com')));
emit('equal different', outcome(fn () => new Domain('example.com') == new Domain('example.org')));
emit('equal after getter', outcome(function () {
    $one = new Domain('example.com');
    $one->getName();

    return [$one == new Domain('example.com'), $one == $one];
}));
emit('clone', outcome(function () {
    $domain = new Domain('blog.example.co.uk');
    $domain->getSub();
    $copy = clone $domain;

    return [properties($copy), $copy->getApex(), $copy == $domain];
}));
emit('var_dump', outcome(function () {
    $domain = new Domain('Blog.Example.CO.UK');
    $domain->getRegisterable();
    ob_start();
    var_dump($domain);

    return preg_replace('/#\d+ /', '# ', ob_get_clean());
}));
emit('read protected', outcome(fn () => (new Domain('example.com'))->domain));
emit('instanceof', outcome(fn () => new Domain('example.com') instanceof Domain));
emit('final', outcome(fn () => (new ReflectionClass(Domain::class))->isFinal()));
emit('subclass', outcome(function () {
    $sub = new class ('Blog.Example.CO.UK') extends Domain {
        public function shout(): string
        {
            return strtoupper($this->getApex());
        }
    };

    return [$sub->shout(), $sub->getSub(), $sub instanceof Domain];
}));
$methods = (new ReflectionClass(Domain::class))->getMethods(ReflectionMethod::IS_PUBLIC);
usort($methods, fn ($a, $b) => strcmp($a->getName(), $b->getName()));
emit('methods', outcome(fn () => array_map(fn (ReflectionMethod $method) => [$method->getName(), $method->isStatic(), $method->getNumberOfParameters(), $method->getNumberOfRequiredParameters()], $methods)));
emit('validators stay PHP', outcome(fn () => array_map(fn (string $class) => (new ReflectionClass($class))->isInternal(), [
    Utopia\Domains\Validator\PublicDomain::class,
    Utopia\Domains\Registrar::class,
])));
emit('public domain validator', outcome(fn () => array_map(fn ($value) => (new Utopia\Domains\Validator\PublicDomain())->isValid($value), ['example.com', 'localhost', 'a.unknowntld', 'blog.example.co.uk'])));

// Known differences of the extension, each a waiver in crates/extension/README.md.
$waivers = [
    'URL message with bytes that are not UTF-8' => outcome(fn () => new Domain("http://\xff")),
];
$expected = $internal ? [
    'URL message with bytes that are not UTF-8' => ['throws' => 'Exception', 'message' => "'http://\u{FFFD}' must be a valid domain or hostname"],
] : $waivers;
foreach ($expected as $name => $outcome) {
    if ($waivers[$name] !== $outcome) {
        fwrite(STDERR, "Waiver changed: {$name}: " . json_encode(exported($waivers[$name])) . PHP_EOL);
        exit(1);
    }
}
fwrite(STDERR, count($expected) . ' waivers hold' . PHP_EOL);
