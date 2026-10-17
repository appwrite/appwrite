# Utopia Audit

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/audit`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/audit) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Audit trails: who did what to which resource, from where and when, stored in a utopia-database collection or a ClickHouse table, and read back by user, resource, event, time and custom queries.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-audit = { git = "https://github.com/utopia-rs/audit" }
```

An audit trail records who did what to which resource, from where and when: a user updating a document, an API key deleting a bucket. This library writes those records (`Log`s) through an adapter and reads them back by user, by resource, by event and time window, or with any query. The Database adapter keeps them in a `utopia-database` collection; the ClickHouse adapter in a ClickHouse table built for analytics, with actor, project, geo, SDK and user-agent columns. Both read and write what the PHP library does, so PHP and Rust services share one trail. Adapters are async, so the examples run with `utopia_async::run`.

## Record and read logs

`Audit::log` records one event; `logs_by_user`, `logs_by_resource` and their `_and_events` variants read them back, newest first, a page at a time (`Page`: 25 from the start by default), optionally within a time window (`Range`). Here the Database adapter runs on the in-memory database adapter.

```rust
use std::sync::Arc;

use utopia_audit::{Audit, Page, Range, adapter};
use utopia_cache::{Cache, None as NoCache};
use utopia_database::adapter::memory::Memory;
use utopia_database::Database;

utopia_async::Builder::current_thread().build()?.block_on(async {
    let db = Database::new(Arc::new(Memory::new()), Arc::new(Cache::from_dyn(Arc::new(NoCache))));
    db.set_database("audit");
    db.create(None).await?;

    let audit = Audit::new(adapter::Database::new(db));
    audit.setup().await?;
    for (event, resource) in [("documents.create", "database/d1/document/1"), ("documents.update", "database/d1/document/1")] {
        audit.log(Some("ada"), event, resource, "Mozilla/5.0", "203.0.113.7", Default::default()).await?;
    }

    let logs = audit.logs_by_user("ada", Range::default(), Page::default()).await?;
    assert_eq!(logs.len(), 2);
    assert_eq!(logs[0].event(), "documents.update");
    let updates = audit
        .count_logs_by_resource_and_events("database/d1/document/1", &["documents.update"], Range::default(), None)
        .await?;
    assert_eq!(updates, 1);
    Ok::<_, Box<dyn std::error::Error>>(())
})?;
```

## Import logs in a batch, with their times

`log_batch` writes many logs at once, each with its own `time` (any date string PHP's `DateTime` reads). A `Range` keeps the logs between two dates; `between` is inclusive when both ends are given.

```rust
use std::sync::Arc;

use php_std::{Array, Value};
use utopia_audit::{Audit, DateTime, Page, Range, adapter};
use utopia_cache::{Cache, None as NoCache};
use utopia_database::adapter::memory::Memory;
use utopia_database::Database;

fn log(event: &str, time: &str) -> Array {
    let json = serde_json::json!({
        "userId": "ada", "event": event, "resource": "user/ada",
        "userAgent": "Mozilla/5.0", "ip": "203.0.113.7", "time": time, "data": {"source": "import"},
    });
    match Value::from_json(&json) {
        Value::Array(log) => log,
        _ => unreachable!(),
    }
}

utopia_async::Builder::current_thread().build()?.block_on(async {
    let db = Database::new(Arc::new(Memory::new()), Arc::new(Cache::from_dyn(Arc::new(NoCache))));
    db.set_database("audit");
    db.create(None).await?;
    let audit = Audit::new(adapter::Database::new(db));
    audit.setup().await?;

    audit
        .log_batch(vec![
            log("users.create", "2024-06-15 12:00:00"),
            log("users.update", "2024-06-15 13:00:00"),
            log("users.delete", "2024-06-15 14:00:00"),
        ])
        .await?;

    let utc = php_std::datetime::TzInfo::utc();
    let after = DateTime::new(b"2024-06-15 12:30:00", None, &utc)?;
    let before = DateTime::new(b"2024-06-15 13:30:00", None, &utc)?;
    let range = Range { after: Some(&after), before: Some(&before) };
    let logs = audit.logs_by_user("ada", range, Page::default()).await?;
    assert_eq!(logs.len(), 1);
    assert_eq!(logs[0].event(), "users.update");
    assert_eq!(logs[0].time(), "2024-06-15T13:00:00.000+00:00");
    Ok::<_, Box<dyn std::error::Error>>(())
})?;
```

## Find logs with queries

`find` and `count` take queries: `query::equal` (one value or a list), `query::less_than`, `query::greater_than` and `query::between` are audit's lenient factories, and every other one is `Query`'s own (`Query::limit`, `Query::order_asc`, `Query::contains_string`, ...). `count` ignores limits, offsets and cursors, and with a `max` stops counting there.

```rust
use std::sync::Arc;

use utopia_audit::{Audit, Query, adapter, query};
use utopia_cache::{Cache, None as NoCache};
use utopia_database::adapter::memory::Memory;
use utopia_database::Database;

utopia_async::Builder::current_thread().build()?.block_on(async {
    let db = Database::new(Arc::new(Memory::new()), Arc::new(Cache::from_dyn(Arc::new(NoCache))));
    db.set_database("audit");
    db.create(None).await?;
    let audit = Audit::new(adapter::Database::new(db));
    audit.setup().await?;
    for event in ["teams.create", "teams.update", "users.create"] {
        audit.log(Some("ada"), event, "team/t1", "curl/8", "203.0.113.7", Default::default()).await?;
    }

    let teams = audit
        .find(&[query::equal("userId", "ada"), Query::starts_with("event", "teams."), Query::limit(10)])
        .await?;
    assert_eq!(teams.len(), 2);
    assert_eq!(audit.count(&[Query::equal("event", ["teams.create", "users.create"])], None).await?, 2);
    assert_eq!(audit.count(&[], Some(1)).await?, 1);
    Ok::<_, Box<dyn std::error::Error>>(())
})?;
```

## Keep logs in ClickHouse

The ClickHouse adapter talks to ClickHouse's HTTP interface (port 8123 by default). It validates its host, port and names when they are set; `setup` creates the table (`<namespace>_audits` in the database, which must exist), with a TTL when a retention is set. With shared tables, rows carry the tenant and every read, count and cleanup is limited to the current one. Schema columns a log leaves out are taken from its `data`, and `resourceType`, `resourceId` and `resourceParent` are parsed from its resource.

```rust
use utopia_audit::adapter::clickhouse::{ClickHouse, Options};
use utopia_audit::{Audit, Page, Range};

utopia_async::run(async {
    let options = Options { password: "clickhouse".into(), ..Options::default() };
    let mut adapter = ClickHouse::new("localhost", options)?;
    adapter.set_database("analytics")?.set_namespace("project_1")?.set_retention(Some(90))?;
    adapter.set_shared_tables(true).set_tenant(Some(1));
    assert_eq!(adapter.table(), "audits");

    let audit = Audit::new(adapter);
    audit.setup().await?;
    let logs = audit.logs_by_user("ada", Range::default(), Page { limit: 10, ..Page::default() }).await?;
    println!("{} logs", logs.len());
    Ok::<_, Box<dyn std::error::Error>>(())
})?;
```

## Split a resource path

Resources are paths of `<type>/<id>` pairs. `schema::parse_resource` splits one into its last id, its type and the parent path, as the ClickHouse adapter does for every log; anything else is an id alone.

```rust
use utopia_audit::schema::parse_resource;

let document = parse_resource("database/d1/collection/c1/document/42");
assert_eq!((document.kind, document.id, document.parent), ("document", "42", "database/d1/collection/c1"));
assert_eq!(parse_resource("user/ada").parent, "");
assert_eq!(parse_resource("odd/path/here").id, "odd/path/here");
```

## How it is built

`Audit<A>` is generic over its `Adapter`, so calls dispatch statically; `Audit<Box<dyn Adapter>>` (or `Arc`, or `&A`) takes one chosen at run time. Implement `Adapter` to keep logs anywhere else. The SQL adapters share their schema through `schema::Sql`: attribute and index definitions (utopia-database's `Attribute` and `Index` models, as PHP's database 8 schema), and the column definition of each backend.

The ClickHouse adapter is generic over its transport, any `utopia_client::Adapter`: the default is a `utopia_client::Client` over `Http`, with PHP's 30 second timeout, redirects followed and connections reused. It builds its statements with `utopia-query`'s ClickHouse builder and schema, with typed `{paramN:Type}` parameters sent as multipart form fields (`utopia_client::multipart`), and inserts batches as `JSONEachRow` bodies, byte for byte as PHP sends them.

Logs are `Log`s: the attributes as the adapter returned them (PHP arrays of `php_std::Value`s, in order), with a getter for each known one.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
