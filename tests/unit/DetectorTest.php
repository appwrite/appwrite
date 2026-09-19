<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
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
use Utopia\Detector\Detection\Rendering\XStatic;
use Utopia\Detector\Detection\Rendering\SSR;
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

class DetectorTest extends TestCase
{
    /**
     * @param string[] $files List of files to check
     * @dataProvider packagerDataProvider
     */
    public function testDetectPackager(array $files, ?string $expectedPackager): void
    {
        $detector = new Packager();
        $detector
            ->addOption(new PNPM())
            ->addOption(new Yarn())
            ->addOption(new NPM());

        foreach ($files as $file) {
            $detector->addInput($file);
        }

        $detectedPackager = $detector->detect();

        if ($expectedPackager) {
            $this->assertSame($expectedPackager, $detectedPackager?->getName());
        } else {
            $this->assertNull($detectedPackager);
        }
    }

    /**
     * @return array<array{array<string>, string|null}>
     */
    public function packagerDataProvider(): array
    {
        return [
            [['bun.lockb', 'fly.toml', 'package.json', 'remix.config.js'], 'npm'],
            [['yarn.lock'], 'yarn'],
            [['pnpm-lock.yaml'], 'pnpm'],
            [['composer.json'], null],  // test for FAILURE
        ];
    }

    /**
     * @param string[] $files List of files to check
     * @dataProvider runtimeDataProviderByFilematch
     */
    public function testDetectRuntimeByFilematch(
        array $files,
        ?string $runtime,
        ?string $commands,
        ?string $entrypoint,
        string $packager = 'pnpm'
    ): void {
        $detector = new Runtime(
            new Strategy(Strategy::FILEMATCH),
            $packager
        );

        $detector
            ->addOption(new Node())
            ->addOption(new Bun())
            ->addOption(new Deno())
            ->addOption(new PHP())
            ->addOption(new Python())
            ->addOption(new Dart())
            ->addOption(new Swift())
            ->addOption(new Ruby())
            ->addOption(new Java())
            ->addOption(new CPP())
            ->addOption(new Dotnet());

        foreach ($files as $file) {
            $detector->addInput($file);
        }

        $detectedRuntime = $detector->detect();

        if ($runtime) {
            $this->assertNotNull($detectedRuntime);
            $this->assertSame($runtime, $detectedRuntime?->getName());
            $this->assertSame($commands, $detectedRuntime?->getCommands());
            $this->assertSame($entrypoint, $detectedRuntime?->getEntrypoint());
        } else {
            $this->assertNull($detectedRuntime);
        }
    }

    /**
     * @return array<array{array<string>, string|null, string|null, string|null, string|null}>
     */
    public function runtimeDataProviderByFilematch(): array
    {
        return [
            [['package-lock.json', 'yarn.lock', 'tsconfig.json'], 'node', 'pnpm install', 'index.js', 'pnpm'],
            [['package-lock.json', 'yarn.lock', 'tsconfig.json'], 'node', 'yarn install', 'index.js', 'yarn'],
            [['composer.json', 'composer.lock'], 'php', 'composer install && composer run build', 'index.php', 'pnpm'],
            [['pubspec.yaml'], 'dart', 'dart pub get', 'main.dart', 'pnpm'],
            [['Gemfile', 'Gemfile.lock'], 'ruby', 'bundle install && bundle exec rake build', 'main.rb', 'pnpm'],
            [['index.html', 'style.css'], null, null, null, 'pnpm'], // Test for FAILURE
        ];
    }

    /**
     * @param string[] $files List of files to check
     * @dataProvider runtimeDataProviderByLanguages
     */
    public function testDetectRuntimeByLanguage(
        array $files,
        ?string $runtime,
        ?string $commands,
        string $packager = 'pnpm'
    ): void {
        $detector = new Runtime(
            new Strategy(Strategy::LANGUAGES),
            $packager
        );

        $detector
            ->addOption(new Node())
            ->addOption(new Bun())
            ->addOption(new Deno())
            ->addOption(new PHP())
            ->addOption(new Python())
            ->addOption(new Dart())
            ->addOption(new Swift())
            ->addOption(new Ruby())
            ->addOption(new Java())
            ->addOption(new CPP())
            ->addOption(new Dotnet());

        foreach ($files as $file) {
            $detector->addInput($file);
        }

        $detectedRuntime = $detector->detect();

        if ($runtime) {
            $this->assertNotNull($detectedRuntime);
            $this->assertSame($runtime, $detectedRuntime?->getName());
            $this->assertSame($commands, $detectedRuntime?->getCommands());
        } else {
            $this->assertNull($detectedRuntime);
        }
    }

    /**
     * @return array<array{array<string>, string|null, string|null, string|null}>
     */
    public function runtimeDataProviderByLanguages(): array
    {
        return [
            [
                ['TypeScript', 'JavaScript', 'DockerFile'],
                'node',
                'pnpm install',
                'pnpm',
            ],
            [
                ['TypeScript', 'JavaScript', 'DockerFile'],
                'node',
                'yarn install',
                'yarn',
            ],
            // Test for FAILURE
            [
                ['HTML'],
                null,
                null,
                'pnpm',
            ],
        ];
    }

    /**
     * @param string[] $files List of files to check
     * @dataProvider runtimeDataProviderByFileExtensions
     */
    public function testDetectRuntimeByFileExtension(
        array $files,
        ?string $runtime,
        ?string $commands,
        string $packager = 'pnpm'
    ): void {
        $detector = new Runtime(
            new Strategy(Strategy::EXTENSION),
            $packager
        );

        $detector
            ->addOption(new Node())
            ->addOption(new Bun())
            ->addOption(new Deno())
            ->addOption(new PHP())
            ->addOption(new Python())
            ->addOption(new Dart())
            ->addOption(new Swift())
            ->addOption(new Ruby())
            ->addOption(new Java())
            ->addOption(new CPP())
            ->addOption(new Dotnet());

        foreach ($files as $file) {
            $detector->addInput($file);
        }

        $detectedRuntime = $detector->detect();

        if ($runtime) {
            $this->assertNotNull($detectedRuntime);
            $this->assertSame($runtime, $detectedRuntime?->getName());
            $this->assertSame($commands, $detectedRuntime?->getCommands());
        } else {
            $this->assertNull($detectedRuntime);
        }
    }

    /**
     * @return array<array{array<string>, string|null, string|null}>
     */
    public function runtimeDataProviderByFileExtensions(): array
    {
        return [
            [['main.ts', 'main.js', 'DockerFile'], 'node', 'pnpm install'],
            [['main.ts', 'main.js', 'DockerFile'], 'node', 'yarn install', 'yarn'],
            [['composer.json', 'index.php', 'DockerFile'], 'php', 'composer install && composer run build'],
            [['index.html', 'style.css'], null, null], // Test for FAILURE
        ];
    }

    /**
     * @param string[] $files List of files to check
     * @dataProvider frameworkDataProvider
     */
    public function testFrameworkDetection(array $files, ?string $framework, ?string $installCommand = null, ?string $buildCommand = null, ?string $outputDirectory = null, string $packager = 'pnpm'): void
    {
        $detector = new Framework($packager);

        $detector
            ->addOption(new Flutter())
            ->addOption(new Nuxt())
            ->addOption(new Astro())
            ->addOption(new Remix())
            ->addOption(new SvelteKit())
            ->addOption(new NextJs())
            ->addOption(new Lynx())
            ->addOption(new Angular())
            ->addOption(new Analog())
            ->addOption(new TanStackStart());

        foreach ($files as $file) {
            $detector->addInput($file, Framework::INPUT_FILE);
        }

        $detectedFramework = $detector->detect();

        if ($framework) {
            $this->assertNotNull($detectedFramework);
            $this->assertSame($framework, $detectedFramework?->getName());
            $this->assertSame($installCommand, $detectedFramework?->getInstallCommand());
            $this->assertSame($buildCommand, $detectedFramework?->getBuildCommand());
            $this->assertSame($outputDirectory, $detectedFramework?->getOutputDirectory());
        } else {
            $this->assertNull($detectedFramework);
        }
    }

    /**
     * @return array<array{array<string>, string|null, string|null, string|null, string|null}>
     */
    public function frameworkDataProvider(): array
    {
        return [
            [['src', 'types', 'makefile', 'components.js', 'debug.js', 'package.json', 'svelte.config.js'], 'sveltekit', 'pnpm install', 'pnpm run build', './build'],
            [['app', 'backend', 'public', 'Dockerfile', 'docker-compose.yml', 'ecosystem.config.js', 'middleware.ts', 'next.config.js', 'package-lock.json', 'package.json', 'server.js', 'tsconfig.json'], 'nextjs', 'pnpm install', 'pnpm run build', './.next'],
            [['assets', 'components', 'layouts', 'pages', 'babel.config.js', 'error.vue', 'nuxt.config.js', 'yarn.lock'], 'nuxt', 'pnpm install', 'pnpm run build', './.output'],
            [['lynx.config.js'], 'lynx', 'pnpm install', 'pnpm run build', './dist'],
            [['src', 'package.json', 'tsconfig.json', 'angular.json', 'logo.png'], 'angular', 'pnpm install', 'pnpm run build', './dist/angular'],
            [['app', 'public', 'remix.config.js', 'remix.env.d.ts', 'sandbox.config.js', 'tsconfig.json', 'package.json'], 'remix', 'pnpm install', 'pnpm run build', './build'],
            [['public', 'src', 'astro.config.mjs', 'package-lock.json', 'package.json', 'tsconfig.json'], 'astro', 'pnpm install', 'pnpm run build', './dist'],
            [['src', 'static', 'scripts', 'eslint.config.js', 'package.json', 'pnpm-lock.yaml', 'svelte.config.js', 'tsconfig.js', 'vite.config.js', 'vite.config.lib.js'], 'sveltekit', 'pnpm install', 'pnpm run build', './build'],
            [['index.html', 'style.css'], null, null, null, null], // Test for FAILURE
        ];
    }

    /**
     * @param string[] $files List of files to check
     * @param string $framework The framework
     * @param string $rendering The expected rendering type
     * @param string|null $fallbackFile The expected fallback file
     * @dataProvider renderingDataProvider
     */
    public function testRenderingDetection(array $files, string $framework, string $rendering, ?string $fallbackFile): void
    {
        $detector = new Rendering($framework);
        $detector
            ->addOption(new SSR())
            ->addOption(new XStatic());

        foreach ($files as $file) {
            $detector->addInput($file);
        }

        $detectedRendering = $detector->detect();

        $this->assertNotNull($detectedRendering);
        $this->assertSame($rendering, $detectedRendering->getName());
        $this->assertSame($fallbackFile, $detectedRendering->getFallbackFile());
    }

    /**
     * @return array<array{array<string>, string, string|null, string|null}>
     */
    public function renderingDataProvider(): array
    {
        return [
            [['server/pages/index.html', 'server/pages/api/users.js', '.next/server/unrelated-file.js'], 'nextjs', 'static', 'server/pages/index.html'],
            [['server/pages/api/users.js', '.next/server/pages/_app.js'], 'nextjs', 'static', null],
            [['server/pages/index.html', 'server/pages/api/users.js', '.next/turbopack'], 'nextjs', 'ssr', null],
            [['server/pages/index.html', 'server/pages/api/users.js', '.next/server/webpack-runtime.js'], 'nextjs', 'ssr', null],
            [['.next/some-standalone-files.js', 'server.js'], 'nextjs', 'ssr', null],

            // Ensure server.js detection from Next.js doesn't interfere with other frameworks
            [['nuxt.config.js', 'server/index.mjs', 'server.js'], 'nuxt', 'ssr', null],
            [['nuxt.config.js', 'index.html', 'server.js'], 'nuxt', 'static', 'index.html'],
            [['nuxt.config.js', '200.html', '202.html', 'server.js'], 'nuxt', 'static', null],

            [['index.html', 'about.html', '404.html'], 'nextjs', 'static', null],
            [['nitro.json', 'server/index.mjs'], 'nuxt', 'ssr', null],
            [['server/server.mjs'], 'angular', 'ssr', null],
            [['server/index.mjs'], 'analog', 'ssr', null],
            [['server/index.mjs'], 'tanstack-start', 'ssr', null],
            [['index.html', '_nuxt/something.js'], 'nuxt', 'static', 'index.html'],
            [['server/pages/index.js', 'prerendered/about.html', 'handler.js'], 'sveltekit', 'ssr', null],
            [['index.html', 'about.html'], 'sveltekit', 'static', null],
            [['index.html', 'style.css'], 'nextjs', 'static', 'index.html'],
            [['server/entry.mjs', 'server/renderers.mjs', 'server/pages/'], 'astro', 'ssr', null],
            [['index.html', 'about.html'], 'astro', 'static', null],
            [['build/server/index.js', 'build/server/renderers.js'], 'remix', 'ssr', null],
            [['index.html', 'about.html'], 'remix', 'static', null],
            [['about.html', 'style.css'], 'remix', 'static', 'about.html'],
            [['index.html', 'style.css'], 'flutter', 'static', 'index.html'],
            [['index.html', 'about.html'], 'tanstack-start', 'static', null],
        ];
    }

    /**
     * Test TanStack Start framework detection with packages type input
     */
    public function testTanStackStartDetectionWithPackages(): void
    {
        $detector = new Framework('npm');

        $detector
            ->addOption(new Flutter())
            ->addOption(new Nuxt())
            ->addOption(new Astro())
            ->addOption(new Remix())
            ->addOption(new SvelteKit())
            ->addOption(new NextJs())
            ->addOption(new Lynx())
            ->addOption(new Angular())
            ->addOption(new Analog())
            ->addOption(new TanStackStart());

        $packageJson = json_encode([
            'name' => 'my-app',
            'dependencies' => [
                '@tanstack/react-start' => '^1.0.0',
                'react' => '^18.0.0',
            ],
        ], JSON_UNESCAPED_SLASHES) ?: '';

        $detector->addInput($packageJson, Framework::INPUT_PACKAGES);

        $detectedFramework = $detector->detect();

        $this->assertNotNull($detectedFramework);
        // Makes static code analyser smarter
        if (is_null($detectedFramework)) {
            throw new \Exception('Framework not detected');
        }
        $this->assertSame('tanstack-start', $detectedFramework->getName());
        $this->assertSame('npm install', $detectedFramework->getInstallCommand());
        $this->assertSame('npm run build', $detectedFramework->getBuildCommand());
        $this->assertSame('./.output', $detectedFramework->getOutputDirectory());
    }

    /**
     * Test TanStack Start framework detection with devDependencies
     */
    public function testTanStackStartDetectionWithDevPackages(): void
    {
        $detector = new Framework('pnpm');

        $detector->addOption(new TanStackStart());

        $packageJson = json_encode([
            'name' => 'my-app',
            'devDependencies' => [
                '@tanstack/react-start' => '^1.0.0',
            ],
        ], JSON_UNESCAPED_SLASHES) ?: '';

        $detector->addInput($packageJson, Framework::INPUT_PACKAGES);

        $detectedFramework = $detector->detect();

        $this->assertNotNull($detectedFramework);
        // Makes static code analyser smarter
        if (is_null($detectedFramework)) {
            throw new \Exception('Framework not detected');
        }

        $this->assertSame('tanstack-start', $detectedFramework->getName());
        $this->assertSame('pnpm install', $detectedFramework->getInstallCommand());
        $this->assertSame('pnpm run build', $detectedFramework->getBuildCommand());
    }

    /**
     * Test that Framework detector rejects invalid input types
     */
    public function testFrameworkDetectorRejectsInvalidInputType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid input type 'language'");

        $detector = new Framework('npm');
        $detector->addInput('JavaScript', 'language');
    }

    /**
     * @return array<mixed>
     */
    public function frameworkEdgeCasesProvider(): array
    {
        return [
            // React-based
            [
                'assertion' => 'Just react should mean just react',
                'files' => [
                    'package.json',
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        'react' => '^17.0.2'
                    ]
                ]) ?: '',
                'framework' => 'react',
            ],
            [
                'assertion' => 'React with Next package is Next.js',
                'files' => [
                    'package.json',
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        'react' => '^17.0.2',
                        'next' => '^12.0.7'
                    ]
                ]) ?: '',
                'framework' => 'nextjs',
            ],
            [
                'assertion' => 'React with Next config is Next.js',
                'files' => [
                    'package.json',
                    'next.config.js'
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        'react' => '^17.0.2',
                    ]
                ]) ?: '',
                'framework' => 'nextjs',
            ],
            [
                'assertion' => 'React with React Native is React Native',
                'files' => [
                    'package.json',
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        'react' => '^17.0.2',
                        'react-native' => '^0.68.2'
                    ]
                ]) ?: '',
                'framework' => 'react-native',
            ],
            [
                'assertion' => 'React with Tanstack Start is Tanstack Start',
                'files' => [
                    'package.json',
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        'react' => '^17.0.2',
                        '@tanstack/react-start' => '^1.0.0'
                    ]
                ], JSON_UNESCAPED_SLASHES) ?: '',
                'framework' => 'tanstack-start',
            ],
            [
                'assertion' => 'React with Remix is Remix',
                'files' => [
                    'package.json',
                    'remix.config.js'
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        'react' => '^17.0.2'
                    ]
                ]) ?: '',
                'framework' => 'remix',
            ],
            [
                'assertion' => 'React with Lynx config file is Lynx',
                'files' => [
                    'package.json',
                    'lynx.config.ts'
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        'react' => '^17.0.2'
                    ]
                ]) ?: '',
                'framework' => 'lynx',
            ],
            [
                'assertion' => 'React with Lynx package is Lynx',
                'files' => [
                    'package.json'
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        'react' => '^17.0.2',
                        '@lynx-js/react' => '^1.0.0'
                    ]
                ], JSON_UNESCAPED_SLASHES) ?: '',
                'framework' => 'lynx',
            ],

            // Angular-based
            [
                'assertion' => 'Just Angular should mean just Angular',
                'files' => [
                    'package.json',
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        '@angular/core' => '^14.0.0'
                    ]
                ]) ?: '',
                'framework' => 'angular',
            ],
            [
                'assertion' => 'Angular with Analog is Analog',
                'files' => [
                    'package.json',
                    'angular.json',
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        '@angular/core' => '^14.0.0',
                        '@analogjs/platform' => '^14.0.0',
                    ]
                ], JSON_UNESCAPED_SLASHES) ?: '',
                'framework' => 'analog',
            ],

            // Vue-based
            [
                'assertion' => 'Just Vue should mean just Vue',
                'files' => [
                    'package.json',
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        'vue' => '^3.2.47',
                    ]
                ]) ?: '',
                'framework' => 'vue',
            ],
            [
                'assertion' => 'Vue with Nuxt config file is Nuxt',
                'files' => [
                    'package.json',
                    'nuxt.config.js',
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        'vue' => '^3.2.47',
                    ]
                ]) ?: '',
                'framework' => 'nuxt',
            ],
            [
                'assertion' => 'Vue with Nuxt package is Nuxt',
                'files' => [
                    'package.json',
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        'vue' => '^3.2.47',
                        'nuxt' => '^3.0.0'
                    ]
                ]) ?: '',
                'framework' => 'nuxt',
            ],

            // Astro-based
            [
                'assertion' => 'Just Astro should mean just Astro',
                'files' => [
                    'package.json',
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        'astro' => '^5.0.0'
                    ]
                ]) ?: '',
                'framework' => 'astro',
            ],
            [
                'assertion' => 'Astro with React is Astro',
                'files' => [
                    'package.json',
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        'astro' => '^5.0.0',
                        'react' => '^18.2.0'
                    ]
                ]) ?: '',
                'framework' => 'astro',
            ],
            [
                'assertion' => 'Astro with Angular package is Astro',
                'files' => [
                    'package.json',
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        'astro' => '^5.0.0',
                        '@angular/core' => '^18.2.0'
                    ]
                ], JSON_UNESCAPED_SLASHES) ?: '',
                'framework' => 'astro',
            ],
            [
                'assertion' => 'Astro with Angular file is Astro',
                'files' => [
                    'package.json',
                    'angular.json',
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        'astro' => '^5.0.0',
                    ]
                ], JSON_UNESCAPED_SLASHES) ?: '',
                'framework' => 'astro',
            ],
            [
                'assertion' => 'Astro with Angular file and package is Astro',
                'files' => [
                    'package.json',
                    'angular.json',
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        'astro' => '^5.0.0',
                        'angular' => '^18.2.0'
                    ]
                ], JSON_UNESCAPED_SLASHES) ?: '',
                'framework' => 'astro',
            ],
            [
                'assertion' => 'Astro with Vue is Astro',
                'files' => [
                    'package.json',
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        'astro' => '^5.0.0',
                        'vue' => '^3.2.47'
                    ]
                ]) ?: '',
                'framework' => 'astro',
            ],


            // Svelte-based
            [
                'assertion' => 'Just Svelte should mean just Svelte',
                'files' => [
                    'package.json',
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        'svelte' => '^3.54.0'
                    ]
                ]) ?: '',
                'framework' => 'svelte',
            ],
            [
                'assertion' => 'Svelte with SvelteKit is SvelteKit',
                'files' => [
                    'package.json',
                ],
                'package' => \json_encode([
                    'dependencies' => [
                        'svelte' => '^3.54.0',
                        '@sveltejs/kit' => '^1.0.0'
                    ]
                ], JSON_UNESCAPED_SLASHES) ?: '',
                'framework' => 'sveltekit',
            ],
        ];
    }

    /**
     * Test scenarios that can possibly result in multiple frameworks,
     * but only one is accurate detection.
     * @param array<string> $files
     * @dataProvider frameworkEdgeCasesProvider
     */
    public function testFrameworkEdgeCases(string $assertion, array $files, string $packageFile, string $framework): void
    {
        $detector = new Framework('npm');

        $detector
            ->addOption(new Analog())
            ->addOption(new Angular())
            ->addOption(new Astro())
            ->addOption(new Flutter())
            ->addOption(new Lynx())
            ->addOption(new NextJs())
            ->addOption(new Nuxt())
            ->addOption(new React())
            ->addOption(new ReactNative())
            ->addOption(new Remix())
            ->addOption(new Svelte())
            ->addOption(new SvelteKit())
            ->addOption(new TanStackStart())
            ->addOption(new Vue());

        foreach ($files as $file) {
            $detector->addInput($file, Framework::INPUT_FILE);
        }

        $detector->addInput($packageFile, Framework::INPUT_PACKAGES);

        $detection = $detector->detect();

        $this->assertNotNull($detection, $assertion);
        // Makes static code analyser smarter
        if (is_null($detection)) {
            throw new \Exception('Framework not detected');
        }

        $this->assertSame($framework, $detection->getName(), $assertion);
    }

    public function testTanStackStartAdapterDetection(): void
    {
        $fw = new TanStackStart();

        $this->assertSame('ssr', $fw->getAdapter('export default defineConfig({ plugins: [tanstackStart()] })'));
        $this->assertSame('static', $fw->getAdapter('export default defineConfig({ plugins: [tanstackStart({ prerender: { routes: [\'/\'] } })] })'));
        $this->assertSame('ssr', $fw->getAdapter('export default defineConfig({ plugins: [tanstackStart({ prerender: false })] })'));
        $this->assertSame('ssr', $fw->getAdapter('export default defineConfig({ plugins: [tanstackStart({ "prerender": false })] })'));
        $this->assertSame('ssr', $fw->getAdapter('// prerender: true' . "\n" . 'export default defineConfig({})'));
        $this->assertSame('static', $fw->getAdapter('server: { url: "https://example.com" },' . "\n" . 'prerender: { routes: [\'/\'] }'));
        $this->assertNotEmpty($fw->getConfigFiles());
    }

    public function testSvelteKitAdapterDetection(): void
    {
        $fw = new SvelteKit();

        $this->assertSame('ssr', $fw->getAdapter('import adapter from \'@sveltejs/adapter-auto\'; export default { kit: { adapter: adapter() } }'));
        $this->assertSame('static', $fw->getAdapter('import adapter from \'@sveltejs/adapter-static\'; export default { kit: { adapter: adapter() } }'));
        $this->assertSame('static', $fw->getAdapter('{"dependencies":{"@sveltejs/adapter-static":"^3.0.0"}}'));
        $this->assertSame('ssr', $fw->getAdapter('// import adapter from \'@sveltejs/adapter-static\'' . "\n" . 'import adapter from \'@sveltejs/adapter-auto\''));
        $this->assertContains('package.json', $fw->getConfigFiles());
        $this->assertNotEmpty($fw->getConfigFiles());
    }

    public function testAstroAdapterDetection(): void
    {
        $fw = new Astro();

        $this->assertSame('static', $fw->getAdapter('export default defineConfig({ integrations: [] })'));
        $this->assertSame('ssr', $fw->getAdapter('export default defineConfig({ output: \'server\', adapter: node({ mode: \'standalone\' }) })'));
        $this->assertSame('ssr', $fw->getAdapter('export default defineConfig({ output: "server" })'));
        $this->assertSame('ssr', $fw->getAdapter('export default defineConfig({ output: \'hybrid\' })'));
        $this->assertSame('ssr', $fw->getAdapter('export default defineConfig({ output  :  \'server\' })'));
        $this->assertSame('static', $fw->getAdapter('// output: \'server\'' . "\n" . 'export default defineConfig({})'));
        $this->assertSame('ssr', $fw->getAdapter('site: "https://example.com",' . "\n" . 'output: "server"'));
        $this->assertSame('ssr', $fw->getAdapter('export default defineConfig({ output: `server` })'));
        $this->assertSame('ssr', $fw->getAdapter('export default defineConfig({ output: `hybrid` })'));
        $this->assertNotEmpty($fw->getConfigFiles());
    }

    public function testRemixAdapterDetection(): void
    {
        $fw = new Remix();

        $this->assertSame('ssr', $fw->getAdapter('{"dependencies":{"@remix-run/react":"^2.0.0"}}'));
        $this->assertSame('ssr', $fw->getAdapter('{"dependencies":{"@remix-run/serve":"^2.0.0"}}'));
        $this->assertSame('ssr', $fw->getAdapter('{"dependencies":{"@remix-run/node":"^2.0.0"}}'));
        $this->assertSame('ssr', $fw->getAdapter(''));
        $this->assertContains('package.json', $fw->getConfigFiles());
    }

    public function testJasprDetectionWithPubspec(): void
    {
        $detector = new Framework('npm');

        $detector
            ->addOption(new Flutter())
            ->addOption(new Jaspr());

        $pubspec = <<<'YAML'
        name: my_site
        environment:
          sdk: ^3.10.0

        dependencies:
          jaspr: ^0.20.0
          jaspr_router: ^0.10.0

        jaspr:
          mode: server
        YAML;

        $detector->addInput($pubspec, Framework::INPUT_PACKAGES);
        $detector->addInput('pubspec.yaml', Framework::INPUT_FILE);
        $detector->addInput('pubspec.lock', Framework::INPUT_FILE);

        $detectedFramework = $detector->detect();

        $this->assertNotNull($detectedFramework);
        // Makes static code analyser smarter
        if (is_null($detectedFramework)) {
            throw new \Exception('Framework not detected');
        }

        $this->assertSame('jaspr', $detectedFramework->getName());
        $this->assertSame('dart pub get', $detectedFramework->getInstallCommand());
        $this->assertSame('dart run jaspr_cli:jaspr build', $detectedFramework->getBuildCommand());
        $this->assertSame('./build/jaspr', $detectedFramework->getOutputDirectory());
    }

    public function testFlutterStillWinsWithoutJasprDependency(): void
    {
        $detector = new Framework('npm');

        $detector
            ->addOption(new Flutter())
            ->addOption(new Jaspr());

        $pubspec = <<<'YAML'
        name: my_app
        environment:
          sdk: ^3.10.0

        dependencies:
          flutter:
            sdk: flutter
        YAML;

        $detector->addInput($pubspec, Framework::INPUT_PACKAGES);
        $detector->addInput('pubspec.yaml', Framework::INPUT_FILE);
        $detector->addInput('pubspec.lock', Framework::INPUT_FILE);

        $detectedFramework = $detector->detect();

        $this->assertNotNull($detectedFramework);
        $this->assertSame('flutter', $detectedFramework?->getName());
    }

    public function testJasprAdapterDetection(): void
    {
        $fw = new Jaspr();

        $this->assertSame('ssr', $fw->getAdapter("jaspr:\n  mode: server\n"));
        $this->assertSame('static', $fw->getAdapter("jaspr:\n  mode: static\n"));
        $this->assertSame('static', $fw->getAdapter("jaspr:\n  uses-flutter: true\n"));
        $this->assertSame('ssr', $fw->getAdapter("name: my_site\n\njaspr:\n  mode: server\n\ndependencies:\n  jaspr: ^0.20.0\n"));
        $this->assertSame('', $fw->getAdapter("name: my_app\ndependencies:\n  flutter:\n    sdk: flutter\n"));
        $this->assertSame('', $fw->getAdapter("# jaspr:\n#   mode: server\n"));
        $this->assertContains('pubspec.yaml', $fw->getConfigFiles());
    }
}
