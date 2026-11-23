<?php

// Parity corpus for utopia-php/detector: the detectors, Strategy, and every framework, packager,
// rendering and runtime. Run it on the PHP classes and on the utopia extension's
// (_APP_EXTENSION_LIBRARIES=detector), and diff stdout: every line must match. Stderr names the
// implementation that ran.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Detector\Detection;
use Utopia\Detector\Detection\Framework\Analog;
use Utopia\Detector\Detection\Framework\Angular;
use Utopia\Detector\Detection\Framework as FrameworkDetection;
use Utopia\Detector\Detection\Framework\Astro;
use Utopia\Detector\Detection\Framework\Flutter;
use Utopia\Detector\Detection\Framework\Jaspr;
use Utopia\Detector\Detection\Framework\JS;
use Utopia\Detector\Detection\Framework\Lynx;
use Utopia\Detector\Detection\Framework\NextJs;
use Utopia\Detector\Detection\Framework\Nuxt;
use Utopia\Detector\Detection\Framework\React;
use Utopia\Detector\Detection\Framework\ReactNative;
use Utopia\Detector\Detection\Framework\Remix;
use Utopia\Detector\Detection\Framework\Svelte;
use Utopia\Detector\Detection\Framework\SvelteKit;
use Utopia\Detector\Detection\Framework\TanStackStart;
use Utopia\Detector\Detection\Framework\Vue;
use Utopia\Detector\Detection\Packager as PackagerDetection;
use Utopia\Detector\Detection\Packager\NPM;
use Utopia\Detector\Detection\Packager\PNPM;
use Utopia\Detector\Detection\Packager\Yarn;
use Utopia\Detector\Detection\Rendering as RenderingDetection;
use Utopia\Detector\Detection\Rendering\SSR;
use Utopia\Detector\Detection\Rendering\XStatic;
use Utopia\Detector\Detection\Runtime as RuntimeDetection;
use Utopia\Detector\Detection\Runtime\Bun;
use Utopia\Detector\Detection\Runtime\CPP;
use Utopia\Detector\Detection\Runtime\Dart;
use Utopia\Detector\Detection\Runtime\Deno;
use Utopia\Detector\Detection\Runtime\Dotnet;
use Utopia\Detector\Detection\Runtime\Java;
use Utopia\Detector\Detection\Runtime\Node;
use Utopia\Detector\Detection\Runtime\PHP;
use Utopia\Detector\Detection\Runtime\Python;
use Utopia\Detector\Detection\Runtime\Ruby;
use Utopia\Detector\Detection\Runtime\Swift;
use Utopia\Detector\Detector;
use Utopia\Detector\Detector\Framework;
use Utopia\Detector\Detector\Packager;
use Utopia\Detector\Detector\Rendering;
use Utopia\Detector\Detector\Runtime;
use Utopia\Detector\Detector\Strategy;

const FRAMEWORKS = [Analog::class, Angular::class, Astro::class, Flutter::class, Jaspr::class, Lynx::class, NextJs::class, Nuxt::class, React::class, ReactNative::class, Remix::class, Svelte::class, SvelteKit::class, TanStackStart::class, Vue::class];
const PACKAGERS = [NPM::class, PNPM::class, Yarn::class];
const RENDERINGS = [SSR::class, XStatic::class];
const RUNTIMES = [Bun::class, CPP::class, Dart::class, Deno::class, Dotnet::class, Java::class, Node::class, PHP::class, Python::class, Ruby::class, Swift::class];
const BASES = [Detection::class, Detector::class, FrameworkDetection::class, JS::class, PackagerDetection::class, RenderingDetection::class, RuntimeDetection::class, Strategy::class, Framework::class, Packager::class, Rendering::class, Runtime::class];
const CLASSES = [...BASES, ...FRAMEWORKS, ...PACKAGERS, ...RENDERINGS, ...RUNTIMES];
/** The packagers the commands are read with: the three, the empty default, others, and bytes that are not UTF-8. */
const PACKAGER_NAMES = ['npm', 'pnpm', 'yarn', '', 'bun', 'NPM', ' npm', 'deno', "np\xffm"];

$internal = (new ReflectionClass(Detector::class))->isInternal();
fwrite(STDERR, 'Utopia\\Detector: ' . ($internal ? 'utopia extension' : 'PHP') . PHP_EOL);
// A run meant for the extension that gets the PHP classes would compare PHP with PHP and pass.
$requested = in_array('detector', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' detector, but the other implementation loaded' . PHP_EOL);
    exit(1);
}
foreach (CLASSES as $class) {
    if ((new ReflectionClass($class))->isInternal() !== $internal) {
        fwrite(STDERR, $class . ' is not from the same implementation as Detector' . PHP_EOL);
        exit(1);
    }
}

/** Warnings raised by the current case, and not silenced: PHP's `@yaml_parse()` raises its own under `@`. */
$warnings = [];
set_error_handler(function (int $level, string $message) use (&$warnings): bool {
    if (!(error_reporting() & $level)) {
        return true;
    }
    $warnings[] = $message;

    return true;
});

/** $value with every string that is not UTF-8 as its hex, and every object as its class and properties. */
function exported(mixed $value): mixed
{
    if (is_string($value)) {
        return mb_check_encoding($value, 'UTF-8') ? $value : ['hex' => bin2hex($value)];
    }
    if (is_array($value)) {
        return array_map(exported(...), $value);
    }
    if (is_object($value)) {
        return ['class' => $value::class, 'properties' => properties($value)];
    }

    return $value;
}

/** The properties as (array) shows them, keyed by name (objects inside as their class). */
function properties(object $object): array
{
    $properties = [];
    foreach ((array) $object as $key => $value) {
        $at = strrpos($key, "\0");
        $name = $at === false ? $key : substr($key, $at + 1);
        $properties[$name] = is_object($value) ? ['class' => $value::class] : (is_array($value) ? array_map(fn ($v) => is_object($v) ? ['class' => $v::class] : exported($v), $value) : exported($value));
    }

    return $properties;
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

/** $dump with each object handle ("#12 ") dropped: handles differ between runs. */
function withoutHandles(string $dump): string
{
    return preg_replace('/#\d+ /', '# ', $dump);
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

// Each class, as reflection shows it. `isInternal()`, the file and the doc comments differ by
// construction.
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
        'parents' => class_parents($name), 'methods' => get_class_methods($name),
    ]));
    foreach ($class->getProperties() as $property) {
        emit('property ' . $name . '::$' . $property->getName(), outcome(fn () => [
            $property->getDeclaringClass()->getName(), Reflection::getModifierNames($property->getModifiers()), (string) $property->getType(),
            $property->hasDefaultValue(), exported($property->getDefaultValue()), $property->isPromoted(), $property->isReadOnly(), $property->isStatic(),
        ]));
    }
    emit('property order ' . $name, outcome(fn () => array_map(fn ($p) => $p->getName(), $class->getProperties())));
    foreach ($class->getMethods() as $method) {
        emit('method ' . $name . '::' . $method->getName(), outcome(fn () => [
            $method->getDeclaringClass()->getName(), Reflection::getModifierNames($method->getModifiers()), $method->isStatic(), $method->isAbstract(),
            (string) $method->getReturnType(), $method->getReturnType()?->allowsNull(), $method->hasTentativeReturnType(), $method->returnsReference(),
            $method->getNumberOfParameters(), $method->getNumberOfRequiredParameters(), array_map(parameter(...), $method->getParameters()),
        ]));
    }
}

// Every leaf's answers, the commands with every packager, and setPackager()'s return.
foreach (FRAMEWORKS as $class) {
    emit('framework ' . $class, outcome(function () use ($class) {
        $framework = new $class();

        return [
            $framework->getName(), $framework->getFiles(), $framework->getPackages(), $framework->getOutputDirectory(), $framework->getConfigFiles(),
            $framework->getInstallCommand(), $framework->getBuildCommand(), get_parent_class($framework), $framework instanceof Detection, $framework instanceof JS,
            $framework::class,
        ];
    }));
    foreach (PACKAGER_NAMES as $packager) {
        emit('framework commands ' . $class . ' ' . $packager, outcome(function () use ($class, $packager) {
            $framework = new $class();
            $same = $framework->setPackager($packager) === $framework;

            return [$same, $framework->getInstallCommand(), $framework->getBuildCommand(), $framework->getOutputDirectory()];
        }));
    }
}
foreach (RUNTIMES as $class) {
    emit('runtime ' . $class, outcome(function () use ($class) {
        $runtime = new $class();

        return [$runtime->getName(), $runtime->getLanguages(), $runtime->getFileExtensions(), $runtime->getFiles(), $runtime->getCommands(), $runtime->getEntrypoint(), get_parent_class($runtime)];
    }));
    foreach (PACKAGER_NAMES as $packager) {
        emit('runtime commands ' . $class . ' ' . $packager, outcome(function () use ($class, $packager) {
            $runtime = new $class();
            $same = $runtime->setPackager($packager) === $runtime;

            return [$same, $runtime->getCommands(), $runtime->getEntrypoint()];
        }));
    }
}
foreach (PACKAGERS as $class) {
    emit('packager ' . $class, outcome(fn () => [(new $class())->getName(), (new $class())->getFiles(), get_parent_class($class)]));
}
$renderingFrameworks = ['nextjs', 'nuxt', 'sveltekit', 'astro', 'remix', 'angular', 'analog', 'tanstack-start', 'flutter', 'jaspr', 'lynx', 'react', 'vue', '', 'NEXTJS', '0', "\xff"];
foreach (RENDERINGS as $class) {
    emit('rendering ' . $class, outcome(fn () => [(new $class())->getName(), (new $class())->getFallbackFile(), (new $class('index.html'))->getFallbackFile(), (new $class(null))->getFallbackFile()]));
    foreach ($renderingFrameworks as $framework) {
        emit('rendering files ' . $class . ' ' . $framework, outcome(fn () => (new $class())->getFiles($framework)));
    }
}
emit('SSR::FRAMEWORK_FILES', outcome(fn () => SSR::FRAMEWORK_FILES));
emit('constants', outcome(fn () => [Framework::INPUT_FILE, Framework::INPUT_PACKAGES, Strategy::FILEMATCH, Strategy::EXTENSION, Strategy::LANGUAGES]));

// Adapters, from the configuration files the frameworks read.
$configs = [
    '', 'export default {}', "export default defineConfig({ output: 'server' })", 'export default defineConfig({ output: "hybrid" })',
    'export default defineConfig({ output: `server` })', "export default defineConfig({ output: 'static' })", "// output: 'server'\nexport default {}",
    "const url = 'https://x.dev'; // output: 'server'", "export default { output:'server' }", "export default { output\n:\n'server' }", "export default { myoutput: 'server' }",
    "export default { output: 'servers' }", "/* output: 'server' */", "import adapter from '@sveltejs/adapter-static';", "import adapter from '@sveltejs/adapter-node';",
    "// import adapter from '@sveltejs/adapter-static';\nimport adapter from '@sveltejs/adapter-auto';", '{"devDependencies":{"@sveltejs/adapter-static":"^3"}}',
    "import { nitro } from 'nitro/vite'", "import { nitroV2Plugin } from '@tanstack/nitro-v2-vite-plugin'", 'tanstackStart({ prerender: { enabled: true } })',
    "tanstackStart({ 'prerender': false })", 'tanstackStart({ prerender: false })', 'tanstackStart({ prerender: falsey })', 'tanstackStart({ prerenderAll: true })',
    "tanstackStart({ \"prerender\" :\tfalse })", "name: app\njaspr:\n  mode: server\n", "name: app\njaspr:\n  mode: static\n", "name: app\njaspr:\n  mode: client\n",
    "name: app\njaspr:\n  mode: other\n", "name: app\njaspr: server\n", "name: app\n", "jaspr:\n  mode: [server]\n", "jaspr: {mode: server}", ": : :\n\t- [", "jaspr:\n  mode: 1\n",
    "\xff\xfe output: 'server'", "output: 'server'\0", str_repeat('// comment', 200) . "\noutput: 'hybrid'",
];
foreach (FRAMEWORKS as $class) {
    foreach ($configs as $i => $config) {
        emit('adapter ' . $class . ' ' . $i, outcome(fn () => (new $class())->getAdapter($config)));
    }
}

// Strategy.
foreach (['filematch', 'extension', 'languages', '', 'x', 'FILEMATCH', ' filematch', 'filematch ', '0', "\xff", "file\0match", 'languages2'] as $value) {
    emit('strategy ' . bin2hex($value), outcome(function () use ($value) {
        $strategy = new Strategy($value);

        return [$strategy->getValue(), properties($strategy)];
    }));
}

// Inputs and options as the detectors store them.
emit('inputs', outcome(function () {
    $packager = new Packager();
    $same = $packager->addInput('a')->addInput('b', 'x') === $packager;
    $packager->addOption(new NPM())->addOption(new NPM());
    $framework = new Framework();
    $framework->addInput('next.config.js', Framework::INPUT_FILE)->addInput('{}', 'packages');

    return [$same, properties($packager), properties($framework), properties(new Framework('yarn')), properties(new Rendering('nextjs')), properties(new Runtime(new Strategy('languages'), 'npm'))];
}));
foreach (['', 'language', 'FILE', 'file ', 'Packages', "\xff", 'extension', 'path'] as $type) {
    emit('framework input type ' . bin2hex($type), outcome(fn () => properties((new Framework())->addInput('x', $type))));
}
emit('framework input type missing', outcome(fn () => (new Framework())->addInput('x')));

/** Arguments of the wrong type, as PHP coerces or refuses them (weak mode: this file has no strict_types). */
$arguments = [
    'addInput int' => fn () => properties((new Packager())->addInput(12, 34)),
    'addInput float' => fn () => properties((new Packager())->addInput(1.5)),
    'addInput bool' => fn () => properties((new Packager())->addInput(true)),
    'addInput null' => fn () => (new Packager())->addInput(null),
    'addInput array' => fn () => (new Packager())->addInput([]),
    'addInput stringable' => fn () => properties((new Packager())->addInput(new class () {
        public function __toString(): string
        {
            return 'package.json';
        }
    })),
    'addInput none' => fn () => (new Packager())->addInput(),
    'framework addInput int type' => fn () => (new Framework())->addInput('x', 1),
    'addOption string' => fn () => (new Packager())->addOption('npm'),
    'addOption null' => fn () => (new Packager())->addOption(null),
    'addOption object' => fn () => (new Packager())->addOption(new stdClass()),
    'addOption strategy' => fn () => (new Packager())->addOption(new Strategy('filematch')),
    'addOption none' => fn () => (new Packager())->addOption(),
    'addOption other kind' => fn () => properties((new Packager())->addOption(new Node())),
    'runtime no strategy' => fn () => new Runtime(),
    'runtime string strategy' => fn () => new Runtime('filematch'),
    'runtime null strategy' => fn () => new Runtime(null),
    'runtime int packager' => fn () => properties(new Runtime(new Strategy('filematch'), 5)),
    'rendering no framework' => fn () => new Rendering(),
    'rendering int framework' => fn () => properties(new Rendering(5)),
    'rendering array framework' => fn () => new Rendering([]),
    'framework int packager' => fn () => properties(new Framework(7)),
    'framework null packager' => fn () => new Framework(null),
    'strategy none' => fn () => new Strategy(),
    'strategy int' => fn () => new Strategy(1),
    'strategy null' => fn () => new Strategy(null),
    'setPackager int' => fn () => properties((new React())->setPackager(1)),
    'setPackager null' => fn () => (new React())->setPackager(null),
    'setPackager none' => fn () => (new Node())->setPackager(),
    'getAdapter none' => fn () => (new Astro())->getAdapter(),
    'getAdapter array' => fn () => (new Astro())->getAdapter([]),
    'getAdapter int' => fn () => (new SvelteKit())->getAdapter(5),
    'base getAdapter array' => fn () => (new React())->getAdapter([]),
    'base getAdapter none' => fn () => (new React())->getAdapter(),
    'getFiles none' => fn () => (new SSR())->getFiles(),
    'getFiles int' => fn () => (new SSR())->getFiles(1),
    'static getFiles array' => fn () => (new XStatic())->getFiles([]),
    'rendering construct array' => fn () => new XStatic([]),
    'rendering construct int' => fn () => properties(new XStatic(5)),
    'extra arguments' => fn () => [properties((new Packager('x', 'y'))->addInput('a', 'b', 'c')), (new React('x'))->getName('y')],
    'abstract detector' => fn () => new Detector(),
    'abstract detection' => fn () => new Detection(),
    'abstract framework' => fn () => new FrameworkDetection(),
    'abstract js' => fn () => new JS(),
];
foreach ($arguments as $case => $run) {
    emit('arguments ' . $case, outcome($run));
}
// Strict mode, in a child process (the caller's mode decides).
foreach ([
    'new Utopia\\Detector\\Detector\\Packager(); (new Utopia\\Detector\\Detector\\Packager())->addInput(5);',
    '(new Utopia\\Detector\\Detection\\Framework\\React())->setPackager(5);',
    'new Utopia\\Detector\\Detector\\Strategy(5);',
    '(new Utopia\\Detector\\Detection\\Framework\\Astro())->getAdapter(5);',
    'new Utopia\\Detector\\Detector\\Framework(5);',
] as $code) {
    emit('strict ' . $code, outcome(function () use ($code) {
        $result = child('try { ' . $code . ' echo "ok"; } catch (Throwable $e) { echo get_class($e), ": ", preg_replace("/, called in .*/", "", $e->getMessage()); }', true);

        return $result;
    }));
}

// Real-world projects: file lists and manifests, through every detector as Appwrite runs them.
$projects = [
    'next app' => [['package.json', 'package-lock.json', 'next.config.js', 'tsconfig.json', 'app', 'public', 'README.md', '.gitignore'], '{"name":"web","dependencies":{"next":"15.0.0","react":"19.0.0","react-dom":"19.0.0"},"devDependencies":{"typescript":"^5","@types/react":"^19"}}', ['TypeScript', 'CSS', 'JavaScript']],
    'next pnpm mjs' => [['package.json', 'pnpm-lock.yaml', 'next.config.mjs', 'src', 'tailwind.config.ts'], '{"dependencies":{"next":"14","react":"18"}}', ['TypeScript']],
    'next no config' => [['package.json', 'yarn.lock', 'pages'], '{"dependencies":{"next":"13","react":"18"}}', ['JavaScript']],
    'nuxt' => [['package.json', 'nuxt.config.ts', 'app.vue', 'pnpm-lock.yaml'], '{"private":true,"devDependencies":{"nuxt":"^3.12"},"dependencies":{"vue":"^3.4"}}', ['Vue', 'TypeScript']],
    'sveltekit' => [['package.json', 'svelte.config.js', 'vite.config.ts', 'src', 'static', 'package-lock.json'], '{"devDependencies":{"@sveltejs/kit":"^2","@sveltejs/adapter-auto":"^3","svelte":"^5","vite":"^5"}}', ['Svelte', 'TypeScript', 'JavaScript']],
    'svelte vite' => [['package.json', 'svelte.config.js', 'vite.config.js', 'index.html'], '{"devDependencies":{"svelte":"^4","@sveltejs/vite-plugin-svelte":"^3"}}', ['Svelte', 'JavaScript']],
    'astro' => [['package.json', 'astro.config.mjs', 'src', 'public', 'pnpm-lock.yaml'], '{"dependencies":{"astro":"^4.0.0"}}', ['Astro', 'TypeScript']],
    'astro react' => [['package.json', 'astro.config.mjs', 'tsconfig.json'], '{"dependencies":{"astro":"^4","@astrojs/react":"^3","react":"^18","react-dom":"^18"}}', ['Astro', 'TypeScript']],
    'astro svelte' => [['package.json', 'astro.config.ts', 'svelte.config.js'], '{"dependencies":{"astro":"^4","svelte":"^4"}}', ['Astro']],
    'astro vue only' => [['package.json'], '{"dependencies":{"astro":"^4","vue":"^3"}}', []],
    'remix' => [['package.json', 'remix.config.js', 'app', 'yarn.lock'], '{"dependencies":{"@remix-run/node":"^2","@remix-run/react":"^2","react":"^18"}}', ['TypeScript']],
    'remix vite' => [['package.json', 'vite.config.ts', 'app'], '{"dependencies":{"@remix-run/react":"^2","react":"^18"}}', ['TypeScript']],
    'angular' => [['package.json', 'angular.json', 'tsconfig.json', 'src'], '{"dependencies":{"@angular/core":"^18","@angular/common":"^18","rxjs":"~7"}}', ['TypeScript', 'HTML', 'SCSS']],
    'analog' => [['package.json', 'angular.json', 'vite.config.ts'], '{"dependencies":{"@analogjs/platform":"^1","@angular/core":"^17"}}', ['TypeScript']],
    'react vite' => [['package.json', 'vite.config.ts', 'index.html', 'src', 'package-lock.json'], '{"dependencies":{"react":"^18","react-dom":"^18"},"devDependencies":{"vite":"^5"}}', ['TypeScript', 'CSS']],
    'react native expo' => [['package.json', 'app.json', 'App.tsx', 'yarn.lock'], '{"dependencies":{"expo":"~51","react":"18.2.0","react-native":"0.74.0"}}', ['TypeScript']],
    'lynx' => [['package.json', 'lynx.config.ts', 'src'], '{"dependencies":{"@lynx-js/react":"^0.1","react":"^18"}}', ['TypeScript']],
    'tanstack start' => [['package.json', 'vite.config.ts', 'src'], '{"dependencies":{"@tanstack/react-start":"^1","@tanstack/react-router":"^1","react":"^19"}}', ['TypeScript']],
    'tanstack solid' => [['package.json', 'app.config.ts'], '{"dependencies":{"@tanstack/solid-start":"^1","solid-js":"^1"}}', ['TypeScript']],
    'vue vite' => [['package.json', 'vite.config.js', 'index.html', 'src'], '{"dependencies":{"vue":"^3.4"},"devDependencies":{"@vitejs/plugin-vue":"^5"}}', ['Vue', 'JavaScript']],
    'flutter' => [['pubspec.yaml', 'pubspec.lock', 'lib', 'web', 'analysis_options.yaml'], "name: app\nenvironment:\n  sdk: '>=3.0.0 <4.0.0'\ndependencies:\n  flutter:\n    sdk: flutter\n  cupertino_icons: ^1.0.2\ndev_dependencies:\n  flutter_test:\n    sdk: flutter\n", ['Dart', 'HTML']],
    'jaspr' => [['pubspec.yaml', 'pubspec.lock', 'lib', 'web'], "name: site\ndependencies:\n  jaspr: ^0.15.0\ndev_dependencies:\n  build_runner: ^2.4.0\n  jaspr_builder: ^0.15.0\njaspr:\n  mode: static\n", ['Dart']],
    'jaspr with flutter' => [['pubspec.yaml'], "name: x\ndependencies:\n  jaspr: any\n  flutter:\n    sdk: flutter\n", ['Dart']],
    'dart server' => [['pubspec.yaml', 'bin', 'lib'], "name: srv\ndependencies:\n  shelf: ^1.4.0\n", ['Dart']],
    'library only' => [['package.json', 'index.js', 'package-lock.json'], '{"dependencies":{"lodash":"^4"}}', ['JavaScript']],
    'monorepo' => [['package.json', 'pnpm-lock.yaml', 'pnpm-workspace.yaml', 'apps', 'packages', 'turbo.json'], '{"private":true,"devDependencies":{"turbo":"^2"}}', ['TypeScript']],
    'no manifest' => [['index.html', 'style.css', 'app.js'], '', ['HTML', 'CSS', 'JavaScript']],
    'python' => [['requirements.txt', 'main.py', 'setup.py'], '', ['Python']],
    'php' => [['composer.json', 'composer.lock', 'index.php', 'src'], '', ['PHP']],
    'ruby' => [['Gemfile', 'Gemfile.lock', 'main.rb', 'Rakefile'], '', ['Ruby']],
    'deno' => [['deps.ts', 'mod.ts', 'main.ts'], '', ['TypeScript']],
    'bun' => [['bun.lockb', 'package.json', 'index.ts'], '{"dependencies":{"hono":"^4"}}', ['TypeScript']],
    'dotnet' => [['Program.cs', 'Function.csproj'], '', ['C#']],
    'java' => [['pom.xml', 'src'], '', ['Java']],
    'kotlin' => [['build.gradle.kts', 'src'], '', ['Kotlin']],
    'swift' => [['Package.swift', 'Sources'], '', ['Swift']],
    'cpp' => [['CMakeLists.txt', 'main.cpp', 'util.hpp'], '', ['C++', 'CMake']],
    'go' => [['go.mod', 'main.go'], '', ['Go']],
    'empty' => [[], '', []],
    'nested paths' => [['./package.json', 'src/next.config.js', 'NEXT.CONFIG.JS', 'next.config.js/', ' next.config.js'], '{"dependencies":{"react":"1"}}', ['typescript']],
];
$frameworkOptions = fn () => [new Analog(), new Angular(), new Astro(), new Flutter(), new Jaspr(), new Lynx(), new NextJs(), new Nuxt(), new React(), new ReactNative(), new Remix(), new Svelte(), new SvelteKit(), new TanStackStart(), new Vue()];
$runtimeOptions = fn () => [new Bun(), new Deno(), new Node(), new PHP(), new Python(), new Dart(), new Swift(), new Ruby(), new Java(), new CPP(), new Dotnet()];
foreach ($projects as $project => [$files, $manifest, $languages]) {
    emit('project ' . $project, outcome(function () use ($files, $manifest, $languages, $frameworkOptions, $runtimeOptions) {
        $detector = new Packager();
        foreach ($files as $file) {
            $detector->addInput($file);
        }
        $detector->addOption(new Yarn())->addOption(new PNPM())->addOption(new NPM());
        $packager = $detector->detect();
        $name = $packager?->getName() ?? 'npm';
        $out = ['packager' => $packager];

        $detector = new Framework($name);
        $detector->addInput($manifest, Framework::INPUT_PACKAGES);
        foreach ($files as $file) {
            $detector->addInput($file, Framework::INPUT_FILE);
        }
        foreach ($frameworkOptions() as $option) {
            $detector->addOption($option);
        }
        $framework = $detector->detect();
        $out['framework'] = $framework === null ? null : [$framework->getName(), $framework->getInstallCommand(), $framework->getBuildCommand(), $framework->getOutputDirectory(), $framework];

        foreach ([Strategy::FILEMATCH, Strategy::LANGUAGES, Strategy::EXTENSION] as $value) {
            $detector = new Runtime(new Strategy($value), $name);
            foreach ($value === Strategy::LANGUAGES ? $languages : $files as $input) {
                $detector->addInput($input);
            }
            foreach ($runtimeOptions() as $option) {
                $detector->addOption($option);
            }
            $runtime = $detector->detect();
            $out['runtime ' . $value] = $runtime === null ? null : [$runtime->getName(), $runtime->getCommands(), $runtime->getEntrypoint(), $runtime];
        }

        return $out;
    }));
}

// Every pair of frameworks, in both orders, scored on one file or package of each: the ties,
// the parents tie-break and Astro's.
$signals = [];
foreach (FRAMEWORKS as $class) {
    $framework = new $class();
    $signals[$class] = [$framework->getFiles()[0] ?? null, $framework->getPackages()[0] ?? null];
}
foreach (FRAMEWORKS as $a) {
    foreach (FRAMEWORKS as $b) {
        foreach (['files', 'packages', 'both'] as $via) {
            emit('pair ' . $a . ' ' . $b . ' ' . $via, outcome(function () use ($a, $b, $via, $signals) {
                $detector = new Framework('yarn');
                $dependencies = [];
                foreach ([$a, $b] as $class) {
                    [$file, $package] = $signals[$class];
                    if ($via !== 'packages' && $file !== null) {
                        $detector->addInput($file, Framework::INPUT_FILE);
                    }
                    if ($via !== 'files' && $package !== null) {
                        $dependencies[$package] = '*';
                    }
                }
                $detector->addInput(json_encode(['dependencies' => (object) $dependencies]), Framework::INPUT_PACKAGES);
                $detector->addOption(new $a())->addOption(new $b());
                $found = $detector->detect();

                return $found === null ? null : [$found::class, $found->getBuildCommand()];
            }));
        }
    }
}

// Seeded random projects: subsets of every framework's files and packages, options in random
// orders and subsets, manifests in JSON and YAML.
mt_srand(7503);
$allFiles = ['package.json', 'pubspec.yaml', 'pubspec.lock', 'index.html', 'vite.config.ts', 'README.md'];
$allPackages = ['react-dom', 'typescript', 'vite', 'lodash'];
foreach (FRAMEWORKS as $class) {
    $framework = new $class();
    $allFiles = array_merge($allFiles, $framework->getFiles());
    $allPackages = array_merge($allPackages, $framework->getPackages());
}
$allFiles = array_values(array_unique($allFiles));
$allPackages = array_values(array_unique($allPackages));
$packagerNames = ['npm', 'pnpm', 'yarn', '', 'bun'];
for ($i = 0; $i < 3000; $i++) {
    $files = [];
    foreach ($allFiles as $file) {
        if (mt_rand(0, 9) === 0) {
            $files[] = $file;
        }
    }
    $packages = [];
    foreach ($allPackages as $package) {
        if (mt_rand(0, 7) === 0) {
            $packages[$package] = '^1.0.0';
        }
    }
    $options = FRAMEWORKS;
    shuffle($options);
    $options = array_slice($options, 0, mt_rand(1, count($options)));
    $packager = $packagerNames[mt_rand(0, count($packagerNames) - 1)];
    $sections = ['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies', 'dev_dependencies', 'dependency_overrides', 'other'];
    $section = $sections[mt_rand(0, count($sections) - 1)];
    $format = mt_rand(0, 3);
    $manifest = match ($format) {
        0, 1 => json_encode([$section => (object) $packages]),
        2 => yaml_emit([$section => $packages]),
        3 => '',
    };
    $manifests = mt_rand(0, 4) === 0 ? [$manifest, json_encode(['dependencies' => (object) array_slice($packages, 0, 1)])] : [$manifest];
    emit('random ' . $i, outcome(function () use ($files, $manifests, $options, $packager) {
        $detector = new Framework($packager);
        foreach ($manifests as $manifest) {
            $detector->addInput($manifest, Framework::INPUT_PACKAGES);
        }
        foreach ($files as $file) {
            $detector->addInput($file, Framework::INPUT_FILE);
        }
        foreach ($options as $class) {
            $detector->addOption(new $class());
        }
        $found = $detector->detect();

        return $found === null ? null : [$found::class, $found->getInstallCommand(), $found->getBuildCommand()];
    }));
}

// Manifests: what dependencies() reads out of package.json and pubspec.yaml, valid or not.
class ExposedFramework extends Framework
{
    public function read(string $manifest): array
    {
        return $this->dependencies($manifest);
    }
}
$manifests = [
    '', '{}', '[]', 'null', 'true', '1', '"string"', '{"dependencies":{}}', '{"dependencies":[]}', '{"dependencies":["react","vue"]}',
    '{"dependencies":{"react":"1","vue":"2"},"devDependencies":{"svelte":"3"},"peerDependencies":{"react":"1"},"optionalDependencies":{"x":"1"}}',
    '{"dependencies":{"123":"1","0":"x","-1":"y","01":"z"}}', '{"dependencies":"react"}', '{"dependencies":null}', '{"Dependencies":{"react":"1"}}',
    '{"dependencies":{"react":"1"},"dependencies":{"vue":"1"}}', '{"dependencies":{"":"1"," react":"2"}}', '{"dependencies":{"ré":"1","\u00e9":"2"}}',
    '{"dependencies":{"a":"1"}', '{dependencies: {react: 1}}', "{'dependencies': {'react': '1'}}", '{"dependencies":{"react":"1"}} trailing',
    str_repeat('[', 600) . str_repeat(']', 600), '{"a":' . str_repeat('{"a":', 520) . '1' . str_repeat('}', 521),
    "dependencies:\n  flutter:\n    sdk: flutter\n  http: ^1.0.0\ndev_dependencies:\n  lints: ^3.0.0\ndependency_overrides:\n  meta: 1.0.0\n",
    "dependencies:\n  - react\n  - vue\n", "dependencies: react\n", "dependencies:\n  123: x\n  true: y\n  null: z\n", "dependencies:\n  1.5: x\n",
    "---\ndependencies:\n  a: 1\n---\ndependencies:\n  b: 1\n", "dependencies: {a: 1, b: 2}\n", "dependencies:\n\ta: 1\n", ": : :\n- [", "%YAML 1.2\n---\ndependencies:\n  a: 1\n",
    "dependencies: &x\n  a: 1\ndev_dependencies: *x\n", "dependencies:\n  ? complex\n  : value\n", "# comment only\n", "\xff\xfe{}", "{\"dependencies\":{\"\xff\":\"1\"}}",
    "dependencies:\n  \"\xff\": 1\n", "dependencies:\n  a: !!binary aGVsbG8=\n", '{"devDependencies":{"@angular/core":"1"},"dev_dependencies":{"jaspr":"1"}}',
];
foreach ($manifests as $i => $manifest) {
    emit('manifest ' . $i, outcome(fn () => (new ExposedFramework())->read($manifest)));
    emit('manifest detect ' . $i, outcome(function () use ($manifest, $frameworkOptions) {
        $detector = new Framework();
        $detector->addInput($manifest, Framework::INPUT_PACKAGES);
        foreach ($frameworkOptions() as $option) {
            $detector->addOption($option);
        }

        return $detector->detect()?->getName();
    }));
}

// Rendering: each framework's server files, HTML fallbacks, and option orders.
$builds = [
    'next server' => ['nextjs', ['.next/server/webpack-runtime.js', '.next/static/chunk.js']],
    'next turbopack' => ['nextjs', ['.next/turbopack', 'server/pages/index.html']],
    'next standalone' => ['nextjs', ['server.js', 'node_modules']],
    'next export' => ['nextjs', ['index.html', '404.html', '_next/static/x.js']],
    'nuxt' => ['nuxt', ['server/index.mjs', 'public/index.html']],
    'nuxt generate' => ['nuxt', ['index.html', '_nuxt/entry.js']],
    'sveltekit node' => ['sveltekit', ['handler.js', 'index.js']],
    'sveltekit static' => ['sveltekit', ['index.html']],
    'astro ssr' => ['astro', ['server/entry.mjs', 'client/_astro/x.js']],
    'astro static' => ['astro', ['index.html', 'about/index.html']],
    'remix' => ['remix', ['build/server/index.js', 'build/client/assets/x.js']],
    'angular ssr' => ['angular', ['server/server.mjs', 'browser/index.html']],
    'analog' => ['analog', ['server/index.mjs']],
    'tanstack' => ['tanstack-start', ['server/server.js']],
    'tanstack nitro' => ['tanstack-start', ['server/index.mjs']],
    'flutter' => ['flutter', ['index.html', 'main.dart.js', 'flutter.js']],
    'jaspr server' => ['jaspr', ['app']],
    'jaspr static' => ['jaspr', ['index.html', 'main.dart.js']],
    'lynx' => ['lynx', ['main.lynx.bundle']],
    'other' => ['other', ['server.js', 'index.html']],
    'empty framework' => ['', ['server.js']],
    'one html deep' => ['vue', ['assets/app.js', 'dist/index.html']],
    'two html' => ['vue', ['index.html', '404.html']],
    'html upper' => ['vue', ['INDEX.HTML']],
    'html suffix' => ['vue', ['index.html.bak', 'index.htm']],
    'html dotfile' => ['vue', ['.html']],
    'html dir' => ['vue', ['dir.html/', 'a.b/index']],
    'html query' => ['vue', ['index.html?x', 'page.html']],
    'html not utf8' => ['vue', ["\xff.html"]],
    'no files' => ['nextjs', []],
];
foreach ($builds as $build => [$framework, $files]) {
    foreach ([[SSR::class, XStatic::class], [XStatic::class, SSR::class], [SSR::class], [XStatic::class], []] as $order) {
        emit('rendering ' . $build . ' ' . implode(',', $order), outcome(function () use ($framework, $files, $order) {
            $detector = new Rendering($framework);
            foreach ($files as $file) {
                $detector->addInput($file);
            }
            $options = [];
            foreach ($order as $class) {
                $detector->addOption($options[] = new $class());
            }
            $found = $detector->detect();

            return [$found, array_search($found, $options, true), $found->getName(), $found->getFallbackFile()];
        }));
    }
}

// Runtimes: every runtime's signals through every strategy, alone and in orders.
foreach (RUNTIMES as $class) {
    $runtime = new $class();
    foreach ([Strategy::FILEMATCH => $runtime->getFiles(), Strategy::LANGUAGES => $runtime->getLanguages(), Strategy::EXTENSION => array_map(fn ($e) => 'src/main.' . $e, $runtime->getFileExtensions())] as $value => $inputs) {
        foreach ($inputs as $input) {
            foreach (['forward', 'reverse'] as $order) {
                emit('runtime ' . $class . ' ' . $value . ' ' . $input . ' ' . $order, outcome(function () use ($value, $input, $order) {
                    $detector = new Runtime(new Strategy($value), 'yarn');
                    $detector->addInput($input);
                    $options = RUNTIMES;
                    if ($order === 'reverse') {
                        $options = array_reverse($options);
                    }
                    foreach ($options as $option) {
                        $detector->addOption(new $option());
                    }
                    $found = $detector->detect();

                    return $found === null ? null : [$found::class, $found->getCommands()];
                }));
            }
        }
    }
}
foreach (['file.', '.ts', 'a.b.ts', 'dir.ts/file', 'file.TS', 'noext', '', '.', 'x/.js', "a.\xff", 'archive.tar.gz', 'C:\\dir\\main.py', 'main.py/'] as $input) {
    emit('runtime extension ' . bin2hex($input), outcome(function () use ($input, $runtimeOptions) {
        $detector = new Runtime(new Strategy(Strategy::EXTENSION));
        $detector->addInput($input);
        foreach ($runtimeOptions() as $option) {
            $detector->addOption($option);
        }

        return $detector->detect()?->getName();
    }));
}

// PHP subclasses: of the bases, of the leaves and of the detectors, through detect().
class Custom extends FrameworkDetection
{
    public array $calls = [];

    public function __construct(private string $name = 'custom', private array $files = ['custom.config.js'], private array $packages = ['custom'])
    {
        parent::__construct();
    }

    public function getName(): string
    {
        $this->calls[] = 'getName';

        return $this->name;
    }

    public function getFiles(): array
    {
        $this->calls[] = 'getFiles';

        return $this->files;
    }

    public function getPackages(): array
    {
        $this->calls[] = 'getPackages';

        return $this->packages;
    }

    public function getInstallCommand(): string
    {
        return 'custom install ' . $this->packager;
    }

    public function getBuildCommand(): string
    {
        return 'custom build';
    }

    public function getOutputDirectory(): string
    {
        return './custom';
    }
}
class MyNext extends NextJs
{
    public function getFiles(): array
    {
        return ['my.config.js', ...parent::getFiles()];
    }
}
class MyAstro extends Astro
{
}
class RenamedReact extends React
{
    public function getName(): string
    {
        return 'renamed';
    }
}
class LoudPackager extends React
{
    public array $packagers = [];

    public function setPackager(string $packager): self
    {
        $this->packagers[] = $packager;

        return parent::setPackager(strtoupper($packager));
    }
}
class OwnDependencies extends Framework
{
    protected function dependencies(string $manifest): array
    {
        return explode(',', $manifest);
    }
}
class OwnInputs extends Framework
{
    public function addInput(string $content, string $type = ''): self
    {
        return parent::addInput(strtolower($content), $type);
    }
}
class Lockfile extends PackagerDetection
{
    public function getName(): string
    {
        return 'lockfile';
    }

    public function getFiles(): array
    {
        return ['custom.lock'];
    }
}
class Zig extends RuntimeDetection
{
    public function getName(): string
    {
        return 'zig';
    }

    public function getLanguages(): array
    {
        return ['Zig'];
    }

    public function getFileExtensions(): array
    {
        return ['zig'];
    }

    public function getFiles(): array
    {
        return ['build.zig'];
    }

    public function getCommands(): string
    {
        return 'zig build ' . $this->packager;
    }

    public function getEntrypoint(): string
    {
        return 'main.zig';
    }
}
class MyNode extends Node
{
    public function getLanguages(): array
    {
        return ['NodeScript'];
    }
}
class AlwaysLanguages extends Strategy
{
    public function getValue(): string
    {
        return Strategy::LANGUAGES;
    }
}
class Edge extends RenderingDetection
{
    public function getName(): string
    {
        return 'edge';
    }

    public function getFiles(string $framework): array
    {
        return [$framework . '/edge.js'];
    }
}
class MySSR extends SSR
{
}
class MyStatic extends XStatic
{
    public function getFiles(string $framework): array
    {
        return ['static.marker'];
    }
}
class WeirdFiles extends FrameworkDetection
{
    public function getName(): string
    {
        return 'weird';
    }

    public function getFiles(): array
    {
        return [1, 1.5, true, null, 'weird.js'];
    }

    public function getPackages(): array
    {
        return [1, 'weird', null];
    }

    public function getInstallCommand(): string
    {
        return '';
    }

    public function getBuildCommand(): string
    {
        return '';
    }

    public function getOutputDirectory(): string
    {
        return '';
    }
}

$subclassCases = [
    'custom alone' => function () {
        $custom = new Custom();
        $detector = (new Framework('npm'))->addInput('custom.config.js', Framework::INPUT_FILE)->addOption($custom);
        $found = $detector->detect();

        return [$found === $custom, $found->getInstallCommand(), $custom->calls];
    },
    'custom ties react (fewer parents)' => function () {
        $detector = (new Framework())->addInput('{"dependencies":{"custom":"1","react":"1"}}', Framework::INPUT_PACKAGES);
        $detector->addOption(new React())->addOption(new Custom());

        return $detector->detect()?->getName();
    },
    'custom named react sums' => function () {
        $detector = (new Framework())->addInput('{"dependencies":{"custom":"1","vue":"1","nuxt":"1"}}', Framework::INPUT_PACKAGES);
        $detector->addOption(new Nuxt())->addOption(new Custom('react'))->addOption(new React());
        $found = $detector->detect();

        return [$found::class, $found->getName()];
    },
    'custom named astro' => function () {
        $detector = (new Framework())->addInput('{"dependencies":{"custom":"1","vue":"1"}}', Framework::INPUT_PACKAGES);
        $detector->addOption(new Custom('astro'))->addOption(new Vue());

        return $detector->detect()?->getName();
    },
    'numeric name' => function () {
        $detector = (new Framework())->addInput('x.js', Framework::INPUT_FILE)->addOption(new Custom('123', ['x.js']));

        return $detector->detect();
    },
    'numeric name not canonical' => function () {
        $detector = (new Framework())->addInput('x.js', Framework::INPUT_FILE)->addOption(new Custom('0123', ['x.js']));

        return $detector->detect()?->getName();
    },
    'empty name' => function () {
        $detector = (new Framework())->addInput('x.js', Framework::INPUT_FILE)->addOption(new Custom('', ['x.js']));

        return $detector->detect()?->getName();
    },
    'duplicate files' => function () {
        $detector = (new Framework())->addInput('x.js', Framework::INPUT_FILE)->addInput('react.js', Framework::INPUT_FILE);
        $detector->addOption(new Custom('a', ['x.js', 'x.js']))->addOption(new Custom('b', ['x.js', 'react.js']));

        return $detector->detect()?->getName();
    },
    'my next' => function () {
        $detector = (new Framework('yarn'))->addInput('my.config.js', Framework::INPUT_FILE);
        $detector->addOption(new React())->addOption(new NextJs())->addOption(new MyNext());
        $found = $detector->detect();

        return [$found::class, $found->getBuildCommand()];
    },
    'my next vs next tie' => function () {
        $detector = (new Framework('yarn'))->addInput('next.config.js', Framework::INPUT_FILE);
        $detector->addOption(new MyNext())->addOption(new NextJs());
        $found = $detector->detect();

        return [$found::class, properties($found)];
    },
    'my astro tie' => function () {
        $detector = (new Framework())->addInput('svelte.config.js', Framework::INPUT_FILE);
        $detector->addOption(new MyAstro())->addOption(new Svelte());

        return $detector->detect()::class;
    },
    'renamed react' => function () {
        $detector = (new Framework())->addInput('{"dependencies":{"react":"1"}}', Framework::INPUT_PACKAGES);
        $detector->addOption(new RenamedReact())->addOption(new NextJs());
        $found = $detector->detect();

        return [$found::class, $found->getName()];
    },
    'loud packager' => function () {
        $option = new LoudPackager();
        $detector = (new Framework('yarn'))->addInput('{"dependencies":{"react":"1"}}', Framework::INPUT_PACKAGES)->addOption($option);
        $found = $detector->detect();

        return [$found === $option, $option->packagers, $found->getInstallCommand(), properties($found)];
    },
    'own dependencies' => function () {
        $detector = (new OwnDependencies())->addInput('react,next', Framework::INPUT_PACKAGES)->addOption(new React())->addOption(new NextJs());

        return $detector->detect()?->getName();
    },
    'own inputs' => function () {
        $detector = (new OwnInputs())->addInput('NEXT.CONFIG.JS', Framework::INPUT_FILE)->addOption(new NextJs());

        return [$detector->detect()?->getName(), properties($detector)];
    },
    'weird files' => function () {
        $detector = (new Framework())->addInput('1', Framework::INPUT_FILE)->addInput('weird.js', Framework::INPUT_FILE)->addInput('{"dependencies":{"weird":"1","1":"1"}}', Framework::INPUT_PACKAGES);
        $detector->addOption(new WeirdFiles())->addOption(new React());

        return $detector->detect()?->getName();
    },
    'same object twice' => function () {
        $next = new NextJs();
        $detector = (new Framework())->addInput('next.config.js', Framework::INPUT_FILE)->addOption($next)->addOption($next)->addOption(new React());

        return $detector->detect() === $next;
    },
    'packager subclass' => function () {
        $detector = (new Packager())->addInput('custom.lock')->addInput('package.json')->addOption(new Lockfile())->addOption(new NPM());

        return $detector->detect();
    },
    'runtime subclass' => function () {
        $results = [];
        foreach ([Strategy::FILEMATCH => 'build.zig', Strategy::LANGUAGES => 'Zig', Strategy::EXTENSION => 'src/main.zig'] as $value => $input) {
            $detector = (new Runtime(new Strategy($value), 'npm'))->addInput($input)->addOption(new Node())->addOption(new Zig());
            $found = $detector->detect();
            $results[] = [$found, $found?->getCommands()];
        }

        return $results;
    },
    'my node' => function () {
        $detector = (new Runtime(new Strategy(Strategy::LANGUAGES)))->addInput('NodeScript')->addOption(new MyNode());

        return [$detector->detect()];
    },
    'strategy subclass' => function () {
        $detector = (new Runtime(new AlwaysLanguages(Strategy::FILEMATCH), 'yarn'))->addInput('Python')->addInput('requirements.txt')->addOption(new Node())->addOption(new Python());

        return [$detector->detect(), properties($detector)];
    },
    'rendering subclass' => function () {
        $detector = (new Rendering('nextjs'))->addInput('nextjs/edge.js')->addInput('server.js')->addOption(new Edge())->addOption(new SSR());

        return $detector->detect();
    },
    'ssr subclass' => function () {
        $detector = (new Rendering('nuxt'))->addInput('server/index.mjs')->addOption(new MySSR('x.html'));

        return $detector->detect();
    },
    'static subclass' => function () {
        $detector = (new Rendering('vue'))->addInput('static.marker')->addInput('a.html')->addOption(new MyStatic());

        return [$detector->detect(), (new Rendering('vue'))->addInput('a.html')->addOption(new MyStatic())->detect()];
    },
    'mixed kinds' => function () {
        $detector = (new Packager())->addInput('package.json')->addOption(new Node())->addOption(new NPM());

        return $detector->detect();
    },
    'clone' => function () {
        $detector = (new Framework('npm'))->addInput('next.config.js', Framework::INPUT_FILE)->addOption(new NextJs());
        $copy = clone $detector;
        $copy->addInput('nuxt.config.js', Framework::INPUT_FILE)->addOption(new Nuxt());
        $next = (new NextJs())->setPackager('yarn');
        $nextCopy = clone $next;
        $strategy = clone new Strategy('extension');
        $static = clone new XStatic('a.html');

        return [properties($detector), properties($copy), $detector == $copy, $next == $nextCopy, $nextCopy->getInstallCommand(), $strategy->getValue(), $static->getFallbackFile(), $copy->detect()?->getName()];
    },
    'equality' => fn () => [new NextJs() == new NextJs(), new NextJs() == (new NextJs())->setPackager('npm'), new Strategy('extension') == new Strategy('extension'), new Strategy('extension') == new Strategy('languages'), new XStatic() == new XStatic(null), new SSR() == new XStatic()],
    'serialize' => function () {
        $detector = (new Runtime(new Strategy('languages'), 'yarn'))->addInput('Python')->addOption(new Python());
        $copy = unserialize(serialize($detector));

        return [serialize(new NextJs()), serialize(new Strategy('filematch')), serialize(new XStatic('a.html')), $copy->detect(), $copy == $detector];
    },
    'var_dump' => function () {
        $detector = (new Runtime(new Strategy('languages'), 'yarn'))->addInput('Python')->addOption(new Python());
        ob_start();
        var_dump($detector, new XStatic(), (new Rendering('x'))->detect(), new Strategy('extension'));
        $dump = ob_get_clean();

        return withoutHandles($dump);
    },
    'var_export' => fn () => var_export([new NextJs(), new Strategy('extension'), new SSR('x')], true),
    'json' => fn () => json_encode([new NextJs(), new Strategy('extension'), new Framework()]),
    'get_object_vars' => fn () => [get_object_vars(new NextJs()), get_object_vars(new Framework('x'))],
    'uninitialized strategy' => function () {
        $class = new ReflectionClass(Runtime::class);
        $runtime = $class->newInstanceWithoutConstructor();

        return [properties($runtime), $runtime->addInput('Python')->addOption(new Python())->detect()];
    },
    'uninitialized framework' => fn () => (new ReflectionClass(Rendering::class))->newInstanceWithoutConstructor()->detect(),
    'uninitialized strategy value' => fn () => (new ReflectionClass(Strategy::class))->newInstanceWithoutConstructor()->getValue(),
    'uninitialized fallback' => fn () => (new ReflectionClass(XStatic::class))->newInstanceWithoutConstructor()->getFallbackFile(),
    'inputs rewritten' => function () {
        $detector = new class () extends Packager {
            public function rewrite(): static
            {
                $this->inputs = [['content' => 'package.json'], ['type' => 'x'], 'flat', ['type' => 'file', 'content' => ['nested']]];
                $this->options = [new NPM()];

                return $this;
            }
        };

        return $detector->rewrite()->detect();
    },
    'framework inputs rewritten' => function () {
        $detector = new class () extends Framework {
            public function rewrite(): static
            {
                $this->inputs = [['type' => 'file'], ['type' => 'packages', 'content' => 5], ['content' => 'x'], ['type' => 'file', 'content' => 'next.config.js']];
                $this->options = [new NextJs()];

                return $this;
            }
        };

        return $detector->rewrite()->detect()?->getName();
    },
    'options rewritten' => function () {
        $detector = new class () extends Packager {
            public function rewrite(): static
            {
                $this->inputs = [['type' => '', 'content' => 'package.json']];
                $this->options = ['npm'];

                return $this;
            }
        };

        return $detector->rewrite()->detect();
    },
    'subclass of every detector' => function () {
        $framework = new class ('npm') extends Framework {};
        $packager = new class () extends Packager {};
        $rendering = new class ('nextjs') extends Rendering {};
        $runtime = new class (new Strategy('filematch'), 'yarn') extends Runtime {};

        return [
            $framework->addInput('next.config.js', Framework::INPUT_FILE)->addOption(new NextJs())->detect()?->getBuildCommand(),
            $packager->addInput('yarn.lock')->addOption(new Yarn())->detect()?->getName(),
            $rendering->addInput('server.js')->addOption(new SSR())->detect()?->getName(),
            $runtime->addInput('package-lock.json')->addOption(new Node())->detect()?->getCommands(),
        ];
    },
];
foreach ($subclassCases as $case => $run) {
    emit('subclass ' . $case, outcome($run));
}

// A large repository: thousands of files through every detector.
emit('large', outcome(function () use ($frameworkOptions, $runtimeOptions) {
    $files = [];
    for ($i = 0; $i < 5000; $i++) {
        $files[] = 'src/components/c' . $i . ($i % 3 ? '.tsx' : '.ts');
    }
    array_push($files, 'package.json', 'next.config.mjs', 'pnpm-lock.yaml', 'public/index.html');
    $packager = (new Packager())->addOption(new Yarn())->addOption(new PNPM())->addOption(new NPM());
    $framework = (new Framework('pnpm'))->addInput('{"dependencies":{"next":"1","react":"1"}}', Framework::INPUT_PACKAGES);
    $runtime = new Runtime(new Strategy(Strategy::EXTENSION), 'pnpm');
    $rendering = (new Rendering('nextjs'))->addOption(new SSR())->addOption(new XStatic());
    foreach ($files as $file) {
        $packager->addInput($file);
        $framework->addInput($file, Framework::INPUT_FILE);
        $runtime->addInput($file);
        $rendering->addInput($file);
    }
    foreach ($frameworkOptions() as $option) {
        $framework->addOption($option);
    }
    foreach ($runtimeOptions() as $option) {
        $runtime->addOption($option);
    }

    return [$packager->detect()?->getName(), $framework->detect()?->getName(), $runtime->detect()?->getName(), $rendering->detect(), count(properties($framework)['inputs'])];
}));

// A detector reused: more inputs and options after a first detect().
emit('reuse', outcome(function () {
    $detector = new Framework('npm');
    $detector->addOption(new React())->addInput('{"dependencies":{"react":"1"}}', Framework::INPUT_PACKAGES);
    $first = $detector->detect();
    $detector->addOption(new NextJs())->addInput('next.config.js', Framework::INPUT_FILE);
    $second = $detector->detect();

    return [$first?->getName(), $second?->getName(), properties($first), properties($second)];
}));

// The known differences (crates/extension/README.md), asserted: each line prints `true` on both
// implementations while the difference is the documented one, and changes if it goes away or
// grows.
class Fickle extends Custom
{
    private int $asked = 0;

    public function getName(): string
    {
        return ++$this->asked > 2 ? 'later' : 'first';
    }
}
class IntegerPackages extends Framework
{
    protected function dependencies(string $manifest): array
    {
        return [7];
    }
}
$waivers = [
    // `@yaml_parse()`'s silenced warnings reach only a handler that ignores error_reporting().
    'silenced yaml warnings' => function () use ($internal) {
        $seen = 0;
        set_error_handler(function () use (&$seen) {
            $seen++;

            return true;
        });
        $value = (new ExposedFramework())->read(": : :\n- [");
        restore_error_handler();

        return [$value, ($seen > 0) === !$internal];
    },
    // A getName() that changes: the same answer, and PHP's warning only in PHP.
    'fickle name' => function () use ($internal) {
        $warned = [];
        set_error_handler(function (int $level, string $message) use (&$warned) {
            $warned[] = $message;

            return true;
        });
        $found = (new Framework())->addInput('custom.config.js', Framework::INPUT_FILE)->addOption(new Fickle())->detect();
        restore_error_handler();

        return [$found?->getName(), ($warned !== []) === !$internal];
    },
    // Integer package names: PHP's strict in_array() matched them, the extension compares strings.
    'integer packages' => function () use ($internal) {
        $found = (new IntegerPackages())->addInput('{}', Framework::INPUT_PACKAGES)->addOption(new Custom('ints', [], [7]))->detect();

        return ($found !== null) === !$internal;
    },
    // Exceptions name the caller: the classes have no PHP source.
    'exception file' => function () use ($internal) {
        try {
            new Strategy('x');
        } catch (Exception $e) {
            return ($e->getFile() === __FILE__) === $internal;
        }

        return 'no exception';
    },
];
foreach ($waivers as $case => $run) {
    emit('waiver ' . $case, outcome($run));
}
