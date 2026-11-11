<?php

/*
 * Ports packages/vcs/tests to compat cases: runs a test class (a unit test
 * or an E2E one) against the fixture server (fixtures/run.php, started by
 * record.sh in the same container) with recording adapters, and writes
 * tests/compat/vcs/cases/<Class>.json or E2E-<Class>.json. See Recorder.php.
 *
 *   php record.php <out dir> <test>      (E2E/GiteaTest, GiteaTest, ...)
 *
 * The test classes run from a rewritten copy: adapters are constructed as
 * the recording subclasses below, uniqid() is numbered, the compose
 * services' addresses (Services.php) and the providers' public hosts are
 * the fixture server's, the clone commands a test runs are recorded, and
 * each test creates its own owner instead of sharing the first test's (a
 * case starts from an empty fixture namespace).
 */

namespace Tests\Compat\Vcs\Recorder;

use PHPUnit\TextUI\Application;

require __DIR__ . '/../../../../vendor/autoload.php';
require __DIR__ . '/Recorder.php';

$out = (string) $_SERVER['argv'][1];
$test = (string) $_SERVER['argv'][2];
$copy = '/tmp/vcs-recorder/tests';
$base = Recorder::BASE;

exec('rm -rf /tmp/vcs-recorder && mkdir -p ' . $copy . '/E2E ' . $copy . '/.tokens ' . Recorder::CLONES);
foreach (['gitea', 'forgejo', 'gogs', 'gitlab'] as $provider) {
    file_put_contents("{$copy}/.tokens/{$provider}.txt", "test-token-{$provider}\n");
}

/*
 * The copy of the tests.
 */
$sources = '/usr/src/code/packages/vcs/tests';
$rewrites = [
    'uniqid()' => '\\' . Recorder::class . '::uniqid()',
    "'http://127.0.0.1:13000'" => "'{$base}gitea'",
    "'http://127.0.0.1:13001'" => "'{$base}forgejo'",
    "'http://127.0.0.1:13002'" => "'{$base}gogs'",
    "'http://127.0.0.1:13003'" => "'{$base}gitlab'",
    "'http://127.0.0.1:15000'" => "'{$base}catcher'",
    "'http://request-catcher:5000'" => "'{$base}catcher'",
    "'https://github.com/'" => "'{$base}github/'",
    "'https://bitbucket.org/'" => "'{$base}bitbucket/'",
    "'/tmp/test-clone" => "'" . Recorder::CLONES . 'test-clone',
    "exec(\$command . ' 2>&1', \$output, \$exitCode)" => '\\' . Recorder::class . '::exec($command, $directory, $output, $exitCode)',
    "if (self::\$owner === '' || self::\$owner === '0')" => 'if (true)',
    "if (self::\$owner === '')" => 'if (true)',
    "self::\$owner = System::getEnv('TESTS_BITBUCKET_WORKSPACE') ?: " => 'self::$owner = ',
];
foreach (['new Gitea(', 'new Forgejo(', 'new Gogs(', 'new GitLab(', 'new GitHub(', 'new Bitbucket(', 'new Origin('] as $construct) {
    $rewrites[$construct] = 'new \\Tests\\Compat\\Vcs\\Recording\\' . substr($construct, 4);
}
foreach (['', 'E2E/'] as $dir) {
    foreach (glob("{$sources}/{$dir}*.php") ?: [] as $file) {
        file_put_contents($copy . '/' . $dir . basename($file), strtr((string) file_get_contents($file), $rewrites));
    }
}
spl_autoload_register(static function (string $class) use ($copy): void {
    if (str_starts_with($class, 'Utopia\\VCS\\Tests\\')) {
        $file = $copy . '/' . str_replace('\\', '/', substr($class, \strlen('Utopia\\VCS\\Tests\\'))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
}, prepend: true);

/** A type as code in another namespace. */
function type(?\ReflectionType $type): string
{
    if ($type instanceof \ReflectionNamedType) {
        $name = $type->getName();
        $code = $type->isBuiltin() || \in_array($name, ['self', 'static'], true) ? $name : '\\' . $name;

        return ($type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '') . $code;
    }
    if ($type instanceof \ReflectionUnionType) {
        return implode('|', array_map(static fn (\ReflectionType $t): string => type($t), $type->getTypes()));
    }

    return (string) $type;
}

/*
 * The recording adapters: each public method records itself and calls the
 * parent. GitHub and Bitbucket, whose hosts are fixed, are pointed at the
 * fixture server the way the compat adapter points them.
 */
$constructors = [
    'Gitea' => ['adapter' => 'gitea'],
    'Forgejo' => ['adapter' => 'forgejo'],
    'Gogs' => ['adapter' => 'gogs'],
    'GitLab' => ['adapter' => 'gitlab'],
    'GitHub' => ['adapter' => 'github', 'endpoint' => "{$base}github"],
    'Bitbucket' => ['adapter' => 'bitbucket', 'web' => "{$base}bitbucket"],
    'Origin' => ['adapter' => 'origin'],
];
foreach ($constructors as $name => $args) {
    $parent = new \ReflectionClass('Utopia\\VCS\\Adapter\\Git\\' . $name);
    $methods = '';
    foreach ($parent->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isStatic() || $method->isConstructor() || $method->isFinal()) {
            continue;
        }
        $params = [];
        $names = [];
        foreach ($method->getParameters() as $param) {
            $code = ($param->hasType() ? type($param->getType()) . ' ' : '') . ($param->isPassedByReference() ? '&' : '') . ($param->isVariadic() ? '...' : '') . '$' . $param->getName();
            if ($param->isDefaultValueAvailable()) {
                $code .= ' = ' . ($param->isDefaultValueConstant() ? '\\' . ltrim((string) $param->getDefaultValueConstantName(), '\\') : var_export($param->getDefaultValue(), true));
            }
            $params[] = $code;
            $names[] = $param->getName();
        }
        $return = $method->hasReturnType() ? ': ' . type($method->getReturnType()) : '';
        $call = '\\' . Recorder::class . '::call($this->recorded, ' . var_export($method->getName(), true) . ', ' . var_export($names, true) . ', $args, fn () => parent::' . $method->getName() . '(...$args))';
        $body = '$args = \\func_get_args(); ' . ($return === ': void' ? $call . ';' : 'return ' . $call . ';');
        $methods .= "    public function {$method->getName()}(" . implode(', ', $params) . "){$return}\n    {\n        {$body}\n    }\n\n";
    }
    $setup = match ($name) {
        'GitHub' => '$this->endpoint = ' . var_export($args['endpoint'], true) . ';',
        'Bitbucket' => '$this->bitbucketUrl = ' . var_export($args['web'], true) . '; parent::setEndpoint(' . var_export("{$base}bitbucket/2.0", true) . ');',
        default => '',
    };
    $record = '$this->recorded = \\' . Recorder::class . '::construct(' . var_export($args + ['class' => $name], true) . ');';
    if ($name === 'Bitbucket') {
        $record .= ' \\' . Recorder::class . '::call($this->recorded, \'setEndpoint\', [\'endpoint\'], [' . var_export("{$base}bitbucket/2.0", true) . '], fn () => null);';
    }
    eval("namespace Tests\\Compat\\Vcs\\Recording;\n\nclass {$name} extends \\Utopia\\VCS\\Adapter\\Git\\{$name}\n{\n    private string \$recorded;\n\n    public function __construct(\\Utopia\\Cache\\Cache \$cache)\n    {\n        parent::__construct(\$cache);\n        {$setup}\n        {$record}\n    }\n\n{$methods}}\n");
}

/*
 * GitHub's app: a key generated for these cases (github-app.test.pem; the
 * fixture server verifies the JWTs signed with it), its id and installation.
 */
$pem = (string) file_get_contents(__DIR__ . '/github-app.test.pem');
putenv('TESTS_GITHUB_PRIVATE_KEY=' . str_replace("\n", '\\n', $pem));
putenv('TESTS_GITHUB_APP_IDENTIFIER=123456');
putenv('TESTS_GITHUB_INSTALLATION_ID=7654321');
putenv('TESTS_BITBUCKET_ACCESS_TOKEN=test-token-bitbucket');
putenv('TESTS_BITBUCKET_WORKSPACE');
putenv('GIT_CONFIG_GLOBAL=/tmp/vcs-recorder/gitconfig');
putenv('GIT_CONFIG_NOSYSTEM=1');
putenv('GIT_TERMINAL_PROMPT=0');
putenv('GIT_AUTHOR_DATE=1767225600 +0000');
putenv('GIT_COMMITTER_DATE=1767225600 +0000');
file_put_contents('/tmp/vcs-recorder/gitconfig', "[http]\n\tsslVerify = false\n[advice]\n\tdetachedHead = false\n");

file_put_contents('/tmp/vcs-recorder/phpunit.xml', <<<'XML'
    <?xml version="1.0"?>
    <phpunit colors="false" cacheResult="false">
        <extensions>
            <bootstrap class="Tests\Compat\Vcs\Recorder\Recorder"/>
        </extensions>
    </phpunit>
    XML);

(new Application())->run(['phpunit', '--configuration', '/tmp/vcs-recorder/phpunit.xml', '--do-not-cache-result', "{$copy}/{$test}.php"]);
$cases = array_values(Recorder::cases());
if ($test === 'E2E/GitHubTest') {
    $private = openssl_pkey_get_private($pem);
    $public = $private === false ? '' : (string) (openssl_pkey_get_details($private) ?: [])['key'];
    foreach ($cases as $i => $case) {
        array_unshift($cases[$i]['steps'], ['op' => 'fixture.config', 'args' => ['config' => ['github' => ['rs256' => $public]]]]);
    }
}
$file = [
    'source' => "packages/vcs/tests/{$test}.php",
    'cases' => $cases,
];
file_put_contents($out . '/' . str_replace('/', '-', $test) . '.json', json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
foreach (Recorder::$outcomes as $name => $outcome) {
    fwrite(STDERR, basename($test) . "::{$name}: {$outcome}\n");
}
