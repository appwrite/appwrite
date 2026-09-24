<?php

namespace Utopia\Detector\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
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
use Utopia\Detector\Detector\Framework;
use Utopia\Detector\Detector\Packager;
use Utopia\Detector\Detector\Rendering;
use Utopia\Detector\Detector\Runtime;
use Utopia\Detector\Detector\Strategy;

class DetectorTest extends TestCase
{
    /**
     * @param string[] $files List of files to check
     */
    #[DataProvider('packagerDataProvider')]
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
    public static function packagerDataProvider(): array
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
     */
    #[DataProvider('runtimeDataProviderByFilematch')]
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
            $this->assertSame($runtime, $detectedRuntime->getName());
            $this->assertSame($commands, $detectedRuntime->getCommands());
            $this->assertSame($entrypoint, $detectedRuntime->getEntrypoint());
        } else {
            $this->assertNull($detectedRuntime);
        }
    }

    /**
     * @return array<array{array<string>, string|null, string|null, string|null, string|null}>
     */
    public static function runtimeDataProviderByFilematch(): array
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
     */
    #[DataProvider('runtimeDataProviderByLanguages')]
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
            $this->assertSame($runtime, $detectedRuntime->getName());
            $this->assertSame($commands, $detectedRuntime->getCommands());
        } else {
            $this->assertNull($detectedRuntime);
        }
    }

    /**
     * @return array<array{array<string>, string|null, string|null, string|null}>
     */
    public static function runtimeDataProviderByLanguages(): array
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
     */
    #[DataProvider('runtimeDataProviderByFileExtensions')]
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
            $this->assertSame($runtime, $detectedRuntime->getName());
            $this->assertSame($commands, $detectedRuntime->getCommands());
        } else {
            $this->assertNull($detectedRuntime);
        }
    }

    /**
     * @return array<array{array<string>, string|null, string|null}>
     */
    public static function runtimeDataProviderByFileExtensions(): array
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
     */
    #[DataProvider('frameworkDataProvider')]
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
            $this->assertSame($framework, $detectedFramework->getName());
            $this->assertSame($installCommand, $detectedFramework->getInstallCommand());
            $this->assertSame($buildCommand, $detectedFramework->getBuildCommand());
            $this->assertSame($outputDirectory, $detectedFramework->getOutputDirectory());
        } else {
            $this->assertNull($detectedFramework);
        }
    }

    /**
     * @return array<array{array<string>, string|null, string|null, string|null, string|null}>
     */
    public static function frameworkDataProvider(): array
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
     */
    #[DataProvider('renderingDataProvider')]
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

        $this->assertSame($rendering, $detectedRendering->getName());
        $this->assertSame($fallbackFile, $detectedRendering->getFallbackFile());
    }

    /**
     * @return array<array{array<string>, string, string|null, string|null}>
     */
    public static function renderingDataProvider(): array
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
            [['app', 'index.html', 'main.dart.js'], 'jaspr', 'ssr', null],
            [['index.html', 'main.dart.js', 'styles.css'], 'jaspr', 'static', 'index.html'],
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
    public static function frameworkEdgeCasesProvider(): array
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
     */
    #[DataProvider('frameworkEdgeCasesProvider')]
    public function testFrameworkEdgeCases(string $assertion, array $files, string $package, string $framework): void
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

        $detector->addInput($package, Framework::INPUT_PACKAGES);

        $detection = $detector->detect();

        $this->assertNotNull($detection, $assertion);

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

    /**
     * @param array<string> $files
     */
    #[DataProvider('dartFrameworkDataProvider')]
    public function testDartFrameworkDetection(string $pubspec, array $files, string $framework): void
    {
        // Registration order must not decide between two frameworks sharing the pubspec files
        foreach ([[new Flutter(), new Jaspr()], [new Jaspr(), new Flutter()]] as $options) {
            $detector = new Framework('npm');

            foreach ($options as $option) {
                $detector->addOption($option);
            }

            $detector->addInput($pubspec, Framework::INPUT_PACKAGES);

            foreach ($files as $file) {
                $detector->addInput($file, Framework::INPUT_FILE);
            }

            $detectedFramework = $detector->detect();

            $this->assertNotNull($detectedFramework);
            $this->assertSame($framework, $detectedFramework->getName());
        }
    }

    /**
     * @return array<string, array{string, array<string>, string}>
     */
    public static function dartFrameworkDataProvider(): array
    {
        $pubspecFiles = ['pubspec.yaml', 'pubspec.lock'];

        return [
            'jaspr' => [
                <<<'YAML'
                name: my_site
                environment:
                  sdk: ^3.8.0

                dependencies:
                  jaspr: ^0.23.0

                dev_dependencies:
                  jaspr_builder: ^0.23.0

                jaspr:
                  mode: server
                YAML,
                $pubspecFiles,
                'jaspr',
            ],
            'jaspr with quoted dependency key' => [
                <<<'YAML'
                name: my_site

                dependencies:
                  'jaspr': ^0.23.0
                YAML,
                $pubspecFiles,
                'jaspr',
            ],
            'jaspr declared in a flow mapping' => [
                <<<'YAML'
                name: my_site

                dependencies: {jaspr: ^0.23.0, shelf: ^1.4.0}
                YAML,
                $pubspecFiles,
                'jaspr',
            ],
            'jaspr embedding flutter' => [
                <<<'YAML'
                name: my_site

                dependencies:
                  jaspr: ^0.23.0
                  flutter:
                    sdk: flutter

                jaspr:
                  mode: server
                  flutter: embedded
                YAML,
                $pubspecFiles,
                'jaspr',
            ],
            'jaspr without a lockfile' => [
                <<<'YAML'
                name: my_site

                dependencies:
                  jaspr: ^0.23.0
                YAML,
                ['pubspec.yaml'],
                'jaspr',
            ],
            'flutter' => [
                <<<'YAML'
                name: my_app
                environment:
                  sdk: ^3.8.0

                dependencies:
                  flutter:
                    sdk: flutter

                flutter:
                  uses-material-design: true
                YAML,
                $pubspecFiles,
                'flutter',
            ],
            'jaspr in a multiline flow mapping' => [
                <<<'YAML'
                name: my_site

                dependencies: {shelf: ^1.4.0,
                  jaspr: ^0.23.0}
                YAML,
                $pubspecFiles,
                'jaspr',
            ],
            'jaspr beside a quoted hash' => [
                <<<'YAML'
                name: my_site

                dependencies: {local_pkg: {path: 'packages # local'}, jaspr: ^0.23.0}
                YAML,
                $pubspecFiles,
                'jaspr',
            ],
            'jaspr beside an anchored quoted hash' => [
                <<<'YAML'
                name: my_site

                dependencies: {local_pkg: {path: &local 'packages # local'}, jaspr: ^0.23.0}
                YAML,
                $pubspecFiles,
                'jaspr',
            ],
            'flutter with an apostrophe before a commented jaspr map' => [
                <<<'YAML'
                name: my_app
                description: Don't enable this # {jaspr: 'example'}

                dependencies:
                  flutter:
                    sdk: flutter
                YAML,
                $pubspecFiles,
                'flutter',
            ],
            'flutter with a commented out jaspr dependency' => [
                <<<'YAML'
                name: my_app

                dependencies:
                  flutter:
                    sdk: flutter
                  # jaspr: ^0.23.0
                # dependencies: {jaspr: ^0.23.0}
                YAML,
                $pubspecFiles,
                'flutter',
            ],
            'flutter with a jaspr config but no jaspr dependency' => [
                <<<'YAML'
                name: my_app

                dependencies:
                  flutter:
                    sdk: flutter

                jaspr:
                  mode: server
                YAML,
                $pubspecFiles,
                'flutter',
            ],
            'flutter with an unparsable pubspec' => [
                <<<'YAML'
                name: my_app
                dependencies: {flutter: {sdk: flutter}
                YAML,
                $pubspecFiles,
                'flutter',
            ],
            // Appwrite fetches package.json only, so a Dart repo supplies no manifest today
            'pubspec files with no manifest content' => [
                '',
                $pubspecFiles,
                'flutter',
            ],
        ];
    }

    public function testJasprBuildCommands(): void
    {
        $detector = new Framework('npm');

        $detector
            ->addOption(new Flutter())
            ->addOption(new Jaspr());

        $detector->addInput("name: my_site\n\ndependencies:\n  jaspr: ^0.23.0\n", Framework::INPUT_PACKAGES);
        $detector->addInput('pubspec.yaml', Framework::INPUT_FILE);

        $detectedFramework = $detector->detect();

        $this->assertNotNull($detectedFramework);

        // Must stay in sync with the jaspr adapter in Appwrite's frameworks config
        $this->assertSame('dart pub get', $detectedFramework->getInstallCommand());
        $this->assertSame('jaspr build', $detectedFramework->getBuildCommand());
        $this->assertSame('./build/jaspr', $detectedFramework->getOutputDirectory());
    }

    #[DataProvider('jasprAdapterDataProvider')]
    public function testJasprAdapterDetection(string $pubspec, string $adapter): void
    {
        $this->assertSame($adapter, (new Jaspr())->getAdapter($pubspec));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function jasprAdapterDataProvider(): array
    {
        return [
            'server mode' => ["jaspr:\n  mode: server\n", 'ssr'],
            'static mode' => ["jaspr:\n  mode: static\n", 'static'],
            'client mode' => ["jaspr:\n  mode: client\n", 'static'],
            'quoted mode value' => ["jaspr:\n  mode: 'server'\n", 'ssr'],
            'quoted jaspr key' => ["\"jaspr\":\n  mode: server\n", 'ssr'],
            'flow mapping' => ["jaspr: {mode: server}\n", 'ssr'],
            'trailing comment' => ["jaspr:\n  mode: server # keep in sync\n", 'ssr'],
            'comment on the jaspr key line' => ["jaspr: # mode: static\n  mode: server\n", 'ssr'],
            'commented out mode above the real one' => ["jaspr:\n  # mode: static\n  mode: server\n", 'ssr'],
            'commented out flow mapping' => ["jaspr: {mode: server} # {mode: static}\n", 'ssr'],
            'mode nested under another option' => ["jaspr:\n  dev:\n    mode: static\n  mode: server\n", 'ssr'],
            'multiline flow mapping' => ["jaspr: {flutter: embedded,\n  mode: server}\n", 'ssr'],
            'nested flow mapping' => ["jaspr: {dev: {port: 8080}, mode: server}\n", 'ssr'],
            'escaped quotes in a flow value' => ['jaspr: {dev-command: "echo \'\\"\'", mode: server}'."\n", 'ssr'],
            'apostrophe in a plain scalar' => ["jaspr:\n  mode: server\ndescription: Don't ship # mode: static\n", 'ssr'],
            'doubled quotes in a value' => ["jaspr: {port: 'it''s 8080', mode: server}\n", 'ssr'],
            'anchor before a quoted value' => ['jaspr: {dev-command: &cmd "echo # hello", mode: server}'."\n", 'ssr'],
            'tag before a quoted value' => ["jaspr:\n  port: !!str '8080 # default'\n  mode: server\n", 'ssr'],
            'quoted hash before the mode' => ["jaspr:\n  dev-command: 'serve # fast'\n  mode: server\n", 'ssr'],
            'surrounded by other keys' => ["name: my_site\n\njaspr:\n  mode: server\n\ndependencies:\n  jaspr: ^0.23.0\n", 'ssr'],
            'commented out config' => ["# jaspr:\n#   mode: server\n", ''],
            'jaspr dependency but no config' => ["name: my_site\ndependencies:\n  jaspr: ^0.23.0\n", ''],
            'config without a mode' => ["jaspr:\n  flutter: embedded\n", ''],
            'unknown mode' => ["jaspr:\n  mode: hybrid\n", ''],
            'not a jaspr project' => ["name: my_app\ndependencies:\n  flutter:\n    sdk: flutter\n", ''],
            'unparsable config' => ["jaspr: {mode: server\n", ''],
        ];
    }

    public function testJasprConfigFiles(): void
    {
        $this->assertContains('pubspec.yaml', (new Jaspr())->getConfigFiles());
    }
}
