# Utopia Abuse

[![Build Status](https://travis-ci.org/utopia-php/abuse.svg?branch=master)](https://travis-ci.com/utopia-php/abuse)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/abuse.svg)
[![Discord](https://img.shields.io/discord/564160730845151244)](https://appwrite.io/discord)

Utopia framework abuse library is simple and lite library for managing application usage limits. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free, and can be used as standalone with any other PHP project or framework.

## Getting Started

Install using composer:

```bash
composer require utopia-php/abuse
```

**Time Limit Abuse**

The time limit abuse allow each key (action) to be performed [X] times in given time frame.
This adapter uses a MySQL / MariaDB to store usage attempts. Before using it create the table schema as documented in this repository (./data/schema.sql)

### Database adapter

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Database;

$dbHost = '127.0.0.1';
$dbUser = 'travis';
$dbPass = '';
$dbPort = '3306';

$pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, [
    PDO::ATTR_TIMEOUT => 3, // Seconds
    PDO::ATTR_PERSISTENT => true,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => true,
    PDO::ATTR_STRINGIFY_FETCHES => true,
]);

$db = new Database(new MySQL($pdo), new Cache(new NoCache()));
$db->setNamespace('namespace');

// Limit login attempts to 10 time in 5 minutes time frame
$adapter    = new TimeLimit('login-attempt-from-{{ip}}', 10, (60 * 5), $db);

$adapter->setup(); //setup database as required
$adapter->setParam('{{ip}}', '127.0.0.1')
;

$abuse      = new Abuse($adapter);

// Use vars to resolve adapter key

if($abuse->check()) {
    throw new Exception('Service was abused!'); // throw error and return X-Rate limit headers here
}
```

### Appwrite TablesDB adapter

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit\Appwrite\TablesDB as TablesDBAdapter;
use Appwrite\Client;

$client = (new Client())
    ->setEndpoint('[YOUR_ENDPOINT]')
    ->setProject('[YOUR_PROJECT_ID]')
    ->setKey('[YOUR_API_KEY]');
$databaseId = 'abuse';

// Limit login attempts to 10 time in 5 minutes time frame
$adapter = new TablesDBAdapter('login-attempt-from-{{ip}}', 10, (60 * 5), $client, $databaseId);

$adapter->setup(); //setup database as required
$adapter->setParam('{{ip}}', '127.0.0.1');

$abuse = new Abuse($adapter);

// Use vars to resolve adapter key

if($abuse->check()) {
    throw new Exception('Service was abused!'); // throw error and return X-Rate limit headers here
}
```

**ReCaptcha Abuse**

The ReCaptcha abuse controller is using Google ReCaptcha service to detect when service is being abused by bots.
To use this adapter you need to create an API key from the Google ReCaptcha service [admin console](https://www.google.com/recaptcha/admin).

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\ReCaptcha;

// Limit login attempts to 10 time in 5 minutes time frame
$adapter    = new ReCaptcha('secret-api-key', $_POST['g-recaptcha-response'], $_SERVER['REMOTE_ADDR']);
$abuse      = new Abuse($adapter);

if($abuse->check()) {
    throw new Exception('Service was abused!'); // throw error and return X-Rate limit headers here
}
```

*Notice: The code above is for example purpose only. It is always recommended to validate user input before using it in your code. If you are using a load balancer or any proxy server you might need to get user IP from the HTTP_X_FORWARDE‌​D_FOR header.*

## System Requirements

Utopia Framework requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
