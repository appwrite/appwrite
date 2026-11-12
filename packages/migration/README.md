# Utopia Migration

> [!IMPORTANT]
> This repository is a read-only mirror of [`packages/migration`](https://github.com/appwrite/appwrite/tree/main/packages/migration) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/migration.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Migration is a simple and lite library to migrate and transform resources in-between services. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Getting Started

Install using composer:
```bash
composer require utopia-php/migration
```

Init in your application:
```php
<?php

use Utopia\Migration\Destinations\Local;
use Utopia\Migration\Resource;
use Utopia\Migration\Sources\NHost;
use Utopia\Migration\Transfer;

require_once __DIR__ . '/vendor/autoload.php';

// Initialize your Source
$source = new NHost('subdomain', 'eu-central-1', 'admin-secret', 'database-name', 'username', 'password');

// Initialize your Destination
$destination = new Local(__DIR__ . '/backup');

// Initialize Transfer
$transfer = new Transfer($source, $destination);

// Transfer the resources you want
$transfer->run(
    [Resource::TYPE_USER],
    function (array $resources) {
        foreach ($resources as $resource) {
            echo $resource->getName() . ' ' . $resource->getId() . ': ' . $resource->getStatus() . PHP_EOL;
        }
    }
);
```

The Appwrite destination writes to the destination project's databases as well as its API, so it also takes the project's database handles; see `Destinations\Appwrite::__construct()`.

## Supported Resources Chart

Sources:
|          | Auth | Databases | Storage | Functions | Settings |
|----------|-------|-----------|-------|-----------|-----------|
| Appwrite |   ✅   |     ✅     |     ✅     |   ✅   |          |
| Supabase |   ✅   |     ✅     |     ✅     |       |           |
| NHost    |   ✅   |     ✅     |     ✅     |       |           |
| Firebase |   ✅   |     ✅     |     ✅     |       |           |

Destinations:
|          | Auth | Databases | Storage | Functions | Settings |
|----------|-------|-----------|-------|-----------|-----------|
| Appwrite |   ✅   |     ✅     |   ✅   |     ✅     |          |
| Local    |   ✅   |     ✅     |   ✅   |     ✅     |     ✅     |

> **Warning**
> The Local destination should be used for testing purposes only. It is not recommended to use this destination in production or as a backup. The local destination is there to confirm that a source is working correctly and to test the migration process with needing a target destination instance. This may change in the future however as the library matures.



## System Requirements

Utopia Migration requires PHP 8.5 or later. We recommend using the latest PHP version whenever possible.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
