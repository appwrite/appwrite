//! Scoped state: values a handle shares that a scope can override for the
//! work it runs, and the [`Snapshot`] of a handle's scoped state.
//!
//! A [`Scoped`] value has a handle-wide value and, inside
//! [`Scoped::with`] (or [`Scoped::with_sync`]), an override seen by the
//! future it runs and everything that future awaits, and by nothing else:
//! not by other tasks sharing the handle, and not by sibling futures of a
//! `join!`. A [`Scoped::set`] inside an override changes the override until
//! it ends; outside every override it changes the handle-wide value.
//!
//! Overrides live in a task-local stack of frames, so they follow the
//! future, not the thread. A task spawned with `tokio::spawn` starts
//! outside every override; to run it under the caller's, wrap it with
//! [`inherit`] (PHP's child coroutines inherit their parent's scopes; Rust
//! tasks inherit what they are given). As a PHP child coroutine does, an
//! inheriting task sees the overrides open where it was started, and its
//! own writes to them stay with it and the tasks it starts in turn: the
//! caller keeps seeing the override as it left it. Once an override ends,
//! the tasks that inherited it no longer see it, nor what they wrote to it.
//!
//! PHP: `Utopia\Database\State\Value` (per-coroutine overrides keyed by
//! coroutine id, walking parent coroutines), `State\Frame` (one override,
//! with the writes of the coroutines that inherited it), `State\Group` (the
//! open scopes deciding where a cut-off coroutine's writes go, which has no
//! counterpart: a Rust task is never cut off from the scopes it was given),
//! `State\Snapshot`.

use std::any::Any;
use std::future::Future;
use std::sync::atomic::{AtomicBool, AtomicU64, AtomicUsize, Ordering};
use std::sync::{Arc, Mutex, RwLock};

/// One link of the frames a future runs under: an open override of a
/// [`Scoped`] value, or the point where a task [`inherit`]ed its caller's
/// overrides, which keeps that task's writes to them.
///
/// PHP: `Utopia\Database\State\Frame` (the override, and its `writes` by
/// the coroutines that inherited it).
pub struct Frame {
    link: Link,
    outer: Option<Arc<Frame>>,
}

enum Link {
    /// An override of the value `id`, open while `alive`; `serial` tells it
    /// apart from every other override.
    Override { id: u64, serial: u64, alive: AtomicBool, cell: Mutex<Box<dyn Any + Send>> },
    /// A task's inheritance: its writes to inherited overrides, by override
    /// serial.
    Fork { writes: Mutex<Vec<(u64, Box<dyn Any + Send>)>> },
}

/// The frames a future runs under.
type Frames = Option<Arc<Frame>>;

tokio::task_local! {
    static FRAMES: Frames;
}

static NEXT_ID: AtomicU64 = AtomicU64::new(1);

/// The frames of the current future, if it runs under any.
fn current() -> Frames {
    FRAMES.try_with(Clone::clone).ok().flatten()
}

/// `future`, run under the overrides the caller runs under (for a task
/// spawned on its behalf). The task's writes to those overrides stay with
/// it (and the tasks it starts with `inherit` in turn).
///
/// ```
/// use std::sync::Arc;
/// use utopia_database::state::{Scoped, inherit};
///
/// # tokio_test_block(async {
/// let tenant = Arc::new(Scoped::new(1));
/// let (seen, after) = tenant.with(2, {
///     let tenant = tenant.clone();
///     async move {
///         let child = tenant.clone();
///         let seen = tokio::spawn(inherit(async move {
///             let seen = child.get();
///             child.set(3);
///             seen
///         }))
///         .await
///         .unwrap();
///         (seen, tenant.get())
///     }
/// }).await;
/// assert_eq!((seen, after), (2, 2));
/// # });
/// # fn tokio_test_block(f: impl std::future::Future<Output = ()>) {
/// #     tokio::runtime::Builder::new_current_thread().enable_all().build().unwrap().block_on(f)
/// # }
/// ```
pub fn inherit<F: Future>(future: F) -> impl Future<Output = F::Output> {
    let frames = current()
        .map(|outer| Arc::new(Frame { link: Link::Fork { writes: Mutex::new(Vec::new()) }, outer: Some(outer) }));
    FRAMES.scope(frames, future)
}

/// A value shared by a handle that scopes can override.
///
/// PHP: `Utopia\Database\State\Value`.
pub struct Scoped<T> {
    id: u64,
    shared: RwLock<T>,
    open: AtomicUsize,
}

impl<T: std::fmt::Debug> std::fmt::Debug for Scoped<T> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Scoped").field("shared", &self.shared).finish()
    }
}

impl<T: Default + Clone + Send + Sync + 'static> Default for Scoped<T> {
    fn default() -> Self {
        Scoped::new(T::default())
    }
}

/// Ends an override when its scope ends, also on unwind or cancellation:
/// the frame stops being seen, and the open count drops.
struct Open<'a> {
    count: &'a AtomicUsize,
    frame: Arc<Frame>,
}

impl Drop for Open<'_> {
    fn drop(&mut self) {
        if let Link::Override { alive, .. } = &self.frame.link {
            alive.store(false, Ordering::Release);
        }
        self.count.fetch_sub(1, Ordering::AcqRel);
    }
}

fn lock<V: ?Sized>(m: &Mutex<V>) -> std::sync::MutexGuard<'_, V> {
    m.lock().unwrap_or_else(|e| e.into_inner())
}

/// Where the current future reads and writes a scoped value.
enum Slot {
    /// The handle-wide value.
    Shared,
    /// The cell of an override the future opened (or awaits inside).
    Override(Arc<Frame>),
    /// A fork's write to an inherited override (`serial`), when it has one;
    /// else the override's cell.
    Inherited { fork: Arc<Frame>, frame: Arc<Frame>, serial: u64 },
}

impl<T: Clone + Send + Sync + 'static> Scoped<T> {
    /// A value with the handle-wide `value`.
    pub fn new(value: T) -> Self {
        Scoped { id: NEXT_ID.fetch_add(1, Ordering::Relaxed), shared: RwLock::new(value), open: AtomicUsize::new(0) }
    }

    /// The innermost open override of this value the current future runs
    /// under, and the nearest fork between the future and it.
    fn slot(&self) -> Slot {
        if self.open.load(Ordering::Acquire) == 0 {
            return Slot::Shared;
        }
        let mut fork: Option<Arc<Frame>> = None;
        let mut frame = current();
        while let Some(f) = frame {
            match &f.link {
                Link::Override { id, serial, alive, .. } if *id == self.id && alive.load(Ordering::Acquire) => {
                    let serial = *serial;
                    return match fork {
                        None => Slot::Override(f),
                        Some(fork) => Slot::Inherited { fork, frame: f, serial },
                    };
                }
                Link::Fork { .. } if fork.is_none() => fork = Some(f.clone()),
                _ => {}
            }
            frame = f.outer.clone();
        }
        Slot::Shared
    }

    /// The value the current future sees: its innermost override (as this
    /// task, or a task it inherited from, wrote it), else the handle-wide
    /// value (`get()`).
    pub fn get(&self) -> T {
        let override_value = |frame: &Frame| match &frame.link {
            Link::Override { cell, .. } => lock(cell).downcast_ref::<T>().cloned(),
            Link::Fork { .. } => None,
        };
        let found = match self.slot() {
            Slot::Shared => None,
            Slot::Override(frame) => override_value(&frame),
            Slot::Inherited { fork, frame, serial } => {
                // The nearest fork holding a write wins: this task's, else
                // the task it inherited from, up to the override.
                let mut link = Some(fork);
                let mut written = None;
                while let Some(f) = link {
                    if Arc::ptr_eq(&f, &frame) {
                        break;
                    }
                    if let Link::Fork { writes } = &f.link
                        && let Some((_, v)) = lock(writes).iter().find(|(s, _)| *s == serial)
                    {
                        written = v.downcast_ref::<T>().cloned();
                        break;
                    }
                    link = f.outer.clone();
                }
                written.or_else(|| override_value(&frame))
            }
        };
        found.unwrap_or_else(|| self.shared())
    }

    /// Sets the value the current future sees: its innermost override until
    /// that ends (for a task that inherited the override, its own view of
    /// it), else the handle-wide value (`set()`).
    pub fn set(&self, value: T) {
        match self.slot() {
            Slot::Shared => *self.shared.write().unwrap_or_else(|e| e.into_inner()) = value,
            Slot::Override(frame) => {
                if let Link::Override { cell, .. } = &frame.link {
                    *lock(cell) = Box::new(value);
                }
            }
            Slot::Inherited { fork, serial, .. } => {
                if let Link::Fork { writes } = &fork.link {
                    let mut writes = lock(writes);
                    match writes.iter_mut().find(|(s, _)| *s == serial) {
                        Some(entry) => entry.1 = Box::new(value),
                        None => writes.push((serial, Box::new(value))),
                    }
                }
            }
        }
    }

    /// The handle-wide value, whatever the current future's overrides.
    pub fn shared(&self) -> T {
        self.shared.read().unwrap_or_else(|e| e.into_inner()).clone()
    }

    fn open(&self, value: T) -> (Open<'_>, Arc<Frame>) {
        self.open.fetch_add(1, Ordering::AcqRel);
        let frame = Arc::new(Frame {
            link: Link::Override {
                id: self.id,
                serial: NEXT_ID.fetch_add(1, Ordering::Relaxed),
                alive: AtomicBool::new(true),
                cell: Mutex::new(Box::new(value)),
            },
            outer: current(),
        });
        (Open { count: &self.open, frame: frame.clone() }, frame)
    }

    /// Runs `future` with the value overridden to `value` for it and what it
    /// awaits (`with()`).
    pub async fn with<F: Future>(&self, value: T, future: F) -> F::Output {
        let (_open, frame) = self.open(value);
        FRAMES.scope(Some(frame), future).await
    }

    /// Runs `callback` with the value overridden to `value` (`with()` for
    /// work that does not await).
    pub fn with_sync<R>(&self, value: T, callback: impl FnOnce() -> R) -> R {
        let (_open, frame) = self.open(value);
        FRAMES.sync_scope(Some(frame), callback)
    }
}

/// The scoped state a database handle's reads depend on, as one future
/// sees it: taken with the database's `snapshot()` and applied with
/// `withSnapshot()`, it lets work started elsewhere run under its caller's
/// state.
///
/// PHP: `Utopia\Database\State\Snapshot`.
#[derive(Debug, Clone, PartialEq)]
pub struct Snapshot {
    /// Whether authorization checks apply.
    pub authorization: bool,
    /// The roles authorization checks against.
    pub roles: Vec<String>,
    /// Whether relationships are populated and written.
    pub relationships: bool,
    /// Whether related documents must exist before they are linked.
    pub exist_check: bool,
    /// Whether a relationship population is already running.
    pub population: bool,
    /// Whether every lifecycle hook is silenced.
    pub silenced: bool,
    /// The names of the silenced named lifecycle hooks.
    pub silenced_listeners: Vec<String>,
    /// The tenant reads and writes use (`null`, an integer or a string).
    pub tenant: crate::Value,
    /// Whether attribute filters apply.
    pub filters: bool,
    /// The names of the attribute filters that do not apply, with whether
    /// each is disabled; `None` for none.
    pub disabled_filters: Option<Vec<(String, bool)>>,
    /// Whether documents and queries are validated.
    pub validation: bool,
    /// Whether writes keep the dates they are given.
    pub preserve_dates: bool,
    /// Whether writes keep the sequences they are given.
    pub preserve_sequence: bool,
    /// Whether creating a document that exists is skipped.
    pub ignore_duplicates: bool,
    /// The time an update conflicts after, in the database format.
    pub request_timestamp: Option<String>,
    /// Whether a related document may only be given permissions for roles
    /// the caller holds.
    pub check_grants: bool,
    /// Whether query hooks rewrite the queries of reads.
    pub rewriting: bool,
}

#[cfg(test)]
mod tests {
    use super::*;

    fn block_on<F: Future>(f: F) -> F::Output {
        tokio::runtime::Builder::new_current_thread().enable_all().build().unwrap().block_on(f)
    }

    #[test]
    fn overrides_are_scoped_to_their_future() {
        block_on(async {
            let value = Scoped::new(1);
            assert_eq!(value.get(), 1);
            let inner = value
                .with(2, async {
                    assert_eq!(value.get(), 2);
                    value.set(3);
                    let nested = value.with(4, async { value.get() }).await;
                    (nested, value.get())
                })
                .await;
            assert_eq!(inner, (4, 3));
            assert_eq!(value.get(), 1);
            value.set(5);
            assert_eq!(value.with_sync(6, || value.get()), 6);
            assert_eq!(value.get(), 5);
        });
    }

    #[test]
    fn siblings_do_not_see_each_others_overrides() {
        block_on(async {
            let value = Scoped::new(0);
            let (a, b) = futures_util::join!(
                value.with(1, async {
                    tokio::task::yield_now().await;
                    value.get()
                }),
                async {
                    tokio::task::yield_now().await;
                    value.get()
                }
            );
            assert_eq!((a, b), (1, 0));
        });
    }
}
