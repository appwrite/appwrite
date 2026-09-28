# Utopia Platform

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/platform`](https://github.com/utopia-php/monorepo/tree/main/packages/platform) — please open issues and pull requests there.

[![Tests](https://github.com/utopia-php/monorepo/actions/workflows/tests.yml/badge.svg)](https://github.com/utopia-php/monorepo/actions/workflows/tests.yml)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/platform.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

An object oriented way of writing Applications using Utopia libraries

## Getting started

This library contains abstract classes that assist in implementing services and actions for Utopia http framework and CLI. You must implement `Platform`, `Service` and `Action` classes to build your application.

## Example

Install using Composer

```
composer require utopia-php/platform
```

Implementing a HTTP services using platform.

```php
// Action

<?php

use Utopia\Platform\Action;

class HelloWorldAction extends Action
{
    public function __construct()
    {
        $this->httpPath = '/hello';
        $this->httpMethod = 'GET';
        $this->inject('response');
        $this->callback(fn ($response) => $this->action($response));
    }

    public function action($response)
    {
        $response->send('Hello World!');
    }
}

// service

use Utopia\Platform\Service;

class HelloWorldService extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this->addAction('hello', new HelloWorldAction());
    }
}

// Platform

use Utopia\Platform\Platform;

class HelloWorldPlatform extends Platform
{
    public function __construct()
    {
        $this->addService('helloService', new HelloWorldService());
    }
}

// Using platform to initialize http service

$platform = new HelloWorldPlatform();
$platform->init('http');

```

## System requirements

Utopia Framework requires PHP 8.3 or later. We recommend using the latest PHP version whenever possible.

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and be approved by a core developer before being merged. This is to ensure a proper review of all the code.

We truly ❤️ pull requests! If you wish to help, you can learn more about how you can contribute to this project in the [contribution guide](https://github.com/utopia-php/monorepo/blob/main/CONTRIBUTING.md).

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
