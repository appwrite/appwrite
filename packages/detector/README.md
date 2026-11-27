# Utopia Detector

> [!IMPORTANT]
> This repository is a read-only mirror of [`packages/detector`](https://github.com/appwrite/appwrite/tree/main/packages/detector) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/detector.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Detector is a simple library for fast and reliable environment identification. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting Started

Install using composer:
```bash
composer require utopia-php/detector
```

Requires the `utopia` PHP extension — see [System Requirements](#system-requirements).

Init in your application:
```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\Detector\Detection\Mobile\Android;
use Utopia\Detector\Detection\Mobile\Flutter;
use Utopia\Detector\Detection\Mobile\Ios;
use Utopia\Detector\Detection\Mobile\ReactNative;
use Utopia\Detector\Detector\Framework;
use Utopia\Detector\Detector\Mobile;
use Utopia\Detector\Detector\Packager;
use Utopia\Detector\Detector\Rendering;
use Utopia\Detector\Detector\Runtime;
use Utopia\Detector\Detector\Strategy;

// Initialise Packager Detection
$files = ['bun.lockb', 'fly.toml', 'package.json', 'remix.config.js'];
$detector = new Packager($files);
$detector
    ->addOption(new PNPM())
    ->addOption(new Yarn())
    ->addOption(new NPM());

$detectedPackager = $detector->detect();
$packagerName = $detectedPackager->getName();

// Initialise Runtime Detection
$detector = new Runtime(
    $files,
    new Strategy(Strategy::FILEMATCH), // similar for LANGUAGES and EXTENSIONS
    'pnpm'
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

$detectedRuntime = $detector->detect();
$runtime = $detectedRuntime->getName();
$runtimeCommands = $detectedRuntime->getCommands();

// Initialise Framework Detection
$files = ['src', 'types', 'makefile', 'components.js', 'debug.js', 'package.json', 'svelte.config.js'];
$packager = 'pnpm';
$detector = new Framework($files, $packager);

$detector
    ->addOption(new Flutter())
    ->addOption(new Nuxt())
    ->addOption(new Astro())
    ->addOption(new Analog())
    ->addOption(new Angular())
    ->addOption(new Remix())
    ->addOption(new SvelteKit())
    ->addOption(new Lynx())
    ->addOption(new NextJs());

$detectedFramework = $detector->detect();
$framework = $detectedFramework->getName();
$frameworkInstallCommand = $framework = $detectedFramework->getInstallCommand();

// Initialise Rendering Detection
$files = ['./build/server/index.js', './build/server/renderers.js'];
$framework = 'remix';
$detector = new Rendering($files, $framework);
$detector
    ->addOption(new SSR())
    ->addOption(new Static());

$detectedRendering = $detector->detect();
$rendering = $detectedRendering->getName();

// Initialise Mobile Detection
$detector = new Mobile();
$detector
    ->addInput('pubspec.yaml', Mobile::INPUT_FILE)
    ->addInput($pubspec, Mobile::INPUT_PACKAGES) // package.json or pubspec.yaml content
    ->addInput($gradle, Mobile::INPUT_GRADLE) // build.gradle(.kts), settings.gradle(.kts) or libs.versions.toml content
    ->addOption(new Flutter())
    ->addOption(new ReactNative())
    ->addOption(new Android())
    ->addOption(new Ios());

$detectedMobile = $detector->detect();
$mobile = $detectedMobile->getName(); // flutter, react-native, android or ios
$platforms = $detectedMobile->getPlatforms(); // ['android', 'ios']
```

Mobile detection reads runtime `dependencies` only (Flutter, React Native), Gradle scripts that apply or declare `com.android.application` (Android), and `*.xcodeproj`, `*.xcworkspace` or `Podfile` entries (iOS). When several options match, the one covering the most platforms wins, so a Flutter or React Native project is not reported as its embedded native project.

### Supported Adapters

Detector Adapters:

| Adapter | Status |
|---------|---------|
| Runtime | ✅ |
| Framework | ✅ |
| Packager | ✅ |
| Rendering | ✅ |
| Mobile | ✅ |

Runtime Adapters:

| Adapter | Status |
|---------|---------|
| CPP | ✅ |
| Dart | ✅ |
| Deno | ✅ |
| Dotnet | ✅ |
| Java | ✅ |
| JavaScript | ✅ |
| PHP | ✅ |
| Python | ✅ |
| Ruby | ✅ |
| Swift | ✅ |
| Bun | ✅ |

Framework Adapters:

| Adapter | Status |
|---------|---------|
| Astro | ✅ |
| Flutter | ✅ |
| Jaspr | ✅ |
| NextJs | ✅ |
| Nuxt | ✅ |
| Remix | ✅ |
| SvelteKit | ✅ |
| Angular | ✅ |
| Analog | ✅ |
| Lynx | ✅ |

Packager Adapters:

| Adapter | Status |
|---------|---------|
| NPM | ✅ |
| PNPM | ✅ |
| Yarn | ✅ |

Rendering Adapters:

| Adapter | Status |
|---------|---------|
| SSR | ✅ |
| Static | ✅ |

Mobile Adapters:

| Adapter | Status |
|---------|---------|
| Android | ✅ |
| Flutter | ✅ |
| Ios | ✅ |
| ReactNative | ✅ |

`✅  - supported, 🛠  - work in progress`

## System Requirements

Utopia Detector requires PHP 8.4 or later and the `utopia` extension (`ext-utopia`, built from
[`crates/extension`](https://github.com/appwrite/appwrite/tree/main/crates/extension) in
appwrite/appwrite). Every class of `Utopia\Detector` comes from the extension; this package holds
their signatures for static analysis and editors (`stubs/`) and the tests. 0.2.x is the last
release with the classes in PHP.

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and approved by a core developer before being merged. This is to ensure proper review of all the code.

Fork the project, create a feature branch, and send us a pull request.

You can refer to the [Contributing Guide](CONTRIBUTING.md) for more info.

## Tests

To run all tests, use the following command:

```bash
composer test
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
