# Utopia Detector

> [!IMPORTANT]
> This repository is a read-only mirror of [`packages/detector`](https://github.com/appwrite/appwrite/tree/main/packages/detector) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Build Status](https://travis-ci.org/utopia-php/detector.svg?branch=master)](https://travis-ci.com/utopia-php/detector)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/detector.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Detector is a simple library for fast and reliable environment identification. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting Started

Install using composer:
```bash
composer require utopia-php/detector
```

Requires the `yaml` extension — see [System Requirements](#system-requirements).

Init in your application:
```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\Detector\Detector\Framework;
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
```

### Supported Adapters

Detector Adapters:

| Adapter | Status |
|---------|---------|
| Runtime | ✅ |
| Framework | ✅ |
| Packager | ✅ |
| Rendering | ✅ |

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

`✅  - supported, 🛠  - work in progress`

## System Requirements

Utopia Detector requires PHP 8.0 or later with the `json` and `yaml` extensions. We recommend using the latest PHP version whenever possible.

`json` ships with PHP. `yaml` is a PECL extension, used to read `pubspec.yaml` manifests:

```bash
pecl install yaml
```


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
