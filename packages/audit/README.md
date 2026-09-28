# Utopia Audit

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/audit`](https://github.com/utopia-php/monorepo/tree/main/packages/audit) — please open issues and pull requests there.

[![Build Status](https://travis-ci.org/utopia-php/audit.svg?branch=master)](https://travis-ci.com/utopia-php/audit)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/audit.svg)
[![Discord](https://img.shields.io/discord/564160730845151244)](https://appwrite.io/discord)

Utopia framework audit library is simple and lite library for managing application user logs. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free, and can be used as standalone with any other PHP project or framework.

## Features

- **Adapter Pattern**: Support for multiple storage backends through adapters
- **Default Database Adapter**: Built-in support for utopia-php/database
- **Extensible**: Easy to create custom adapters for different storage solutions
- **Batch Operations**: Support for logging multiple events at once
- **Query Support**: Rich querying capabilities for retrieving logs

## Getting started

Install using Composer:
```bash
composer require utopia-php/audit
```

## Usage

### Using the database adapter (default)

The simplest way to use Utopia Audit is with the built-in Database adapter:

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use PDO;
use Utopia\Audit\Audit;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Database;
use Utopia\Audit\Adapter\Database as DatabaseAdapter;

$dbHost = '127.0.0.1';
$dbUser = 'travis';
$dbPass = '';
$dbPort = '3306';

$pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, [
    PDO::ATTR_TIMEOUT => 3,
    PDO::ATTR_PERSISTENT => true,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => true,
    PDO::ATTR_STRINGIFY_FETCHES => true,
]);
        
$cache = new Cache(new NoCache());

$database = new Database(new MySQL($pdo), $cache);
$database->setNamespace('namespace');

// Create audit instance with Database adapter
$audit = new Audit(new DatabaseAdapter($database));
$audit->setup();
```

### Using a custom adapter

You can create custom adapters by extending the `Utopia\Audit\Adapter` abstract class:

```php
<?php

use Utopia\Audit\Audit;
use Utopia\Audit\Adapter\Database as DatabaseAdapter;
use Utopia\Database\Database;

// Using the Database adapter directly
$adapter = new DatabaseAdapter($database);
$audit = new Audit($adapter);
```

### Basic operations

**Create Log**

A simple example for logging a user action in the audit DB.

```php
$userId = 'user-unique-id';
$event = 'deleted'; // Log specific action name
$resource = 'database/document-1'; // Resource unique ID (great for filtering specific logs)
$userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.88 Safari/537.36'; // Set user-agent
$ip = '127.0.0.1'; // User IP
$data = ['key1' => 'value1','key2' => 'value2']; // Any key-value pair you need to log

$audit->log($userId, $event, $resource, $userAgent, $ip, $data);
```

**Get Logs By User**

Fetch all logs by given user ID with optional filtering parameters:

```php
// Basic usage
$logs = $audit->getLogsByUser('userId');

// With pagination
$logs = $audit->getLogsByUser(
    userId: 'userId',
    limit: 10,
    offset: 0
);

// With time filtering using DateTime objects
$logs = $audit->getLogsByUser(
    userId: 'userId',
    after: new \DateTime('2024-01-01 00:00:00'),
    before: new \DateTime('2024-12-31 23:59:59'),
    limit: 25,
    offset: 0,
    ascending: false  // false = newest first (default), true = oldest first
);
```

**Get Logs By User and Action**

Fetch all logs by given user ID and specific event names with optional filtering:

```php
// Basic usage
$logs = $audit->getLogsByUserAndEvents(
    userId: 'userId',
    events: ['update', 'delete']
);

// With time filtering and pagination
$logs = $audit->getLogsByUserAndEvents(
    userId: 'userId',
    events: ['update', 'delete'],
    after: new \DateTime('-7 days'),
    before: new \DateTime('now'),
    limit: 50,
    offset: 0,
    ascending: false
);
```

**Get Logs By Resource**

Fetch all logs by a given resource name with optional filtering:

```php
// Basic usage
$logs = $audit->getLogsByResource('database/document-1');

// With time filtering and pagination
$logs = $audit->getLogsByResource(
    resource: 'database/document-1',
    after: new \DateTime('-30 days'),
    before: new \DateTime('now'),
    limit: 100,
    offset: 0,
    ascending: true  // Get oldest logs first
);
```

**Batch Logging**

Log multiple events at once for better performance:

```php
use Utopia\Database\DateTime;

$events = [
    [
        'userId' => 'user-1',
        'event' => 'create',
        'resource' => 'database/document/1',
        'userAgent' => 'Mozilla/5.0...',
        'ip' => '127.0.0.1',
        'location' => 'US',
        'data' => ['key' => 'value'],
        'time' => DateTime::now()
    ],
    [
        'userId' => 'user-2',
        'event' => 'update',
        'resource' => 'database/document/2',
        'userAgent' => 'Mozilla/5.0...',
        'ip' => '192.168.1.1',
        'location' => 'UK',
        'data' => ['key' => 'value'],
        'time' => DateTime::now()
    ]
];

$documents = $audit->logBatch($events);
```

**Counting Logs**

All retrieval methods have corresponding count methods with the same filtering capabilities:

```php
// Count all logs for a user
$count = $audit->countLogsByUser('userId');

// Count logs within a time range
$count = $audit->countLogsByUser(
    userId: 'userId',
    after: new \DateTime('-7 days'),
    before: new \DateTime('now')
);

// Count logs by resource
$count = $audit->countLogsByResource('database/document-1');

// Count logs by user and events
$count = $audit->countLogsByUserAndEvents(
    userId: 'userId',
    events: ['create', 'update', 'delete'],
    after: new \DateTime('-30 days')
);

// Count logs by resource and events
$count = $audit->countLogsByResourceAndEvents(
    resource: 'database/document-1',
    events: ['update', 'delete']
);
```

**Advanced Filtering**

Get logs by resource and specific events:

```php
$logs = $audit->getLogsByResourceAndEvents(
    resource: 'database/document-1',
    events: ['create', 'update'],
    after: new \DateTime('-24 hours'),
    limit: 20,
    offset: 0,
    ascending: false
);
```

### Filtering parameters

All retrieval methods support the following optional parameters:

- **after** (`?\DateTime`): Get logs created after this datetime
- **before** (`?\DateTime`): Get logs created before this datetime  
- **limit** (`int`, default: 25): Maximum number of logs to return
- **offset** (`int`, default: 0): Number of logs to skip (for pagination)
- **ascending** (`bool`, default: false): Sort order - false for newest first, true for oldest first

## Adapters

Utopia Audit uses an adapter pattern to support different storage backends. Currently available adapters:

### Database adapter (default)

The Database adapter uses [utopia-php/database](https://github.com/utopia-php/database) to store audit logs in a database.


### ClickHouse adapter

The ClickHouse adapter uses [ClickHouse](https://clickhouse.com/) for high-performance analytical queries on massive amounts of log data. It communicates with ClickHouse via HTTP interface using Utopia Fetch.

**Features:**
- Optimized for analytical queries and aggregations
- Handles billions of log entries efficiently
- Column-oriented storage for fast queries
- Automatic partitioning by month
- Bloom filter indexes for fast lookups

**Usage:**

```php
<?php

use Utopia\Audit\Audit;
use Utopia\Audit\Adapter\ClickHouse;

// Create ClickHouse adapter
$adapter = new ClickHouse(
    host: 'localhost',
    username: 'default',
    password: '',
    port: 8123
);
$adapter->setDatabase('audit');
$adapter->setTable('audit_logs');

$audit = new Audit($adapter);
$audit->setup(); // Creates database and table

// Use as normal — the ClickHouse adapter requires extra attributes in data
$document = $audit->log(
    userId: 'user-123',
    event: 'document.create',
    resource: 'database/document/1',
    userAgent: 'Mozilla/5.0...',
    ip: '127.0.0.1',
    data: [
        'actorType' => 'member',
        'projectId' => 'proj-1',
        'projectInternalId' => 'proj-int-1',
        'teamId' => 'team-1',
        'teamInternalId' => 'team-int-1',
        'hostname' => 'example.org',
        'key' => 'value',
    ]
);
```

**Performance Benefits:**
- Ideal for high-volume logging (millions of events per day)
- Fast aggregation queries (counts, analytics)
- Efficient storage with compression
- Automatic data partitioning and retention policies

### Creating custom adapters

To create a custom adapter, extend the `Utopia\Audit\Adapter` abstract class and implement all required methods:

```php
<?php

namespace MyApp\Audit;

use Utopia\Audit\Adapter;
use Utopia\Audit\Log;

class CustomAdapter extends Adapter
{
    public function getName(): string
    {
        return 'Custom';
    }

    public function setup(): void
    {
        // Initialize your storage backend (create tables, indexes, etc.)
    }

    public function create(array $log): Log
    {
        // Store a single log entry and return a Log object
    }

    public function createBatch(array $logs): array
    {
        // Store multiple log entries and return array of Log objects
    }

    public function getByUser(
        string $userId,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
        int $limit = 25,
        int $offset = 0,
        bool $ascending = false,
    ): array {
        // Retrieve logs by user ID with optional filtering
    }

    public function countByUser(
        string $userId,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
    ): int {
        // Count logs by user ID with optional time filtering
    }

    public function getByResource(
        string $resource,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
        int $limit = 25,
        int $offset = 0,
        bool $ascending = false,
    ): array {
        // Retrieve logs by resource with optional filtering
    }

    public function countByResource(
        string $resource,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
    ): int {
        // Count logs by resource with optional time filtering
    }

    public function getByUserAndEvents(
        string $userId,
        array $events,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
        int $limit = 25,
        int $offset = 0,
        bool $ascending = false,
    ): array {
        // Retrieve logs by user ID and specific events with optional filtering
    }

    public function countByUserAndEvents(
        string $userId,
        array $events,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
    ): int {
        // Count logs by user ID and events with optional time filtering
    }

    public function getByResourceAndEvents(
        string $resource,
        array $events,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
        int $limit = 25,
        int $offset = 0,
        bool $ascending = false,
    ): array {
        // Retrieve logs by resource and specific events with optional filtering
    }

    public function countByResourceAndEvents(
        string $resource,
        array $events,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
    ): int {
        // Count logs by resource and events with optional time filtering
    }

    public function cleanup(\DateTime $datetime): bool
    {
        // Delete logs older than the specified datetime
        // Return true on success, false otherwise
    }
}
```

Then use your custom adapter:

```php
$adapter = new CustomAdapter();
$audit = new Audit($adapter);
```

## System requirements

Utopia Framework requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
