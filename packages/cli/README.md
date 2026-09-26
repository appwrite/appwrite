# Utopia CLI

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/cli`](https://github.com/utopia-php/monorepo/tree/main/packages/cli) — please open issues and pull requests there.

[![Tests](https://github.com/utopia-php/monorepo/actions/workflows/tests.yml/badge.svg)](https://github.com/utopia-php/monorepo/actions/workflows/tests.yml)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/cli.svg)
[![Discord](https://img.shields.io/discord/564160730845151244)](https://appwrite.io/discord)

Utopia framework CLI library is simple and lite library for extending Utopia PHP Framework to be able to code command line applications. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Getting started

Install using Composer:
```bash
composer require utopia-php/cli
```

script.php
```php
<?php
require_once './vendor/autoload.php';

use Utopia\Console;
use Utopia\CLI\CLI;
use Utopia\CLI\Adapters\Generic;
use Utopia\Validator\Wildcard;

$cli = new CLI(new Generic());

$cli
    ->task('command-name')
    ->param('email', null, new Wildcard())
    ->action(function ($email) {
        Console::success($email);
    });

$cli->run();

```

And than, run from command line:

```bash
php script.php command-name --email=me@example.com
```

### Hooks

There are three types of hooks, init hooks, shutdown hooks and error hooks. Init hooks are executed before the task is executed. Shutdown hook is executed after task is executed before application shuts down. Finally error hooks are executed whenever there's an error in the application lifecycle. You can provide multiple hooks for each stage.

```php
require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\CLI\CLI;
use Utopia\Console;
use Utopia\Validator\Wildcard;

CLI::setResource('res1', function() {
    return 'resource 1';
})

CLI::init()
    inject('res1')
    ->action(function($res1) {
        Console::info($res1);
    });

CLI::error()
    ->inject('error')
    ->action(function($error) {
        Console::error('Error occurred ' . $error);
    });

$cli = new CLI();

$cli
    ->task('command-name')
    ->param('email', null, new Wildcard())
    ->action(function ($email) {
        Console::success($email);
    });

$cli->run();
```

## System requirements

Utopia Framework requires PHP 7.4 or later. We recommend using the latest PHP version whenever possible.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
