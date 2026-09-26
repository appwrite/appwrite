# Utopia Console

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/console`](https://github.com/utopia-php/monorepo/tree/main/packages/console) — please open issues and pull requests there.

Small collection of helpers for working with PHP command line applications. The Console class focuses on everyday needs such as logging, prompting users for input, executing external commands, and building long-running daemons.

## Installation

Install using Composer:

```bash
composer require utopia-php/console
```

## Usage

```php
<?php
require_once __DIR__.'/vendor/autoload.php';

use Utopia\Console;
use Utopia\Command;

Console::success('Ready to work!');

$answer = Console::confirm('Continue? [y/N]');

if ($answer !== 'y') {
    Console::warning('Aborting...');
    Console::exit(1);
}

$output = '';
$stderr = '';
$command = new Command(PHP_BINARY)
    ->option('-r', 'echo "Hello";');

$exitCode = Console::execute($command, '', $output, $stderr, 3);

Console::log("Command returned {$exitCode} with: {$output}");
```

### Log messages

```php
Console::log('Plain log');        // stdout
Console::success('Green log');    // stdout
Console::info('Blue log');        // stdout
Console::warning('Yellow log');   // stderr
Console::error('Red log');        // stderr
```

### Execute commands

`Console::execute()` returns the exit code and writes stdout and stderr into the referenced output variables. Pass a timeout (in seconds) to stop long-running processes and an optional progress callback to stream intermediate output. Prefer `Utopia\Command` or argv arrays when you want structured command building.

```php
$command = new Command(PHP_BINARY)
    ->option('-r', 'fwrite(STDOUT, "success\\n");');

$output = '';
$input = '';
$stderr = '';
$exitCode = Console::execute($command, $input, $output, $stderr, 3);

echo $exitCode;  // 0
echo $output;    // "success\n"
```

### Build commands

Use `flag()` for switches without a value, `option()` for keys that take a value, and `argument()` for positional arguments.

```php
$command = new Command('tar')
    ->flag('-cz')
    ->option('-f', 'archive.tar.gz')
    ->option('-C', '/tmp/project')
    ->argument('.');
```

### Compose commands

Use the static helpers when you need shell operators such as pipes, `&&`, `||`, grouping, or redirects.

```php
$pipeline = Command::pipe(
    new Command('ps')->flag('-ef'),
    new Command('grep')->argument('php-fpm'),
    new Command('wc')->flag('-l'),
);

$deploy = Command::and(
    Command::group(
        Command::or(
            new Command('build'),
            new Command('build:fallback'),
        )
    ),
    new Command('publish'),
);

$logs = Command::appendStdout(
    Command::pipe(
        new Command('cat')->argument('app.log'),
        new Command('grep')->argument('ERROR'),
    ),
    'errors.log',
);
```

Plain commands execute in argv mode. Composed, grouped, and redirected commands execute through shell syntax.

### Create a daemon

Use `Console::loop()` to build daemons without tight loops. The helper sleeps between iterations and periodically triggers garbage collection.

```php
<?php

use Utopia\Console;

Console::loop(function () {
    echo "Hello World\n";
}, 1); // 1 second
```

## System requirements

Utopia Console requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

## License

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
