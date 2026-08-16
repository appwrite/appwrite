# utopia-query

SQL/Mongo query builder, AST, schema, and classifiers for Utopia. Rust port of [utopia-php/query](https://github.com/utopia-php/query) (`c334515035a2`).

Fluent builders emit parameterized SQL (or MongoDB command JSON) for MySQL, MariaDB, PostgreSQL, SQLite, ClickHouse, and MongoDB. A serializable [`Query`](src/query.rs) value object, tokenizer, AST parser/walker/serializers, DDL schema builder, wire-protocol classifiers, and hooks match the PHP library.

## Install

```toml
utopia-query = { path = "../utopia-query" }
```

## Usage

```rust
use utopia_query::builder::MySql;
use utopia_query::query::Query;

let mut b = MySql::new();
b.select(["name", "email"])
    .from_table("users")
    .filter([
        Query::equal("status", ["active"]),
        Query::greater_than("age", 18),
    ])
    .sort_asc("name", None)
    .limit(25)
    .offset(0);
let stmt = b.build().unwrap();
assert!(stmt.query.contains("FROM `users`"));
assert_eq!(stmt.bindings.len(), 4);
```

Parse a JSON query (raw SQL is rejected unless `parse_allow_raw`):

```rust
use utopia_query::Query;

let q = Query::parse(r#"{"method":"equal","attribute":"name","values":["Ada"]}"#).unwrap();
assert_eq!(q.get_attribute(), "name");
```

## API Reference

### `Query`

PHP `Utopia\Query\Query`.

| Method | Description |
|--------|-------------|
| `new` / `new_method` | Construct from `Method` or method name string. |
| `parse` / `parse_allow_raw` | Parse JSON. Raw methods rejected unless `allow_raw`. |
| `parse_query` / `parse_queries` | Parse a decoded object or list of JSON strings. |
| `fingerprint` / `shape` | Value-free MD5 shape hash (logical children sorted). |
| `to_array` / `to_string` / `compile` | Serialize or compile via a `Compiler`. |
| `equal` / `not_equal` / `less_than` / `greater_than` / … | Filter factories. |
| `contains` / `contains_string` / `contains_any` / `contains_all` | Containment. |
| `between` / `search` / `regex` / `is_null` / `exists` | More filters. |
| `order_asc` / `order_desc` / `order_random` / `limit` / `offset` / `page` | Order and pagination. |
| `and` / `or` / `elem_match` / `having` | Nested queries. |
| `count` / `sum` / `avg` / `min` / `max` / `group_by` / `group_by_time_bucket` | Aggregates. |
| `join` / `left_join` / `right_join` / `cross_join` / `union` | Joins and set ops. |
| `json_contains` / `json_path` / spatial / vector helpers | JSON, GIS, vectors. |
| `raw` / `merge` / `diff` / `validate` / `group_by_type` | Utilities. |

PHP default arguments are extra Rust wrappers (`join_eq`, `count_star`, `raw_sql`, `page_default`) where omitting args would be ambiguous.

### `Method`

PHP backed enum. `as_str()` is the camelCase JSON value (`equal`, `notEqual`, …). Classifiers: `is_filter`, `is_spatial`, `is_vector`, `is_json`, `is_nested`, `is_aggregate`, `is_join`, `sql_function`.

### `Builder` / dialects

`MySql::new()`, `MariaDb::new()`, `PostgreSql::new()`, `Sqlite::new()`, `ClickHouse::new()`, `MongoDb::new()` return a `Builder` with the matching `DialectKind`.

| Method | Description |
|--------|-------------|
| `from` / `from_table` / `from_sub` / `select` / `distinct` / `filter` / `queries` | SELECT surface. |
| `sort_asc` / `sort_desc` / `limit` / `offset` / `page` / `fetch` | Order and pagination. |
| `count` / `sum` / `avg` / `group_by` / `having` / `join` / `left_join` | Aggregates and joins. |
| `into_table` / `set` / `set_pairs` / `insert` / `update` / `delete` / `upsert` | Writes. |
| `with` / `with_recursive` / `union` / `union_all` / `intersect` / `except` | CTEs and set ops. |
| `where_raw` / `select_raw` / `select_cast` / `order_by_raw` / `having_raw` | Raw SQL fragments. |
| `join_lateral` / `distinct_on` / `limit_by` / `prewhere` / `sample` / `hint` | Dialect extras. |
| `build` / `to_raw_sql` / `explain` / `get_bindings` / `reset` | Compile. |
| `force_index` / `lock` / `lock_of` / `returning` / `begin` / `commit` | Locking and transactions. |

Identifiers are quoted per dialect (`` ` `` vs `"`). Bindings are `?` placeholders. MongoDB `build()` emits command JSON (`operation: find`, …).

### AST / tokenizer / serializer

`Tokenizer` (plus `mysql()` / `postgres()` / `sqlite()` / `clickhouse()`) → `Token` list. `Tokenizer::filter` drops whitespace/comments. `Parser::parse` builds a `Select` tree. `Walker` + `Visitor` rewrite nodes. `Serializer` (`mysql()`, `postgres()`, …) emits SQL.

### Schema

`Schema::mysql()` (and other dialects) → `table(name).column(...).create()`. `ColumnType`, `IndexType`, `ForeignKeyAction` match PHP enums.

### Classifier

`SqlClassifier`, `MysqlClassifier`, `PostgresClassifier`, `MongodbClassifier` implement `Classifier::classify` → `Type` (`read`, `write`, `transaction_begin`, …).

### Hooks

`FilterHook`, `AttributeHook`, `JoinFilterHook`, `WriteHook`. Bundled `Tenant` and `AttributeMap`.

### Errors

`QueryError::Exception` / `Validation` / `Unsupported` with PHP messages (`get_message()`, `get_code()`, `get_previous()`). String codes coerce like PHP `(int)` / `0`.

## Intentional Rust deviations

- Fluent methods take `&mut self` and return `&mut Self` (PHP returns `$this`). Dialect constructors return a shared `Builder` tagged with `DialectKind` instead of PHP inheritance.
- `Query` values use `QueryValue` instead of PHP `mixed`. Nested queries are `QueryValue::Query`.
- Closures (`when`, executors, before/after build) use `FnOnce` / `Arc<dyn Fn>`.
- Subqueries are cloned when attached (`from_sub`), not PHP object identity.
- Live MySQL/Postgres/ClickHouse/Mongo probes always hit `docker-compose.test.yml` (ports `8706` / `8701` / `8124` / `27018`).

## Tests

```bash
cargo test -p utopia-query
```

Ports PHP unit tests for `Query`, `Method`, exceptions, parse/helpers, MySQL compile/build hot paths, tokenizer/parser, classifiers, and schema CREATE. Integration probes always hit the compose MySQL/Postgres/ClickHouse/Mongo ports.

## Benchmarks

```bash
cargo bench --manifest-path crates/utopia-query/Cargo.toml
```

Hot paths: JSON `Query::parse`, MySQL `build()`, tokenize.
