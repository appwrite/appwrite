//! Tasks: [`spawn`], [`spawn_blocking`], [`block_in_place`], [`yield_now`],
//! their [`JoinHandle`]s and [`JoinSet`]s, and task ids.

use std::any::Any;
use std::fmt;
use std::future::Future;
use std::pin::Pin;
use std::task::{Context, Poll};

use futures_util::stream::{FuturesUnordered, StreamExt};

use crate::Engine;
use crate::adapter::{self, Adapter};

#[cfg(feature = "tokio")]
#[doc(no_inline)]
pub use ::tokio::task::LocalKey;

/// Runs `future` as a new task on the current runtime, concurrently with the
/// caller. The task keeps running when its handle is dropped; await the
/// handle for its output.
///
/// # Panics
///
/// Outside a runtime.
///
/// ```
/// utopia_async::run(async {
///     let task = utopia_async::spawn(async { "done" });
///     assert_eq!(task.await.unwrap(), "done");
/// });
/// ```
///
/// PHP: `go(callable $fn)`, `Swoole\Coroutine::create()`.
#[inline]
pub fn spawn<F>(future: F) -> JoinHandle<F::Output>
where
    F: Future + Send + 'static,
    F::Output: Send + 'static,
{
    JoinHandle::new(Engine::spawn(future))
}

/// Runs blocking work (CPU-heavy work, a blocking library call) on the
/// engine's blocking pool, keeping the runtime's workers free.
///
/// # Panics
///
/// Outside a runtime.
///
/// PHP: a Swoole task worker (`$server->task()`).
#[inline]
pub fn spawn_blocking<F, R>(work: F) -> JoinHandle<R>
where
    F: FnOnce() -> R + Send + 'static,
    R: Send + 'static,
{
    JoinHandle::new(Engine::spawn_blocking(work))
}

/// Runs blocking work on the current thread without a trip to the blocking
/// pool. On a multi-threaded runtime the worker first hands its other tasks
/// to another worker; on a single-threaded runtime, or outside a runtime,
/// the work runs in place.
///
/// PHP: a blocking call inside a coroutine, which blocks its worker.
#[inline]
pub fn block_in_place<F: FnOnce() -> R, R>(work: F) -> R {
    Engine::block_in_place(work)
}

/// Gives the runtime's other tasks a turn before continuing.
///
/// PHP: `Swoole\Coroutine::yield()` then `resume()`.
#[inline]
pub async fn yield_now() {
    Engine::yield_now().await;
}

/// The id of the task running now; `None` outside a task.
///
/// PHP: `Swoole\Coroutine::getCid()`.
#[inline]
pub fn id() -> Option<Id> {
    Engine::task_id().map(Id)
}

/// The output of `task`, resuming its panic.
///
/// # Panics
///
/// When the task panicked (with its panic) or was cancelled.
pub(crate) async fn join<T: Send + 'static>(task: JoinHandle<T>) -> T {
    match task.await {
        Ok(output) => output,
        Err(error) if error.is_panic() => std::panic::resume_unwind(error.into_panic()),
        Err(error) => panic!("{error}"),
    }
}

/// A task id: unique among the tasks alive at the same time.
#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Hash)]
pub struct Id(u64);

impl Id {
    /// The id as a number.
    pub fn as_u64(self) -> u64 {
        self.0
    }
}

impl fmt::Display for Id {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        self.0.fmt(f)
    }
}

/// A spawned task. Await it for the task's output, or a [`JoinError`] when
/// the task panicked or was cancelled. Dropping it detaches the task.
pub struct JoinHandle<T> {
    inner: <Engine as Adapter>::JoinHandle<T>,
}

impl<T> JoinHandle<T> {
    #[inline]
    pub(crate) fn new(inner: <Engine as Adapter>::JoinHandle<T>) -> Self {
        JoinHandle { inner }
    }

    /// Cancels the task: it stops at its next await point, and awaiting the
    /// handle gives a cancelled [`JoinError`]. Blocking work cannot be
    /// cancelled once it runs.
    #[inline]
    pub fn abort(&self) {
        adapter::JoinHandle::abort(&self.inner);
    }

    /// Whether the task has ended (finished, panicked or been cancelled).
    #[inline]
    pub fn is_finished(&self) -> bool {
        adapter::JoinHandle::is_finished(&self.inner)
    }

    /// The task's id.
    #[inline]
    pub fn id(&self) -> Id {
        Id(adapter::JoinHandle::id(&self.inner))
    }
}

impl<T> Future for JoinHandle<T> {
    type Output = Result<T, JoinError>;

    #[inline]
    fn poll(mut self: Pin<&mut Self>, cx: &mut Context<'_>) -> Poll<Self::Output> {
        Pin::new(&mut self.inner).poll(cx)
    }
}

impl<T> fmt::Debug for JoinHandle<T> {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("JoinHandle").field("id", &self.id()).finish()
    }
}

/// Why a task has no output: it panicked or was cancelled.
pub struct JoinError {
    id: Id,
    panic: Option<Panic>,
}

/// A panic's payload, shared only as the `Sync` types it can be read as, so
/// a [`JoinError`] can be shared between threads (and be an error any error
/// type can box).
struct Panic(Box<dyn Any + Send + 'static>);

// SAFETY: a shared `Panic` only hands out `&T` for `T: Sync`
// (`Panic::downcast_ref`); the payload itself is only reached by value.
unsafe impl Sync for Panic {}

impl Panic {
    fn downcast_ref<T: Any + Sync>(&self) -> Option<&T> {
        self.0.downcast_ref::<T>()
    }
}

impl JoinError {
    /// The task `id` was cancelled. For adapters.
    pub fn cancelled(id: u64) -> JoinError {
        JoinError { id: Id(id), panic: None }
    }

    /// The task `id` panicked with `payload`. For adapters.
    pub fn panicked(id: u64, payload: Box<dyn Any + Send + 'static>) -> JoinError {
        JoinError { id: Id(id), panic: Some(Panic(payload)) }
    }

    /// Whether the task was cancelled.
    pub fn is_cancelled(&self) -> bool {
        self.panic.is_none()
    }

    /// Whether the task panicked.
    pub fn is_panic(&self) -> bool {
        self.panic.is_some()
    }

    /// The task's id.
    pub fn id(&self) -> Id {
        self.id
    }

    /// The panic's payload, to resume it with [`std::panic::resume_unwind`].
    ///
    /// # Panics
    ///
    /// When the task was cancelled.
    pub fn into_panic(self) -> Box<dyn Any + Send + 'static> {
        self.try_into_panic().expect("`JoinError` reason is not a panic.")
    }

    /// The panic's payload; the error itself when the task was cancelled.
    pub fn try_into_panic(self) -> Result<Box<dyn Any + Send + 'static>, JoinError> {
        match self.panic {
            Some(Panic(payload)) => Ok(payload),
            None => Err(self),
        }
    }

    fn message(&self) -> Option<&str> {
        let payload = self.panic.as_ref()?;
        payload.downcast_ref::<&'static str>().copied().or_else(|| payload.downcast_ref::<String>().map(String::as_str))
    }
}

impl fmt::Display for JoinError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match (&self.panic, self.message()) {
            (None, _) => write!(f, "task {} was cancelled", self.id),
            (Some(_), Some(message)) => write!(f, "task {} panicked with message {message:?}", self.id),
            (Some(_), None) => write!(f, "task {} panicked", self.id),
        }
    }
}

impl fmt::Debug for JoinError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match (&self.panic, self.message()) {
            (None, _) => write!(f, "JoinError::Cancelled({:?})", self.id),
            (Some(_), Some(message)) => write!(f, "JoinError::Panic({:?}, {message:?}, ...)", self.id),
            (Some(_), None) => write!(f, "JoinError::Panic({:?}, ...)", self.id),
        }
    }
}

impl std::error::Error for JoinError {}

impl From<JoinError> for std::io::Error {
    fn from(error: JoinError) -> std::io::Error {
        std::io::Error::other(error.to_string())
    }
}

/// A set of tasks awaited in the order they end. Dropping the set cancels
/// the tasks still running.
///
/// ```
/// use utopia_async::task::JoinSet;
///
/// utopia_async::run(async {
///     let mut set = JoinSet::new();
///     for n in 1..=3 {
///         set.spawn(async move { n * 10 });
///     }
///     let mut total = 0;
///     while let Some(result) = set.join_next().await {
///         total += result.unwrap();
///     }
///     assert_eq!(total, 60);
/// });
/// ```
///
/// PHP: `Swoole\Coroutine\WaitGroup` over coroutines started with `go()`.
pub struct JoinSet<T: Send + 'static> {
    tasks: FuturesUnordered<JoinHandle<T>>,
}

impl<T: Send + 'static> JoinSet<T> {
    /// An empty set.
    pub fn new() -> Self {
        JoinSet { tasks: FuturesUnordered::new() }
    }

    /// Runs `future` as a new task in the set.
    pub fn spawn<F>(&mut self, future: F) -> Id
    where
        F: Future<Output = T> + Send + 'static,
    {
        let task = spawn(future);
        let id = task.id();
        self.tasks.push(task);
        id
    }

    /// Runs blocking work on the blocking pool as a task of the set.
    pub fn spawn_blocking<F>(&mut self, work: F) -> Id
    where
        F: FnOnce() -> T + Send + 'static,
    {
        let task = spawn_blocking(work);
        let id = task.id();
        self.tasks.push(task);
        id
    }

    /// The next task to end and its result; `None` when the set is empty.
    pub async fn join_next(&mut self) -> Option<Result<T, JoinError>> {
        self.tasks.next().await
    }

    /// Waits for every task and returns their outputs in the order they
    /// ended, skipping cancelled tasks and resuming a task's panic.
    pub async fn join_all(mut self) -> Vec<T> {
        let mut outputs = Vec::with_capacity(self.len());
        while let Some(result) = self.join_next().await {
            match result {
                Ok(output) => outputs.push(output),
                Err(error) if error.is_panic() => std::panic::resume_unwind(error.into_panic()),
                Err(_) => {}
            }
        }
        outputs
    }

    /// Cancels every task; they stay in the set until joined.
    pub fn abort_all(&mut self) {
        for task in self.tasks.iter() {
            task.abort();
        }
    }

    /// Cancels every task and waits for them to stop.
    pub async fn shutdown(&mut self) {
        self.abort_all();
        while self.join_next().await.is_some() {}
    }

    /// The number of tasks in the set.
    pub fn len(&self) -> usize {
        self.tasks.len()
    }

    /// Whether the set has no tasks.
    pub fn is_empty(&self) -> bool {
        self.tasks.is_empty()
    }
}

impl<T: Send + 'static> Default for JoinSet<T> {
    fn default() -> Self {
        Self::new()
    }
}

impl<T: Send + 'static> Drop for JoinSet<T> {
    fn drop(&mut self) {
        self.abort_all();
    }
}

impl<T: Send + 'static> fmt::Debug for JoinSet<T> {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("JoinSet").field("len", &self.len()).finish()
    }
}
