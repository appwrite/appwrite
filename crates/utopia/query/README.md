# Utopia Query

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/query`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/query) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Queries as data, compiled to SQL (MySQL, MariaDB, PostgreSQL, SQLite, ClickHouse) and MongoDB commands; schema builders; a SQL tokenizer, parser, serializer and statement classifier.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-query = { git = "https://github.com/utopia-rs/query" }
```

Queries are data: a filter, an order, a page or a join is a `Query` you can build in code, receive as JSON from a client, validate, and compile for the database you run. This library turns lists of queries into SQL for MySQL, MariaDB, PostgreSQL, SQLite and ClickHouse, and into MongoDB commands, always with the values as bindings. It also writes DDL, and reads SQL back with a tokenizer, a parser and a classifier.

## Build a select

Start a builder for your dialect, name the table, add queries, and build: the statement has the SQL with `?` placeholders and the values bound to them, in order.

```rust
use utopia_query::Query;
use utopia_query::builder::MySql;

let statement = MySql::new()
    .from("users", "")
    .select(["name", "email"])
    .filter([Query::equal("status", ["active"]), Query::greater_than("age", 18)])
    .sort_asc("name", None)
    .limit(25)
    .build()?;
assert_eq!(
    statement.query,
    "SELECT `name`, `email` FROM `users` WHERE `status` IN (?) AND `age` > ? ORDER BY `name` ASC LIMIT ?"
);
assert_eq!(statement.bindings.len(), 3);
```

## Accept queries from clients

Clients send queries as JSON. Parsing refuses raw SQL unless you allow it, so an untrusted list can only filter, order and page.

```rust
use utopia_query::{Method, Query};

let query = Query::parse(r#"{"method":"lessThan","attribute":"price","values":[100]}"#, false)?;
assert_eq!(query.method(), Method::LessThan);
assert!(Query::parse(r#"{"method":"raw","attribute":"1 = 1"}"#, false).is_err());
```

## Scope every query to a tenant

A hook adds its condition to every statement a builder compiles, so tenancy cannot be forgotten.

```rust
use utopia_query::builder::MySql;
use utopia_query::hook::Tenant;

let statement = MySql::new().from("docs", "").add_hook(Tenant::new(["7"], "tenant_id")?)?.build()?;
assert_eq!(statement.query, "SELECT * FROM `docs` WHERE tenant_id IN (?)");
```

## Create a table

A schema writes the DDL of the dialect: describe the table, then create it.

```rust
use utopia_query::schema::{MySql, Schema};

let mut users = MySql::new().table("users");
users.id("id");
users.string("email", 255);
assert_eq!(
    users.create()?.query,
    "CREATE TABLE `users` (`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, `email` VARCHAR(255) NOT NULL, PRIMARY KEY (`id`))"
);
```

## Tell reads from writes

A classifier says whether a statement reads, writes or controls a transaction, to route it to a replica or the primary. `classify` reads a raw wire protocol message; `classify_sql` reads the SQL text.

```rust
use utopia_query::Type;
use utopia_query::classifier::{MySql, Sql};

assert_eq!(MySql.classify_sql(b"SELECT * FROM users"), Type::Read);
assert_eq!(MySql.classify_sql(b"UPDATE users SET name = 'a'"), Type::Write);
```

## Contributing

Development happens in [appwrite/appwrite](https://github.com/appwrite/appwrite), where this crate lives at [`crates/utopia/query`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/query). Run these commands from a checkout of it. The [contributing guide](https://utopia-docs.appwrite.network/contributing) covers each step.

### Test

```bash
cargo test -p utopia-query
cargo clippy -p utopia-query --all-targets -- -D warnings
cargo fmt --all --check
```

### Compare with PHP

Replay the results PHP recorded for every case against Rust, without PHP:

```bash
COMPAT_LIB=query cargo test -p compat
```

Run every case on both runtimes, fuzz both with generated inputs, then run the whole conversion check. These need the dev stack (`docker compose up -d`):

```bash
bin/compat run query
bin/compat fuzz query
bin/compat check query
```

### Benchmark

```bash
bin/compat bench query
```

This times every benchmark on PHP and on a release build of Rust, both in containers on the dev stack. It fails if Rust is slower. Add `--write` to record the run in [`tests/compat/query/bench.json`](https://github.com/appwrite/appwrite/blob/main/tests/compat/query/bench.json). Run it on a quiet machine, one library at a time.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
