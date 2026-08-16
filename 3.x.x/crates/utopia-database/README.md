# utopia-database

Application persistence adapters for Utopia. Rust port of [utopia-php/database](https://github.com/utopia-php/database) (PHP SHA [`761050b576d1`](https://github.com/utopia-php/database/commit/761050b576d1)).

Document database API with adapters (Memory, SQL engines, Redis, Mongo, Pool), queries, operators, validators, and helpers (`ID`, `Permission`, `Role`). Default features include **SQLite** (file DB, no container). MySQL, MariaDB, Postgres, and Mongo talk to the compose stack in `docker-compose.test.yml` - the same engines PHP tests against.

## Install

```toml
utopia-database = { path = "../utopia-database" }
```

Optional features: `mysql`, `postgres`, `sqlite`, `mongo`, `redis`.

## Usage

```rust
use utopia_cache::adapter::Memory as CacheMemory;
use utopia_cache::Cache;
use utopia_database::adapter::Memory;
use utopia_database::helpers::{Id, Permission, Role};
use utopia_database::query::Query;
use utopia_database::{Database, Document};

let cache = Cache::new(CacheMemory::new());
let mut db = Database::new(Memory::new(), cache);
db.set_namespace("myapp").unwrap();
db.set_database("myapp").unwrap();
db.create(None).unwrap();
```

## Adapters

| Adapter | PHP name | Feature | Notes |
|---------|----------|---------|-------|
| [`adapter::Memory`](src/adapter/memory.rs) | `Adapter\Memory` | default | In-process. Used by unit tests. |
| [`adapter::Sql`](src/adapter/sql.rs) | `Adapter\SQL` | - | Shared quoting / type-map helpers + PDO wrapper. |
| [`adapter::mysql::Mysql`](src/adapter/mysql.rs) | `Adapter\MySQL` | `mysql` | Compiles with the `mysql` crate. Live I/O env-gated. |
| `adapter::mysql::MariaDb` | `Adapter\MariaDB` | `mysql` | Type alias of `Mysql`. |
| [`adapter::postgres::Postgres`](src/adapter/postgres.rs) | `Adapter\Postgres` | `postgres` | |
| [`adapter::sqlite::Sqlite`](src/adapter/sqlite.rs) | `Adapter\SQLite` | `sqlite` | |
| [`adapter::mongo::Mongo`](src/adapter/mongo.rs) | `Adapter\Mongo` | `mongo` | Mongo is on the PHP not-converting list; adapter still ships. |
| [`adapter::redis_adapter::Redis`](src/adapter/redis_adapter.rs) | `Adapter\Redis` | `redis` | |
| [`adapter::PoolAdapter`](src/adapter/pool.rs) | `Adapter\Pool` | default | [`utopia_pools::Pool`](../utopia-pools). |

## Types

| Type | PHP | Notes |
|------|-----|-------|
| [`Database`](src/database.rs) | `Utopia\Database\Database` | Orchestration: collections, documents, queries, cache, filters. |
| [`Document`](src/document.rs) | `Document` | PHP `ArrayObject` semantics. |
| [`Query`](src/query.rs) | `Query` | Utopia Database queries (not `utopia-php/query`). |
| [`Operator`](src/operator.rs) | `Operator` | Increment, array, string, date operators. |
| [`DateTime`](src/datetime.rs) | `DateTime` | `now`, `format`, `formatTz`. |
| [`Change`](src/change.rs) | `Change` | Old/new document pair. |
| [`Connection`](src/connection.rs) | `Connection` | Lost-connection heuristics. |
| [`Mirror`](src/mirror.rs) | `Mirror` | Dual-write. |
| [`Pdo`](src/pdo.rs) / [`PdoStatement`](src/pdo.rs) | `PDO` / `PDOStatement` | |
| [`Id`](src/helpers/id.rs) | `Helpers\ID` | `unique`, `custom`. |
| [`Permission`](src/helpers/permission.rs) | `Helpers\Permission` | `read`/`create`/`update`/`delete`/`write`, `parse`, `aggregate`. |
| [`Role`](src/helpers/role.rs) | `Helpers\Role` | `any`, `user`, `users`, `team`, `guests`, `label`, `member`. |
| [`Authorization`](src/validator/authorization.rs) | `Validator\Authorization` | Role set + `skip`. |
| [`AttrValue`](src/value.rs) | PHP `mixed` | Null/bool/number/string/array/document/query/operator. |
| [`DatabaseError`](src/error.rs) | `Exception\*` | PHP exception messages. |

## `Database` API

| Method | PHP | Notes |
|--------|-----|-------|
| `new` | `__construct(Adapter, Cache)` | Registers json/datetime/vector/object/spatial filters. Uses [`utopia_cache::Cache`](../utopia-cache). |
| `on` / `before` | `on` / `before` | Event listeners. |
| `silent` | `silent` | Suppresses listeners. |
| `skip_authorization` | `Authorization::skip` | |
| `set_namespace` / `get_namespace` | same | |
| `set_database` / `get_database` | same | |
| `set_cache` / `get_cache` | same | |
| `set_tenant` / `with_tenant` | same | |
| `enable_validation` / `skip_validation` | same | |
| `enable_filters` / `skip_filters` | same | |
| `create` / `exists` / `list` / `delete` | same | `create` also creates `_metadata`. |
| `create_collection` / `update_collection` / `get_collection` / `list_collections` / `delete_collection` | same | |
| `create_attribute` / `update_attribute` / `delete_attribute` / `rename_attribute` | same | |
| `create_index` / `delete_index` / `rename_index` | same | |
| `create_relationship` / `update_relationship` / `delete_relationship` | same | |
| `get_document` / `create_document` / `update_document` / `upsert_document` / `delete_document` | same | Cache + encode/decode. |
| `find` / `find_one` / `foreach` / `count` / `sum` | same | |
| `increase_document_attribute` / `decrease_document_attribute` | same | |
| `encode` / `decode` / `casting` | same | |
| `add_filter` | static `addFilter` | |
| `convert_queries` | same | |
| `get_cache_keys` / `purge_cached_document` | same | |

Constants (`VAR_*`, `INDEX_*`, `PERMISSION_*`, `EVENT_*`, `METADATA`, …) match PHP `Database::*`.

## Validators

PHP `Utopia\Database\Validator\*` is under [`validator`](src/validator): `Key`, `Uid`, `Label`, `Sequence`, `BigInt`, `ByteLength`, `Datetime`, `Spatial`, `Vector`, `ObjectValidator`, `Roles`, `Permissions`, `Authorization`, `Structure`, `PartialStructure`, `Attribute`, `Index`, `IndexDependency`, `Queries`, `IndexedQueries`, `Operator`, query method validators (`Limit`, `Offset`, `Cursor`, `Order`, `Select`, `Filter`), and `Queries\Document` / `Queries\Documents`.

## Deviations from PHP

- Snake_case methods (`get_id`, `create_document`). PHP exception **messages** are preserved.
- `AttrValue` replaces PHP `mixed`. Empty-document `get_id()` is `""` (PHP returns `""`; PHPUnit `assertEquals(null, '')` is loose).
- `Id::unique()` returns `Result<String>`. `Role::user` / `users` take explicit status strings (PHP defaults to `''`).
- `Database` is generic over the adapter (`Database<Memory>`). `Adapter` is not `dyn` because fluent setters return `&mut Self`.
- SQL/Mongo adapters execute live queries. Default `cargo test -p utopia-database` runs Memory + SQLite. `cargo test -p utopia-database --features mysql,postgres,mongo` needs `docker compose -f docker-compose.test.yml up -d`.
- Mongo remains on the family not-converting list; the adapter is still shipped and tested against a container.
- Cache is [`utopia_cache::Cache`](../utopia-cache), not an in-crate stand-in.
- Datetime year bounds use `NaiveDate::year()` instead of PHP's lookbehind regex. Max year is 9999 (cleanup timestamps must stay in range).

## Tests

Default `cargo test -p utopia-database` uses Memory + SQLite. Live MySQL / MariaDB / Postgres / Mongo tests are in `tests/e2e.rs` (`--features mysql,postgres,mongo`) and always hit `docker-compose.test.yml` (`./scripts/e2e-services.sh`).
