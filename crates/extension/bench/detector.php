<?php

// Times utopia-php/detector on whichever implementation loaded, as Appwrite runs it: the
// repository detection endpoint (packager, framework, then runtime by each strategy), and the
// rendering of a finished build. Compare both with
// `python3 crates/extension/bench/run.py crates/extension/bench/detector.php . <utopia.so> 5 yaml`.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Detector\Detection\Framework\Analog;
use Utopia\Detector\Detection\Framework\Angular;
use Utopia\Detector\Detection\Framework\Astro;
use Utopia\Detector\Detection\Framework\Flutter;
use Utopia\Detector\Detection\Framework\Jaspr;
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
use Utopia\Detector\Detection\Packager\NPM;
use Utopia\Detector\Detection\Packager\PNPM;
use Utopia\Detector\Detection\Packager\Yarn;
use Utopia\Detector\Detection\Rendering\SSR;
use Utopia\Detector\Detection\Rendering\XStatic;
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

// A run meant for the extension that gets the PHP classes would time PHP against PHP.
$internal = (new ReflectionClass(Detector::class))->isInternal();
$requested = in_array('detector', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' detector, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

// A Next.js repository's root, as the VCS API lists it, and its package.json.
$files = ['.github', '.gitignore', '.eslintrc.json', 'README.md', 'app', 'components', 'lib', 'next.config.mjs', 'package.json', 'pnpm-lock.yaml', 'postcss.config.js', 'public', 'tailwind.config.ts', 'tsconfig.json'];
$manifest = json_encode([
    'name' => 'web', 'private' => true,
    'dependencies' => ['next' => '15.0.0', 'react' => '19.0.0', 'react-dom' => '19.0.0', 'clsx' => '^2', 'zod' => '^3'],
    'devDependencies' => ['typescript' => '^5', '@types/react' => '^19', 'eslint' => '^9', 'tailwindcss' => '^3', 'postcss' => '^8'],
]);
$languages = ['TypeScript', 'CSS', 'JavaScript'];
$pubspec = "name: app\ndependencies:\n  flutter:\n    sdk: flutter\n  http: ^1.2.0\ndev_dependencies:\n  flutter_test:\n    sdk: flutter\n";
// A static build's files, as the build worker lists them.
$build = [];
for ($i = 0; $i < 300; $i++) {
    $build[] = '_next/static/chunks/' . $i . '.js';
}
$build[] = 'index.html';
// A large repository listing.
$large = $files;
for ($i = 0; $i < 5000; $i++) {
    $large[] = 'src/components/c' . $i . '.tsx';
}

function frameworks(string $packager, string $manifest, array $files): ?string
{
    $detector = new Framework($packager);
    $detector->addInput($manifest, Framework::INPUT_PACKAGES);
    foreach ($files as $file) {
        $detector->addInput($file, Framework::INPUT_FILE);
    }
    $detector
        ->addOption(new Analog())->addOption(new Angular())->addOption(new Astro())->addOption(new Flutter())->addOption(new Jaspr())
        ->addOption(new Lynx())->addOption(new NextJs())->addOption(new Nuxt())->addOption(new React())->addOption(new ReactNative())
        ->addOption(new Remix())->addOption(new Svelte())->addOption(new SvelteKit())->addOption(new TanStackStart())->addOption(new Vue());
    $framework = $detector->detect();

    return $framework === null ? null : $framework->getInstallCommand() . $framework->getBuildCommand() . $framework->getOutputDirectory();
}

function runtimes(string $packager, array $files, array $languages): ?string
{
    foreach ([Strategy::FILEMATCH, Strategy::LANGUAGES, Strategy::EXTENSION] as $value) {
        $detector = new Runtime(new Strategy($value), $packager);
        foreach ($value === Strategy::LANGUAGES ? $languages : $files as $input) {
            $detector->addInput($input);
        }
        $detector
            ->addOption(new Bun())->addOption(new Deno())->addOption(new Node())->addOption(new PHP())->addOption(new Python())->addOption(new Dart())
            ->addOption(new Swift())->addOption(new Ruby())->addOption(new Java())->addOption(new CPP())->addOption(new Dotnet());
        $runtime = $detector->detect();
        if ($runtime !== null) {
            return $runtime->getCommands() . $runtime->getEntrypoint();
        }
    }

    return null;
}

function packager(array $files): string
{
    $detector = new Packager();
    foreach ($files as $file) {
        $detector->addInput($file);
    }
    $detector->addOption(new Yarn())->addOption(new PNPM())->addOption(new NPM());

    return $detector->detect()?->getName() ?? 'npm';
}

function rendering(string $framework, array $files): string
{
    $detector = new Rendering($framework);
    foreach ($files as $file) {
        $detector->addInput($file);
    }

    return $detector->addOption(new SSR())->addOption(new XStatic())->detect()->getName();
}

$cases = [
    'packager' => [fn () => packager($files), 20000],
    'framework' => [fn () => frameworks('pnpm', $manifest, $files), 5000],
    'framework (pubspec)' => [fn () => frameworks('npm', $pubspec, ['pubspec.yaml', 'lib', 'web']), 5000],
    'runtime (3 strategies)' => [fn () => runtimes('pnpm', ['README.md', 'src'], $languages), 5000],
    'detections endpoint' => [fn () => [packager($files), frameworks(packager($files), $manifest, $files), runtimes('pnpm', $files, $languages)], 2000],
    'rendering (301 files)' => [fn () => rendering('nextjs', $build), 2000],
    'framework (5,014 files)' => [fn () => frameworks('pnpm', $manifest, $large), 50],
    'new NextJs + commands' => [fn () => (new NextJs())->setPackager('yarn')->getBuildCommand(), 50000],
    'getAdapter (Astro)' => [fn () => (new Astro())->getAdapter("// output: 'static'\nexport default defineConfig({ output: 'server' });"), 50000],
];
$out = [];
foreach ($cases as $name => [$fn, $n]) {
    for ($i = 0; $i < max(1, intdiv($n, 20)); $i++) {
        $fn();
    }
    $runs = [];
    for ($r = 0; $r < 5; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            $fn();
        }
        $runs[] = (hrtime(true) - $t) / $n;
    }
    sort($runs);
    $out[$name] = $runs[2];
}
$out['peak memory MB'] = round(memory_get_peak_usage(true) / 1048576, 1);
echo json_encode($out), "\n";
