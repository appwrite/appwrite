# Database Abstraction Layer

The goal of this library is to serve as an abstraction layer on top of multiple database adapters and allow storage and query of JSON based documents and relationships.

## Adapters

This library will abstract multiple database technologies using the adapter's design patterns. Below is a list of various adapters we should consider to support:
* MariaDB
* MySQL
* Postgres
* MongoDB

## Data Types

This library will support storing and fetching of all common JSON simple and complex [data types](https://restfulapi.net/json-data-types/).

### Simple Types

* String
* Integer
* Float
* Boolean
* Null

### Complex Types
* Array
* Object
* Relationships
  * Reference (collection / document)
  * References (Array of - collection / document)

Databases that don't support the storage of complex data types should store them as strings and parse them correctly when fetched.

## Persistency

Each database adapter should support the following action for fast storing and retrieval of collections of documents.

**Databases** (Schemas for MariaDB)
* create
* delete

**Collections** (Tables for MariaDB)
* createCollection($name)
* deleteCollection($name)

**Attributes** (Table columns for MariaDB)
* createAttribute(string $collection, string $name, string $type)
* deleteAttribute(string $collection, string $name)

**Indices** (Table indices for MariaDB)
* createIndex(string $collection, string $name, string $type)
* deleteIndex(string $collection, string $name, string $type)

**Documents** (Table rows columns for MariaDB)
* getDocument(string $collection, $id)
* createDocument(string $collection, array $data)
* updateDocument(string $collection, $id, array $data)
* deleteDocument(string $collection, $id)

## Queries

Each database adapter should allow querying simple and advanced queries in consideration of underline limitations.

Method for quering data:
* find(string $collection, $filters)
* findFirst(string $collection, $filters)
* findLast(string $collection, $filters)
* count(string $collection, $filters)

### Supported Query Operations
* Equal (==)
* Not Equal (!=)
* Less Than (<)
* Less or equal (<=)
* Bigger Than (>)
* Bigger or equal (>=)
* Containes / In
* Is Null
* Is Empty

### Joins / Relationships

## Paging

Each database adapter should support two methods for paging. The first method is the classic `Limit and Offset`. The second method is `After` paging, which allows better performance at a larger scale.

## Orders

Multi-column support, order type, use native types

## Features

> Each database collection should hold parallel tables (if needed) with row-level metadata, used to abstract features that are not enabled in all the adapters.

### Row-level Security

### GEO Queries

### Free Search

### Filters

Allow to apply custom filters on specific pre-chosen fields. Avaliable filters:

* Encryption
* JSON (might be redundent with object support)
* Hashing (md5,bcrypt)

## Caching

The library should support memory caching using internal or external memory devices for all read operations. Write operations should actively clean or update the cache.

## Encoding

All database adapters should support UTF-8 encoding and accept emoji characters.

## Documentation

* Data Types
* Operations
* Security
* Benchmarks
* Performance Tips
* Known Limitations

## Tests

* Check for SQL Injections

## Examples (MariaDB)

**Collections Metadata**

```sql
CREATE TABLE IF NOT EXISTS `collections` (
  `_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `_metadata` text() DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Documents**

```sql
CREATE TABLE IF NOT EXISTS `documents_[NAME]` (
  `_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `_uid` varchar(128) NOT NULL AUTO_INCREMENT,
  `custom1` text() DEFAULT NULL,
  `custom2` text() DEFAULT NULL,
  `custom3` text() DEFAULT NULL,
  PRIMARY KEY (`_id`),
  UNIQUE KEY `_index1` (`$id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Documents Authorization**

```sql
CREATE TABLE IF NOT EXISTS `documents_[NAME]_authorization` (
  `_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `_document` varchar(128) DEFAULT NULL,
  `_role` varchar(128) DEFAULT NULL,
  `_action` varchar(128) DEFAULT NULL,
  PRIMARY KEY (`_id`),
  KEY `_index1` (`_document`)
  KEY `_index1` (`_document`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
``` 

## Optimization Tools

https://www.eversql.com/