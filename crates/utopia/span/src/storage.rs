//! Where the current span is kept.

use std::cell::RefCell;
use std::future::Future;
use std::sync::Mutex;

use crate::Span;

/// Keeps the current span of an execution context, so [`Span::add`] and
/// [`Span::current`] work without passing spans around.
///
/// Implement it to keep spans elsewhere (a thread-local, a request
/// extension); register it with [`Span::set_storage`].
///
/// PHP: `Utopia\Span\Storage\Storage`.
pub trait Storage: Send + Sync {
    /// The current span of this context, if any.
    ///
    /// PHP: `get()`.
    fn get(&self) -> Option<Span>;

    /// Sets (or clears, with `None`) the current span of this context.
    ///
    /// PHP: `set($span)`.
    fn set(&self, span: Option<Span>);
}

/// One current span for the whole process.
///
/// PHP: `Utopia\Span\Storage\Memory`, for FPM and CLI processes that handle
/// one request at a time.
///
/// ```
/// use utopia_span::{Memory, Span, Storage};
///
/// let storage = Memory::new();
/// assert!(storage.get().is_none());
///
/// let span = Span::new("job");
/// storage.set(Some(span.clone()));
/// assert_eq!(storage.get(), Some(span));
/// storage.set(None);
/// assert!(storage.get().is_none());
/// ```
///
/// ```php
/// use Utopia\Span\Span;
/// use Utopia\Span\Storage\Memory;
///
/// $storage = new Memory();
/// var_dump($storage->get()); // NULL
///
/// $span = new Span('job');
/// $storage->set($span);
/// var_dump($storage->get() === $span); // bool(true)
/// $storage->set(null);
/// var_dump($storage->get()); // NULL
/// ```
#[derive(Debug, Default)]
pub struct Memory {
    span: Mutex<Option<Span>>,
}

impl Memory {
    /// An empty storage.
    ///
    /// PHP: `new Memory()`.
    pub fn new() -> Self {
        Self::default()
    }
}

impl Storage for Memory {
    fn get(&self) -> Option<Span> {
        self.span.lock().unwrap_or_else(|e| e.into_inner()).clone()
    }

    fn set(&self, span: Option<Span>) {
        *self.span.lock().unwrap_or_else(|e| e.into_inner()) = span;
    }
}

utopia_async::task_local! {
    static CURRENT: RefCell<Option<Span>>;
}

/// One current span per task.
///
/// A task gets its own slot by running inside [`Task::scope`]; outside a
/// scope there is no current span and [`Storage::set`] does nothing.
///
/// PHP: `Utopia\Span\Storage\Coroutine`, which keeps the span in the Swoole
/// coroutine's context (`Coroutine::getContext()`); a scope is a coroutine.
///
/// ```
/// use utopia_span::{Span, Storage, Task};
///
/// # utopia_async::Builder::current_thread().build().unwrap().block_on(async {
/// let storage = Task::new();
/// storage.set(Some(Span::new("ignored")));
/// assert!(storage.get().is_none()); // outside a scope
///
/// Task::scope(async {
///     let storage = Task::new();
///     let span = Span::new("request");
///     storage.set(Some(span.clone()));
///     assert_eq!(storage.get(), Some(span));
/// })
/// .await;
/// # });
/// ```
///
/// ```php
/// use Utopia\Span\Span;
/// use Utopia\Span\Storage\Coroutine;
///
/// $storage = new Coroutine();
/// $storage->set(new Span('ignored'));
/// var_dump($storage->get()); // NULL: outside a coroutine
///
/// \Swoole\Coroutine\run(function () {
///     $storage = new Coroutine();
///     $span = new Span('request');
///     $storage->set($span);
///     var_dump($storage->get() === $span); // bool(true)
/// });
/// ```
#[derive(Debug, Default, Clone, Copy)]
pub struct Task;

impl Task {
    /// The task storage.
    ///
    /// PHP: `new Coroutine()`.
    pub fn new() -> Self {
        Task
    }

    /// Runs `future` with its own current-span slot, initially empty.
    ///
    /// PHP: running code in a new Swoole coroutine.
    pub fn scope<F: Future>(future: F) -> impl Future<Output = F::Output> {
        CURRENT.scope(RefCell::new(None), future)
    }

    /// Runs `f` synchronously with its own current-span slot.
    pub fn sync_scope<R>(f: impl FnOnce() -> R) -> R {
        CURRENT.sync_scope(RefCell::new(None), f)
    }

    /// Whether the caller runs inside a [`Task::scope`].
    ///
    /// PHP: `Coroutine::getCid() !== -1`.
    pub fn in_scope() -> bool {
        CURRENT.try_with(|_| ()).is_ok()
    }
}

impl Storage for Task {
    fn get(&self) -> Option<Span> {
        CURRENT.try_with(|c| c.borrow().clone()).ok().flatten()
    }

    fn set(&self, span: Option<Span>) {
        let _ = CURRENT.try_with(|c| *c.borrow_mut() = span);
    }
}

/// The task's span inside a [`Task::scope`], the process's span outside.
///
/// PHP: `Utopia\Span\Storage\Auto`, which uses the coroutine storage inside
/// a Swoole coroutine and the memory storage elsewhere.
///
/// ```
/// use utopia_span::{Auto, Span, Storage};
///
/// let storage = Auto::new();
/// let span = Span::new("cli");
/// storage.set(Some(span.clone()));
/// assert_eq!(storage.get(), Some(span));
/// ```
///
/// ```php
/// use Utopia\Span\Span;
/// use Utopia\Span\Storage\Auto;
///
/// $storage = new Auto();
/// $span = new Span('cli');
/// $storage->set($span);
/// var_dump($storage->get() === $span); // bool(true)
/// ```
#[derive(Debug, Default)]
pub struct Auto {
    memory: Memory,
}

impl Auto {
    /// An empty storage.
    ///
    /// PHP: `new Auto()`.
    pub fn new() -> Self {
        Self::default()
    }
}

impl Storage for Auto {
    fn get(&self) -> Option<Span> {
        match CURRENT.try_with(|c| c.borrow().clone()) {
            Ok(span) => span,
            Err(_) => self.memory.get(),
        }
    }

    fn set(&self, span: Option<Span>) {
        if let Err(_outside) = CURRENT.try_with(|c| *c.borrow_mut() = span.clone()) {
            self.memory.set(span);
        }
    }
}
