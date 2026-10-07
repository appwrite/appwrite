# Non-Blocking PHP Line Protocol Client for MongoDB

[![Build Status](https://travis-ci.com/utopia-php/system.svg?branch=main)](https://travis-ci.com/utopia-php/mongo)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/mongo.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

A non-blocking PHP client based on the line protocol for MongoDB. Designed to work well in async PHP environments like [Swoole](https://github.com/swoole/swoole-src) 
This library is aiming to be as simple and easy to learn and use. 
This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting Started

Install using composer:
```bash
composer require utopia-php/mongo
```

Init in your application:
```php
<?php

$client = new Client('testing', 'mongo', 27017, 'root', 'example', false);
$client->connect();

// drop database
$client->dropDatabase([]);

// Create a new collection
$client->createCollection('movies');

// Get the list of databases
$client->listDatabaseNames()->databases;

// insert a new document
$document = $client->insert('movies', [
        'name' => 'Armageddon 1',
        'country' => 'USA',
        'language' => 'English'
    ]
);

$id = (string)$document['_id'];
// Find Document with ObjectId
$client->find('movies', ['_id' => new ObjectId($id)])->cursor->firstBatch ?? [];

// insert a new document with specific id
$id = 999;
$client->insert('movies', [
        'name' => 'Armageddon 2',
        '_id' => $id,
        'array' => ['USA', 'UK', 'India'],
        'language' => 'English',
        'float' => 9.9,
        'integer' => 9,
        'is_open' => true,
        'date_string' => (new \DateTime())->format('Y-m-d\TH:i:s.vP'),
    ]
);

// Find document by id
$client->find('movies', ['_id' => $id])->cursor->firstBatch ?? [];

// Find documents by field
$client->find('movies', ['name' => 'Armageddon'])->cursor->firstBatch ?? [];

// Delete a document
$client->delete('movies', ['_id' => $id]);

// drop a collections
$client->dropCollection('movies');

```

## System Requirements

Utopia Mongo client requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)