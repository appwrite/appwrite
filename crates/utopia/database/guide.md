A document database over SQL: collections with typed attributes and indexes, documents carrying their own permissions, queries that filter, order and page them, relationships between collections, tenancy and caching. It is the Rust port of `utopia-php/database` 7.4 and reads and writes the same schemas, rows and metadata, so a PHP service and a Rust service can work on one dataset. This guide covers the common tasks, then how the crate is built and what is left to convert.

## Parse the queries of a list request

A request names its queries as JSON strings. `Query::parse` reads one, with PHP's errors for the malformed ones; `to_string` writes it back.

```
use utopia_database::Query;
use utopia_database::query::Method;

let query = Query::parse(r#"{"method":"equal","attribute":"name","values":["Ada","Grace"]}"#)?;
assert_eq!(query.method(), &Method::Equal);
assert_eq!(query.attribute(), "name");
assert_eq!(query.values().len(), 2);
assert_eq!(query.to_string()?, r#"{"method":"equal","attribute":"name","values":["Ada","Grace"]}"#);

let error = Query::parse(r#"{"method":"near","values":[]}"#).unwrap_err();
assert_eq!(error.message(), "Invalid query method: near");
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Database\Query;

$query = Query::parse('{"method":"equal","attribute":"name","values":["Ada","Grace"]}');
echo $query->getMethod(), ' ', $query->getAttribute(), ' ', count($query->getValues()), "\n"; // equal name 2
echo $query->toString(), "\n";

try {
    Query::parse('{"method":"near","values":[]}');
} catch (\Utopia\Database\Exception\Query $e) {
    echo $e->getMessage(), "\n"; // Invalid query method: near
}
```

## Give a document its permissions

Permissions are strings of a type and a role: `read("any")`, `update("user:ada")`. The helpers build them, and `ID::unique()` makes the ids PHP makes.

```
use utopia_database::helpers::{id, permission::Permission, role::Role};
use utopia_database::{Document, Value};

let mut profile = Document::default();
profile.set("$id", Value::from(id::unique()?.as_str()));
profile.set(
    "$permissions",
    Value::Array(
        [Permission::read(&Role::any()), Permission::update(&Role::user("ada", ""))]
            .into_iter()
            .map(|p| Value::from(p.as_str()))
            .collect(),
    ),
);
profile.set("name", Value::from("Ada"));

assert_eq!(profile.id().len(), 20);
assert_eq!(profile.read(), vec![r#"any"#.to_owned()]);
assert_eq!(profile.update(), vec![r#"user:ada"#.to_owned()]);
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

$profile = new Document([
    '$id' => ID::unique(),
    '$permissions' => [Permission::read(Role::any()), Permission::update(Role::user('ada'))],
    'name' => 'Ada',
]);
echo strlen($profile->getId()), "\n"; // 20
echo json_encode($profile->getRead()), ' ', json_encode($profile->getUpdate()), "\n"; // ["any"] ["user:ada"]
```

## Store and find documents

`Database` sits on an adapter (PostgreSQL here) and a cache. It creates the schema, checks every document against its collection before writing it, and checks permissions against the roles of its `Authorization`.

```no_run
use std::sync::Arc;

use utopia_cache::{Cache, Memory};
use utopia_database::adapter::postgres::Postgres;
use utopia_database::query::Method;
use utopia_database::{Authorization, Database, Document, Query, Value};

tokio::runtime::Runtime::new()?.block_on(async {
    let adapter = Postgres::connect("postgres://root:password@localhost:5432/root").await?;
    let db = Database::new(Arc::new(adapter), Arc::new(Cache::from_dyn(Arc::new(Memory::new()))), Vec::new());
    let authorization = Authorization::new();
    authorization.add_role("any");
    db.set_authorization(Arc::new(authorization));
    db.set_database("guide")?.set_namespace("app")?;
    db.create(None).await?;

    let permissions = ["read(\"any\")", "create(\"any\")"].map(String::from).to_vec();
    db.create_collection("people", Vec::new(), Vec::new(), Some(permissions), false).await?;
    db.create_attribute("people", "name", Database::VAR_STRING, 128, true, Value::Null, true, false, None, Default::default(), Vec::new())
        .await?;

    let mut ada = Document::default();
    ada.set("$id", Value::from("ada"));
    ada.set("name", Value::from("Ada"));
    db.create_document("people", ada).await?;

    let found = db
        .find("people", vec![Query::new(Method::Equal, "name", vec![Value::from("Ada")])], Database::PERMISSION_READ)
        .await?;
    assert_eq!(found[0].id(), "ada");
    Ok::<_, Box<dyn std::error::Error>>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

## How the crate is built

- **`Database`** (`database/`) is PHP's `Database` class split by area, one `impl Database` per file: the schema (settings and scopes, filters, cache keys, collections, attributes, indexes, metadata, transactions), documents (reads with the document cache, creates, updates, upserts, increments, deletes, finds, counts, sums, iteration) and relationships (one-to-one, one-to-many, many-to-one, many-to-many: their attributes, junction collections, population, writes, on-delete rules and relationship queries). A `Database` is a cheap clone: clones share the adapter, cache and settings, as PHP's references to one object do. Scoped settings (`skip_relationships`, `silent`, `with_tenant`, ...) take an async closure and restore the setting when it ends or is dropped.
- **Adapters** (`adapter/`) implement the `Adapter` trait: the SQL of one engine, its limits and capabilities. `Postgres` speaks the wire protocol itself and renders statements as PHP's PDO does with emulated prepares, so the server sees the same SQL from both runtimes. `Sqlite` (feature `sqlite`) runs SQLite in process (rusqlite, bundled) and reproduces PDO-sqlite's prepares, bindings, fetches and errors; `Memory` (feature `memory`) keeps collections in PHP arrays, with PHP's array semantics, and journals for transactions. `MariaDB` (feature `mariadb`; with `Flavor::MySQL` and feature `mysql`, PHP's `MySQL` subclass) runs its statements over `mysql_async`'s text protocol, rendered as PDO mysql renders them (mysqlnd's quoting, `mysql_real_query` with multi statements, the server status for `inTransaction()`).
- **Filters** (`database/filters.rs`) transform attribute values on the way to the adapter and back: the built-in `json`, `datetime` and the spatial, vector and object ones, plus any `Filter` registered globally (`Database::add_filter`) or for one database. Their signatures (the PHP closures' `file:line`) are part of document cache keys, so the keys match PHP's.
- **The cache** (`database/cache.rs`) keeps documents and query results under PHP's keys, in a `utopia_cache::Cache`. A Redis cache with PHP's Json or igbinary codec shares its entries (per-projection hash fields and generation leases included) with PHP.
- **Values**: attributes are PHP values (`Value`, `php_std::types::Value` extended with documents, queries and operators), so loose comparisons, key order and number formatting behave as in PHP.
- **Validators** (`validator/`): structure, queries, indexes, permissions, roles, keys, dates, spatial and vector values, each with PHP's messages.

`Query` here is `Utopia\Database\Query`, the database's own query model. `utopia-php/query`, a separate SQL builder converted as the `utopia-query` crate, overlaps with it only in name: utopia-php/database 7.4 does not use it, and when the PHP library builds its SQL with it, the adapters here move onto `utopia-query` with it.

## The phase plan

The conversion goes in three phases, each checked by `bin/compat check database`:

1. **PostgreSQL** (done): `Database` in full, every helper, validator, `Query`, `Operator` and `Document`, and the PostgreSQL adapter, against the library's e2e suites and unit tests on a PostgreSQL server.
2. **The other SQL adapters and Memory** (done): MariaDB and MySQL (the adapters, their e2e suites with and without shared tables, the unit tests of the MySQL adapter and the adapter-level cases), SQLite and the in-memory adapter, and the e2e suites of each (features `mariadb`, `mysql`, `sqlite`, `memory`).
3. **The rest**: MongoDB (with `utopia-php/mongo`), the Redis database adapter, `Pool`, `Mirror` and its `Mirroring` filters, and caches shared with PHP (Redis, with PHP's igbinary entries and hash-aware entries). All but MongoDB are done (features `redis`, `pool`; `Mirror` in `mirror.rs`).
