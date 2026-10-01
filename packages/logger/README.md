# Utopia Logger

[![Build Status](https://app.travis-ci.com/utopia-php/logger.svg?branch=main)](https://app.travis-ci.com/github/utopia-php/logger)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/logger.svg)
[![Discord](https://img.shields.io/discord/564160730845151244)](https://appwrite.io/discord)

Utopia Logger library is simple and lite library for logging information, such as errors or warnings. This library aims to be as simple and easy to learn and use as possible. This library is maintained by the [Appwrite team](https://appwrite.io).

Although the library was built for the [Utopia Framework](https://github.com/utopia-php/framework) project, it is completely independent, **dependency-free** and can be used with any other PHP project or framework.

## Getting Started

Install using composer:
```bash
composer require utopia-php/logger
```

```php
<?php

require_once '../vendor/autoload.php';

use Utopia\Logger\Adapter\AppSignal;
use Utopia\Logger\Adapter\Raygun;
use Utopia\Logger\Adapter\Sentry;
use Utopia\Logger\Adapter\LogOwl;
use Utopia\Logger\Log;
use Utopia\Logger\Log\Breadcrumb;
use Utopia\Logger\Log\User;
use Utopia\Logger\Logger;

// Prepare log
$log = new Log();
$log->setAction("controller.database.deleteDocument");
$log->setEnvironment("production");
$log->setNamespace("api");
$log->setServer("digitalocean-us-001");
$log->setType(Log::TYPE_WARNING);
$log->setVersion("0.11.5");
$log->setMessage("Document efgh5678 not found");
$log->setUser(new User("efgh5678"));
$log->addBreadcrumb(new Breadcrumb(Log::TYPE_DEBUG, "http", "DELETE /api/v1/database/abcd1234/efgh5678", \microtime(true) - 500));
$log->addBreadcrumb(new Breadcrumb(Log::TYPE_DEBUG, "auth", "Using API key", \microtime(true) - 400));
$log->addBreadcrumb(new Breadcrumb(Log::TYPE_INFO, "auth", "Authenticated with * Using API Key", \microtime(true) - 350));
$log->addBreadcrumb(new Breadcrumb(Log::TYPE_INFO, "database", "Found collection abcd1234", \microtime(true) - 300));
$log->addBreadcrumb(new Breadcrumb(Log::TYPE_DEBUG, "database", "Permission for collection abcd1234 met", \microtime(true) - 200));
$log->addBreadcrumb(new Breadcrumb(Log::TYPE_ERROR, "database", "Missing document when searching by ID!", \microtime(true)));
$log->addTag('sdk', 'Flutter');
$log->addTag('sdkVersion', '0.0.1');
$log->addTag('authMode', 'default');
$log->addTag('authMethod', 'cookie');
$log->addTag('authProvider', 'MagicLink');
$log->addExtra('urgent', false);
$log->addExtra('isExpected', true);

// Sentry
$adapter = new Sentry("[YOUR_SENTRY_DSN]");
$logger = new Logger($adapter);
$logger->addLog($log);

// AppSignal
$adapter = new AppSignal("[YOUR_APPSIGNAL_KEY]");
$logger = new Logger($adapter);
$logger->addLog($log);

// Raygun
$adapter = new Raygun("[YOUR_RAYGUN_KEY]");
$logger = new Logger($adapter);
$logger->addLog($log);

// Log Owl
$adapter = new LogOwl("[YOUR_SERVICE_TICKET]");
$logger = new Logger($adapter);
$logger->addLog($log);

```

### Adapters

Below is a list of supported adapters, and thier compatibly tested versions alongside a list of supported features and relevant limits.

| Adapter | Status |
|---------|---------|
| Sentry | ✅ |
| AppSignal | ✅ |
| Raygun | ✅ |
| Log Owl | ✅ |

` ✅  - supported, 🛠  - work in progress`

## Tests

If you need to install dependencies, run:

```bash
docker run --rm --interactive --tty \
  --volume $PWD:/app \
  composer update --ignore-platform-reqs --optimize-autoloader --no-plugins --no-scripts --prefer-dist
```

To run all unit tests, prepare the `.env` file using `.env.example` as a template. Then, run:

```bash
docker run --rm --interactive --tty --env-file .env \
  --volume $PWD:/app \
  composer test
```

> Make sure to replace `TEST_SENTRY_DSN` with actual keys from Sentry. 

> Make sure to replace `TEST_APPSIGNAL_KEY` with key found in Appsignal -> Project -> App Settings -> Push & deploy -> Push Key

> Make sure to replace `TEST_RAYGUN_KEY` with key found in Raygun -> Project -> Application Settings -> Api Key

To run static code analysis, use the following Psalm command:

```bash
docker run --rm --interactive --tty \
  --volume $PWD:/app \
  composer check
```

## System Requirements

Utopia Framework requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
