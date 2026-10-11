A scheduler runs work at the moments its schedules name, and must never silently skip one. This library describes those moments with triggers (a cron expression, a fixed interval, a one-shot moment) and runs a loop that reads the schedules from your source of truth, selects what is due over windows that tile without gaps, and records how far it got so a restart or a standby replica carries on where it stopped. The scheduler is async, so the examples run it on a Tokio runtime; tests drive it with a clock that stands still until advanced.

## List the occurrences of a cron expression

A trigger answers which moments fall inside a half-open window `[start, end)`. Cron expressions take the five classic fields, names, steps, macros and the Quartz day extensions (`L`, `W`, `#`).

```
use php_std::datetime::{DateTime, Timestamp, TzInfo};
use utopia_schedule::{Cron, Trigger};

let at = |s: &str| DateTime::parse(s.as_bytes(), None, &TzInfo::utc(), Timestamp { sec: 0, usec: 0 }).unwrap();
let third_friday = Cron::new("0 9 ? * FRI#3")?;

let due = third_friday.occurrences_between(&at("2026-01-01 00:00:00"), &at("2026-04-01 00:00:00"));
let due: Vec<_> = due.iter().map(|d| String::from_utf8(d.format(b"Y-m-d H:i")).unwrap()).collect();
assert_eq!(due, ["2026-01-16 09:00", "2026-02-20 09:00", "2026-03-20 09:00"]);
# Ok::<(), utopia_schedule::Error>(())
```

```php
use Utopia\Schedule\Trigger\Cron;

$thirdFriday = new Cron('0 9 ? * FRI#3');

$due = $thirdFriday->occurrencesBetween(new DateTimeImmutable('2026-01-01 00:00:00'), new DateTimeImmutable('2026-04-01 00:00:00'));
echo implode(', ', array_map(fn ($d) => $d->format('Y-m-d H:i'), $due)), "\n"; // 2026-01-16 09:00, 2026-02-20 09:00, 2026-03-20 09:00
```

## Reject an expression that can never run

Invalid and impossible expressions fail when they are built, with the message PHP gives, instead of quietly never firing.

```
use utopia_schedule::{Cron, Error};

let error = Cron::new("0 0 31 2 *").unwrap_err();
assert!(matches!(error, Error::InvalidArgument(_)));
assert_eq!(error.to_string(), "Cron expression \"0 0 31 2 *\" never matches a date");
```

```php
use Utopia\Schedule\Trigger\Cron;

try {
    new Cron('0 0 31 2 *');
} catch (InvalidArgumentException $e) {
    echo $e->getMessage(), "\n"; // Cron expression "0 0 31 2 *" never matches a date
}
```

## Run schedules from a source

A `Source` lists rows (usually database rows, with a version that changes on every edit) and builds each into an `Entry`: a trigger and a payload. `reconcile` loads them, `tick` selects what is due since the last commit, and `commit` records the window once you have handled it; skip the commit and the same occurrences come back on the next tick.

```
use std::sync::Arc;

use php_std::datetime::{DateTime, Timestamp, TzInfo};
use utopia_schedule::{BoxError, Entry, Interval, MemoryStore, Options, Row, Scheduler, Source, TestClock};

/// Functions to run every minute, by id.
struct Functions;

impl Source for Functions {
    type Data = ();
    type Payload = String;

    async fn snapshot(&self) -> Result<Vec<Row<()>>, BoxError> {
        Ok(vec![Row::new("cleanup", "v1", ())])
    }

    async fn make(&self, row: &Row<()>) -> Result<Entry<String>, BoxError> {
        Ok(Entry::new(Interval::new(60, None)?, format!("run {}", row.id)))
    }
}

# tokio::runtime::Builder::new_current_thread().build()?.block_on(async {
let start = DateTime::parse(b"2026-08-18 03:00:30", None, &TzInfo::utc(), Timestamp { sec: 0, usec: 0 }).unwrap();
let clock = Arc::new(TestClock::new(&start));
let mut scheduler = Scheduler::new(Functions, MemoryStore::new(), clock.clone(), Options::default())?;

scheduler.reconcile(false).await?;
scheduler.tick().await?;
scheduler.commit().await?;

clock.advance(60.0);
let due = scheduler.tick().await?;
assert_eq!(due.len(), 1);
assert_eq!(due[0].payload, "run cleanup");
assert_eq!(due[0].key(), "cleanup@1787022060.000000");
scheduler.commit().await?;
# Ok::<(), utopia_schedule::Error>(())
# })?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Schedule\Clock\Test;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;
use Utopia\Schedule\Trigger\Interval;

$functions = new class implements Source {
    public function snapshot(): iterable { return [new Row('cleanup', 'v1')]; }
    public function make(Row $row): Entry { return new Entry(new Interval(60), "run {$row->id}"); }
};

$clock = new Test(new DateTimeImmutable('2026-08-18 03:00:30'));
$scheduler = new Scheduler(source: $functions, clock: $clock);

$scheduler->reconcile();
$scheduler->tick();
$scheduler->commit();

$clock->advance(60.0);
$due = $scheduler->tick();
echo count($due), "\n"; // 1
echo $due[0]->payload, "\n"; // run cleanup
echo $due[0]->key(), "\n"; // cleanup@1787022060.000000
$scheduler->commit();
```

## Share the claim between replicas

Give every replica the same `Store` and only one of them dispatches: the claim carries a lease and the committed watermark, and a standby takes over, from that watermark, once the lease runs out. `MemoryStore` shares it within a process; `RedisStore` shares it across hosts.

```
use utopia_schedule::{Claim, MemoryStore, Store};

# tokio::runtime::Builder::new_current_thread().build()?.block_on(async {
let store = MemoryStore::new();
assert!(store.swap(None, &Claim::new("leader", 100.0, Some(1787022060.0), None)).await?);

// A deposed leader's late write no longer matches the stored token.
assert!(store.swap(Some("leader"), &Claim::new("standby", 200.0, Some(1787022120.0), None)).await?);
assert!(!store.swap(Some("leader"), &Claim::new("leader", 300.0, Some(1787022000.0), None)).await?);
assert_eq!(store.load().await?.unwrap().covered_until, Some(1787022120.0));
# Ok::<(), utopia_schedule::Error>(())
# })?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Schedule\Claim;
use Utopia\Schedule\Store\Memory;

$store = new Memory();
var_dump($store->swap(null, new Claim('leader', 100.0, 1787022060.0))); // bool(true)

// A deposed leader's late write no longer matches the stored token.
var_dump($store->swap('leader', new Claim('standby', 200.0, 1787022120.0))); // bool(true)
var_dump($store->swap('leader', new Claim('leader', 300.0, 1787022000.0))); // bool(false)
var_dump($store->load()->coveredUntil); // float(1787022120)
```
