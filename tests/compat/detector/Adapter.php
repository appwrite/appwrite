<?php

namespace Tests\Compat\Detector;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Detector\Detection\Framework\Analog;
use Utopia\Detector\Detection\Framework\Angular;
use Utopia\Detector\Detection\Framework as FrameworkDetection;
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
use Utopia\Detector\Detector\Framework;
use Utopia\Detector\Detector\Packager;
use Utopia\Detector\Detector\Rendering;
use Utopia\Detector\Detector\Runtime;
use Utopia\Detector\Detector\Strategy;

/**
 * Maps tests/compat/detector/spec.json operations onto utopia-php/detector. Glue only.
 *
 * Options are named (`nextjs`, `node`, `pnpm`, `ssr`) and built fresh for
 * every call. `detect.fixture` runs every detector over a fixture directory
 * under tests/compat/detector/fixtures, as Appwrite does over a repository.
 */
final class Adapter implements Base, Benchmarks
{
    private const FRAMEWORKS = ['analog', 'angular', 'astro', 'flutter', 'jaspr', 'lynx', 'nextjs', 'nuxt', 'react', 'react-native', 'remix', 'svelte', 'sveltekit', 'tanstack-start', 'vue'];

    private const RUNTIMES = ['node', 'bun', 'deno', 'php', 'python', 'dart', 'swift', 'ruby', 'java', 'cpp', 'dotnet'];

    private const PACKAGERS = ['pnpm', 'yarn', 'npm'];

    private const FIXTURES = __DIR__ . '/fixtures';

    public function benchmarks(): array
    {
        $files = ['src', 'static', 'scripts', 'eslint.config.js', 'package.json', 'pnpm-lock.yaml', 'svelte.config.js', 'tsconfig.js', 'vite.config.js', 'vite.config.lib.js'];
        $manifest = '{"name":"app","dependencies":{"@sveltejs/kit":"^2.0.0","svelte":"^5.0.0","vite":"^5.0.0"},"devDependencies":{"typescript":"^5.0.0","prettier":"^3.0.0"}}';

        return [
            'framework' => ['Score 15 frameworks on 10 file names and a package.json', function (Session $s) use ($files, $manifest) {
                return function () use ($files, $manifest) {
                    $detector = new Framework('pnpm');
                    foreach (self::FRAMEWORKS as $name) {
                        $detector->addOption(self::framework($name));
                    }
                    foreach ($files as $file) {
                        $detector->addInput($file, Framework::INPUT_FILE);
                    }
                    $detector->addInput($manifest, Framework::INPUT_PACKAGES);

                    return $detector->detect()?->getName();
                };
            }],
            'runtime' => ['Find the runtime of 10 file names by extension among 11 runtimes', function (Session $s) use ($files) {
                return function () use ($files) {
                    $detector = new Runtime(new Strategy(Strategy::EXTENSION), 'pnpm');
                    foreach (self::RUNTIMES as $name) {
                        $detector->addOption(self::runtime($name));
                    }
                    foreach ($files as $file) {
                        $detector->addInput($file);
                    }

                    return $detector->detect()?->getName();
                };
            }],
            'rendering' => ['Tell the rendering of a 6-file Next.js build', function (Session $s) {
                $files = ['server/pages/index.html', 'server/pages/api/users.js', '.next/server/pages/_app.js', '.next/static/chunks/main.js', 'public/favicon.ico', 'package.json'];

                return function () use ($files) {
                    $detector = new Rendering('nextjs');
                    $detector->addOption(new SSR())->addOption(new XStatic());
                    foreach ($files as $file) {
                        $detector->addInput($file);
                    }

                    return $detector->detect()->getName();
                };
            }],
            'adapter' => ['Read the rendering adapter of a TanStack Start vite.config.ts', function (Session $s) {
                $config = "import { defineConfig } from 'vite' // https://vite.dev\nexport default defineConfig({ plugins: [tanstackStart({ prerender: { enabled: true, crawlLinks: true } })] })\n";
                $framework = new TanStackStart();

                return fn () => $framework->getAdapter($config);
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'packager.detect' => function (array $a, Session $s) {
                $detector = new Packager();
                foreach ($a['options'] ?? [] as $name) {
                    $detector->addOption(self::packager($name));
                }
                foreach ($a['inputs'] ?? [] as $input) {
                    $detector->addInput($input);
                }

                return $detector->detect()?->getName();
            },
            'packager.describe' => function (array $a, Session $s) {
                $packager = self::packager($a['packager']);

                return ['name' => $packager->getName(), 'files' => $packager->getFiles()];
            },
            'strategy.new' => fn (array $a, Session $s) => (new Strategy($a['value']))->getValue(),
            'runtime.detect' => function (array $a, Session $s) {
                $detector = new Runtime(new Strategy($a['strategy']), ...(isset($a['packager']) ? [$a['packager']] : []));
                foreach ($a['options'] ?? [] as $name) {
                    $detector->addOption(self::runtime($name));
                }
                foreach ($a['inputs'] ?? [] as $input) {
                    $detector->addInput($input);
                }
                $runtime = $detector->detect();

                return $runtime === null ? null : ['name' => $runtime->getName(), 'commands' => $runtime->getCommands(), 'entrypoint' => $runtime->getEntrypoint()];
            },
            'runtime.describe' => function (array $a, Session $s) {
                $runtime = self::runtime($a['runtime']);
                if (isset($a['packager'])) {
                    $runtime->setPackager($a['packager']);
                }

                return [
                    'name' => $runtime->getName(),
                    'languages' => $runtime->getLanguages(),
                    'extensions' => $runtime->getFileExtensions(),
                    'files' => $runtime->getFiles(),
                    'commands' => $runtime->getCommands(),
                    'entrypoint' => $runtime->getEntrypoint(),
                ];
            },
            'framework.detect' => function (array $a, Session $s) {
                $detector = new Framework(...(isset($a['packager']) ? [$a['packager']] : []));
                foreach ($a['options'] ?? [] as $name) {
                    $detector->addOption(self::framework($name));
                }
                foreach ($a['files'] ?? [] as $file) {
                    $detector->addInput($file, Framework::INPUT_FILE);
                }
                foreach ($a['packages'] ?? [] as $manifest) {
                    $detector->addInput($manifest, Framework::INPUT_PACKAGES);
                }
                foreach ($a['inputs'] ?? [] as $input) {
                    $detector->addInput($input['content'], $input['type']);
                }

                return self::detected($detector->detect());
            },
            'framework.describe' => function (array $a, Session $s) {
                $framework = self::framework($a['framework']);
                if (isset($a['packager'])) {
                    $framework->setPackager($a['packager']);
                }

                return [
                    'name' => $framework->getName(),
                    'files' => array_values($framework->getFiles()),
                    'packages' => array_values($framework->getPackages()),
                    'install' => $framework->getInstallCommand(),
                    'build' => $framework->getBuildCommand(),
                    'output' => $framework->getOutputDirectory(),
                    'configFiles' => $framework->getConfigFiles(),
                ];
            },
            'framework.adapter' => fn (array $a, Session $s) => self::framework($a['framework'])->getAdapter($a['config']),
            'framework.dependencies' => fn (array $a, Session $s) => (new class () extends Framework {
                /**
                 * @return array<string>
                 */
                public function read(string $manifest): array
                {
                    return $this->dependencies($manifest);
                }
            })->read($a['manifest']),
            'rendering.detect' => function (array $a, Session $s) {
                $detector = new Rendering($a['framework']);
                foreach ($a['options'] ?? [] as $option) {
                    $detector->addOption(self::rendering($option));
                }
                foreach ($a['inputs'] ?? [] as $input) {
                    $detector->addInput($input);
                }
                $rendering = $detector->detect();

                return ['name' => $rendering->getName(), 'fallback' => $rendering->getFallbackFile()];
            },
            'rendering.describe' => function (array $a, Session $s) {
                $rendering = self::rendering($a['rendering']);

                return ['name' => $rendering->getName(), 'files' => $rendering->getFiles($a['framework']), 'fallback' => $rendering->getFallbackFile()];
            },
            'detect.fixture' => fn (array $a, Session $s) => self::fixture($a['fixture']),
        ];
    }

    /**
     * Every detector over a fixture directory: its files (recursively,
     * sorted), package.json and pubspec.yaml as manifests, the first config
     * file of the detected framework for its adapter, and `build/` for the
     * rendering.
     *
     * @return array<string, mixed>
     */
    private static function fixture(string $name): array
    {
        $root = self::FIXTURES . '/' . $name;
        if (!is_dir($root)) {
            throw new Fault("no fixture {$name}");
        }
        $files = self::listing($root);

        $packager = new Packager();
        foreach (self::PACKAGERS as $option) {
            $packager->addOption(self::packager($option));
        }
        $runtimes = [];
        foreach ([Strategy::FILEMATCH, Strategy::EXTENSION] as $strategy) {
            $runtimes[$strategy] = new Runtime(new Strategy($strategy), 'npm');
            foreach (self::RUNTIMES as $option) {
                $runtimes[$strategy]->addOption(self::runtime($option));
            }
        }
        $framework = new Framework('npm');
        foreach (self::FRAMEWORKS as $option) {
            $framework->addOption(self::framework($option));
        }
        foreach ($files as $file) {
            $packager->addInput($file);
            $runtimes[Strategy::FILEMATCH]->addInput($file);
            $runtimes[Strategy::EXTENSION]->addInput($file);
            $framework->addInput($file, Framework::INPUT_FILE);
        }
        foreach (['package.json', 'pubspec.yaml'] as $manifest) {
            if (\in_array($manifest, $files, true)) {
                $framework->addInput((string) file_get_contents("{$root}/{$manifest}"), Framework::INPUT_PACKAGES);
            }
        }

        $detected = $framework->detect();
        $adapter = null;
        $rendering = null;
        if ($detected !== null) {
            foreach ($detected->getConfigFiles() as $config) {
                if (\in_array($config, $files, true)) {
                    $adapter = $detected->getAdapter((string) file_get_contents("{$root}/{$config}"));
                    break;
                }
            }
            if (is_dir("{$root}/build")) {
                $detector = new Rendering($detected->getName());
                $detector->addOption(new SSR())->addOption(new XStatic());
                foreach (self::listing("{$root}/build") as $file) {
                    $detector->addInput($file);
                }
                $found = $detector->detect();
                $rendering = ['name' => $found->getName(), 'fallback' => $found->getFallbackFile()];
            }
        }

        $runtime = static function (?RuntimeDetection $r): ?array {
            return $r === null ? null : ['name' => $r->getName(), 'commands' => $r->getCommands()];
        };

        return [
            'files' => \count($files),
            'packager' => $packager->detect()?->getName(),
            'runtime' => [
                'filematch' => $runtime($runtimes[Strategy::FILEMATCH]->detect()),
                'extension' => $runtime($runtimes[Strategy::EXTENSION]->detect()),
            ],
            'framework' => self::detected($detected),
            'adapter' => $adapter,
            'rendering' => $rendering,
        ];
    }

    /**
     * Files under $root, relative to it, sorted by bytes.
     *
     * @return list<string>
     */
    private static function listing(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), \strlen($root) + 1);
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return array<string, string>|null
     */
    private static function detected(?FrameworkDetection $framework): ?array
    {
        return $framework === null ? null : [
            'name' => $framework->getName(),
            'install' => $framework->getInstallCommand(),
            'build' => $framework->getBuildCommand(),
            'output' => $framework->getOutputDirectory(),
        ];
    }

    private static function packager(string $name): PackagerDetection
    {
        return match ($name) {
            'npm' => new NPM(),
            'pnpm' => new PNPM(),
            'yarn' => new Yarn(),
            default => throw new Fault("unknown packager {$name}"),
        };
    }

    private static function runtime(string $name): RuntimeDetection
    {
        return match ($name) {
            'bun' => new Bun(),
            'cpp' => new CPP(),
            'dart' => new Dart(),
            'deno' => new Deno(),
            'dotnet' => new Dotnet(),
            'java' => new Java(),
            'node' => new Node(),
            'php' => new PHP(),
            'python' => new Python(),
            'ruby' => new Ruby(),
            'swift' => new Swift(),
            default => throw new Fault("unknown runtime {$name}"),
        };
    }

    private static function framework(string $name): FrameworkDetection
    {
        return match ($name) {
            'analog' => new Analog(),
            'angular' => new Angular(),
            'astro' => new Astro(),
            'flutter' => new Flutter(),
            'jaspr' => new Jaspr(),
            'lynx' => new Lynx(),
            'nextjs' => new NextJs(),
            'nuxt' => new Nuxt(),
            'react' => new React(),
            'react-native' => new ReactNative(),
            'remix' => new Remix(),
            'svelte' => new Svelte(),
            'sveltekit' => new SvelteKit(),
            'tanstack-start' => new TanStackStart(),
            'vue' => new Vue(),
            default => throw new Fault("unknown framework {$name}"),
        };
    }

    /**
     * @param array<string, mixed>|string $option `ssr`, `static`, or {type, fallback}
     */
    private static function rendering(array|string $option): RenderingDetection
    {
        $type = \is_array($option) ? $option['type'] : $option;
        $fallback = \is_array($option) ? ($option['fallback'] ?? null) : null;

        return match ($type) {
            'ssr' => new SSR($fallback),
            'static' => new XStatic($fallback),
            default => throw new Fault("unknown rendering {$type}"),
        };
    }
}
