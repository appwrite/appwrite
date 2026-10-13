An audit trail records who did what to which resource, from where and when: a user updating a document, an API key deleting a bucket. This library writes those records (`Log`s) through an adapter and reads them back by user, by resource, by event and time window, or with any query. The Database adapter keeps them in a `utopia-database` collection; the ClickHouse adapter in a ClickHouse table built for analytics, with actor, project, geo, SDK and user-agent columns. Both read and write what the PHP library does, so PHP and Rust services share one trail. Adapters are async, so the examples run on a Tokio runtime.

## Record and read logs

`Audit::log` records one event; `logs_by_user`, `logs_by_resource` and their `_and_events` variants read them back, newest first, a page at a time (`Page`: 25 from the start by default), optionally within a time window (`Range`). Here the Database adapter runs on the in-memory database adapter.

```
use std::sync::Arc;

use utopia_audit::{Audit, Page, Range, adapter};
use utopia_cache::{Cache, None as NoCache};
use utopia_database::adapter::memory::Memory;
use utopia_database::{Authorization, Database};

tokio::runtime::Builder::new_current_thread().build()?.block_on(async {
    let db = Database::new(Arc::new(Memory::new()), Arc::new(Cache::from_dyn(Arc::new(NoCache))), Vec::new());
    db.set_authorization(Arc::new(Authorization::new()));
    db.set_database("audit")?;
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
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Audit\Adapter\Database as Adapter;
use Utopia\Audit\Audit;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;

$db = new Database(new Memory(), new Cache(new NoCache()));
$db->setDatabase('audit');
$db->create();

$audit = new Audit(new Adapter($db));
$audit->setup();
$audit->log('ada', 'documents.create', 'database/d1/document/1', 'Mozilla/5.0', '203.0.113.7');
$audit->log('ada', 'documents.update', 'database/d1/document/1', 'Mozilla/5.0', '203.0.113.7');

$logs = $audit->getLogsByUser('ada');
echo count($logs), ' ', $logs[0]->getEvent(), "\n"; // 2 documents.update
echo $audit->countLogsByResourceAndEvents('database/d1/document/1', ['documents.update']), "\n"; // 1
```

## Import logs in a batch, with their times

`log_batch` writes many logs at once, each with its own `time` (any date string PHP's `DateTime` reads). A `Range` keeps the logs between two dates; `between` is inclusive when both ends are given.

```
use std::sync::Arc;

use php_std::{Array, Value};
use utopia_audit::{Audit, DateTime, Page, Range, adapter};
use utopia_cache::{Cache, None as NoCache};
use utopia_database::adapter::memory::Memory;
use utopia_database::{Authorization, Database};

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

tokio::runtime::Builder::new_current_thread().build()?.block_on(async {
    let db = Database::new(Arc::new(Memory::new()), Arc::new(Cache::from_dyn(Arc::new(NoCache))), Vec::new());
    db.set_authorization(Arc::new(Authorization::new()));
    db.set_database("audit")?;
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
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Audit\Adapter\Database as Adapter;
use Utopia\Audit\Audit;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;

$db = new Database(new Memory(), new Cache(new NoCache()));
$db->setDatabase('audit');
$db->create();
$audit = new Audit(new Adapter($db));
$audit->setup();

$log = fn (string $event, string $time) => [
    'userId' => 'ada', 'event' => $event, 'resource' => 'user/ada',
    'userAgent' => 'Mozilla/5.0', 'ip' => '203.0.113.7', 'time' => $time, 'data' => ['source' => 'import'],
];
$audit->logBatch([
    $log('users.create', '2024-06-15 12:00:00'),
    $log('users.update', '2024-06-15 13:00:00'),
    $log('users.delete', '2024-06-15 14:00:00'),
]);

$logs = $audit->getLogsByUser('ada', new \DateTime('2024-06-15 12:30:00'), new \DateTime('2024-06-15 13:30:00'));
echo count($logs), ' ', $logs[0]->getEvent(), ' ', $logs[0]->getTime(), "\n";
// 1 users.update 2024-06-15T13:00:00.000+00:00
```

## Find logs with queries

`find` and `count` take queries: `query::equal` (one value or a list), `query::less_than`, `query::greater_than` and `query::between` are audit's lenient factories, and every other one is `Query`'s own (`Query::limit`, `Query::order_asc`, `Query::contains_string`, ...). `count` ignores limits, offsets and cursors, and with a `max` stops counting there.

```
use std::sync::Arc;

use utopia_audit::{Audit, Query, adapter, query};
use utopia_cache::{Cache, None as NoCache};
use utopia_database::adapter::memory::Memory;
use utopia_database::{Authorization, Database};

tokio::runtime::Builder::new_current_thread().build()?.block_on(async {
    let db = Database::new(Arc::new(Memory::new()), Arc::new(Cache::from_dyn(Arc::new(NoCache))), Vec::new());
    db.set_authorization(Arc::new(Authorization::new()));
    db.set_database("audit")?;
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
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Audit\Adapter\Database as Adapter;
use Utopia\Audit\Audit;
use Utopia\Audit\Query;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;

$db = new Database(new Memory(), new Cache(new NoCache()));
$db->setDatabase('audit');
$db->create();
$audit = new Audit(new Adapter($db));
$audit->setup();
foreach (['teams.create', 'teams.update', 'users.create'] as $event) {
    $audit->log('ada', $event, 'team/t1', 'curl/8', '203.0.113.7');
}

$teams = $audit->find([Query::equal('userId', 'ada'), Query::startsWith('event', 'teams.'), Query::limit(10)]);
echo count($teams), "\n"; // 2
echo $audit->count([Query::equal('event', ['teams.create', 'users.create'])]), "\n"; // 2
echo $audit->count([], 1), "\n"; // 1
```

## Keep logs in ClickHouse

The ClickHouse adapter talks to ClickHouse's HTTP interface (port 8123 by default). It validates its host, port and names when they are set; `setup` creates the table (`<namespace>_audits` in the database, which must exist), with a TTL when a retention is set. With shared tables, rows carry the tenant and every read, count and cleanup is limited to the current one. Schema columns a log leaves out are taken from its `data`, and `resourceType`, `resourceId` and `resourceParent` are parsed from its resource.

```no_run
use utopia_audit::adapter::clickhouse::{ClickHouse, Options};
use utopia_audit::{Audit, Page, Range};

tokio::runtime::Builder::new_current_thread().enable_all().build()?.block_on(async {
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
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Audit\Adapter\ClickHouse;

$adapter = new ClickHouse('localhost', 'default', 'clickhouse');
$adapter->setDatabase('analytics')->setNamespace('project_1')->setRetention(90);
$adapter->setSharedTables(true)->setTenant(1);
echo $adapter->getTable(), ' ', $adapter->getNamespace(), ' ', $adapter->getRetention(), "\n"; // audits project_1 90
// (new Audit($adapter))->setup() then creates analytics.project_1_audits.
```

## Split a resource path

Resources are paths of `<type>/<id>` pairs. `schema::parse_resource` splits one into its last id, its type and the parent path, as the ClickHouse adapter does for every log; anything else is an id alone.

```
use utopia_audit::schema::parse_resource;

let document = parse_resource("database/d1/collection/c1/document/42");
assert_eq!((document.kind, document.id, document.parent), ("document", "42", "database/d1/collection/c1"));
assert_eq!(parse_resource("user/ada").parent, "");
assert_eq!(parse_resource("odd/path/here").id, "odd/path/here");
```

```php
use Utopia\Audit\Adapter\ClickHouse;

$parse = new ReflectionMethod(ClickHouse::class, 'parseResource');
$adapter = new ClickHouse('localhost');
$document = $parse->invoke($adapter, 'database/d1/collection/c1/document/42');
echo $document['resourceType'], ' ', $document['resourceId'], ' ', $document['resourceParent'], "\n";
// document 42 database/d1/collection/c1
```

## How it is built

`Audit<A>` is generic over its `Adapter`, so calls dispatch statically; `Audit<Box<dyn Adapter>>` (or `Arc`, or `&A`) takes one chosen at run time. Implement `Adapter` to keep logs anywhere else. The SQL adapters share their schema through `schema::Sql`: attribute and index definitions (as PHP's arrays with `to_array`), and the column definition of each backend.

The ClickHouse adapter is generic over its transport, any `utopia_client::Adapter`: the default is a `utopia_client::Client` over `Http`, with PHP's 30 second timeout, redirects followed and connections reused. It builds its statements with `utopia-query`'s ClickHouse builder and schema, with typed `{paramN:Type}` parameters sent as multipart form fields (`utopia_client::multipart`), and inserts batches as `JSONEachRow` bodies, byte for byte as PHP sends them.

Logs are `Log`s: the attributes as the adapter returned them (PHP arrays of `php_std::Value`s, in order), with a getter for each known one.
