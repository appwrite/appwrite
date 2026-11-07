//! The loop: reconcile schedules from a [`Source`], select due occurrences
//! over tiled windows, hand them over, and commit coverage to a [`Store`].

use std::borrow::Cow;
use std::cmp::Ordering;
use std::future::Future;
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering as AtomicOrdering};
use std::time::Instant;

use indexmap::IndexMap;
use php_std::datetime::DateTime;
use php_std::format::{fmod, str_to_float};
use utopia_telemetry::{Counter, Gauge, Histogram, NoTelemetry, Telemetry, Value};

use crate::clock::{Clock, SystemClock};
use crate::moment::{self, by_seconds, from_seconds, seconds, shifted};
use crate::occurrence::Occurrence;
use crate::source::{Row, Source};
use crate::store::{Claim, MemoryStore, Store};
use crate::trigger::Trigger;
use crate::{BoxError, Error};

/// The cadences and identity of a [`Scheduler`]. Every field has PHP's
/// default ([`Options::default`]).
///
/// PHP: the named arguments of `new Scheduler(...)`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Options {
    /// Seconds between ticks in [`Scheduler::run`], anchored to the wall
    /// clock. At least 1; defaults to 1.
    pub tick_seconds: i64,
    /// Seconds between reads of the source. At least 1; defaults to 10.
    pub sync_seconds: i64,
    /// How far past "now" a tick may reach. Zero (the default) hands
    /// occurrences over once due; raising it hands them over early so the
    /// wait for the exact second happens inside [`Scheduler::run`].
    pub lead_seconds: i64,
    /// Seconds between full snapshot syncs when the source has a change
    /// feed; defaults to 30 sync cadences, and 0 disables periodic relisting.
    pub snapshot_seconds: Option<i64>,
    /// Ceiling on how far behind the watermark a window may start, which
    /// bounds the catch-up burst after downtime; defaults to 300.
    pub recover_seconds: Option<i64>,
    /// How long a leadership claim lives before it must be renewed; defaults
    /// to three ticks of delivery and must outlive two.
    pub lease_seconds: Option<i64>,
    /// This instance's identity in the claim; random by default.
    pub token: Option<String>,
}

impl Default for Options {
    /// PHP's defaults: a tick a second, a sync every 10 seconds, no lead.
    fn default() -> Self {
        Self {
            tick_seconds: 1,
            sync_seconds: 10,
            lead_seconds: 0,
            snapshot_seconds: None,
            recover_seconds: None,
            lease_seconds: None,
            token: None,
        }
    }
}

/// Makes a running [`Scheduler::run`] return after the current tick, from
/// anywhere (a handler, a signal, another task).
///
/// PHP: `$scheduler->stop()`, which a handler calls through its closure.
#[derive(Debug, Clone)]
pub struct Stop(Arc<AtomicBool>);

impl Stop {
    /// Stops the loop once the current tick has been delivered and
    /// committed.
    pub fn stop(&self) {
        self.0.store(false, AtomicOrdering::SeqCst);
    }
}

/// A loaded schedule.
struct Registered<P> {
    trigger: Arc<dyn Trigger>,
    payload: P,
    version: String,
    cover_from: Option<DateTime>,
}

/// The four golden signals.
struct Metrics {
    dispatch_delay: Histogram,
    tick_duration: Histogram,
    reconcile_duration: Histogram,
    dispatch_total: Counter,
    error_total: Counter,
    entries: Gauge,
    lag: Gauge,
}

impl Metrics {
    fn new(telemetry: &dyn Telemetry) -> Self {
        // Dispatch delay spans "on time" (well under a second) to a full
        // recovery window of catch-up; the default OpenTelemetry buckets stop
        // at 10 seconds and would flatten every recovery into one bucket.
        let boundaries = [0.1, 0.5, 1.0, 2.0, 5.0, 15.0, 30.0, 60.0, 120.0, 300.0];
        Self {
            dispatch_delay: telemetry.histogram("schedule.dispatch.delay", Some("s"), None, Some(&boundaries)),
            tick_duration: telemetry.histogram("schedule.tick.duration", Some("s"), None, None),
            reconcile_duration: telemetry.histogram("schedule.reconcile.duration", Some("s"), None, None),
            dispatch_total: telemetry.counter("schedule.dispatch.total", None, None),
            error_total: telemetry.counter("schedule.error.total", None, None),
            entries: telemetry.gauge("schedule.entries", None, None),
            lag: telemetry.gauge("schedule.lag", Some("s"), None),
        }
    }

    fn error(&self, stage: &'static str) {
        self.error_total.add(1, &[("stage", Value::Str(Cow::Borrowed(stage)))]);
    }
}

/// What reconciliation failures go to instead of failing the sync.
type OnError<D> = Box<dyn Fn(&Error, Option<&Row<D>>) + Send + Sync>;

/// PHP's `<=>` on two ids (numeric ids compare as numbers).
fn compare_ids(a: &str, b: &str) -> Ordering {
    let a: php_std::Value = php_std::Value::from(a);
    a.cmp_php(&php_std::Value::from(b))
}

/// `max(0.0, $value)`.
fn non_negative(value: f64) -> f64 {
    if 0.0 < value { value } else { 0.0 }
}

/// A running loop with two duties: reconcile schedules into memory from a
/// source of truth, and dispatch their occurrences at the right time.
///
/// The correctness rules it enforces:
///
/// - Selection happens over an explicit half-open window, never against a
///   moving "now": a minute boundary the loop crosses mid-evaluation cannot
///   drop the occurrence sitting on it.
/// - Windows tile: each opens where the previous committed window closed
///   (the watermark, part of the [`Claim`] held in the [`Store`]). Nothing
///   falls between ticks, and with shared state nothing falls across a
///   restart either.
/// - Delivery is at-least-once: [`Scheduler::commit`] advances the
///   watermark only after the caller has handled a tick's occurrences.
/// - Catch-up is bounded by `recover_seconds`.
/// - Coverage extends to late discoveries: an entry that appears or changes
///   between syncs is covered once from the moment it took effect
///   ([`Row::active_from`]), so a one-shot due sooner than the sync lag runs
///   late instead of never.
/// - The watermark never rewinds: a clock stepping backwards produces empty
///   windows.
/// - Reconciliation is level-based: a full snapshot diff converges
///   additions, updates and removals, including hard deletes.
/// - Leadership and the watermark share one claim: replicas sharing a
///   [`Store`] elect one dispatcher, a standby takes over when the claim
///   expires, and a deposed leader's late commit is fenced. Failover trades
///   duplicates, never losses; [`Occurrence::key`] lets consumers drop them.
///
/// One instance is one loop: reconciliation, selection and the commit all
/// run on the caller's task, so the schedule map needs no locking.
///
/// ```
/// use std::sync::Arc;
///
/// use php_std::datetime::{DateTime, Timestamp, TzInfo};
/// use utopia_schedule::{BoxError, Cron, Entry, MemoryStore, Options, Row, Scheduler, Source, TestClock};
///
/// /// Every row is a minutely cron.
/// struct Minutely(Vec<&'static str>);
///
/// impl Source for Minutely {
///     type Data = ();
///     type Payload = ();
///
///     async fn snapshot(&self) -> Result<Vec<Row<()>>, BoxError> {
///         Ok(self.0.iter().map(|id| Row::new(*id, "v1", ())).collect())
///     }
///
///     async fn make(&self, _: &Row<()>) -> Result<Entry<()>, BoxError> {
///         Ok(Entry::new(Cron::new("* * * * *")?, ()))
///     }
/// }
///
/// # utopia_async::Builder::current_thread().build()?.block_on(async {
/// let start = DateTime::parse(b"2026-08-18 03:00:30", None, &TzInfo::utc(), Timestamp { sec: 0, usec: 0 }).unwrap();
/// let clock = Arc::new(TestClock::new(&start));
/// let mut scheduler = Scheduler::new(Minutely(vec!["fn"]), MemoryStore::new(), clock.clone(), Options::default())?;
///
/// scheduler.reconcile(false).await?;
/// scheduler.tick().await?;
/// scheduler.commit().await?;
///
/// clock.advance(90.0);
/// let due = scheduler.tick().await?;
/// assert_eq!(due[0].key(), "fn@1787022060.000000");
/// scheduler.commit().await?;
/// # Ok::<(), utopia_schedule::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Schedule\Clock\Test;
/// use Utopia\Schedule\Scheduler;
/// use Utopia\Schedule\Source;
/// use Utopia\Schedule\Source\Entry;
/// use Utopia\Schedule\Source\Row;
/// use Utopia\Schedule\Trigger\Cron;
///
/// $source = new class implements Source {
///     public function snapshot(): iterable { return [new Row('fn', 'v1')]; }
///     public function make(Row $row): Entry { return new Entry(new Cron('* * * * *')); }
/// };
///
/// $clock = new Test(new DateTimeImmutable('2026-08-18 03:00:30'));
/// $scheduler = new Scheduler(source: $source, clock: $clock);
///
/// $scheduler->reconcile();
/// $scheduler->tick();
/// $scheduler->commit();
///
/// $clock->advance(90.0);
/// $due = $scheduler->tick();
/// echo $due[0]->key(), "\n"; // fn@1787022060.000000
/// $scheduler->commit();
/// ```
///
/// PHP: `Utopia\Schedule\Scheduler`.
pub struct Scheduler<S: Source, St: Store = MemoryStore, C: Clock = SystemClock> {
    source: S,
    store: St,
    clock: C,
    tick_seconds: i64,
    sync_seconds: i64,
    lead_seconds: i64,
    snapshot_seconds: i64,
    recover_seconds: i64,
    lease_seconds: i64,
    token: String,
    entries: IndexMap<String, Registered<S::Payload>>,
    /// Delivered one-shots, id => version, kept so the next snapshot does
    /// not re-add a row whose completion the source has not recorded yet.
    tombstones: IndexMap<String, String>,
    pending_covered_until: Option<DateTime>,
    /// The source read that fed the pending tick.
    pending_synced_until: Option<DateTime>,
    pending_one_shots: Vec<(String, String)>,
    pending_covered: Vec<(String, String)>,
    running: Arc<AtomicBool>,
    /// Cursor for incremental syncs; `None` forces the next sync to be full.
    last_sync_at: Option<DateTime>,
    next_sync_at: f64,
    next_snapshot_at: f64,
    metrics: Metrics,
    on_error: Option<OnError<S::Data>>,
}

impl<S: Source, St: Store, C: Clock> Scheduler<S, St, C> {
    /// A scheduler reconciling against `source`, keeping its claim in
    /// `store` (share a [`RedisStore`](crate::RedisStore) between replicas to
    /// elect one dispatcher) and reading time from `clock`.
    ///
    /// Fails with [`Error::InvalidArgument`] on a non-positive tick or sync
    /// cadence, a negative snapshot cadence, lead time or recovery ceiling,
    /// or a lease shorter than two ticks of delivery.
    ///
    /// PHP: `new Scheduler($source, $store, ..., clock: $clock)`.
    pub fn new(source: S, store: St, clock: C, options: Options) -> Result<Self, Error> {
        let Options {
            tick_seconds,
            sync_seconds,
            lead_seconds,
            snapshot_seconds,
            recover_seconds,
            lease_seconds,
            token,
        } = options;
        let snapshot_seconds = snapshot_seconds.unwrap_or(sync_seconds.saturating_mul(30));
        let recover_seconds = recover_seconds.unwrap_or(300);
        let delivery = tick_seconds.saturating_add(lead_seconds);
        let lease_seconds = lease_seconds.unwrap_or(delivery.saturating_mul(3));

        if tick_seconds < 1 {
            return Err(Error::InvalidArgument("Tick interval must be at least 1 second".into()));
        }
        if sync_seconds < 1 {
            return Err(Error::InvalidArgument("Sync cadence must be at least 1 second".into()));
        }
        if snapshot_seconds < 0 {
            return Err(Error::InvalidArgument("Snapshot cadence must not be negative".into()));
        }
        if lead_seconds < 0 || recover_seconds < 0 {
            return Err(Error::InvalidArgument("Lead time and recovery ceiling must not be negative".into()));
        }
        // A tick holds its claim through delivery, to the end of the lead time.
        if lease_seconds < delivery.saturating_mul(2) {
            return Err(Error::InvalidArgument(
                "A leadership claim must outlive at least two ticks, delivery included".into(),
            ));
        }

        let token = token.unwrap_or_else(|| {
            let mut bytes = [0u8; 8];
            let _ = getrandom::fill(&mut bytes);
            bytes.iter().map(|b| format!("{b:02x}")).collect()
        });

        Ok(Self {
            source,
            store,
            clock,
            tick_seconds,
            sync_seconds,
            lead_seconds,
            snapshot_seconds,
            recover_seconds,
            lease_seconds,
            token,
            entries: IndexMap::new(),
            tombstones: IndexMap::new(),
            pending_covered_until: None,
            pending_synced_until: None,
            pending_one_shots: Vec::new(),
            pending_covered: Vec::new(),
            running: Arc::new(AtomicBool::new(false)),
            last_sync_at: None,
            next_sync_at: 0.0,
            next_snapshot_at: 0.0,
            metrics: Metrics::new(&NoTelemetry),
            on_error: None,
        })
    }

    /// The scheduler, recording its metrics through `telemetry`: the four
    /// golden signals as `schedule.dispatch.delay` and
    /// `schedule.tick.duration` (latency), `schedule.dispatch.total` and
    /// `schedule.entries` (traffic), `schedule.error.total` by `stage`
    /// (errors) and `schedule.lag` (saturation: seconds the window start
    /// trails "now"), plus `schedule.reconcile.duration`.
    ///
    /// PHP: the `telemetry` argument.
    pub fn with_telemetry(mut self, telemetry: &dyn Telemetry) -> Self {
        self.metrics = Metrics::new(telemetry);
        self
    }

    /// The scheduler, sending reconciliation failures (a sync that fails, a
    /// row [`Source::make`] rejects) to `handler` so dispatch keeps running
    /// on the last good view; without one those failures are returned. The
    /// handler receives the row `make` rejected, or `None` for a failure no
    /// single row owns.
    ///
    /// PHP: the `onError` argument.
    pub fn on_error(mut self, handler: impl Fn(&Error, Option<&Row<S::Data>>) + Send + Sync + 'static) -> Self {
        self.on_error = Some(Box::new(handler));
        self
    }

    /// This instance's identity in the claim.
    pub fn token(&self) -> &str {
        &self.token
    }

    /// The source.
    pub fn source(&self) -> &S {
        &self.source
    }

    /// The store.
    pub fn store(&self) -> &St {
        &self.store
    }

    /// The clock.
    pub fn clock(&self) -> &C {
        &self.clock
    }

    /// A handle that stops [`Scheduler::run`] from anywhere.
    pub fn stopper(&self) -> Stop {
        Stop(self.running.clone())
    }

    /// Makes [`Scheduler::run`] return after the current tick finishes
    /// delivering and committing. The claim is released so a standby takes
    /// over immediately instead of waiting out the lease.
    ///
    /// PHP: `stop()`.
    pub fn stop(&self) {
        self.running.store(false, AtomicOrdering::SeqCst);
    }

    /// Synchronizes memory with the source: one full snapshot diff (`full`,
    /// the first sync, or a source without a change feed), or one
    /// incremental pass over [`Source::since`].
    ///
    /// A source that fails to list discards the whole batch: a failed sync
    /// never looks like a mass removal. A row [`Source::make`] rejects is
    /// skipped (reported through [`Scheduler::on_error`]) and its previous
    /// entry, if any, stays.
    ///
    /// PHP: `reconcile()`.
    pub async fn reconcile(&mut self, full: bool) -> Result<(), Error> {
        let started = Instant::now();
        let sync_start = self.clock.now()?;
        let since = self.last_sync_at.clone();

        // A change feed cannot report a hard delete, so only a full snapshot
        // converges removals; incremental syncs fill the gaps between them.
        let mut full = full;
        let feed = match (&since, full) {
            (Some(moment), false) => match self.source.since(moment).await {
                Some(feed) => feed,
                None => {
                    full = true;
                    self.source.snapshot().await
                }
            },
            _ => {
                full = true;
                self.source.snapshot().await
            }
        };
        let feed = feed.map_err(Error::Source)?;

        let mut rows: IndexMap<String, Row<S::Data>> = IndexMap::with_capacity(feed.len());
        for row in feed {
            rows.insert(row.id.clone(), row);
        }

        for (id, row) in &rows {
            if !row.active {
                self.entries.shift_remove(id);
                self.tombstones.shift_remove(id);
                continue;
            }

            if self.tombstones.get(id).is_some_and(|version| *version == row.version) {
                continue; // a delivered one-shot whose row the source still lists
            }
            self.tombstones.shift_remove(id);

            let existing = self.entries.get(id).map(|entry| entry.version == row.version);
            if existing == Some(true) {
                continue;
            }

            let entry = match self.source.make(row).await {
                Ok(entry) => entry,
                Err(error) => {
                    self.report(Error::Source(error), "make", Some(row))?;
                    continue;
                }
            };

            let cover_from = self.cover_from(row.active_from.as_ref(), since.as_ref(), existing.is_some()).await?;
            self.entries.insert(
                id.clone(),
                Registered { trigger: entry.trigger, payload: entry.payload, version: row.version.clone(), cover_from },
            );
        }

        if full {
            self.entries.retain(|id, _| rows.contains_key(id));
            self.tombstones.retain(|id, _| rows.contains_key(id));
        }

        self.last_sync_at = Some(sync_start);
        self.metrics.reconcile_duration.record(started.elapsed().as_secs_f64(), &[("full", Value::Bool(full))]);
        Ok(())
    }

    /// Where a freshly made entry's own coverage starts: from the moment it
    /// took effect when nobody who committed the current coverage could have
    /// known about it (it took effect after the source was last read), or
    /// when it replaces a definition this process held; otherwise it rides
    /// the watermark like everything else.
    async fn cover_from(
        &self,
        active_from: Option<&DateTime>,
        since: Option<&DateTime>,
        replaced: bool,
    ) -> Result<Option<DateTime>, Error> {
        let Some(active_from) = active_from else {
            return Ok(None);
        };
        if replaced {
            return Ok(Some(active_from.clone()));
        }
        let seen = match since {
            Some(since) => Some(since.clone()),
            None => self.store.load().await?.and_then(|claim| claim.synced_until).and_then(from_seconds),
        };
        Ok(match seen {
            Some(seen) if *active_from <= seen => None,
            _ => Some(active_from.clone()),
        })
    }

    /// Whether the definition `occurrence` was selected against is still the
    /// one memory holds: `false` once the source disables, deletes or
    /// rewrites it. [`Scheduler::run`] applies this before every hand-over.
    ///
    /// PHP: `isCurrent()`.
    pub fn is_current(&self, occurrence: &Occurrence<S::Payload>) -> bool {
        self.entries.get(&occurrence.id).is_some_and(|entry| entry.version == occurrence.version)
    }

    /// How many schedules are loaded (the `schedule.entries` gauge).
    ///
    /// PHP: `count()`.
    pub fn count(&self) -> usize {
        self.entries.len()
    }

    /// Selects every occurrence in the next window, oldest first, after
    /// confirming (or taking) leadership; a follower gets an empty list.
    ///
    /// The window opens at the claimed watermark (clamped to the recovery
    /// ceiling, "now" on the first ever run) and closes at now plus the lead
    /// time; an entry with pending coverage of its own is covered from there
    /// instead, once. The result stays pending until [`Scheduler::commit`];
    /// ticking again without committing selects the same occurrences.
    ///
    /// PHP: `tick()`.
    pub async fn tick(&mut self) -> Result<Vec<Occurrence<S::Payload>>, Error> {
        let started = Instant::now();

        let Some(claim) = self.elect().await? else {
            self.clear_pending();
            return Ok(Vec::new());
        };

        let now = self.clock.now()?;
        let end = if self.lead_seconds > 0 { by_seconds(&now, self.lead_seconds) } else { now.clone() };
        let mut start = claim.covered_until.and_then(from_seconds).unwrap_or_else(|| now.clone());

        let floor = shifted(&now, format!("-{} seconds", self.recover_seconds).as_bytes());
        if start < floor {
            start = floor.clone();
        }

        self.metrics.entries.record(self.entries.len() as i64, &[]);
        self.metrics.lag.record(non_negative(seconds(&now) - seconds(&start)), &[]);

        if start >= end {
            // Nothing to cover, but committing `start` still initializes the
            // watermark on first run, and never rewinds it when the clock has
            // stepped backwards past the committed edge.
            self.pending_covered_until = Some(start);
            self.pending_synced_until = self.last_sync_at.clone();
            self.pending_one_shots.clear();
            self.pending_covered.clear();
            return Ok(Vec::new());
        }

        let mut occurrences = Vec::new();
        let mut one_shots = Vec::new();
        let mut covered = Vec::new();

        for (id, entry) in &self.entries {
            let mut entry_start = entry.cover_from.as_ref().unwrap_or(&start);
            if *entry_start < floor {
                entry_start = &floor;
            }
            if *entry_start >= end {
                continue;
            }

            let dues = entry.trigger.occurrences_between(entry_start, &end);
            let delivered = !dues.is_empty();
            for due in dues {
                occurrences.push(Occurrence {
                    id: id.clone(),
                    due,
                    payload: entry.payload.clone(),
                    version: entry.version.clone(),
                });
            }

            if entry.cover_from.is_some() {
                covered.push((id.clone(), entry.version.clone()));
            }
            if delivered && !entry.trigger.recurring() {
                one_shots.push((id.clone(), entry.version.clone()));
            }
        }

        occurrences.sort_by(|a, b| a.due.cmp_time(&b.due).then_with(|| compare_ids(&a.id, &b.id)));

        self.pending_covered_until = Some(end);
        self.pending_synced_until = self.last_sync_at.clone();
        self.pending_one_shots = one_shots;
        self.pending_covered = covered;
        self.metrics.tick_duration.record(started.elapsed().as_secs_f64(), &[]);

        Ok(occurrences)
    }

    /// Reconciles if the source's cadence says it is time.
    async fn sync_if_due(&mut self) -> Result<(), Error> {
        let now = seconds(&self.clock.now()?);
        if now < self.next_sync_at {
            return Ok(());
        }

        let full = self.snapshot_seconds > 0 && now >= self.next_snapshot_at;
        if let Err(error) = self.reconcile(full).await {
            self.report(error, "reconcile", None)?;
        }

        self.next_sync_at = now + self.sync_seconds as f64;
        if full {
            self.next_snapshot_at = now + self.snapshot_seconds as f64;
        }
        Ok(())
    }

    /// Waits for a delivery moment, still reconciling on the way: sleeping
    /// straight through would leave a cancellation during the wait
    /// unobservable.
    async fn wait(&mut self, until: f64) -> Result<(), Error> {
        loop {
            let now = seconds(&self.clock.now()?);
            if now >= until {
                return Ok(());
            }

            self.sync_if_due().await?;

            let to_sync = self.next_sync_at - now;
            let to_sync = if 0.001 < to_sync { to_sync } else { 0.001 };
            let left = until - now;
            self.clock.sleep(if to_sync < left { to_sync } else { left }).await;
        }
    }

    /// Hands a tick's occurrences to `handler` as each falls due: those
    /// sharing a moment together, those already past due immediately and in
    /// order. Waiting is against an absolute target, so a slow handler
    /// cannot push the ones behind it late.
    async fn deliver<H, F>(&mut self, occurrences: Vec<Occurrence<S::Payload>>, handler: &mut H) -> Result<(), Error>
    where
        H: FnMut(Vec<Occurrence<S::Payload>>) -> F,
        F: Future<Output = Result<(), BoxError>>,
    {
        if occurrences.is_empty() {
            return Ok(());
        }

        let mut batches: IndexMap<String, Vec<Occurrence<S::Payload>>> = IndexMap::new();
        for occurrence in occurrences {
            batches.entry(moment::text(&occurrence.due)).or_default().push(occurrence);
        }
        let mut batches: Vec<(f64, Vec<Occurrence<S::Payload>>)> =
            batches.into_iter().map(|(at, batch)| (str_to_float(at.as_bytes()), batch)).collect();
        batches.sort_by(|a, b| a.0.partial_cmp(&b.0).unwrap_or(Ordering::Equal));

        for (at, batch) in batches {
            let wait = at - seconds(&self.clock.now()?);
            if wait > 0.0 {
                self.wait(at).await?;

                // Only a waited batch can have outlived the tick's election.
                if self.elect().await?.is_none() {
                    return Ok(());
                }
            }

            let due: Vec<_> = batch.into_iter().filter(|occurrence| self.is_current(occurrence)).collect();
            if due.is_empty() {
                continue;
            }

            // Lateness is measured at hand-over, not after the handler.
            let handed_over = seconds(&self.clock.now()?);
            let count = due.len();

            if let Err(error) = handler(due).await {
                self.metrics.error("dispatch");
                return Err(Error::Handler(error));
            }

            self.metrics.dispatch_total.add(count as i64, &[]);
            for _ in 0..count {
                self.metrics.dispatch_delay.record(non_negative(handed_over - at), &[]);
            }
        }
        Ok(())
    }

    /// Advances the watermark past the last tick's window, renews the
    /// leadership claim (one compare-and-swap does both) and retires what
    /// the tick consumed. Call it after handling the tick's occurrences;
    /// skipping it on failure is what makes re-delivery work.
    ///
    /// The swap is fenced: if another instance took the claim while the tick
    /// was in flight, nothing is written and the pending tick is dropped
    /// (counted as `schedule.error.total{stage="lease"}`). Returns whether
    /// the watermark advanced.
    ///
    /// PHP: `commit()`.
    pub async fn commit(&mut self) -> Result<bool, Error> {
        let Some(covered_until) = self.pending_covered_until.clone() else {
            return Ok(false);
        };

        let expires_at = seconds(&self.clock.now()?) + self.lease_seconds as f64;
        // The read that fed *this* tick, not the newest one; a tick that has
        // not reconciled at all keeps what a predecessor recorded.
        let synced_until = match &self.pending_synced_until {
            Some(synced) => Some(seconds(synced)),
            None => self.store.load().await?.and_then(|claim| claim.synced_until),
        };
        let next = Claim::new(self.token.clone(), expires_at, Some(seconds(&covered_until)), synced_until);

        if !self.store.swap(Some(&self.token), &next).await? {
            // Deposed mid-tick: the new leader re-covers this window.
            self.metrics.error("lease");
            self.clear_pending();
            return Ok(false);
        }

        for (id, version) in std::mem::take(&mut self.pending_covered) {
            if let Some(entry) = self.entries.get_mut(&id)
                && entry.version == version
            {
                entry.cover_from = None;
            }
        }

        for (id, version) in std::mem::take(&mut self.pending_one_shots) {
            if self.entries.get(&id).is_some_and(|entry| entry.version == version) {
                self.entries.shift_remove(&id);
            }
            self.tombstones.insert(id, version);
        }

        self.clear_pending();
        Ok(true)
    }

    /// The loop: elect, reconcile on the source's cadence, then tick,
    /// dispatch and commit on a wall-anchored cadence, until stopped
    /// ([`Scheduler::stop`], [`Stop::stop`]). Followers idle and poll for the
    /// claim.
    ///
    /// `handler` is called once per due moment in a tick: everything sharing
    /// a moment arrives together, oldest first, and a moment still ahead is
    /// held until it arrives. It must return only once its work has settled,
    /// because the window is committed after the last hand-over returns. An
    /// error means "do not commit": the loop returns it
    /// ([`Error::Handler`]) and the whole tick is delivered again on the
    /// next run. The claim is released however the loop ends.
    ///
    /// PHP: `run()`.
    pub async fn run<H, F>(&mut self, mut handler: H) -> Result<(), Error>
    where
        H: FnMut(Vec<Occurrence<S::Payload>>) -> F,
        F: Future<Output = Result<(), BoxError>>,
    {
        self.running.store(true, AtomicOrdering::SeqCst);
        let result = self.run_loop(&mut handler).await;
        let released = self.release().await;
        released.and(result)
    }

    async fn run_loop<H, F>(&mut self, handler: &mut H) -> Result<(), Error>
    where
        H: FnMut(Vec<Occurrence<S::Payload>>) -> F,
        F: Future<Output = Result<(), BoxError>>,
    {
        while self.running.load(AtomicOrdering::SeqCst) {
            if self.elect().await?.is_none() {
                self.clock.sleep(self.tick_seconds as f64).await;
                continue;
            }

            self.sync_if_due().await?;

            let occurrences = self.tick().await?;
            self.deliver(occurrences, handler).await?;
            self.commit().await?;

            if !self.running.load(AtomicOrdering::SeqCst) {
                break;
            }

            let tick = self.tick_seconds as f64;
            let phase = fmod(seconds(&self.clock.now()?), tick);
            let mut pause = tick - phase;
            if pause < 0.001 {
                pause += tick;
            }
            self.clock.sleep(pause).await;
        }
        Ok(())
    }

    /// Confirms or takes leadership: the current claim when this instance
    /// holds it (or just took it), `None` when another holder's claim is
    /// still live.
    async fn elect(&mut self) -> Result<Option<Claim>, Error> {
        let claim = self.store.load().await?;
        let now = seconds(&self.clock.now()?);
        let lease = self.lease_seconds as f64;

        let (expected, covered_until, synced_until) = match claim {
            Some(claim) if claim.token == self.token => {
                // Renew before the tick, not only at commit, so the lease
                // covers the dispatch ahead; half a lease of slack keeps this
                // to one extra write per lease.
                if claim.expires_at - now >= lease / 2.0 {
                    return Ok(Some(claim));
                }
                let renewed = Claim::new(self.token.clone(), now + lease, claim.covered_until, claim.synced_until);
                if self.store.swap(Some(&self.token), &renewed).await? {
                    return Ok(Some(renewed));
                }
                self.clear_pending();
                return Ok(None); // lost the claim between load and swap
            }
            Some(claim) => {
                if claim.expires_at > now {
                    return Ok(None); // another instance leads
                }
                // Coverage, and how much of the source produced it, carry
                // over from the predecessor.
                (Some(claim.token), claim.covered_until, claim.synced_until)
            }
            None => (None, None, None),
        };

        let next = Claim::new(self.token.clone(), now + lease, covered_until, synced_until);
        if !self.store.swap(expected.as_deref(), &next).await? {
            return Ok(None); // lost the takeover race
        }

        // A tick left pending from before losing leadership must never commit
        // after re-acquiring: its window predates the successor's coverage.
        self.clear_pending();
        Ok(Some(next))
    }

    /// An empty, expired claim keeps the watermark and frees the lease.
    async fn release(&mut self) -> Result<(), Error> {
        let Some(claim) = self.store.load().await? else {
            return Ok(());
        };
        if claim.token != self.token {
            return Ok(());
        }
        let released = Claim::new("", 0.0, claim.covered_until, claim.synced_until);
        self.store.swap(Some(&self.token), &released).await?;
        self.clear_pending();
        Ok(())
    }

    fn clear_pending(&mut self) {
        self.pending_covered_until = None;
        self.pending_synced_until = None;
        self.pending_one_shots.clear();
        self.pending_covered.clear();
    }

    /// Counts a failure, then hands it to the `on_error` handler, or returns
    /// it when there is none.
    fn report(&self, error: Error, stage: &'static str, row: Option<&Row<S::Data>>) -> Result<(), Error> {
        self.metrics.error(stage);
        match &self.on_error {
            Some(handler) => {
                handler(&error, row);
                Ok(())
            }
            None => Err(error),
        }
    }
}
