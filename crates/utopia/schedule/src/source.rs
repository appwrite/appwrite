//! Where schedules come from: a [`Source`] lists [`Row`]s and builds each
//! into an [`Entry`].

use std::future::Future;
use std::sync::Arc;

use php_std::datetime::DateTime;

use crate::BoxError;
use crate::trigger::Trigger;

/// One schedule as the source of truth describes it: a lightweight
/// descriptor the scheduler can diff cheaply. Building the actual schedule
/// (parsing the spec, hydrating context) is deferred to [`Source::make`] and
/// happens only when `version` changes.
///
/// PHP: `Utopia\Schedule\Source\Row`.
#[derive(Debug, Clone)]
pub struct Row<D> {
    /// Stable identity of the schedule in the source.
    pub id: String,
    /// Opaque change marker (an updated-at stamp works); a new version
    /// re-makes the entry and re-arms a delivered one-shot.
    pub version: String,
    /// Raw source data, passed through to [`Source::make`].
    pub data: D,
    /// `false` marks a soft-deleted row: the entry is removed in both full
    /// and incremental syncs.
    pub active: bool,
    /// The moment this definition takes effect; set it to the row's last
    /// change time. Occurrences before it are never delivered, and
    /// occurrences after it are delivered even when the watermark passed
    /// them before the row was discovered: late, never never. Without it,
    /// coverage falls back to the watermark.
    pub active_from: Option<DateTime>,
}

impl<D> Row<D> {
    /// An active row without an activation moment.
    ///
    /// PHP: `new Row($id, $version, $data)`.
    pub fn new(id: impl Into<String>, version: impl Into<String>, data: D) -> Self {
        Self { id: id.into(), version: version.into(), data, active: true, active_from: None }
    }

    /// The row, marked active or soft-deleted.
    ///
    /// PHP: the `active` argument.
    pub fn active(mut self, active: bool) -> Self {
        self.active = active;
        self
    }

    /// The row, taking effect at `moment`.
    ///
    /// PHP: the `activeFrom` argument.
    pub fn active_from(mut self, moment: DateTime) -> Self {
        self.active_from = Some(moment);
        self
    }
}

/// What [`Source::make`] returns for a [`Row`]: the trigger that decides
/// when it runs, and the payload handed to the dispatch handler with every
/// occurrence.
///
/// PHP: `Utopia\Schedule\Source\Entry`.
#[derive(Clone)]
pub struct Entry<P> {
    /// When the schedule runs.
    pub trigger: Arc<dyn Trigger>,
    /// Handed over with every occurrence.
    pub payload: P,
}

impl<P> Entry<P> {
    /// An entry running on `trigger`, carrying `payload`.
    ///
    /// PHP: `new Entry($trigger, $payload)`.
    pub fn new(trigger: impl Trigger + 'static, payload: P) -> Self {
        Self { trigger: Arc::new(trigger), payload }
    }

    /// An entry on a trigger shared with other entries.
    pub fn shared(trigger: Arc<dyn Trigger>, payload: P) -> Self {
        Self { trigger, payload }
    }
}

impl<P: std::fmt::Debug> std::fmt::Debug for Entry<P> {
    /// Writes the payload and whether the trigger recurs.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Entry").field("recurring", &self.trigger.recurring()).field("payload", &self.payload).finish()
    }
}

/// Where a scheduler's schedules come from, usually a database.
///
/// The scheduler converges memory to whatever [`Source::snapshot`] reports,
/// so additions, updates and removals (including hard deletes no change
/// feed can see) all follow from stating the desired set. A change feed
/// ([`Source::since`]) is only ever an optimization on top of it.
///
/// PHP: `Utopia\Schedule\Source`, with `Utopia\Schedule\Changes` folded in
/// as [`Source::since`].
pub trait Source: Send + Sync {
    /// What a row carries for [`Source::make`] (`Row::$data`).
    type Data: Send + Sync;
    /// What every occurrence carries to the handler (`Entry::$payload`).
    type Payload: Clone + Send + Sync;

    /// Every schedule that should be running, at the moment of the call. An
    /// error discards the whole batch rather than reading as a mass
    /// removal, so a failing query is safe.
    ///
    /// PHP: `snapshot()`; a generator throwing part-way is an error here.
    fn snapshot(&self) -> impl Future<Output = Result<Vec<Row<Self::Data>>, BoxError>> + Send;

    /// Builds the schedule a row describes. Called only when a row is new or
    /// its version changed, so this is where expensive work belongs. An
    /// error skips that one row: the failure is reported and the row's
    /// previous entry, if any, stays.
    ///
    /// PHP: `make()`.
    fn make(&self, row: &Row<Self::Data>) -> impl Future<Output = Result<Entry<Self::Payload>, BoxError>> + Send;

    /// Rows whose definition changed at or after `moment`, including ones
    /// that were disabled (report those inactive); `None` when the source
    /// has no change feed, which makes every sync a full snapshot.
    ///
    /// It is strictly an optimization: a change feed cannot see a hard
    /// delete, so the scheduler still takes a full snapshot every
    /// `snapshot_seconds` to converge removals.
    ///
    /// PHP: `Changes::since()`; `None` is a source that does not implement
    /// `Changes`.
    fn since(&self, moment: &DateTime) -> impl Future<Output = Option<Result<Vec<Row<Self::Data>>, BoxError>>> + Send {
        let _ = moment;
        std::future::ready(None)
    }
}

impl<T: Source + ?Sized> Source for Arc<T> {
    type Data = T::Data;
    type Payload = T::Payload;

    /// The shared source's snapshot.
    fn snapshot(&self) -> impl Future<Output = Result<Vec<Row<Self::Data>>, BoxError>> + Send {
        (**self).snapshot()
    }

    /// The shared source's entry for `row`.
    fn make(&self, row: &Row<Self::Data>) -> impl Future<Output = Result<Entry<Self::Payload>, BoxError>> + Send {
        (**self).make(row)
    }

    /// The shared source's changes.
    fn since(&self, moment: &DateTime) -> impl Future<Output = Option<Result<Vec<Row<Self::Data>>, BoxError>>> + Send {
        (**self).since(moment)
    }
}
