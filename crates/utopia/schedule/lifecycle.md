A scheduler runs one loop. It has two lifecycles. The loop's: `run` takes the leadership claim, then reads the source, ticks, hands over and commits, once every tick, until it is stopped, and then releases the claim. And each tick's: it selects the occurrences of one window, hands them to your handler as each falls due, and commits the window. Windows tile, so nothing falls between two ticks, and a window is committed only after its handler returned, so nothing is lost when a handler fails.

## The loop

```flow
Build | `Scheduler::new` with a source, a store, a clock and `Options`, then `on_error` and `with_telemetry`
Run | `run` marks the loop running and takes the handler
> Every `tick_seconds`, until stopped
Elect | the claim is loaded from the store. While another instance holds a live claim, this one sleeps a tick and tries again
Sync | once `sync_seconds` have passed, `reconcile` reads the source: the change feed, or a full snapshot every `snapshot_seconds`
Tick | the occurrences of the next window, below
Deliver | each moment's occurrences go to the handler when the moment comes
Commit | one swap in the store moves the watermark to the window's end and renews the lease
Pause | a sleep to the next multiple of `tick_seconds` on the clock, so ticks stay on whole seconds
<
Release | the claim is freed, keeping the watermark, so a standby takes over at once instead of waiting out the lease
```

`stop`, or `stop` on a `Stop` handle from `stopper`, ends the loop from anywhere: a handler, a signal, another task. The tick in progress still delivers and commits first. However the loop ends, by a stop or an error, the claim is released.

The example runs the loop on a `TestClock`, which moves forward when the scheduler sleeps instead of blocking. It starts at 03:00:30, so the first occurrence is 03:01:00. The tick that covers it runs at 03:01:01, because a window does not include its end:

```
use std::sync::Arc;
use php_std::datetime::{DateTime, Timestamp, TzInfo};
use utopia_schedule::{BoxError, Clock, Cron, Entry, MemoryStore, Options, Row, Scheduler, Source, Store, TestClock};

/// Functions that run every minute, by id.
struct Functions(Vec<&'static str>);

impl Source for Functions {
    type Data = ();
    type Payload = String;

    async fn snapshot(&self) -> Result<Vec<Row<()>>, BoxError> {
        Ok(self.0.iter().map(|id| Row::new(*id, "v1", ())).collect())
    }

    async fn make(&self, row: &Row<()>) -> Result<Entry<String>, BoxError> {
        Ok(Entry::new(Cron::new("* * * * *")?, format!("run {}", row.id)))
    }
}

fn at(moment: &str) -> DateTime {
    DateTime::parse(moment.as_bytes(), None, &TzInfo::utc(), Timestamp { sec: 0, usec: 0 }).unwrap()
}

# utopia_async::Builder::current_thread().build()?.block_on(async {
let clock = Arc::new(TestClock::new(&at("2026-08-18 03:00:30")));
let store = Arc::new(MemoryStore::new());
let mut scheduler = Scheduler::new(Functions(vec!["cleanup", "backup"]), store.clone(), clock.clone(), Options::default())?;

let stop = scheduler.stopper();
let mut handed = Vec::new();
scheduler
    .run(|due| {
        handed.push(due.iter().map(|occurrence| occurrence.key()).collect::<Vec<_>>());
        stop.stop();
        async { Ok::<_, BoxError>(()) }
    })
    .await?;

assert_eq!(handed, [["backup@1787022060.000000", "cleanup@1787022060.000000"]]);
assert_eq!(clock.now()?.format(b"H:i:s"), b"03:01:01");
let claim = store.load().await?.unwrap();
assert_eq!(claim.token, "");
assert_eq!(claim.covered_until, Some(1787022061.0));
# Ok::<(), utopia_schedule::Error>(())
# })?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

The handler got both functions due at 03:01:00 in one call, sorted by id. After the loop, the claim is released: no token, and the watermark where the last window ended.

## A tick

```flow
Window | it opens at the committed watermark, or at now on the very first run, and never more than `recover_seconds` back. It closes at now plus `lead_seconds`
Select | each loaded schedule's occurrences in the window, start included and end excluded. A schedule that took effect between two reads of the source (`Row::active_from`) is covered once from that moment instead. All of them are sorted by time, then by id
> For each moment in the window
Wait | a moment still ahead, with `lead_seconds`, is held until it comes. The source is still read on the way, and the claim is checked again after the wait
Hand over | the occurrences that share the moment go to the handler together. One whose schedule was disabled, deleted or changed since the tick is dropped
<
Commit | the watermark moves to the window's end, and the one-shots that were handed over are retired
! Keep the window | the handler failed. Nothing is committed, `run` returns `Error::Handler`, and the next run hands the same window over again
```

`tick` and `commit` are public, so a caller can drive the steps without `run`. A tick stays pending until `commit`: ticking again without committing selects the same occurrences, which is how a failed hand-over is retried. The watermark never moves back, so a clock that steps backwards produces empty windows until it catches up.

In the example, the scheduler is down for an hour. The window after it does not reach back the whole hour: `recover_seconds` limits it to the last 300 seconds, so the burst after an outage stays small:

```
# use std::sync::Arc;
# use php_std::datetime::{DateTime, Timestamp, TzInfo};
# use utopia_schedule::{BoxError, Cron, Entry, Row, Source};
# struct Functions(Vec<&'static str>);
# impl Source for Functions {
#     type Data = ();
#     type Payload = String;
#     async fn snapshot(&self) -> Result<Vec<Row<()>>, BoxError> {
#         Ok(self.0.iter().map(|id| Row::new(*id, "v1", ())).collect())
#     }
#     async fn make(&self, row: &Row<()>) -> Result<Entry<String>, BoxError> {
#         Ok(Entry::new(Cron::new("* * * * *")?, format!("run {}", row.id)))
#     }
# }
# fn at(moment: &str) -> DateTime {
#     DateTime::parse(moment.as_bytes(), None, &TzInfo::utc(), Timestamp { sec: 0, usec: 0 }).unwrap()
# }
use utopia_schedule::{MemoryStore, Options, Scheduler, TestClock};

# utopia_async::Builder::current_thread().build()?.block_on(async {
let clock = Arc::new(TestClock::new(&at("2026-08-18 03:00:30")));
let mut scheduler = Scheduler::new(Functions(vec!["cleanup"]), MemoryStore::new(), clock.clone(), Options::default())?;
scheduler.reconcile(false).await?;

// The first window opens and closes at now: it only sets the watermark.
assert!(scheduler.tick().await?.is_empty());
scheduler.commit().await?;

clock.advance(3600.0);
let minutes = |due: &[utopia_schedule::Occurrence<String>]| {
    due.iter().map(|occurrence| String::from_utf8(occurrence.due.format(b"H:i")).unwrap()).collect::<Vec<_>>()
};
assert_eq!(minutes(&scheduler.tick().await?), ["03:56", "03:57", "03:58", "03:59", "04:00"]);

// Not committed yet: the same window again.
assert_eq!(scheduler.tick().await?.len(), 5);
assert!(scheduler.commit().await?);
assert!(scheduler.tick().await?.is_empty());
# Ok::<(), utopia_schedule::Error>(())
# })?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

## Leadership

```flow
Load | the claim: the token of the instance holding it, when its lease expires, and the watermark
Renew | the holder renews its lease when less than half of it is left, and again at every commit
Follow | a live claim held by another instance: this one hands nothing over
Take over | a claim that expired or was released is taken with its watermark, so the new leader starts where the old one stopped
! Deposed | the commit finds another token in the store. Nothing is written, the tick is dropped, and the new leader covers that window again
```

Replicas that share a store elect one dispatcher. Share a `MemoryStore` through an `Arc` within a process, or a `RedisStore` across hosts. The lease lasts three ticks of delivery by default. A failover can hand an occurrence over twice, never zero times: drop the duplicates by `Occurrence::key`.

In the example, two replicas share a store. Replica `a` leads, then stops renewing. Once its lease runs out, `b` takes over from `a`'s watermark:

```
# use std::sync::Arc;
# use php_std::datetime::{DateTime, Timestamp, TzInfo};
# use utopia_schedule::{BoxError, Cron, Entry, Row, Source};
# struct Functions(Vec<&'static str>);
# impl Source for Functions {
#     type Data = ();
#     type Payload = String;
#     async fn snapshot(&self) -> Result<Vec<Row<()>>, BoxError> {
#         Ok(self.0.iter().map(|id| Row::new(*id, "v1", ())).collect())
#     }
#     async fn make(&self, row: &Row<()>) -> Result<Entry<String>, BoxError> {
#         Ok(Entry::new(Cron::new("* * * * *")?, format!("run {}", row.id)))
#     }
# }
# fn at(moment: &str) -> DateTime {
#     DateTime::parse(moment.as_bytes(), None, &TzInfo::utc(), Timestamp { sec: 0, usec: 0 }).unwrap()
# }
use utopia_schedule::{MemoryStore, Options, Scheduler, Store, TestClock};

# utopia_async::Builder::current_thread().build()?.block_on(async {
let clock = Arc::new(TestClock::new(&at("2026-08-18 03:00:30")));
let store = Arc::new(MemoryStore::new());
let options = |token: &str| Options { token: Some(token.into()), ..Options::default() };
let mut a = Scheduler::new(Functions(vec!["cleanup"]), store.clone(), clock.clone(), options("a"))?;
let mut b = Scheduler::new(Functions(vec!["cleanup"]), store.clone(), clock.clone(), options("b"))?;
a.reconcile(false).await?;
b.reconcile(false).await?;

a.tick().await?;
a.commit().await?;
assert!(b.tick().await?.is_empty());
assert_eq!(store.load().await?.unwrap().token, "a");

clock.advance(90.0);
let due = b.tick().await?;
assert_eq!(due[0].key(), "cleanup@1787022060.000000");
assert!(b.commit().await?);

assert!(a.tick().await?.is_empty());
assert_eq!(store.load().await?.unwrap().token, "b");
# Ok::<(), utopia_schedule::Error>(())
# })?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

## When something fails

```flow
Any step | reading the source, building a schedule, the handler, or the store
! On error, or return | a failure reading the source or building a schedule goes to the `on_error` handler, and the loop goes on with what it had. Any other failure, or one of those without `on_error`, ends `run` with the error, after the claim is released
```

| Failure | Then |
|---|---|
| The source cannot list its rows | The whole batch is discarded, so a failing query never reads as every schedule being removed. Memory keeps the last good view. `on_error` gets the error without a row; without `on_error`, `run` returns `Error::Source` |
| `make` rejects a row | That row is skipped, and its previous entry, if any, stays. `on_error` gets the error and the row; without `on_error`, `run` returns it |
| The handler returns an error | `run` returns `Error::Handler`. The window is not committed, so the next run hands it over again |
| The store cannot be reached | `run` returns the error. The window is not committed |
| Another instance took the claim during the tick | Not an error for the caller: the commit writes nothing, and the loop goes on as a follower |
| The clock steps backwards | Not an error: windows are empty until the clock passes the watermark again |

In the example, the queue behind the handler is down for the first hand-over. `run` returns the error and the window stays uncommitted. The next run hands the same occurrence over again:

```
# use std::sync::Arc;
# use php_std::datetime::{DateTime, Timestamp, TzInfo};
# use utopia_schedule::{BoxError, Cron, Entry, Row, Source};
# struct Functions(Vec<&'static str>);
# impl Source for Functions {
#     type Data = ();
#     type Payload = String;
#     async fn snapshot(&self) -> Result<Vec<Row<()>>, BoxError> {
#         Ok(self.0.iter().map(|id| Row::new(*id, "v1", ())).collect())
#     }
#     async fn make(&self, row: &Row<()>) -> Result<Entry<String>, BoxError> {
#         Ok(Entry::new(Cron::new("* * * * *")?, format!("run {}", row.id)))
#     }
# }
# fn at(moment: &str) -> DateTime {
#     DateTime::parse(moment.as_bytes(), None, &TzInfo::utc(), Timestamp { sec: 0, usec: 0 }).unwrap()
# }
use utopia_schedule::{Error, MemoryStore, Options, Scheduler, Store, TestClock};

# utopia_async::Builder::current_thread().build()?.block_on(async {
let clock = Arc::new(TestClock::new(&at("2026-08-18 03:00:30")));
let store = Arc::new(MemoryStore::new());
let mut scheduler = Scheduler::new(Functions(vec!["cleanup"]), store.clone(), clock.clone(), Options::default())?;

let error = scheduler.run(|_| async { Err::<(), BoxError>("queue unavailable".into()) }).await.unwrap_err();
assert!(matches!(error, Error::Handler(_)));
assert_eq!(error.to_string(), "queue unavailable");
assert_eq!(store.load().await?.unwrap().covered_until, Some(1787022060.0));

let stop = scheduler.stopper();
let mut handed = Vec::new();
scheduler
    .run(|due| {
        handed.extend(due.iter().map(|occurrence| occurrence.key()));
        stop.stop();
        async { Ok::<_, BoxError>(()) }
    })
    .await?;
assert_eq!(handed, ["cleanup@1787022060.000000"]);
# Ok::<(), Error>(())
# })?;
# Ok::<(), Box<dyn std::error::Error>>(())
```
