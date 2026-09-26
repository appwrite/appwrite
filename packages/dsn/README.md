# Utopia DSN

> [!IMPORTANT]
> This repository is a read-only mirror of [`packages/dsn`](https://github.com/appwrite/appwrite/tree/main/packages/dsn) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/dsn.svg)
[![Discord](https://img.shields.io/discord/564160730845151244)](https://appwrite.io/discord)

Utopia DSN library is simple and lite library for parsing and managing Data Source Names or DSNs. This library aims to be as simple and easy to learn and use as possible. This library is maintained by the [Appwrite team](https://appwrite.io).

Although the library was built for the [Utopia Framework](https://github.com/utopia-php/framework) project, it is completely independent, **dependency-free** and can be used with any other PHP project or framework.

## Getting Started

Install using composer:
```bash
composer require utopia-php/dsn
```

```php
<?php

require_once '../vendor/autoload.php';

$dsn = new DSN('mariadb://user:password@localhost:3306/database?charset=utf8&timezone=UTC');
$scheme = $dsn->getScheme(); // mariadb
$user = $dsn->getUser(); // user
$password = $dsn->getPassword(); // password
$host = $dsn->getHost(); // localhost
$port = $dsn->getPort(); // 3306
$path = $dsn->getPath(); // database
$query = $dsn->getQuery(); // charset=utf8&timezone=UTC
$charset = $dsn->getParam('charset') // utf8
$timezone = $dsn->getParam('timezone') // UTC
```

## Tests

```bash
composer test
```

## System Requirements

Utopia DSN requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)