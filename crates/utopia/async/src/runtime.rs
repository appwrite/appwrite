//! Runtimes: [`run`], [`Runtime`] and its [`Builder`], and [`Handle`].

use std::future::Future;
use std::io;
use std::time::Duration;

use crate::Engine;
use crate::adapter::{self, Adapter};
use crate::task::JoinHandle;

/// Runs `future` to its end on a new single-threaded runtime, and returns
/// its output. Tasks it spawns run on the same thread, between its awaits.
///
/// # Panics
///
/// When the engine cannot start (it cannot open its I/O driver).
///
/// ```
/// let answer = utopia_async::run(async {
///     let task = utopia_async::spawn(async { 21 * 2 });
///     task.await.unwrap()
/// });
/// assert_eq!(answer, 42);
/// ```
///
/// PHP: `Swoole\Coroutine\run(callable $fn)`.
pub fn run<F: Future>(future: F) -> F::Output {
    Builder::current_thread().build().expect("utopia-async: the engine cannot start").block_on(future)
}

/// The scheduler of a runtime.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Flavor {
    /// Every task runs on the thread that drives the runtime
    /// ([`Runtime::block_on`]).
    ///
    /// PHP: a Swoole worker process, whose coroutines share one thread.
    CurrentThread,
    /// Tasks run on a pool of worker threads, which take work from each
    /// other when idle.
    MultiThread,
}

/// Configures a [`Runtime`].
///
/// ```
/// use utopia_async::Builder;
///
/// let runtime = Builder::multi_thread().threads(2).name("worker").build()?;
/// assert_eq!(runtime.block_on(async { 1 + 1 }), 2);
/// # Ok::<(), std::io::Error>(())
/// ```
///
/// PHP: the options of `Swoole\Coroutine\Scheduler::set()` and of a Swoole
/// server (`worker_num`, `task_worker_num`).
#[derive(Debug, Clone)]
pub struct Builder {
    pub(crate) flavor: Flavor,
    pub(crate) threads: Option<usize>,
    pub(crate) blocking_threads: Option<usize>,
    pub(crate) stack_size: Option<usize>,
    pub(crate) name: Option<String>,
}

impl Builder {
    /// A single-threaded runtime: every task runs on the thread that calls
    /// [`Runtime::block_on`].
    pub fn current_thread() -> Builder {
        Builder::new(Flavor::CurrentThread)
    }

    /// A multi-threaded runtime: tasks run on a pool of worker threads (one
    /// per CPU unless [`Builder::threads`] says otherwise).
    pub fn multi_thread() -> Builder {
        Builder::new(Flavor::MultiThread)
    }

    fn new(flavor: Flavor) -> Builder {
        Builder { flavor, threads: None, blocking_threads: None, stack_size: None, name: None }
    }

    /// The number of worker threads of a multi-threaded runtime.
    pub fn threads(&mut self, threads: usize) -> &mut Builder {
        self.threads = Some(threads);
        self
    }

    /// The most threads the blocking pool ([`crate::spawn_blocking`], file
    /// operations) starts.
    pub fn blocking_threads(&mut self, threads: usize) -> &mut Builder {
        self.blocking_threads = Some(threads);
        self
    }

    /// The stack size of the runtime's threads, in bytes.
    pub fn stack_size(&mut self, bytes: usize) -> &mut Builder {
        self.stack_size = Some(bytes);
        self
    }

    /// The name of the runtime's threads.
    pub fn name(&mut self, name: impl Into<String>) -> &mut Builder {
        self.name = Some(name.into());
        self
    }

    /// The scheduler this builder configures.
    pub fn flavor(&self) -> Flavor {
        self.flavor
    }

    /// Starts the runtime.
    pub fn build(&self) -> io::Result<Runtime> {
        Ok(Runtime { inner: adapter::Runtime::new(self)? })
    }
}

/// A running engine instance: worker threads, timers, an I/O driver and a
/// blocking pool. Dropping it stops it.
///
/// PHP: `Swoole\Coroutine\Scheduler`.
#[derive(Debug)]
pub struct Runtime {
    inner: <Engine as Adapter>::Runtime,
}

impl Runtime {
    /// A multi-threaded runtime with one worker per CPU.
    pub fn new() -> io::Result<Runtime> {
        Builder::multi_thread().build()
    }

    /// Runs `future` to its end on this runtime, driving its tasks, timers
    /// and I/O, and blocks the calling thread until then.
    ///
    /// PHP: `Swoole\Coroutine\Scheduler::start()`.
    #[inline]
    pub fn block_on<F: Future>(&self, future: F) -> F::Output {
        adapter::Runtime::block_on(&self.inner, future)
    }

    /// A handle on this runtime.
    pub fn handle(&self) -> Handle {
        Handle { inner: adapter::Runtime::handle(&self.inner) }
    }

    /// Runs `future` as a new task on this runtime.
    pub fn spawn<F>(&self, future: F) -> JoinHandle<F::Output>
    where
        F: Future + Send + 'static,
        F::Output: Send + 'static,
    {
        self.handle().spawn(future)
    }

    /// Stops the runtime, waiting up to `timeout` for blocking work.
    pub fn shutdown_timeout(self, timeout: Duration) {
        adapter::Runtime::shutdown_timeout(self.inner, timeout);
    }
}

/// A handle on a running runtime, cheap to clone and to send to other
/// threads.
#[derive(Debug, Clone)]
pub struct Handle {
    inner: <Engine as Adapter>::Handle,
}

impl Handle {
    /// The runtime the calling thread runs in; `None` outside one.
    ///
    /// PHP: `Swoole\Coroutine::getCid() > 0`.
    #[inline]
    pub fn current() -> Option<Handle> {
        <<Engine as Adapter>::Handle as adapter::Handle>::current().map(|inner| Handle { inner })
    }

    /// The runtime's scheduler.
    #[inline]
    pub fn flavor(&self) -> Flavor {
        adapter::Handle::flavor(&self.inner)
    }

    /// Runs `future` as a new task on the runtime.
    #[inline]
    pub fn spawn<F>(&self, future: F) -> JoinHandle<F::Output>
    where
        F: Future + Send + 'static,
        F::Output: Send + 'static,
    {
        JoinHandle::new(Engine::spawn_on(&self.inner, future))
    }

    /// Runs `future` to its end with the runtime's timers and I/O, blocking
    /// the calling thread. Inside a task, wrap it in
    /// [`crate::block_in_place`] first.
    #[inline]
    pub fn block_on<F: Future>(&self, future: F) -> F::Output {
        adapter::Handle::block_on(&self.inner, future)
    }
}
