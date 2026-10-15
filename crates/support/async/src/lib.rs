//! The async engine behind one adapter: tasks, timers, sockets, files,
//! processes and signals for every Utopia crate, on an engine chosen at
//! compile time.
//!
//! Utopia crates never name an engine. They spawn tasks, sleep, connect and
//! read files through this crate, which forwards each call to the engine of
//! the build: [`Engine`], an [`Adapter`]. Tokio is the default
//! ([`Tokio`], feature `tokio`). Another engine is a new adapter in
//! [`adapter`] and a feature flag; no other crate changes.
//!
//! The engine is chosen at compile time, so a call is a direct call into it:
//! no boxing, no dynamic dispatch. Each engine type is wrapped in a type of
//! this crate with only the operations the [`Adapter`] contract promises, so
//! code cannot come to rely on one engine's extras.
//!
//! ```
//! use std::time::Duration;
//!
//! use utopia_async::sync::mpsc;
//!
//! utopia_async::run(async {
//!     let (sender, mut receiver) = mpsc::channel(8);
//!     for worker in 0..3 {
//!         let sender = sender.clone();
//!         utopia_async::spawn(async move {
//!             utopia_async::sleep(Duration::from_millis(worker)).await;
//!             sender.send(worker).await.unwrap();
//!         });
//!     }
//!     drop(sender);
//!
//!     let mut done = Vec::new();
//!     while let Some(worker) = receiver.recv().await {
//!         done.push(worker);
//!     }
//!     done.sort();
//!     assert_eq!(done, [0, 1, 2]);
//! });
//! ```
//!
//! ```php
//! use Swoole\Coroutine;
//! use Swoole\Coroutine\Channel;
//!
//! use function Swoole\Coroutine\run;
//!
//! run(function () {
//!     $channel = new Channel(8);
//!     foreach ([0, 1, 2] as $worker) {
//!         go(function () use ($channel, $worker) {
//!             Coroutine::sleep($worker / 1000);
//!             $channel->push($worker);
//!         });
//!     }
//!
//!     $done = [];
//!     foreach ([0, 1, 2] as $_) {
//!         $done[] = $channel->pop();
//!     }
//!     sort($done);
//!     echo json_encode($done), "\n"; // [0,1,2]
//! });
//! ```
//!
//! Tests run on a fresh single-threaded runtime with
//! [`#[utopia_async::test]`](test) (`threads = N` for a multi-threaded
//! one).
//!
//! PHP: the Swoole extension, which every Utopia library uses through its
//! own `Adapter\Swoole` classes. There is no PHP library; the table maps
//! Swoole's API.
//!
//! | PHP (Swoole) | Rust |
//! |---|---|
//! | the Swoole extension | [`Engine`]: the [`Adapter`] of the build ([`Tokio`]) |
//! | `Swoole\Coroutine\run()` | [`run`] |
//! | `Swoole\Coroutine\Scheduler` | [`Runtime`], [`Builder`] |
//! | `go()`, `Swoole\Coroutine::create()` | [`spawn`] → [`JoinHandle`] |
//! | `Swoole\Coroutine::getCid()` | [`task::id`], [`Handle::current`] |
//! | `Swoole\Coroutine::getContext()` | [`task_local!`] |
//! | `Swoole\Coroutine::sleep()`, `usleep()` | [`sleep`], [`time::sleep_until`] |
//! | a call's `$timeout` | [`timeout`] |
//! | `Swoole\Timer::tick()`, `after()` | [`time::interval`], [`spawn`] and [`sleep`] |
//! | `Swoole\Coroutine\WaitGroup` | [`task::JoinSet`] |
//! | `Swoole\Coroutine\Channel`, `Lock`, `Barrier` | [`sync`] |
//! | task workers (`$server->task()`) | [`spawn_blocking`], [`block_in_place`] |
//! | `Swoole\Coroutine\Socket`, `Client`, `Server` | [`net`] |
//! | the coroutine hooks over files and streams | [`fs`], [`io`] |
//! | `proc_open()`, `Swoole\Coroutine\System::exec()` | [`process`] |
//! | `Swoole\Process::signal()` | [`signal`] |

#![warn(missing_docs)]

extern crate self as utopia_async;

pub mod adapter;
pub mod fs;
#[cfg(feature = "hyper")]
pub mod hyper;
pub mod io;
pub mod net;
pub mod process;
mod runtime;
pub mod signal;
pub mod sync;
pub mod task;
pub mod time;

pub use adapter::Adapter;
#[cfg(feature = "tokio")]
pub use adapter::Tokio;
pub use runtime::{Builder, Flavor, Handle, Runtime, run};
pub use task::{JoinError, JoinHandle, block_in_place, spawn, spawn_blocking, yield_now};
pub use time::{sleep, timeout};

/// Runs an async test function to its end on a new runtime: single-threaded,
/// or multi-threaded with `threads = N` workers.
///
/// ```
/// #[utopia_async::test]
/// async fn adds() {
///     let task = utopia_async::spawn(async { 1 + 1 });
///     assert_eq!(task.await.unwrap(), 2);
/// }
///
/// #[utopia_async::test(threads = 2)]
/// async fn adds_in_parallel() -> std::io::Result<()> {
///     let task = utopia_async::spawn_blocking(|| 2 + 2);
///     assert_eq!(task.await?, 4);
///     Ok(())
/// }
/// ```
///
/// PHP: a PHPUnit test whose body runs in `Swoole\Coroutine\run()`.
pub use utopia_async_macros::test;

/// Waits on several futures at once and runs the branch of the first to
/// complete, dropping the others.
#[doc(inline)]
pub use ::tokio::select;

/// Runs several futures concurrently in the current task and waits for all.
#[doc(inline)]
pub use ::tokio::join;

/// Runs several fallible futures concurrently in the current task: all
/// their outputs, or the first error.
#[doc(inline)]
pub use ::tokio::try_join;

/// Pins values on the stack.
#[doc(inline)]
pub use ::tokio::pin;

/// Declares task-local storage: a value set for the duration of a future
/// with [`task::LocalKey::scope`] and read by the code it runs.
///
/// PHP: `Swoole\Coroutine::getContext()`.
#[cfg(feature = "tokio")]
#[doc(inline)]
pub use ::tokio::task_local;

/// The engine of this build.
#[cfg(feature = "tokio")]
pub type Engine = adapter::Tokio;

#[cfg(not(feature = "tokio"))]
compile_error!("utopia-async needs an engine: enable its `tokio` feature");

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
