# Utopia Abuse

> [!IMPORTANT]
> This repository is a read-only mirror of [`packages/abuse`](https://github.com/appwrite/appwrite/tree/main/packages/abuse) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

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
This adapter uses a MySQL / MariaDB to store usage attempts. Before using it, call `$adapter->setup()` once to create the collection it stores attempts in.

### Database adapter

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\Abuse\Adapter\TimeLimit\Database as TimeLimit;
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
$adapter = new TimeLimit('login-attempt-from-{{ip}}', 10, 60 * 5, $db);
$adapter->setup(); // Setup database as required

// withParams() returns an immutable copy with the key resolved
$result = $adapter->withParams(['{{ip}}' => '127.0.0.1'])->check();

header('X-RateLimit-Limit: ' . $result->limit);
header('X-RateLimit-Remaining: ' . $result->remaining);
header('X-RateLimit-Reset: ' . $result->reset);

if ($result->limited) {
    throw new Exception('Service was abused!');
}
```

### Appwrite TablesDB adapter

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Appwrite\Client;
use Utopia\Abuse\Adapter\TimeLimit\Appwrite\TablesDB;

$client = new Client()
    ->setEndpoint('[YOUR_ENDPOINT]')
    ->setProject('[YOUR_PROJECT_ID]')
    ->setKey('[YOUR_API_KEY]');
$databaseId = 'abuse';

// Limit login attempts to 10 time in 5 minutes time frame
$adapter = new TablesDB('login-attempt-from-{{ip}}', 10, 60 * 5, $client, $databaseId);
$adapter->setup(); // Setup database as required

$result = $adapter->withParams(['{{ip}}' => '127.0.0.1'])->check();

if ($result->limited) {
    throw new Exception('Service was abused!');
}
```

**ReCaptcha Abuse**

The ReCaptcha abuse controller is using Google ReCaptcha service to detect when service is being abused by bots.
To use it you need to create an API key from the Google ReCaptcha service [admin console](https://www.google.com/recaptcha/admin).

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\Abuse\ReCaptcha;

$recaptcha = new ReCaptcha('secret-api-key');

// verify() returns true when the token belongs to a human scoring at least 0.5
if (!$recaptcha->verify($_POST['g-recaptcha-response'], $_SERVER['REMOTE_ADDR'])) {
    throw new Exception('Service was abused!');
}
```

*Notice: The code above is for example purpose only. It is always recommended to validate user input before using it in your code. If you are using a load balancer or any proxy server you might need to get user IP from the HTTP_X_FORWARDE‌​D_FOR header.*

## System Requirements

Utopia Abuse requires PHP 8.5 or later. We recommend using the latest PHP version whenever possible.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
