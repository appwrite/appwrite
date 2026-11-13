<?php

// Parity corpus for Utopia\DSN\DSN. Run it on the PHP class and on the utopia
// extension's (_APP_EXTENSION_LIBRARIES=dsn), and diff stdout: every line must match.
// Stderr names the implementation that ran. Inputs come from #14308's tests/compat/dsn
// cases, plus edge cases they do not cover.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\DSN\DSN;

$internal = (new ReflectionClass(DSN::class))->isInternal();
fwrite(STDERR, 'Utopia\\DSN\\DSN: ' . ($internal ? 'utopia extension' : 'PHP') . PHP_EOL);
// A run meant for the extension that gets the PHP class would compare PHP with PHP and pass.
$requested = in_array('dsn', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' dsn, but the other implementation loaded' . PHP_EOL);
    exit(1);
}
ini_set('zend.exception_ignore_args', '0');

/** The observable outcome of $run, with any exception reduced to its class and message. */
function outcome(callable $run): array
{
    try {
        return ['value' => $run()];
    } catch (Throwable $e) {
        return ['throws' => $e::class, 'message' => $e->getMessage()];
    }
}

/** Everything a DSN exposes. */
function read(DSN $dsn, array $keys): array
{
    $params = [];
    foreach ($keys as $key) {
        $params[$key] = outcome(fn () => $dsn->getParam($key, 'fallback'));
    }

    return [
        'scheme' => $dsn->getScheme(),
        'user' => $dsn->getUser(),
        'password' => $dsn->getPassword(),
        'host' => $dsn->getHost(),
        'port' => $dsn->getPort(),
        'path' => $dsn->getPath(),
        'query' => $dsn->getQuery(),
        'string' => (string) $dsn,
        'params' => $params,
    ];
}

function emit(string $case, array $result): void
{
    echo json_encode([$case, $result], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES), PHP_EOL;
}

$keys = ['0', '01', '1', 'a', 'a.b', 'a_b', 'b', 'c', 'c d', 'c_d', 'charset', 'doesNotExist', 'e_f', 'insecure', 'region', 't', 'timezone', 'url', 'value', 'missing', 'charset'];

$parse = [
    'mariadb://user:password@localhost:3306/database?charset=utf8&timezone=UTC',
    'mariadb://user@localhost:3306/database?charset=utf8&timezone=UTC',
    'mariadb://user@localhost/database?charset=utf8&timezone=UTC',
    'mariadb://user@localhost?charset=utf8&timezone=UTC',
    'mariadb://user@localhost',
    'mariadb://user:@localhost',
    'mariadb://localhost',
    'mysql://localhost:3306',
    's3://user:secret@host:3306/bucket?region=us-east-1',
    'sms://user:sl%2Fsh%2B%24%40no%3Aher@localhost',
    'sms://admin%40example.com@localhost',
    'sms://localhost?value=I+am+100%25+value%3D%3Ccomplex%3E%2C+%22right%22%3F%21',
    'mariadb://',
    's3://AKIAKEY:SECRETKEY@/backups?region=us-east-1',
    '//AKIAKEY:SECRETKEY@localhost/database',
    'AKIAKEY:SECRETKEY@localhost/database',
    '0://localhost',
    'mysql://0:3306',
    'x://h?0',
    'x://h?',
    'x://h?a[b]=1&c=2&t[]=1',
    'x://h?a.b=1&c+d=2&e%20f=3&1=one',
    'x://h:00080/p',
    'x://h:65536',
    'x://h:80a',
    'x://h//a/b/',
    'x://u:p@h/db?a=1#frag',
    'x://u:p@h/d?a=',
    'x://%FF:%FE%00@h',
    'x://user:p@ss@host/db',
    'postgresql://user@[::1]:5432/db',
    'database_db_main',
    '',
    'mailto:user@example.com',
    'x://@h',
    'x://h:',
    'a:',
    '?a=1',
    '#f',
    'x://:80',
    'x:///db',
    'mariadb://user:p%40ss%2Fword@localhost:3306/data%20base?charset=utf8',
    'mariadb://user:p%40ss@localhost:3306/data%20base/%FF?charset=utf8#frag',
    's3://localhost/b%3Furl%3Dx',
    'redis://cache:0',
    'redis://h?0',
    'redis://h?',
    'x://h?a[b]=1&c=2',
    'x://h?a.b=1&c+d=2',
    'redis://u%FF:p%FE@h/p%FD',
    'pgsql://u:p@[::1]:5432/db',
    'redis://user:@h',
    'redis://:pass@h',
    'mailto:someone',
    '//host/path',
    '0://0',
    'redis://h:99999',
    'redis://h:-1',
    'http://h/%2F%2F/a b',
    'redis://h/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
];

$from = [
    ['scheme' => 's3', 'host' => 'minio.example.com', 'user' => 'admin@example.com', 'password' => 'sl/sh+$@no:her', 'port' => 9000, 'path' => 'b?url=http://169.254.169.254/latest&insecure=true#', 'params' => ['region' => 'us-east-1', 'value' => 'a&b=c']],
    ['scheme' => 's3', 'host' => 'localhost', 'path' => 'bucket/prefix'],
    ['scheme' => 's3', 'host' => 'minio.example.com/other', 'path' => 'bucket'],
    ['scheme' => 's3', 'host' => 'minio.example.com?url=http://10.0.0.1', 'path' => 'bucket'],
    ['scheme' => 's3', 'host' => 'minio.example.com#x', 'path' => 'bucket'],
    ['scheme' => 's3', 'host' => 'user@minio.example.com', 'path' => 'bucket'],
    ['scheme' => 's3', 'host' => 'minio.example.com:9000', 'path' => 'bucket'],
    ['scheme' => 's3', 'host' => 'localhost', 'password' => 'secret'],
    ['scheme' => 's3', 'host' => 'localhost', 'params' => ['x[y]' => 'z']],
    ['scheme' => 's3', 'host' => 'localhost', 'params' => ['a.b' => 'c']],
    ['scheme' => 's3', 'host' => 'localhost', 'params' => ['a b' => 'c']],
    ['scheme' => 's3', 'host' => 'localhost', 'params' => ['region' => 'eu central', 'url' => 'https://x?y=1&z']],
    ['scheme' => 'mariadb', 'host' => 'localhost', 'user' => 'user', 'password' => 'p@ss/word', 'port' => 3306, 'path' => 'data base', 'params' => ['charset' => 'utf8']],
    ['scheme' => 'redis', 'host' => 'cache', 'user' => '', 'password' => '', 'port' => 0, 'params' => ['0' => 'a', '01' => 'b']],
    ['scheme' => 's3', 'host' => 'h', 'params' => ['n' => 5]],
    ['scheme' => 's3', 'host' => 'h', 'params' => ['b' => true]],
    ['scheme' => 's3', 'host' => 'h', 'params' => ['z' => null]],
    ['scheme' => 's3', 'host' => 'h', 'params' => ['0' => 'zero', '1' => 'one']],
    ['scheme' => 's3', 'host' => 'h', 'params' => ['a', 'b']],
    ['scheme' => 's3', 'host' => 'h', 'port' => 0],
    ['scheme' => 's3', 'host' => 'h', 'port' => 70000],
    ['scheme' => 's3', 'host' => 'h', 'password' => 'secret'],
    ['scheme' => 's3', 'host' => 'h', 'user' => '', 'password' => ''],
    ['scheme' => '', 'host' => 'h'],
];

foreach ($parse as $input) {
    emit('parse ' . $input, outcome(fn () => read(new DSN($input), $keys)));
}

foreach ($from as $parts) {
    emit('from ' . json_encode($parts), outcome(fn () => read(DSN::from(...$parts), $keys)));
}

// Positional and named arguments, and getParam's default.
emit('from positional', outcome(fn () => (string) DSN::from('redis', 'cache', 'u', 'p', 6379, 'db', ['a' => 'b'])));
emit('getParam default', outcome(fn () => (new DSN('redis://h?a=1'))->getParam('b')));

// DSN.php has no strict_types: PHP converts scalar arguments, and Cloud relies on it
// (getParam('connectTimeout', 1.0) took down staging when the binding refused it).
$stringable = new class () {
    public function __toString(): string
    {
        return 'redis://h:1';
    }
};
$weak = [
    'getParam float default' => fn () => (new DSN('redis://h'))->getParam('x', 1.0),
    'getParam fractional default' => fn () => (new DSN('redis://h'))->getParam('x', 0.1),
    'getParam huge default' => fn () => (new DSN('redis://h'))->getParam('x', 1e20),
    'getParam int default' => fn () => (new DSN('redis://h'))->getParam('x', 5),
    'getParam bool defaults' => fn () => [(new DSN('redis://h'))->getParam('x', true), (new DSN('redis://h'))->getParam('x', false)],
    'getParam null default' => fn () => (new DSN('redis://h'))->getParam('x', null),
    'getParam array default' => fn () => (new DSN('redis://h'))->getParam('x', []),
    'getParam int key' => fn () => (new DSN('redis://h?5=a'))->getParam(5),
    'new Stringable' => fn () => (string) new DSN($stringable),
    'new int' => fn () => (string) new DSN(5),
    'new null' => fn () => (string) new DSN(null),
    'new object' => fn () => (string) new DSN(new stdClass()),
    'from numeric string port' => fn () => [(string) DSN::from('s3', 'h', port: '9000'), (string) DSN::from('s3', 'h', port: ' 9000 ')],
    'from float port' => fn () => (string) DSN::from('s3', 'h', port: 9000.0),
    'from non-numeric port' => fn () => (string) DSN::from('s3', 'h', port: '9000abc'),
    'from bool and null port' => fn () => [(string) DSN::from('s3', 'h', port: true), (string) DSN::from('s3', 'h', port: null)],
    'from int user and path' => fn () => (string) DSN::from('s3', 'h', user: 7, path: 3),
];
foreach ($weak as $name => $run) {
    // A TypeError's message names the caller's file and line in PHP, so only its class is compared.
    $result = outcome($run);
    if (($result['throws'] ?? null) === TypeError::class) {
        unset($result['message']);
    }
    emit('weak ' . $name, $result);
}

// A clone reads the same, compares equal and is not the same object.
$original = new DSN('redis://u:p@h:1/db?a=1');
$copy = clone $original;
emit('clone', outcome(fn () => [$copy == $original, $copy === $original, read($copy, ['a'])]));
emit('compare', outcome(fn () => [new DSN('a://x') == new DSN('b://y'), new DSN('a://x') == new DSN('a://x')]));
emit('properties', outcome(fn () => array_map(strval(...), array_keys((array) new DSN('redis://u:p@h:1/db?q=1')))));
emit('stringable', outcome(fn () => $original instanceof Stringable));

// Every method takes and returns what DSN.php declares.
$methods = (new ReflectionClass(DSN::class))->getMethods(ReflectionMethod::IS_PUBLIC);
usort($methods, fn (ReflectionMethod $a, ReflectionMethod $b) => strcmp($a->getName(), $b->getName()));
foreach ($methods as $method) {
    $parameters = array_map(
        fn (ReflectionParameter $p) => [(string) $p->getType(), $p->getName(), $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null],
        $method->getParameters(),
    );
    emit('signature ' . $method->getName(), ['value' => [(string) $method->getReturnType(), $parameters, $method->isStatic()]]);
}

// The DSN and the credentials never reach an exception's trace.
foreach (['new' => fn () => new DSN('redis://user:SECRET@'), 'from' => fn () => DSN::from('redis', 'h:1', 'user', 'SECRET')] as $name => $run) {
    try {
        $run();
        emit('trace ' . $name, ['value' => 'no exception']);
    } catch (Throwable $e) {
        emit('trace ' . $name, ['leaks' => str_contains(print_r($e->getTrace(), true), 'SECRET') || str_contains($e->getTraceAsString(), 'SECRET')]);
    }
}

if (!$internal) {
    exit;
}

// Known differences of the extension, each a waiver in crates/extension/README.md.
$waivers = [
    // utopia-dsn reads the DSN as UTF-8, where parse_url takes any bytes.
    'non-UTF-8 DSN' => outcome(fn () => new DSN("redis://h/\xff")),
    // utopia-dsn's from() takes string parameters; PHP also round-trips a list.
    'list parameter' => outcome(fn () => DSN::from('s3', 'h', params: ['list' => ['a', 'b']])),
    // The serializable PHP class becomes an internal one that refuses serialize().
    'serialize' => outcome(fn () => serialize(new DSN('redis://h'))),
];
$expected = [
    'non-UTF-8 DSN' => ['throws' => InvalidArgumentException::class, 'message' => 'Unable to parse DSN: malformed'],
    'list parameter' => ['throws' => InvalidArgumentException::class, 'message' => 'Unable to build DSN: a part does not survive encoding'],
    'serialize' => ['throws' => Exception::class, 'message' => "Serialization of 'Utopia\\DSN\\DSN' is not allowed"],
];
// PHP caches the parsed query in $params on getParam(), which then shows in == and var_dump().
$read = new DSN('redis://h?a=1');
$read->getParam('a');
$waivers['params cache'] = outcome(fn () => $read == new DSN('redis://h?a=1'));
$expected['params cache'] = ['value' => true];

foreach ($expected as $name => $outcome) {
    if ($waivers[$name] !== $outcome) {
        fwrite(STDERR, "Waiver changed: {$name}: " . json_encode($waivers[$name]) . PHP_EOL);
        exit(1);
    }
}
fwrite(STDERR, count($expected) . ' waivers hold' . PHP_EOL);
