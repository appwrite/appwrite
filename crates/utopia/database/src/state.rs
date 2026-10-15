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
//! tasks inherit what they are given).
//!
//! PHP: `Utopia\Database\State\Value` (per-coroutine overrides keyed by
//! coroutine id, walking parent coroutines), `State\Frame` (one override),
//! `State\Group` (the open scopes deciding where a cut-off coroutine's
//! writes go, which has no counterpart: a Rust task is never cut off from
//! the scopes it was given), `State\Snapshot`.

use std::any::Any;
use std::future::Future;
use std::sync::atomic::{AtomicU64, AtomicUsize, Ordering};
use std::sync::{Arc, Mutex, RwLock};

/// One open override: the scoped value it overrides, its cell, and the
/// override it is nested in.
///
/// PHP: `Utopia\Database\State\Frame`.
pub struct Frame {
    id: u64,
    cell: Mutex<Box<dyn Any + Send>>,
    outer: Option<Arc<Frame>>,
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
/// spawned on its behalf).
///
/// ```
/// use std::sync::Arc;
/// use utopia_database::state::{Scoped, inherit};
///
/// # tokio_test_block(async {
/// let tenant = Arc::new(Scoped::new(1));
/// let seen = tenant.with(2, {
///     let tenant = tenant.clone();
///     async move { tokio::spawn(inherit(async move { tenant.get() })).await.unwrap() }
/// }).await;
/// assert_eq!(seen, 2);
/// # });
/// # fn tokio_test_block(f: impl std::future::Future<Output = ()>) {
/// #     tokio::runtime::Builder::new_current_thread().enable_all().build().unwrap().block_on(f)
/// # }
/// ```
pub fn inherit<F: Future>(future: F) -> impl Future<Output = F::Output> {
    FRAMES.scope(current(), future)
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

/// Decrements the open count when an override ends, also on unwind or
/// cancellation.
struct Open<'a>(&'a AtomicUsize);

impl Drop for Open<'_> {
    fn drop(&mut self) {
        self.0.fetch_sub(1, Ordering::AcqRel);
    }
}

impl<T: Clone + Send + Sync + 'static> Scoped<T> {
    /// A value with the handle-wide `value`.
    pub fn new(value: T) -> Self {
        Scoped { id: NEXT_ID.fetch_add(1, Ordering::Relaxed), shared: RwLock::new(value), open: AtomicUsize::new(0) }
    }

    /// The innermost override of this value the current future runs under.
    fn frame(&self) -> Option<Arc<Frame>> {
        let mut frame = current();
        while let Some(f) = frame {
            if f.id == self.id {
                return Some(f);
            }
            frame = f.outer.clone();
        }
        None
    }

    /// The value the current future sees: its innermost override, else the
    /// handle-wide value (`get()`).
    pub fn get(&self) -> T {
        if self.open.load(Ordering::Acquire) > 0
            && let Some(frame) = self.frame()
            && let Some(value) = frame.cell.lock().unwrap_or_else(|e| e.into_inner()).downcast_ref::<T>()
        {
            return value.clone();
        }
        self.shared.read().unwrap_or_else(|e| e.into_inner()).clone()
    }

    /// Sets the value the current future sees: its innermost override until
    /// that ends, else the handle-wide value (`set()`).
    pub fn set(&self, value: T) {
        if self.open.load(Ordering::Acquire) > 0
            && let Some(frame) = self.frame()
        {
            *frame.cell.lock().unwrap_or_else(|e| e.into_inner()) = Box::new(value);
            return;
        }
        *self.shared.write().unwrap_or_else(|e| e.into_inner()) = value;
    }

    /// The handle-wide value, whatever the current future's overrides.
    pub fn shared(&self) -> T {
        self.shared.read().unwrap_or_else(|e| e.into_inner()).clone()
    }

    fn push(&self, value: T) -> Frames {
        Some(Arc::new(Frame { id: self.id, cell: Mutex::new(Box::new(value)), outer: current() }))
    }

    /// Runs `future` with the value overridden to `value` for it and what it
    /// awaits (`with()`).
    pub async fn with<F: Future>(&self, value: T, future: F) -> F::Output {
        self.open.fetch_add(1, Ordering::AcqRel);
        let _open = Open(&self.open);
        FRAMES.scope(self.push(value), future).await
    }

    /// Runs `callback` with the value overridden to `value` (`with()` for
    /// work that does not await).
    pub fn with_sync<R>(&self, value: T, callback: impl FnOnce() -> R) -> R {
        self.open.fetch_add(1, Ordering::AcqRel);
        let _open = Open(&self.open);
        FRAMES.sync_scope(self.push(value), callback)
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
