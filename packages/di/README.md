> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/di`](https://github.com/utopia-php/monorepo/tree/main/packages/di) — please open issues and pull requests there.

<p>
    <img height="45" src="docs/logo.png" alt="Logo">
</p>

[![CI](https://github.com/utopia-php/di/actions/workflows/ci.yml/badge.svg)](https://github.com/utopia-php/di/actions/workflows/ci.yml)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/di.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Utopia DI is a small PSR-11 compatible dependency injection container with parent-child scopes. It is designed to stay simple while still covering the dependency lifecycle used across the Utopia libraries. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the Utopia Framework project it is dependency free, and can be used as standalone with any other PHP project or framework.

## Getting started

Install using Composer:

```bash
composer require utopia-php/di
```

```php
require_once __DIR__.'/../vendor/autoload.php';

use Utopia\DI\Container;

$di = new Container();

$di->set('age', fn (): int => 25);

$di->set(
    'john',
    fn (int $age): string => 'John Doe is '.$age.' years old.',
    ['age']
);

$john = $di->get('john');
```

Dependencies are resolved from the third `set()` argument and passed to the factory in that same order.

Register factories directly and list the dependency IDs they need.

```php
$di->set('config', fn (): array => [
    'dsn' => 'mysql:host=localhost;dbname=app',
    'username' => 'root',
    'password' => 'secret',
]);

$di->set(
    'db',
    fn (array $config): PDO => new PDO(
        $config['dsn'],
        $config['username'],
        $config['password']
    ),
    ['config']
);
```

Factories are resolved once per container instance. A child container behaves in two distinct ways:

- If the child does not define a key, it falls back to the parent and reuses the parent's resolved value.
- If the child defines the same key locally, it resolves and caches its own value without changing the parent.

```php
$counter = 0;

$di->set('requestId', function () use (&$counter): string {
    $counter++;

    return 'request-'.$counter;
});

$di->get('requestId'); // "request-1"

$child = new Container($di);

$child->get('requestId'); // "request-1" (falls back to the parent cache)

$child->set('requestId', function () use (&$counter): string {
    $counter++;

    return 'request-'.$counter;
});

$child->get('requestId'); // "request-2" (child now uses its own local definition)
$di->get('requestId'); // "request-1" (parent is unchanged)
```

## System requirements

Utopia DI requires PHP 8.2 or later. We recommend using the latest PHP version whenever possible.

## More from Utopia

Our ecosystem supports other thin PHP projects aiming to extend the core PHP Utopia libraries.

Each project is focused on solving a single, very simple problem and you can use Composer to include any of them in your next project.

You can find all libraries in [GitHub Utopia organization](https://github.com/utopia-php).

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and approved by a core developer before being merged. This is to ensure proper review of all the code.

Fork the [utopia-php monorepo](https://github.com/utopia-php/monorepo), create a feature branch, and send us a pull request.

You can refer to the [Contributing Guide](https://github.com/utopia-php/monorepo/blob/main/CONTRIBUTING.md) for more info.

For security issues, please email security@appwrite.io instead of posting a public issue in GitHub.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
